# Processing & UI Parity Plan — Web Workspace vs JERVA Desktop

> **For:** Codex Agent (implementation)
> **Project:** `TranscriptionServer` (`D:\Transcriber Project\TranscriptionServer`)
> **Depends on:** `JERVA_WEB_SAAS_PLAN.md` (done), `NO_MANUAL_CODE_PLAN.md`
> **Goal:** The web `/workspace` must **behave** like the JERVA desktop workspace — same loaders, modals, button states, progress UI, toasts, and verbatim copy. It already *looks* close; this plan fixes *how it acts*.
> **Source of truth:** `AITranscriber\resources\js\` (app.js, live/upload/settings controllers), `AITranscriber\public\{loader,notification}.js`, `AITranscriber\public\js\modals\*.js`, and the JERVA (`$workspace === true`) branches of `shared/modals/*.blade.php`.

---

## 1. Hard Rules (read first)

1. **NO offline modes.** Nothing from the exclusion list (§8) may appear: no online/offline toggle, no Whisper model picker, no "Silero"/"VAD"/"Sherpa"/"Diarization" phase strings anywhere, no offline-model download UI, no Resources/Memory/Server-license settings tabs.
2. **NO ASTRA indication.** Never emit the dark class strings the desktop JS emits (`text-cyan-300`, `border-white/10`, `bg-white/[0.03]`, `tracking-[0.3em]`, gradient progress bars, rose/emerald pills). The desktop's CSS remaps those to light at runtime — **the Vue port emits the final light classes directly** (mapping table in §3.3).
3. **Verbatim copy.** User-facing strings come from §7 exactly. No paraphrasing.
4. **Server reality wins over desktop fiction.** Desktop phases like "Silero"/"Sherpa" don't exist on the web — collapse all server-side work into the single **"Processing"** phase. Everything else (status strings, button choreography, modal flows, timings) replicates 1:1.
5. **Web adaptations are allowed where the desktop used desktop-only bridges** (Tauri file dialogs, background-job registry) — specified per feature below.
6. **All audio processing goes through the EXISTING transcription API pipeline** — `POST /api/transcribe` + `GET /api/transcribe/jobs/{job}` (the same endpoints the desktop app consumes, with license validation, per-license 120/min rate limit, 20-clip/20-min batch constraints, provider fallback, masked provider identity, and request logging). The current parallel web pipeline (`WebTranscriptProcessor` calling provider services directly) is **replaced**, not extended. Audio is **sent by chunk**, replicating the desktop's online processing flow end-to-end.
7. **One license key per user, auto-generated at registration.** Desktop users type a license key into Settings; browser users can't — so every new account gets its own `a_p_i_s` license row. This is what keeps the endpoint's per-license rate limit and request logs **per-user** instead of shared. The key is used **server-side only** — it never reaches the browser (see §6.2 for the full design).

---

## 2. Current State — Gap Audit (verified)

The entire workspace is one 1,269-line `resources/js/pages/workspace/Index.vue`; no composables; renders with `layout: null`.

| Area | Desktop JERVA behavior | Web today | Gap |
|---|---|---|---|
| **Processing pipeline** | Desktop backend → `POST /api/transcribe` (Bearer license, `response_mode=async`, batched `audio[]` clips with `clip_index/clip_start_ms/clip_end_ms` parallel arrays) → 202 `{job_id}` → poll `GET /api/transcribe/jobs/{job}` every 2s → map `clips[]` results | `WebTranscriptProcessor` — a **parallel pipeline** calling provider services directly (duplicate fallback logic), whole-file uploads, no license, no API request logs | Route web processing through the existing API pipeline with per-user licenses (§6.2) |
| **Audio chunking** | Uploads split into sections and sent in batches of ≤20 clips / ≤20 min total (`HostedTranscriptionLimitGuard` mirrors server constants), per-section status lifecycle, transcript streams in per batch | Whole file in one request, one queued job | Browser-side chunking + batched sending, replicating the desktop flow (§4.1) |
| **License keys** | One license per desktop install, entered in Settings; endpoint rate-limits/logs per license | No licenses for web users (session auth only) | Auto-generate one license per user at registration (§6.3) |
| Button loaders | `toggleLoading()` swaps content to spinner + **"Processing..."** + disabled | Label text swap only ("Uploading"); no spinners anywhere | Full loader pattern missing |
| Toasts | Top-right green-500/red-500, 5s auto-dismiss, slide-out 300ms | **No `<Toaster/>` mounted in workspace** (layout is null); `WorkspaceController` flashes `success` but `flashToast` listens for `toast` — actions are silent; inline banners persist forever | Toasts entirely broken in workspace |
| Upload flow | Status line choreography ("Uploading source {n}%" → "Processing {n} of {total}" → "Complete"), transport buttons Start/Pause/Continue/Retry/Cancel that **disappear when disabled**, XHR upload %, cancel aborts | `fetch` (no progress), label swap, no cancel/retry, red banner on error | Whole state machine missing |
| Processing indicator | Determinate bar + status strings; summary gets indeterminate `animate-pulse` bar | **Fake static `w-2/3` bar** hard-coded | Replace with real status-driven UI |
| Live recording | Two-line button ("Listening/Ready to capture" → "Recording/Stop recording"), MM:SS timer @100ms, support line states, pending clips with state pills, mic-error strings | Plain "Live"/"Stop Live" swap, no timer, no mic-error handling, chunk uploads flicker the Upload button | Most of the flow missing |
| Polish modal | Native dialog, 4 presets w/ exact instruction payloads + custom textarea (2000 max), preset-select sync, error line, replace warning; page button shows "Polishing" | shadcn dialog, presets only, **no custom input** (API supports it), dialog hangs during **synchronous** request, double-submit possible | Missing custom instructions + async state |
| Summary modal | Status flow idle/processing/complete/failed, source selector (Raw/Cleaned), indeterminate bar, export inside modal, 2s status polling, "close and return later" | Simple dialog, synchronous POST, hangs open | Entire status flow missing |
| Export | Upward-opening menu (TXT/Word/Excel), "Processing..." on hidden button, toast on download start | A **dialog** (wrong pattern), `window.location.href`, zero feedback, never closes | Wrong component + no feedback |
| Slide-overs (pending clips, log) | 300ms slide, ESC + backdrop close, focus management | Hand-rolled, no ESC/focus/scroll-lock | A11y gaps |
| Delete project | n/a (desktop has no multi-project delete) | **Immediate delete, no confirm** | Needs confirm dialog |
| 402 quota | n/a | Red banner; `upgrade: true` flag ignored | Needs upgrade CTA banner |
| Polling | Desktop polls per-flow with completion | 5s `setInterval` **never stops** once started | Lifecycle + stop conditions |

---

## 3. Foundation Work (do first)

### 3.1 Workspace toast system (replicate `public/notification.js`)

The desktop toast = fixed top-right (`top: 20px; right: 20px`), `px-6 py-3 rounded shadow-lg text-white`, **green-500 success / red-500 error** (no third type), X close button, **5000ms** auto-dismiss, close = `translateX(100%)` over **300ms**, stacked by document order, z-index above all modals.

- **Do not** drag vue-sonner into the workspace (it doesn't match the desktop look); build a small `WorkspaceToast.vue` + `useWorkspaceToast()` composable matching the spec above. Mount it inside `workspace/Index.vue` itself (workspace has no layout).
- Keep vue-sonner for settings/marketing pages (out of scope here).
- Fix the silent-Inertia-actions bug while at it: make `WorkspaceController` flash `toast => {type, message}` (the shape `flashToast` expects) OR render workspace-side toasts from `flash.success` on page load — pick one, apply to project create/rename/delete.
- Toast strings from §7.5.

### 3.2 Button loader pattern (replicate `public/loader.js` `toggleLoading`)

Create `ProcessingButton.vue` (or a `useButtonLoading` directive) implementing the desktop contract:

- On loading: stash current slot content, render spinner (SVG circle `opacity-25` + path `opacity-75`, `animate-spin -ml-1 mr-3 h-4 w-4 text-white`) + label **"Processing..."**, set `disabled`.
- On done: restore original content, re-enable.
- Used by: settings saves (already in settings area), export actions, summary-modal export, any future mutating button. Buttons whose labels change semantically (e.g. "Polish" → "Polishing") use their own label-swap instead — see per-feature specs.

### 3.3 Light-class emission table (replaces the desktop's dark→light remap)

Emit these directly in Vue (never emit the dark originals):

| Desktop JS emits (ASTRA dark) | Web emits (JERVA light) | Where |
|---|---|---|
| `text-cyan-300` (range labels, "Clip N", speaker names) | `text-blue-600` | transcript rows, pending clips |
| `text-slate-100` / `text-white` (body, titles) | `text-slate-900` | transcript rows, clip cards |
| `text-slate-400` / `text-slate-500` | `text-slate-500` (labels only) / `text-slate-600` (readable copy) | metas — respect contrast floor |
| `border-white/10` on `article` clip cards | `border-blue-100 bg-blue-50` | pending clips |
| `span.rounded-lg.border` status pills (Speech/log) | `border-green-200 bg-green-50 text-green-800` (`#bbf7d0/#ecfdf5/#166534`) | processing log |
| Gradient bar `from-cyan-400 via-emerald-300 to-amber-300` | `bg-blue-600` solid | clip progress bars |
| rose/emerald/cyan pill variants | JERVA pills per §4.6 | pending clips |
| Playing-row highlight `border-cyan-300/20 bg-cyan-300/5` | `border-blue-200 bg-blue-50` | clip playback |

### 3.4 Composable extraction

Split `Index.vue` logic (it stays the page shell) into testable composables — required for the state machines below:

- `useTranscriptPolling(project)` — status polling with **stop conditions** (§6.1)
- `useAudioUpload(project)` — XHR upload with progress %, abort, retry (§4.1)
- `useLiveRecorder(project)` — MediaRecorder segments, timer, queue (§4.2)
- `useTranscriptActions(project)` — polish/summarize/export state (§4.3–4.5)
- `useWorkspaceToast()` — §3.1

---

## 4. Feature Replication Specs

### 4.1 Upload flow — full desktop state machine

**Panel** (above command dock, visible once active): file name ("Select an audio file" placeholder), meta line, `Duration: --:--`, status line `[status]`, percent label, determinate bar (`h-2 rounded-full bg-slate-100` track, `bg-blue-600 transition-[width] duration-150` fill).

**Transport buttons** (all `h-12`; exact classes from desktop; **disabled ⇒ `display:none`** — the signature JERVA behavior):

| Button | Style | Visible/enabled when |
|---|---|---|
| **Browse** | `h-12 min-w-32 border border-blue-200 bg-blue-50 text-blue-700 hover:border-blue-300 hover:bg-blue-100` | Always (file pick) |
| **Start** | `bg-blue-600 text-white hover:bg-blue-700 disabled:bg-slate-200 disabled:text-slate-500` | file chosen && !inFlight && !hasSession |
| **Pause** | `border border-slate-200 bg-white text-slate-700 hover:border-blue-300 hover:bg-blue-50` | inFlight && !pauseRequested *(v1: pause = abort-to-retryable; see note)* |
| **Continue** | same as Pause | session exists && unfinished sections |
| **Retry** | same as Pause | session exists && retryable (failed/cancelled) |
| **Cancel** | `border border-red-200 bg-red-50 text-red-700 hover:border-red-300 hover:bg-red-100` | inFlight || progress exists |

**Chunked sending — replicate the desktop's online flow (browser adaptation):** the desktop slices audio into sections locally (via Silero VAD — banned on web) and ships them in batches to `/api/transcribe`. The web replicates this **without VAD**:

1. **Slice client-side:** decode the picked file with `AudioContext.decodeAudioData`, split into fixed **60-second sections** (same cadence as desktop's live segments), re-encode each section (WAV via `AudioBuffer` PCM export — simplest, universally decodable server-side). Each section gets `{clip_index, clip_start_ms, clip_end_ms, duration_ms, range_label "MM:SS-MM:SS"}`.
2. **Batch guard (mirror server constants, like the desktop's `HostedTranscriptionLimitGuard`):** ≤ **20 clips** per batch and ≤ **1,200,000 ms** summed duration; violation → toast **"Audio is too big."** before anything is sent.
3. **Send batches sequentially** to the web endpoint (`POST /workspace/{project}/upload`, now accepting `audio[]` + per-clip metadata arrays — §6.2), which forwards each batch through the **existing `/api/transcribe` pipeline** using the current user's license (async mode → poll jobs endpoint) and writes returned `clips[]` texts into `TranscriptSection` rows.
4. **Per-section status lifecycle** (drives the pending-clips list in upload mode): **"Waiting" → "Sending" → "Processing" → "Complete"**; bad terminals **"Failed"** / **"Cancelled"**. Meta strings: "Waiting for source upload" → "{size} sent" → "Ready to retry" / "Ready to continue".
5. **Transcript streams in per batch:** after each batch completes, its sections append to the transcript panel (exactly like desktop's `renderTranscript()` after each batch) — not all-at-once at the end.
6. **Pause/Continue/Cancel/Retry operate on the section queue**, honestly: Pause stops dispatching further batches ("Pausing" → "Paused"); Continue resumes unfinished sections; Retry resets Failed/Cancelled sections and re-sends; Cancel aborts the in-flight XHR, asks the server to cancel the active API job (§6.2 cancel passthrough), maps unfinished sections to "Cancelled", settles after **350ms** → status **"Cancelled"**.

**Status-line choreography (verbatim, in order):**
1. Idle: **"Ready"**
2. File picked: **"Ready"**, meta → `"{size} selected"`
3. Slicing: **"Preparing source"**
4. Uploading a batch (XHR `progress` event): **"Uploading source {n}%"** (bar stays 0 — percent lives in the text, exactly like desktop)
5. Server processing batches: **"Processing"**, then **"Processing {firstBatchPosition} of {totalSections}"**
6. Done (all sections complete): **"Complete"**, bar forced `100%`, toast **"Audio transcription completed."**
7. Failed: **"Failed"** + toast (server message or **"Audio upload could not be processed."**)
8. Cancel click: **"Cancelling"** → (350ms settle) → **"Cancelled"**

Implementation: `XMLHttpRequest` (not `fetch`) for real upload-progress events; `xhr.abort()` for cancel. Retry re-POSTs the same sections. Progress bar = completed sections / total sections (determinate); the desktop's per-section simulated 350ms easing timer may be reused for in-flight sections (cap 98%, snap to 100 on response, 150ms hold).

### 4.2 Live recording flow

**Record button** (two-line label, replicates `[data-record-toggle]`):

| State | Top line (`text-xs font-semibold uppercase`) | Bottom line (`text-sm font-semibold`) | Icon |
|---|---|---|---|
| Idle | **"Listening"** `text-blue-200` | **"Ready to capture"** `text-white` | play triangle |
| Mic request | (unchanged) | **"Requesting microphone"** `text-rose-50` | play |
| Recording | **"Recording"** `text-rose-300` | **"Stop recording"** `text-rose-50` | stop square |
| Unsupported/blocked | **"Unavailable"** `text-rose-300` | **"Click for details"** `text-rose-50` | play |

Button base stays `h-12 min-w-40 bg-blue-600` + `hover:scale-[1.01]`; `aria-pressed` mirrors state.

**Progress panel** (visible while recording or processing):
- Active name: **"Ready" / "Recording" / "Processing"**
- Note: current clip range **"MM:SS-MM:SS"**
- Elapsed timer: **MM:SS** (<1h) / **HH:MM:SS**, tick every **100ms**, reset **"00:00:00"** on stop
- Bar: elapsed within the current segment
- Support line strings: **"Ready"**, **"Live"**, **"Sending"**, **"Saved"**, **"Save failed"**, **"Requesting mic"**, **"Microphone blocked"**, **"Start failed"**, **"Processing"**

**Segments:** keep the 15s `MediaRecorder` timeslice → `POST /workspace/{project}/chunk` — but chunks are now processed through the **same existing `/api/transcribe` pipeline** with the user's license (§6.2), one async job per chunk, polled to completion. Fix the current bugs per desktop behavior:
- Chunk uploads must NOT touch the Upload button state (separate state stores — the current `isUploading` cross-contamination bug)
- `getUserMedia` denial → support **"Microphone blocked"** + toast **"Microphone access is blocked. Please allow it to record audio."**
- Chunk failure → queue item state "Error", recording auto-stops, toast **"Clip {n} could not be saved. Please try again."**
- While a chunk is uploading: support **"Sending"** → **"Saved"**

**Pending clips sidebar (live mode):** each queued/sending clip = card (`rounded-lg border border-blue-100 bg-blue-50 p-4`): kicker **"Clip {n}"** (`text-xs uppercase text-blue-600`), range label (`text-lg font-semibold text-slate-900`), state pill, `bg-blue-600` progress bar. Pills (`rounded-full border px-2.5 py-1 text-[0.68rem] font-semibold uppercase`):

| State | Classes |
|---|---|
| **Waiting** | `border-slate-200 bg-white text-slate-600` |
| **Sending** | `border-blue-200 bg-blue-50 text-blue-700` |
| **Saved** | `border-green-200 bg-green-50 text-green-800` |
| **Error** | `border-red-200 bg-red-50 text-red-700` |

Empty: dashed `border-blue-200 bg-blue-50` box, `text-sm text-blue-900`, **"No recordings yet."**

### 4.3 Processing indicator (transcript cards)

Replace the fake `w-2/3` bar with the desktop's summary-style indeterminate bar + real status:
- While `queued|processing`: status pill text from server status (**"Processing"**), indeterminate bar `h-1 overflow-hidden bg-blue-100` with inner `h-full w-full animate-pulse bg-blue-600`, body copy driven by processing-log phase (server already returns `processing_log[]`; show latest entry label, e.g. "Transcribing"). **Never render "Silero"/"Sherpa"/"Diarization" strings** — the backend must map its log labels to web-safe ones (§6.4).
- Failed: red pill + the transcript's error message inline (currently hidden) + **Retry** button (re-runs upload flow for uploads; re-queues processing for chunks).

### 4.4 Polish modal — exact desktop replication

Keep shadcn `Dialog` shell but replicate desktop content/behavior (`[data-polish-dialog]` spec):

- Header: kicker **"Polish transcript"** (`text-xs font-semibold uppercase text-blue-600`), title **"Instructions"**
- **4 preset buttons** (`sm:grid-cols-2`), labels and **exact instruction payloads** (copy verbatim from desktop `polish-instructions.js`):
  - **"Translate to English"** → "Translate every non-English part of the transcript into clear English. Treat Cebuano, Bisaya, Filipino, Tagalog, and mixed code-switching as source language. Do not leave source-language words untranslated unless they are names, offices, agencies, titles, acronyms, places, or proper nouns. Preserve meaning, speaker intent, numbers, and time order."
  - **"Translate to Filipino"** → "Translate every non-Filipino part of the transcript into clear Filipino. Treat English, Cebuano, Bisaya, and mixed code-switching as source language. …" (same tail)
  - **"Fix grammar"** → "Fix grammar, spelling, punctuation, capitalization, and obvious speech-to-text mistakes without translating the transcript. Preserve the original language choices, meaning, names, titles, numbers, and time order."
  - **"Translate and fix"** → "Translate every non-English sentence, phrase, or word into polished English, then fix grammar, spelling, punctuation, capitalization, and obvious speech-to-text mistakes. Treat Cebuano, Bisaya, Filipino, Tagalog, and mixed code-switching as source language. …" (same tail)
  - Selected style `border-blue-600 bg-blue-600 text-white`; idle `border-blue-200 bg-white text-blue-900 hover:border-blue-400 hover:bg-blue-50`. **Selection sync rule:** preset is selected only when textarea content *exactly equals* the preset payload; any edit deselects.
- **Custom instructions textarea** (currently missing — API already accepts `preset=custom` + `instruction` ≤4000): `rows=7`, maxlength 2000, placeholder verbatim: *"Example: Translate Cebuano, Bisaya, Filipino, and code-switched speech into polished English while preserving names, offices, acronyms, titles, numbers, and meaning."*
- Error line (trim < 3 chars on confirm): **"Enter instructions before polishing."** (`text-sm font-semibold text-blue-700`), hides on input.
- Replace warning (always shown on open when cleaned text exists): **"Polishing again replaces the current polished transcript."** (`border-l-2 border-blue-500 bg-blue-50 text-blue-950`).
- Confirm **"Polish transcript"** validates then closes the modal; the **dock Polish button** carries the loading state: label **"Polish"** → **"Polishing"** + disabled.
- **Make it asynchronous (backend change, §6.4):** queue the polish job (which calls `POST /api/polish` with the user's license); transcript status → `processing`; poll via existing status endpoint; done → toast **"Transcript polished."**; fail → toast **"Transcript could not be polished."** This kills the current "dialog hangs + double-submit" bug and matches the desktop's background-job behavior.

### 4.5 Summary modal — exact desktop replication

Content/flow per `[data-summary-dialog]` spec:

- Header row: status pill (`border-blue-200 bg-blue-50 text-blue-800`), **Source** select (**"Raw transcript"** / **"Cleaned transcript"**), run button, format select (**"TXT" / "Excel" / "Microsoft Word"**) + Export
- Indeterminate bar while processing: `h-1 bg-blue-100` + inner `animate-pulse bg-blue-600` (full-width pulse — **not** a width bar)
- Status flow:

| Status | Pill text | Run button | Body |
|---|---|---|---|
| idle | **"Ready"** | **"Summarize"** | summary text or **"No summary has been created for this project."** |
| processing | **"Summarizing..."** | **"Replace summary"** (disabled while requesting) | **"The summary is being prepared. You may close this window and return later."** |
| complete | **"Complete"** | **"Replace summary"** | rendered markdown |
| failed | **"Failed"** | **"Retry"** | previous text + error box (`border-l-2 border-blue-500 bg-blue-50 text-blue-800`) |

- Backend (§6.4): queued summarize job + `summary_status` on the transcript; modal polls the existing status endpoint every **2s** while processing (and continues on reopen — "close and return later" is real)
- Export button: disabled unless complete && non-empty; click → `ProcessingButton` loader (**"Processing..."**) → **fetch as blob → object-URL download** (so completion is observable) → toast **"Export download started: {filename}"**; failure toast **"Could not save the summary export. Please try again."**
- Footer note verbatim: **"Starting again replaces this project's existing summary."**
- Summary markdown render classes (workspace branch): headings `mt-5 first:mt-0 text-sm font-semibold uppercase text-blue-700`; `ul` `my-3 ml-5 list-disc space-y-2`; paragraphs `my-3 first:mt-0 last:mb-0`; `strong` `font-semibold text-slate-950`; empty fallback `text-blue-900`.

### 4.6 Export — replace the dialog with the desktop's upward menu

- Trigger: `h-11 border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-700 hover:border-blue-300 hover:bg-blue-100`, download icon + **"Export"**, `aria-haspopup="menu"`, `aria-expanded`
- Menu: `absolute bottom-full right-0 mb-2 w-44 rounded-lg border border-blue-100 bg-white p-1 shadow-[0_16px_40px_rgba(15,23,42,0.14)]` — **opens upward**, instant (no transition), `role="menu"`
- Items (`h-9 rounded-md px-3 text-sm font-semibold text-slate-900 hover:bg-blue-50 hover:text-blue-700`): **"TXT"**, **"Microsoft Word"**, **"Excel"** — with a leading **Raw/Cleaned source toggle** (desktop uses hidden selects; web exposes a small two-option segmented control above the items: **"Raw" / "Cleaned"**; cleaned disabled with tooltip **"Polish the transcript before exporting the cleaned version."** when no cleaned text)
- Outside click / ESC closes; one menu at a time
- Click item → menu closes → blob fetch download (like §4.5) → toast **"Export download started: {filename}"**; no content → toast **"No transcription is ready to export yet."**

### 4.7 Slide-overs (pending clips + processing log) — a11y parity

Keep the custom slide-overs (they match desktop visually) but add the desktop's behaviors:
- Open: overlay fade + panel `translate-x-full → 0`, `transition-transform duration-300 ease-out`; **focus moves into panel**
- Close: X button, **backdrop click**, **ESC key**; reverse animation (300ms) then unmount; **focus returns to the dock trigger**
- `aria-hidden`, `aria-expanded` on triggers, `role="dialog" aria-modal="true"`
- Log slide-over: toggle button swaps **"Log" ↔ "Transcript"** (list icon ↔ back-arrow); while fetching: disabled + **"Loading"**; log pills: **"Speech"** (`border-green-200 bg-green-50 text-green-800`) / **"No speech"** (`border-blue-300 bg-white text-blue-900`); empty: `"No processing logs found for {project}."` Errors: **"Could not load processing logs."**

### 4.8 Modals (Add Transcript / Rename) + destructive confirm

- Add Transcript: overlay `bg-slate-950/40` fade **200ms**; card `mt-24 max-w-md rounded-lg bg-white p-4 shadow-2xl`; **focus the name input on open**; **Enter submits**; backdrop click closes; buttons **"Cancel"** (`border-slate-200 text-slate-700 hover:bg-slate-50`) / **"Add"** (`bg-blue-600 hover:bg-blue-700`). shadcn Dialog already animates — tune to these values.
- **Delete project: add confirm dialog** (desktop has no precedent — use the same modal pattern): title **"Delete transcript?"**, copy **"This permanently deletes '{title}' and its transcripts. This cannot be undone."**, buttons Cancel / **"Delete"** (`border-red-200 bg-red-50 text-red-700 hover:bg-red-100`).

### 4.9 Empty states (verbatim desktop copy)

| Location | Copy |
|---|---|
| No project | kicker "Transcription workspace"; title **"Hi, what are we transcribing today?"** (`text-3xl font-semibold text-slate-950`); body **"Start a transcript from the left, then choose Live or Upload Audio. I'll keep the transcript here so you can polish, summarize, export, or review the processing log when it's ready."** (`text-blue-950`); header title **"Welcome"** |
| Project, no activity | title **"Great. How do you want to add audio?"**; body **"Choose Live if you're recording now, or Upload Audio if the file is already on your computer."** |
| Live panel empty | kicker **"Live transcript"**; headline **"Ready when you are."**; body **"Press Live below to start capturing audio. Your transcript will appear here as each section finishes."** |
| Upload panel empty | kicker **"Upload transcript"**; headline **"Drop in an audio file and I'll organize the transcript."**; body **"Choose Upload Audio below, browse for a file, and the finished transcript will appear here."** |
| Sidebar Recent empty | **"No transcripts yet."** (`px-3 py-3 text-sm text-slate-500`) |
| Transcript list empty | **"No entries yet."** |

---

## 5. Toasts, Banners & Quota

1. **Remove the persistent inline `actionMessage`/`actionError` banners** — desktop has no such thing; all feedback moves to toasts + in-context status lines.
2. **402 / quota responses** (`{message, upgrade: true}`): show a dedicated dismissible banner at the top of the transcript column — `rounded-lg border border-blue-100 bg-blue-50 px-4 py-3`, text `text-sm text-blue-900` = server message, plus **"View plans"** link (`text-blue-700 font-semibold`) → `/settings/billing`. Auto-dismiss on next successful action.
3. Toast trigger map (verbatim strings in §7.5): upload complete/failed, polish done/failed, summary failed, export started/failed, clip saved-failed, mic blocked, project created/renamed/deleted.

---

## 6. Backend Adjustments (supporting, minimal)

### 6.1 Polling lifecycle
- Poll `/status` every **3s while any transcript is `queued|processing`** OR while live recording has unsaved chunks; **stop when idle** (current bug: never stops). After stop, one final refresh. Expose `lastUpdated` for tests, not UI.

### 6.2 Pipeline unification — web processing through the EXISTING endpoints (core change)

**Replace the parallel web pipeline.** Today `WebTranscriptProcessor` duplicates provider selection/fallback and calls STT services directly. Instead, all web audio processing flows through the existing API endpoints the desktop uses — same provider fallback, same masked identity (`aims_server` / "AIMS Server" / "Free-Model-Fast"), same `transcription_api_request_logs` entries, same per-license rate limit.

**Architecture (key stays server-side):**

```
Browser (session auth)
  → POST /workspace/{project}/upload   (batched sections: audio[] + clip_index/clip_start_ms/clip_end_ms arrays)
  → POST /workspace/{project}/chunk    (single live slice + clip metadata)
        │  Web controllers: session auth, entitlement checks (can.transcribe quota → 402),
        │  resolve the current user's license (§6.3), then forward internally:
        ▼
  POST /api/transcribe   (Authorization: Bearer <user's license>, response_mode=async)
        → 202 {job_id}
  GET  /api/transcribe/jobs/{job}  (poll every 2s until completed|failed)
        → result.clips[]  →  map into TranscriptSection rows (position, text, started_at_ms/ended_at_ms)
```

- **Forwarding mechanism:** an internal `WebApiTranscriptionClient` service that wraps the existing endpoints exactly like the desktop's `HostedApi\HostedTranscriptionClient` does (Bearer token, multipart `audio[]`, parallel metadata arrays, `response_mode=async`, 2s job polling, deadline). Call the pipeline through Laravel's internal request dispatch (or extract the controller's pipeline into a shared service if a self-HTTP-call is undesirable — decision left to implementer; the **contract is the existing endpoint behavior**, not code duplication).
- **Batch constraints honored end-to-end:** web controllers enforce the same 20-clip / 1,200,000-ms guard (matching the API's `MAX_TRANSCRIBE_BATCH_CLIPS` / `MAX_TRANSCRIBE_BATCH_DURATION_MS`) and return 422 **"Audio is too big."** on violation.
- **Rate-limit surfacing:** if the API answers 429 `{message: "License key is rate-limited.", retry_after}`, the web endpoint pauses dispatch, keeps sections in "Waiting", and retries after `retry_after` seconds (per-user license makes this rare — §6.3).
- **Cancel passthrough:** `POST /workspace/{project}/transcripts/{transcript}/cancel` → aborts local dispatch; for the active API job, best-effort: stop polling and discard the result on arrival; mark remaining sections "Cancelled". (The API has no job-cancel endpoint — do not add one in this plan.)
- **Delete `WebTranscriptProcessor`'s duplicated provider-fallback code** once the pipeline route is live; keep `ProcessWebTranscriptJob` as the queue wrapper (it now calls `WebApiTranscriptionClient` and maps results).
- **Entitlements stay at the web layer** (plan quotas, 402 upgrade flow) — the API pipeline knows nothing about plans; the license only carries rate limiting and logging. Usage metering (`usage_records`) continues from `TranscriptSection` durations.
- **Polish:** route through `POST /api/polish` with the user's license as well (same unification); **summarize** has no API endpoint — keep the queued web job (text-fixer services) for it.

### 6.3 Per-user license keys (auto-provisioned at registration)

**Why:** the endpoint's rate limit (`transcription-api-license:{id}`, 120/min) and request logs are keyed per license. One shared web license = all users share one limit and one audit trail. Per-user licenses give every account its own limit and its own log stream — the browser equivalent of each desktop install having its own key.

1. **Migration:** add nullable `user_id` (FK → `users.id`, `cascadeOnDelete`, indexed, unique) to `a_p_i_s`. No other column changes (no quotas/expiry on the license itself — plan quotas stay in the entitlements layer).
2. **Model:** `API::user()` belongsTo; `User::license()` hasOne. Add `user_id` to `API` `$fillable`.
3. **Provisioning:** hook user creation (Fortify `CreateNewUser` / registered event listener) → create the license row:
   - `app_name` = `"web-user-{uuid}"` (unique; not the email — don't leak PII into the admin list)
   - `app_token` = reuse the existing generator format: **`'is_license_' . bin2hex(random_bytes(48))`** (extract the admin controller's `makeUniqueLicenseKey()` into a shared service used by both)
   - `can_post = 1`, `can_get = 1`, others 0, `is_active = 1`
4. **Suspension sync:** when `user_status` becomes `banned`/`deactivated` → set their license `is_active = 0` (and back to 1 on reactivation). Deleting a user cascades the license.
5. **Security:** the key is **never** sent to the browser, never in Inertia props, never in API responses to the user, never logged in web responses. All `/api/*` calls are made server-to-server. (If the key is ever exposed, revocation = `is_active = 0` + regenerate — same as the admin flow today.)
6. **Admin visibility:** provisioned licenses naturally appear in the existing `/settings/api` manager — add a `user_id` column/link there so admins can trace license → user.
7. **Backfill:** an artisan command (or the migration's post-step) provisions licenses for existing users.

### 6.4 Async polish/summarize + web-safe labels
- Convert `TranscriptActionController@polish` / `@summarize` from synchronous to **queued jobs** (`ProcessWebPolishJob`, `ProcessWebSummarizeJob`) that update the transcript row (`cleaned_text` / `summary_text` + status), so the frontend's 2–3s polling reflects progress — mirrors desktop background jobs. Polish internally calls `POST /api/polish` with the user's license (§6.2); summarize stays a web job.
- Status payload: add `polish_status`, `summary_status` (`idle|processing|complete|failed` + `error_message`) to the transcript resource.
- **Web-safe processing-log labels:** everything the web renders comes from transcript statuses and the API job lifecycle — ensure only labels like **"Queued"**, **"Transcribing"**, **"Finalizing"**, **"Complete"**, **"Failed"** ever reach the UI. Grep for and ban "Silero", "Sherpa", "Diarization", "VAD", "Whisper" in any user-facing string/path the web can render (§8).
- Export endpoints unchanged (blob download works today); ensure filename header `Content-Disposition` so the toast can show `{filename}`.

### 6.5 No backend changes needed for
Toasts, loaders, menus, slide-overs, timers, empty states — pure frontend.

---

## 7. Copy Inventory (verbatim — use exactly)

### 7.1 Buttons
"Add Transcript" · "Add" · "Cancel" · "Live" · "Upload Audio" · "Browse" · "Start" · "Pause" · "Continue" · "Retry" · "Pending clips" · "Polish" · "Polishing" · "Summarize" · "Replace summary" · "Export" · "TXT" · "Microsoft Word" · "Excel" · "Log" · "Transcript" · "Loading" · "Processing..." · "Ready to capture" · "Stop recording" · "Requesting microphone" · "Delete"

### 7.2 Upload statuses
"Ready" · "Uploading source {n}%" · "Processing" · "Pausing" · "Paused" · "Cancelling" · "Cancelled" · "Complete" · "Failed" · "Ready to continue"

### 7.3 Live statuses
"Listening" · "Recording" · "Ready" · "Live" · "Sending" · "Saved" · "Save failed" · "Requesting mic" · "Microphone blocked" · "Start failed" · "Processing"

### 7.4 Pills / clip states
"Waiting" · "Sending" · "Saved" · "Error" · "Processing" · "Complete" · "Failed" · "Cancelled" · "Clip {n}"

### 7.5 Toasts
- Success: **"Transcript polished."** · **"Audio transcription completed."** · **"Export download started: {filename}"** · **"Settings saved."**
- Error: **"An error occurred. Please try again."** · **"No raw transcript is ready to polish yet."** · **"Transcript could not be polished."** · **"The transcript could not be summarized."** · **"Polish the transcript before exporting the cleaned version."** · **"No transcription is ready to export yet."** · **"Could not load processing logs."** · **"Audio upload could not be processed."** · **"Microphone access is blocked. Please allow it to record audio."** · **"Live recording could not start. Please try again."** · **"Clip {n} could not be saved. Please try again."** · **"Create a summary before exporting."** · **"Could not save the summary export. Please try again."**

### 7.6 Modal strings
Polish: "Instructions" · "Preset" · "Custom instructions" · "Enter instructions before polishing." · "Polishing again replaces the current polished transcript." · "Polish transcript" (+ 4 payloads §4.4)
Summary: "Ready" · "Summarizing..." · "Complete" · "Failed" · "The summary is being prepared. You may close this window and return later." · "No summary has been created for this project." · "Starting again replaces this project's existing summary." · "Source" · "Raw transcript" · "Cleaned transcript"

---

## 8. Exclusion List (banned in the web workspace)

**Offline/engine UI:** online/offline toggle switch · Whisper model `<select>` · "Offline Whisper is ready." / install tooltips · offline-model download modal & all its strings · `ai-transcriber-transcription-engine` / `ai-transcriber-whisper-model` equivalents · connectivity polling
**Offline phase strings (never render):** "Silero" · "Whisper" · "Sherpa" · "Diarization" · "Separating speakers" · "Preparing offline audio" · "VAD" — server labels collapse to **"Processing"/"Transcribing"** (§6.4)
**Offline settings tabs:** Resources (CPU/RAM/GPU) · Memory (storage/clear) · Server license tab
**ASTRA/dark:** all dark class strings (use §3.3 instead) · cyan/violet accents · `tracking-[0.3em]` kickers · gradient progress bars · dark `@else` modal branches · any "ASTRA" brand asset/name · the dark body gradient
**Desktop-only:** Tauri bridges (`choose_audio_file`, save dialogs, updater), speaker-session beacons, "Sign in when accounts are ready." stub footer

---

## 9. Phases

### Phase 1 — Foundation
Toast system (§3.1) + flash fix · `ProcessingButton` (§3.2) · composable extraction (§3.4) · polling lifecycle (§6.1) · delete confirm (§4.8) · remove inline banners (§5.1)
**Accept:** toasts fire in workspace for project CRUD; polling stops when idle; no behavior regressions.

### Phase 2 — Upload flow parity (§4.1)
License provisioning (§6.3: migration, registration hook, suspension sync, backfill) · pipeline unification (§6.2: `WebApiTranscriptionClient`, batched `audio[]` web endpoints, job polling, section mapping, retire `WebTranscriptProcessor` duplication) · browser slicing + batch guard · XHR upload + progress string choreography + transport buttons (disappear-when-disabled) + cancel/retry + status bar + toasts
**Accept:** side-by-side with desktop, every §7.2 string appears at the right moment; sections stream in per batch; cancel aborts; retry works on failure; API request logs show the user's own license (not a shared one); cancel/retry/quota paths intact.

### Phase 3 — Live recording parity (§4.2)
Record button states + timer + support line + pending-clip cards/pills + mic-error handling + state-store separation (no Upload-button flicker)
**Accept:** every §7.3 string reachable; clip failure path (kill network mid-record) shows "Error" pill + toast + auto-stop.

### Phase 4 — Transcript actions parity
Polish modal full spec + custom instructions (§4.4) · Summary modal status flow (§4.5) · Export upward menu + blob downloads (§4.6) · async jobs + web-safe labels (§6.4) · processing indicator replacement (§4.3) · 402 banner (§5.2)
**Accept:** polish/summarize run async with live status; export toasts show filenames; no dialog ever hangs.

### Phase 5 — Chrome parity
Slide-over a11y (§4.7) · modal fine-tuning (§4.8) · empty states (§4.9) · update `WorkspacePreview.vue` marketing mock to match the new processing indicator
**Accept:** keyboard-only walkthrough (Tab/ESC/Enter) works across workspace; landing preview matches workspace.

### Phase 6 — Parity review & hardening
Side-by-side pass against §10 checklist · grep sweep for §8 banned strings/classes · Pest + vue-tsc + Vite green
**Accept:** §10 fully checked.

---

## 10. Acceptance Checklist — Desktop Parity Pass

Run the web workspace and the JERVA desktop app side-by-side:

- [ ] Every button in §7.1 exists with the same label and the same enabled/disabled/visible choreography (transport buttons disappear when disabled)
- [ ] "Processing..." spinner appears on settings saves and summary-modal export — exact SVG spinner + label
- [ ] Upload: "Uploading source {n}%" shows real XHR percent; "Processing {n} of {total}" during batches; "Complete" + toast "Audio transcription completed."
- [ ] Chunked processing: uploads are sliced into 60s sections and sent in ≤20-clip / ≤20-min batches; transcript sections stream in per completed batch; per-section pills cycle Waiting → Sending → Processing → Complete
- [ ] Pipeline: web audio reaches providers **only** via `POST /api/transcribe` + job polling (verify `transcription_api_request_logs` rows carry the acting user's license; no direct provider calls from web code remain)
- [ ] Licenses: registering a new account auto-creates an `a_p_i_s` row (`web-user-*`, `can_post+can_get`, active); key never appears in any browser-visible payload; banning a user deactivates their license; two users hammering uploads don't consume each other's rate limit
- [ ] Live: button shows "Listening / Ready to capture" → "Recording / Stop recording"; timer ticks; "Microphone blocked" path works (deny permission)
- [ ] Polish: all 4 presets fill the exact payload; editing deselects; <3 chars shows "Enter instructions before polishing."; dock button shows "Polishing"
- [ ] Summary: idle→"Summarizing..."→"Complete" flow with pulse bar; close-and-reopen while processing resumes polling; footer note present
- [ ] Export: upward menu, 3 formats, raw/cleaned toggle, toast "Export download started: {filename}"
- [ ] Toasts: top-right, green/red, 5s dismiss, slide-out; stacking works
- [ ] Slide-overs: 300ms slide, ESC + backdrop close, focus returns to trigger
- [ ] Empty states: all §4.9 copy verbatim
- [ ] **Zero matches** when grepping rendered DOM for: "Silero", "Sherpa", "Diarization", "Whisper", "VAD", "offline", "ASTRA", "cyan", gradient bars
- [ ] 402 quota: blue banner + "View plans" → `/settings/billing`
- [ ] Polling stops when nothing is queued/processing (network tab silent while idle)
- [ ] Full Pest suite, `vue-tsc`, Vite build pass

---

## 11. Reference Files

| Purpose | Path |
|---|---|
| Desktop interaction sources (spec) | `AITranscriber\resources\js\{app.js,live\live-controller.js,upload\*.js,shared\*.js}`, `AITranscriber\public\{loader,notification}.js`, `AITranscriber\public\js\modals\{sidebar,polish-instructions,transcript-summary}.js` |
| Desktop markup (light branches) | `AITranscriber\resources\views\jerva\pages\partials\transcription-chat-workspace.blade.php`, `AITranscriber\resources\views\shared\modals\*.blade.php` |
| Web workspace (to refactor) | `TranscriptionServer\resources\js\pages\workspace\Index.vue` |
| Web endpoints | `TranscriptionServer\app\Http\Controllers\Web\{WorkspaceController,TranscriptionController,TranscriptActionController}.php` |
| Existing API pipeline (to route through — DO NOT duplicate) | `TranscriptionServer\app\Http\Controllers\Api\TranscriptionController.php` (`transcribe`, `transcriptionJobStatus`, `polish`), `TranscriptionServer\app\Jobs\ProcessAsyncTranscriptionJob.php` |
| License system | `TranscriptionServer\app\Models\API.php` (table `a_p_i_s`), `TranscriptionServer\app\Http\Controllers\Api\APIController.php` (`makeUniqueLicenseKey`) |
| Desktop API client (reference for `WebApiTranscriptionClient`) | `AITranscriber\app\Services\HostedApi\HostedTranscriptionClient.php`, `HostedTranscriptionJobClient.php`, `HostedTranscriptionLimitGuard.php`, `HostedTranscriptionPayloadMapper.php` |
| Processor/jobs (to refactor) | `TranscriptionServer\app\Services\WebTranscriptProcessor.php`, `TranscriptionServer\app\Jobs\ProcessWebTranscriptJob.php` |
| Marketing mock to sync | `TranscriptionServer\resources\js\components\WorkspacePreview.vue` |
