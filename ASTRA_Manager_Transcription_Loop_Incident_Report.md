# ASTRA_Manager Web Upload Transcription Loop Incident Report

**Project:** TranscriptionServer (ASTRA_Manager Repo)  
**Affected Flow:** Web Upload → Transport Chunking → Server Audio Chunking → API Queue → Result Synchronization  
**Incident Date:** August 3, 2026  
**Severity:** Critical  
**Status:** Root cause identified; production frontend build must be verified and rebuilt

---

## 1. Executive Summary

ASTRA_Manager repeatedly created transcription jobs while processing a large uploaded audio file.

The transcription provider and `/api/transcribe` endpoint were not the original cause. A separate four-hour endpoint test completed without reproducing the issue.

The loop originated in the Web Upload frontend before `/api/transcribe` was invoked.

An older implementation of `sendUnfinished()` iterated over the frontend `clips` collection while calling `postBatch()` inside the loop. However, `postBatch()` uploaded the entire selected source file rather than one server transcription batch.

After the first upload was accepted, the server returned transcription progress containing the generated one-minute audio clips. The frontend then replaced its local display list with that larger server clip list. Because the loop condition depended on the same mutable list, the loop continued and repeatedly uploaded the complete source file.

Each repeated upload completion created:

- A new web transcript
- A new server-side chunking operation
- A new set of API transcription jobs
- Additional RunPod jobs
- Additional result polling and queue synchronization work

The source-level frontend bug was fixed in commit:

```text
0a49b38 Queue Error fix
```

The most likely reason the incident still occurred in production is that the deployed Vite bundle was built before this fix, or an old browser tab or cached asset continued running the previous JavaScript.

---

## 2. Scope of Investigation

The investigation focused on the following flow:

```text
Web Upload
→ Upload transport chunking
→ Source reconstruction
→ Server audio chunking
→ Web transcript creation
→ API transcription job creation
→ Provider result polling
→ Web transcript queue update
```

The provider transcription implementation itself was excluded as the primary cause because:

- The `/api/transcribe` endpoint was independently tested with approximately four hours of audio.
- That direct endpoint test did not reproduce the repeated job creation.
- New API jobs are created before result synchronization, during upload completion and API queue submission.

---

## 3. Expected Processing Flow

A normal four-hour upload should follow this sequence:

```text
1. User selects one audio file
2. Browser splits the file into 20 MB transport chunks
3. Browser uploads each transport chunk once
4. Browser calls the upload completion endpoint once
5. Server reconstructs the original source
6. Server uses FFmpeg to split it into one-minute WAV clips
7. Server groups the clips into batches of up to 20 clips or 20 minutes
8. Server creates one API job for each batch
9. Browser polls project status
10. Completed API results are merged into the web transcript
```

For approximately four hours of audio:

```text
4 hours × 60 minutes = approximately 240 one-minute clips
240 clips ÷ 20 clips per API batch = approximately 12 API jobs
```

Therefore, the following is normal:

```text
1 web transcript
approximately 240 server clips
approximately 12 API jobs
```

Repeated web transcripts or significantly more than the expected number of API jobs indicate duplicate source submission.

---

## 4. Root Cause

### 4.1 Previous Frontend Behavior

The previous Web Upload implementation contained logic equivalent to:

```ts
for (
    let index = 0;
    index < clips.value.length;
    index += MAX_BATCH_CLIPS
) {
    const payload = await postBatch(projectId);
}
```

The logic assumed that each iteration submitted only one transcription batch.

That assumption was incorrect.

`postBatch()` performs the following operations:

```text
Generate a new upload ID
→ Split the entire selected source into 20 MB HTTP upload chunks
→ Upload every source chunk
→ Call POST /workspace/{project}/upload/complete
```

It does not submit only the current 20-item slice from `clips.value`.

As a result, every loop iteration uploaded the entire selected source file again.

### 4.2 Mutable Loop Length

Before the first server response, the frontend upload clip list initially contained one placeholder:

```text
clips.value.length = 1
```

After upload completion, the server returned actual transcription progress. For a four-hour file, this could expand the list to approximately:

```text
clips.value.length = 240
```

The loop condition read `clips.value.length` again after every iteration.

Therefore, the loop that initially appeared to have one iteration expanded while it was running:

```text
Initial iteration:
index = 0
clips.length = 1

After server response:
clips.length = approximately 240

Next iterations:
index = 20
index = 40
index = 60
...
index = 220
```

Each iteration called `postBatch()` and uploaded the complete four-hour source again.

### 4.3 Resulting Cascade

Each repeated source upload caused:

```text
POST /workspace/{project}/upload/complete
→ Create a new Transcript record
→ Run FFmpeg source chunking again
→ Store another complete set of one-minute clips
→ Call startApiTranscription()
→ Create another set of API transcription jobs
→ Submit more RunPod jobs
```

The repeated RunPod job IDs were therefore a downstream symptom of repeated Web Upload submission.

---

## 5. Relevant Code Locations

### Frontend Upload Controller

```text
resources/js/composables/useAudioUpload.ts
```

Important methods:

```text
start()
sendUnfinished()
postBatch()
syncTranscripts()
syncServerClips()
```

The current implementation correctly calls:

```ts
const payload = await postBatch(projectId);
```

only once per selected source session.

It also blocks duplicate submission while the session is active:

```ts
if (
    !selectedFile.value ||
    clips.value.length === 0 ||
    inFlight.value ||
    hasSession.value
) {
    return;
}
```

### Browser Transport Chunking

```text
resources/js/composables/useAudioUpload.ts
```

Current transport chunk size:

```text
20 MB
```

Browser endpoints:

```text
POST /workspace/{project}/upload/chunk
POST /workspace/{project}/upload/complete
```

### Transport Chunk Assembly

```text
app/Services/ChunkedUploadService.php
```

Responsibilities:

```text
Store uploaded file parts
Validate chunk indexes and hashes
Reassemble the source file
Validate final file size
Clean upload session files
```

### Server Audio Chunking

```text
app/Services/WebAudioChunkerService.php
```

Current audio chunk duration:

```text
60 seconds
```

Responsibilities:

```text
Read source duration using FFmpeg
Split large source files into one-minute WAV clips
Return clip start and end timestamps
Clean temporary audio chunk files
```

### Web Upload Completion

```text
app/Http/Controllers/Web/TranscriptionController.php
```

Relevant method:

```text
completeUpload()
```

Responsibilities:

```text
Assemble the uploaded source
Run server audio chunking
Create the web Transcript
Create API transcription jobs
Return the queued transcript
Clean temporary upload files
```

### Web Queue Coordination

```text
app/Services/Web/TranscriptionWorkflowService.php
```

Relevant methods:

```text
queueTranscript()
startApiTranscription()
storedClipBatches()
syncApiTranscriptionJobs()
persistApiJobs()
combinedApiJobResult()
```

### Internal API Invocation

```text
app/Services/WebApiTranscriptionClient.php
```

The web application invokes the API transcription controller internally in Laravel.

This means the internal `/api/transcribe` invocation does not necessarily appear as a separate external Nginx access-log request.

### API Job Processing

```text
app/Http/Controllers/Api/TranscriptionController.php
```

Relevant methods:

```text
transcribe()
createAsyncTranscriptionJob()
transcriptionJobStatus()
refreshAsyncTranscriptionJob()
completeAsyncTranscriptionWithFallback()
```

---

## 6. Source Fix Already Present

Commit:

```text
0a49b38 Queue Error fix
```

The fix removed the frontend loop that repeatedly called `postBatch()`.

The corrected design:

```text
One selected source
→ One postBatch() call
→ One upload completion request
→ One web transcript
→ Multiple expected API batches
```

A regression test was added to verify that the source is submitted only once even after the server expands the frontend clip display list.

Relevant test:

```text
tests/Feature/WorkspaceTest.php
```

Test intent:

```text
web upload submits the selected source only once when server clip progress expands the display list
```

The test checks that:

```text
await postBatch(projectId)
```

appears only once and that the old loop is absent.

---

## 7. Most Likely Production Cause

The repository source currently contains the fix.

The incident can still occur when production serves an older compiled frontend asset.

Updating TypeScript source with:

```bash
git pull
```

does not update compiled Vite assets automatically.

The application must also run:

```bash
npm run build
```

The deployed frontend is served from:

```text
public/build/
```

Possible reasons production still ran the old logic:

1. `npm run build` was not executed after pulling commit `0a49b38`.
2. The Vite build failed and the previous files remained in `public/build`.
3. Cloudflare cached an older HTML page or JavaScript asset.
4. The browser kept an old workspace tab open.
5. A service worker or aggressive browser cache retained an older bundle.
6. Multiple production nodes did not receive the same frontend build.

---

## 8. Verification Commands

### 8.1 Verify the Fix Exists in Deployed Source

```bash
cd /var/www/ASTRA_Manager

git rev-parse --short HEAD

git merge-base --is-ancestor 0a49b38 HEAD \
    && echo "Single-upload fix is in source" \
    || echo "Single-upload fix is missing"

grep -n "await postBatch(projectId)" \
    resources/js/composables/useAudioUpload.ts

grep -n "index += MAX_BATCH_CLIPS" \
    resources/js/composables/useAudioUpload.ts || true
```

Expected result:

```text
Single-upload fix is in source
Exactly one await postBatch(projectId)
No index += MAX_BATCH_CLIPS
```

### 8.2 Compare the Source Fix Date With the Frontend Build

```bash
git show -s --format='%ci %h %s' 0a49b38

stat -c '%y %n' public/build/manifest.json
```

If `public/build/manifest.json` predates commit `0a49b38`, production was serving a frontend built before the fix.

### 8.3 Rebuild Production Assets

```bash
cd /var/www/ASTRA_Manager

npm ci
npm run build

php artisan optimize:clear
```

### 8.4 Check Duplicate Upload Completion Requests

```bash
sudo grep -hE \
'"POST /workspace/[0-9]+/upload/complete' \
/var/log/nginx/access.log* | tail -100
```

For one selected source, the expected count is:

```text
1 upload completion request
```

Repeated completion requests for the same user and project within a short period confirm repeated frontend source submission.

### 8.5 Inspect Recent Web Transcripts

```bash
php artisan tinker --execute='
\App\Models\Transcript::query()
    ->latest("id")
    ->limit(30)
    ->get()
    ->each(function ($transcript) {
        $clips = [];
        $jobs = [];

        foreach ($transcript->processing_log ?? [] as $entry) {
            $entryClips = data_get($entry, "context.clips");
            $entryJobs = data_get($entry, "context.api_jobs");

            if (is_array($entryClips)) {
                $clips = $entryClips;
            }

            if (is_array($entryJobs)) {
                $jobs = $entryJobs;
            }
        }

        dump([
            "transcript_id" => $transcript->id,
            "created_at" => $transcript->created_at?->toDateTimeString(),
            "status" => $transcript->status,
            "duration_seconds" => $transcript->duration_seconds,
            "server_clips" => count($clips),
            "api_jobs" => count($jobs),
            "expected_api_jobs" => (int) ceil(count($clips) / 20),
        ]);
    });
'
```

Indicators of repeated source submission:

```text
Multiple transcript IDs
Same duration
Same server clip count
Created seconds or minutes apart
Same project and user
```

Expected normal result for one four-hour upload:

```text
One transcript
Approximately 240 server clips
Approximately 12 API jobs
```

---

## 9. Secondary Queue Synchronization Risk

A separate concurrency weakness exists in the result synchronization path.

The browser polls:

```text
GET /workspace/{project}/status
```

The controller calls:

```text
syncApiTranscriptionJobs()
```

This method:

```text
Loads queued or processing transcripts
Reads the first pending API job
Requests its current result
Updates the processing log
Updates partial or final transcript output
```

There is no atomic claim or database lock around selecting the next pending API job.

The frontend prevents overlapping interval ticks inside one component instance, but concurrency is still possible from:

- The initial mounted refresh
- Multiple browser tabs
- Multiple devices
- Slow requests overlapping with manual refreshes
- Future frontend changes
- Duplicate API calls from external clients

Possible effects:

```text
Two requests read the same pending API batch
Both process the same completed result
Both update transcript sections
One stale request overwrites a newer processing_log state
The same pending batch is checked repeatedly
Partial transcript state is temporarily inconsistent
```

This race does not appear to create new API jobs by itself.

New API jobs are created in:

```text
startApiTranscription()
```

which is called during a new upload completion or live transcription submission.

However, the synchronization race should still be fixed.

---

## 10. Recommended Permanent Fixes

### 10.1 Keep the Single-Submission Frontend Guard

Retain all current guards:

```ts
inFlight.value
hasSession.value
queuedTranscriptIds.value
```

Ensure `postBatch()` is called once per selected source.

### 10.2 Add Server-Side Upload Idempotency

Frontend protection is insufficient by itself.

The server should reject duplicate completion of the same upload session.

Recommended design:

```text
Store upload session state:
uploading
assembling
completed
failed
cancelled
```

The completion endpoint should atomically transition:

```text
uploading → assembling
```

Only one request should be allowed to claim this transition.

A repeated completion request should return the already-created transcript rather than creating another one.

Suggested unique idempotency key:

```text
user_id + project_id + upload_id
```

### 10.3 Persist the Upload ID on the Transcript

Add a nullable unique field such as:

```text
source_upload_id
```

or create a separate upload-session table.

This allows the server to guarantee:

```text
One upload ID
→ One web transcript
```

### 10.4 Add an Atomic Result-Sync Claim

Before checking a pending API job, obtain an atomic claim using one of these approaches:

```text
Database row lock with lockForUpdate()
Distributed cache lock
Dedicated api_job_syncing status
Per-transcript atomic lock
```

Example logical flow:

```text
Acquire transcript sync lock
Reload current processing_log
Select first pending API job
Request provider status
Persist updated result
Release lock
```

### 10.5 Make the Status Endpoint Read-Only

The preferred architecture is:

```text
Background worker updates API job state
Status endpoint only reads database state
Browser polling never performs provider work
```

This prevents browser refresh frequency from controlling server processing.

If no background queue infrastructure is used, an internal scheduled processor or dedicated command should own result synchronization.

### 10.6 Add Backend Regression Tests

Required tests:

```text
Two simultaneous upload completion requests with one upload ID create one transcript
Repeated completion returns the existing transcript
One four-hour upload creates the expected number of API batches
Concurrent status calls cannot process the same pending API job twice
Stale status writes cannot overwrite completed job state
Cancellation prevents later status polling from reviving processing
```

### 10.7 Add Operational Metrics

Log or measure:

```text
upload_id
user_id
project_id
transcript_id
source size
source duration
server clip count
API batch count
RunPod job count
upload completion request count
```

Alert when:

```text
One upload ID creates more than one transcript
API job count exceeds ceil(server_clip_count / 20)
One user creates several same-duration transcripts within a short period
RunPod job creation suddenly spikes
```

---

## 11. Immediate Production Actions

Before allowing another large upload:

```bash
cd /var/www/ASTRA_Manager

git pull
npm ci
npm run build
php artisan optimize:clear
```

Then:

```text
Close all existing ASTRA workspace tabs
Open a new private/incognito browser window
Purge relevant Cloudflare cache if necessary
Verify the loaded JavaScript asset is the new build
Test with a short audio source
Check that /upload/complete appears once
Test with a longer source
Confirm one transcript and the expected API batch count
```

Do not test another four-hour file until the single-completion behavior is confirmed using a smaller controlled upload.

---

## 12. Final Finding

The incident was caused by an older Web Upload frontend implementation that repeatedly submitted the complete selected source.

The failure chain was:

```text
Mutable frontend clip list
→ Loop continues after server expands progress clips
→ postBatch() uploads the entire source on every iteration
→ Repeated upload completion requests
→ Duplicate web transcripts
→ Duplicate server chunking
→ Duplicate API transcription jobs
→ Large RunPod queue spike
```

The `/api/transcribe` endpoint behaved as a downstream receiver of repeated submissions and was not the original source of the loop.

The source fix is already present in commit `0a49b38`, but production must be verified to ensure that the compiled frontend bundle includes it.

The remaining architectural weaknesses are:

```text
No server-side upload idempotency
No atomic lock around web result synchronization
Status polling performs state-changing work
```

These should be addressed to prevent recurrence even when an old browser asset, duplicate HTTP request, or concurrent status poll occurs.
