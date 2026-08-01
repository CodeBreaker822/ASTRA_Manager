# Transcription Queue Issue Report

**Date:** 2026-08-01  
**Status:** ⚠️ CRITICAL — Large audio uploads stuck at Queue 0%  
**Affected:** Audio files >100MB, processed asynchronously

---

## Issue Summary

When uploading large audio files (140MB+), the transcription job remains stuck at **Queue 0%** indefinitely. Small files (1-5 minutes) complete successfully but still show **0% → 100%** with no progress updates during processing.

### Symptoms

- ✗ Large audio upload (140MB) stuck at "Queued" status after 10+ minutes
- ✗ Small audio (1 minute) completes but shows no progress (0% immediately, then 100%)
- ✗ No intermediate status updates during processing
- ✗ Queue appears frozen/unresponsive
- ✗ Duration display shows "Server Measured" placeholder text instead of actual duration

### Impact

- **Users cannot transcribe large audio files**
- **No progress feedback during processing**
- **HTTP timeout failures on large batches**
- **Sequential processing blocks all other jobs**

---

## Root Cause Analysis

### Why Async Jobs Are Stuck

The transcription system claims to be "asynchronous" but processes jobs **synchronously in HTTP requests**:

```
User Upload
  ↓
POST /transcription/upload
  ↓
create Transcript (status='queued')
  ↓
return 202 + job_id
  ↓
Client polls GET /api/transcribe/jobs/{job_id}
  ↓
transcriptionJobStatus() called
  ↓
refreshAsyncTranscriptionJob() → processAsyncTranscriptionJob()
  ↓
⚠️ ENTIRE BATCH TRANSCRIBED IN HTTP REQUEST (no queue worker!)
  ↓
Update job status to 'completed'
```

### Why Large Files Timeout

**File:** `TranscriptionServer/app/Http/Controllers/Api/TranscriptionController.php`  
**Method:** `processAsyncTranscriptionJob()` at line 951

Current flow:
1. Job starts processing when client polls status
2. Calls `transcribeQueuedClips()` (line 985)
3. Processes each clip **sequentially**:
   - Load audio from disk
   - Send to Groq API
   - Wait for response
   - Save result
   - Repeat for next clip
4. **No progress updates** sent to client
5. **No background worker** — all work happens in HTTP request thread
6. HTTP times out (default 30-300 seconds depending on server)
7. Job stays "queued" or "processing" forever

### Why Small Files Work

- 1-minute audio completes transcription within HTTP timeout window
- Still shows 0% → 100% because processing is synchronous with no intermediate updates

### Duration Display Issue

**File:** `TranscriptionServer/app/Services/Web/TranscriptPayloadPresenter.php`

The presenter returns `duration_seconds` from the database, but the frontend is displaying "Server Measured" as a placeholder text. This suggests:
- Duration is not being calculated/stored properly during upload
- Frontend expects actual duration value but gets null/empty
- Fallback text displays instead of actual value

---

## Current Architecture (Broken)

```
Upload → Async API
  ├─ Create ApiTranscriptionJob (status='queued')
  └─ Return 202 immediately
  
Client Poll (every 2-5 seconds)
  └─ transcriptionJobStatus()
      └─ refreshAsyncTranscriptionJob()
          └─ if mode='queue' AND status='queued'
              └─ processAsyncTranscriptionJob() ⚠️ RUNS IN HTTP REQUEST
                  └─ transcribeQueuedClips()
                      ├─ Try batch transcribe (RunPod only)
                      └─ If fails, loop through each clip sequentially ❌
                          ├─ Clip 1: Send to Groq, wait, save result
                          ├─ Clip 2: Send to Groq, wait, save result
                          └─ Clip N: Send to Groq, wait, save result
```

**Problems:**
- ❌ No background queue worker
- ❌ Processing happens in HTTP request (timeout risk)
- ❌ Sequential processing (no parallelization)
- ❌ No progress tracking
- ❌ Blocks other requests

---

## Expected Architecture (Recommended)

```
Upload → Async API
  ├─ Create ApiTranscriptionJob (status='queued')
  ├─ Dispatch ProcessAsyncTranscriptionJob to queue
  └─ Return 202 immediately

Background Queue Worker (parallel)
  ├─ Pick up job from queue
  ├─ Update status='processing'
  ├─ Process clips (with progress tracking)
  └─ Update status='completed'

Client Poll
  └─ Get current job status
      ├─ queued (waiting to start)
      ├─ processing (X of Y clips done)
      └─ completed (get results)
```

**Benefits:**
- ✅ No HTTP timeout issues
- ✅ Multiple jobs processed in parallel
- ✅ True asynchronous processing
- ✅ Progress updates available during processing
- ✅ Handles large files elegantly

---

## Required Changes

### 1. Create Queue Job Class

Create `app/Jobs/ProcessAsyncTranscriptionJob.php`:
- Accept `ApiTranscriptionJob` ID
- Load job and process clips
- Update progress to database
- Handle failures with retry logic

### 2. Dispatch Job on Upload

In `ApiTranscriptionController::createAsyncTranscriptionJob()`:
- After creating job, dispatch to queue
- Don't wait for response
- Return 202 immediately

### 3. Add Progress Tracking

In `ApiTranscriptionJob` model:
- Add `processed_clips_count` column
- Add `total_clips_count` column  
- Update during processing

### 4. Fix Duration Display

In `TranscriptPayloadPresenter::present()`:
- Ensure `duration_seconds` is always populated
- Add fallback duration calculation from clips

### 5. Configure Queue Worker

In `config/queue.php` and `.env`:
- Set `QUEUE_CONNECTION=database` or similar
- Configure daemon workers in production
- Add supervisor config for auto-restart

---

## Code Locations

| Component | File | Line |
|-----------|------|------|
| Async Job Creation | `Api/TranscriptionController.php` | 811-950 |
| Job Processing | `Api/TranscriptionController.php` | 951-1000 |
| Job Status Check | `Api/TranscriptionController.php` | 1190-1220 |
| Status Refresh | `Api/TranscriptionController.php` | 1050-1130 |
| Clip Transcription | `Api/TranscriptionController.php` | 1257-1305 |
| Batch Transcription | `Api/TranscriptionController.php` | 1441-1486 |
| Duration Display | `Web/TranscriptPayloadPresenter.php` | 1-100 |

---

## Test Cases

When implemented, these should pass:

- [ ] Upload 140MB audio → Returns 202 with job_id
- [ ] Poll status during processing → Shows "processing"
- [ ] Wait for job completion → Shows "completed" with results
- [ ] Upload multiple files → All process in parallel
- [ ] Large file takes 15+ minutes → No timeout, complete success
- [ ] Duration displays actual value → Not "Server Measured" placeholder
- [ ] Job cancellation → Stops processing cleanly

---

## Estimated Impact

**Severity:** 🔴 CRITICAL  
**User Impact:** Complete inability to process large transcriptions  
**Fix Complexity:** Medium (requires queue infrastructure setup)  
**Performance Gain:** Dramatic (supports unlimited concurrent uploads)  

---

## Next Steps

1. [ ] Create `ProcessAsyncTranscriptionJob` queue job class
2. [ ] Modify `createAsyncTranscriptionJob()` to dispatch to queue
3. [ ] Add progress columns to `api_transcription_jobs` table
4. [ ] Update `refreshAsyncTranscriptionJob()` to check progress
5. [ ] Fix duration calculation and display
6. [ ] Configure Laravel queue worker (database/Redis)
7. [ ] Test with large audio files (100MB+)
8. [ ] Deploy queue worker to production
