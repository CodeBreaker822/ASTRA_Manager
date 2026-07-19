# JERVA Web — SaaS Transcriber Implementation Plan

> **For:** Codex Agent (implementation)
> **Project:** `TranscriptionServer` (`D:\Transcriber Project\TranscriptionServer`)
> **Goal:** Build the web-based, online version of the JERVA Transcriber (desktop, Tauri) as a future SaaS product — visually identical in theme to the JERVA desktop UI.
> **Reference UI source:** `D:\Transcriber Project\AITranscriber\resources\views\jerva\` and `D:\Transcriber Project\AITranscriber\resources\css\app.css`

---

## 1. Context & Current State

### 1.1 What JERVA desktop is (design reference)

JERVA is a **light-only, ChatGPT-style single-page workspace**:

- White app background, `slate-50` sidebar, **blue-600 (`#2563eb`)** primary accent
- Font: **Instrument Sans** (weights 400/500/600, Bunny Fonts)
- `rounded-lg` (0.5rem) everywhere, 1px hairline borders (`slate-200`), soft diffuse shadows
- No gradients, no glassmorphism, no dark mode
- Layout: `19rem` sidebar (logo, + Add Transcript, Recent list, plan footer) + main column (72px header, transcript panel `max-w-3xl`, bottom command dock)

### 1.2 What TranscriptionServer already has (do NOT rebuild)

| Already built | Details |
|---|---|
| Stack | Laravel 13, **Vue 3 + Inertia + TypeScript**, Tailwind v4 (CSS-first), shadcn-vue (reka-ui), lucide icons, vue-sonner |
| Auth | **Fortify**: login, password reset, email verification, 2FA, **passkeys**. Pages exist under `resources/js/pages/auth/` |
| Transcription API | `app/Http/Controllers/Api/TranscriptionController.php` — multi-provider STT (AssemblyAI, AWS, Azure, Deepgram, ElevenLabs, Gladia, Google, Groq, RunPod, Speechmatics), sync/async jobs, fallback |
| Polish / Summarize | LLM cleaner services (Cerebras, DeepSeek, Gemini, Groq, Mistral, OpenAI-compatible, OpenRouter, Cloudflare) |
| Admin | Provider settings, license keys, API request logs, user/position/permission management |
| Font | Instrument Sans already configured in `vite.config.ts` |
| Radius | `--radius: 0.5rem` already matches JERVA |

### 1.3 Gaps this plan closes

1. Current theme is shadcn **neutral (black/white)** → must be re-themed to **JERVA blue/white**.
2. No marketing pages (`/features`, `/price`, `/blog`, `/download`, landing `/`).
3. No in-browser **workspace** (the JERVA workspace ported to web).
4. **Registration appears disabled** in Fortify features (views/actions exist) — must be enabled for SaaS.
5. No plans/entitlements/billing scaffolding (needed for SaaS; billing integration is a later phase).

---

## 2. Scope

### 2.1 In scope (web version)

- **Pages:** `/` (landing), `/login` (+ register/forgot/reset on same theme), `/workspace`, `/features`, `/price`, `/blog`, `/download` (desktop app download)
- **Workspace features (online only):**
  - Transcript projects (sidebar list, create/rename/delete)
  - **Upload Audio** → server-side transcription via existing provider pipeline
  - **Live transcription** via browser `MediaRecorder` → chunked upload to server (no local VAD — server handles processing)
  - **Polish** (instruction presets: Translate to English/Filipino, Fix grammar, Translate + fix, custom)
  - **Summarize** (Raw/Cleaned source selector)
  - **Export**: TXT, Word, Excel (Raw/Cleaned)
  - Processing log view, pending-clips slide-over
  - Settings: profile/password/appearance (existing) + transcription preferences (provider/model pickers driven by server, not local Whisper)
- **SaaS foundations:** plan tiers, usage quotas (minutes transcribed), entitlements middleware, pricing page wired to plan config
- **Design system:** exact JERVA tokens (Section 4) applied through shadcn-vue CSS variables

### 2.2 Out of scope (desktop/offline only — do NOT port)

- ❌ **Silero VAD** (local voice activity detection, `vad-cli`)
- ❌ **Sherpa-ONNX speaker diarization** (bundled ONNX models)
- ❌ **Offline Whisper** (whisper.cpp, ggml model downloads, model picker)
- ❌ Online/Offline engine toggle (web is always online)
- ❌ Tauri updater modal, desktop splash/asset polling
- ❌ Resource management (CPU threads / RAM / GPU VRAM) and local memory cleanup settings
- ❌ License-key server settings (web users use their account, not license keys)

---

## 3. Information Architecture

### 3.1 Route map

| Route | Page | Layout | Auth |
|---|---|---|---|
| `/` | Landing (hero on JERVA theme, CTA → `/login` or `/price`) | Marketing | Public |
| `/features` | Feature showcase | Marketing | Public |
| `/price` | Pricing tiers | Marketing | Public |
| `/blog` | Blog index (+ `/blog/{slug}` post view) | Marketing | Public |
| `/download` | Download the JERVA desktop app | Marketing | Public |
| `/download/latest` | Latest installer redirect/file stream (per `?platform=` param) | — | Public |
| `/login` | Login (JERVA-themed) | Auth (centered card) | Guest |
| `/register` | Register | Auth | Guest |
| `/forgot-password`, `/reset-password`, `/verify-email`, 2FA challenge | Auth support pages | Auth | Mixed |
| `/workspace` | **Main app** — JERVA workspace port | Workspace (sidebar + main) | Required + verified |
| `/workspace/{project}` | Deep link to a transcript project | Workspace | Required |
| `/settings/*` | Profile, Security, Appearance, Transcription prefs, **Billing** (placeholder) | App settings layout | Required |
| `/dashboard` | **Redirect to `/workspace`** (keep route for Fortify home compat) | — | Required |

> Note: Fortify `home` is currently `/dashboard` — either point it to `/workspace` in `config/fortify.php` or make `/dashboard` redirect. Prefer setting `home => '/workspace'`.

### 3.2 Keep existing admin/API

Do not touch: `/settings/api` provider manager, license keys, `/api/transcribe`, `/api/polish`, `/api/license/status`, chatbot routes, user management. The web workspace will **reuse the same service layer** (`app/Services/*`) through new web-facing controllers.

---

## 4. Design System — JERVA Tokens (exact values)

### 4.1 shadcn-vue CSS variable re-theme (`resources/css/app.css`)

Replace the current neutral `:root` with JERVA values. **Keep `.dark` tokens but ship light-only** (JERVA has no dark mode; dark mode is a future option — see Phase 6 note).

| Token | Value (light) | Equivalent |
|---|---|---|
| `--background` | `hsl(0 0% 100%)` | white |
| `--foreground` | `hsl(222.2 84% 4.9%)` | slate-950 `#020617` |
| `--card` / `--card-foreground` | white / slate-950 | |
| `--primary` | `hsl(221.2 83.2% 53.3%)` | **blue-600 `#2563eb`** |
| `--primary-foreground` | `hsl(210 40% 98%)` | near-white |
| `--secondary` | `hsl(210 40% 96.1%)` | slate-100 |
| `--secondary-foreground` | `hsl(222.2 47.4% 11.2%)` | slate-900 |
| `--muted` | `hsl(210 40% 96.1%)` | slate-100 |
| `--muted-foreground` | `hsl(215.4 16.3% 35%)` | **slate-600 `#475569`** (see contrast rules §7) |
| `--accent` | `hsl(214.3 94.6% 92.7%)` | **blue-100 `#dbeafe`** (active nav) |
| `--accent-foreground` | `hsl(217.2 91.2% 30%)` | blue-800 `#1e40af` |
| `--destructive` | `hsl(0 71.2% 51.8%)` | red-700-ish `#b91c1c` family |
| `--border` | `hsl(214.3 31.8% 91.4%)` | slate-200 `#e2e8f0` |
| `--input` | `hsl(213.3 96.9% 87.3%)` | **blue-200 `#bfdbfe`** (JERVA input borders) |
| `--ring` | `hsl(221.2 83.2% 53.3%)` | blue-600 (focus rings + `ring-blue-100` halo) |
| `--radius` | `0.5rem` | already set ✔ |
| `--sidebar-background` | `hsl(210 40% 98%)` | **slate-50 `#f8fafc`** |
| `--sidebar-primary` | blue-600 | |
| `--sidebar-accent` | blue-100 | |
| `--sidebar-ring` | blue-600 | |

Additional JERVA utility values used directly in components:

- Soft surfaces: `bg-blue-50` `#eff6ff`; border accents `border-blue-100/200`; hover `border-blue-300/400`
- Primary hover: `bg-blue-700` `#1d4ed8`
- Muted-on-blue text: `text-blue-900` `#1e3a8a`, `text-blue-950` `#172554` (empty states)
- Success badge: border `#bbf7d0`, bg `#ecfdf5`, text `#166534`
- Danger button: `border-red-200` `bg-red-50` `text-red-700`
- Shadows: `shadow-[0_12px_32px_rgba(15,23,42,0.08)]` (pills/cards), `shadow-[0_16px_40px_rgba(15,23,42,0.14)]` (menus), `shadow-2xl` (modals), active-item indicator `shadow-[inset_3px_0_0_#2563eb]`
- Selection: `selection:bg-blue-100 selection:text-black`
- Scrollbars (webkit): thumb `#2563eb`, track `#eff6ff`

### 4.2 Typography

| Element | Classes | Notes |
|---|---|---|
| Base | `text-[14px] leading-5` (workspace), `text-sm`/`text-base` (marketing body min 16px) | Font `Instrument Sans` 400/500/600 only — no bold/black |
| Eyebrow labels | `text-xs font-semibold uppercase tracking-wide text-blue-600` | JERVA signature pattern |
| Page/panel titles | `text-lg`–`text-xl font-semibold text-slate-950` | |
| Modal titles | `text-base font-semibold` | |
| Marketing H1 | `text-4xl md:text-5xl font-semibold tracking-tight text-slate-950` | |
| Marketing H2 | `text-3xl font-semibold tracking-tight text-slate-950` | |
| Empty-state headline | `text-3xl font-semibold text-slate-950` + `text-blue-950` body | e.g. "Hi, what are we transcribing today?" |
| Buttons | `text-sm font-semibold` | |

### 4.3 Spacing & layout standards (strict)

- **Buttons:** `h-10` (default) / `h-11` / `h-12` (hero CTAs), `px-4`, `rounded-lg`, `text-sm font-semibold`; icon buttons `h-10 w-10`
- **Inputs:** `h-11 rounded-lg border border-blue-200 bg-white px-3 text-sm text-slate-900 placeholder:text-slate-500 focus:border-blue-500 focus:ring-2 focus:ring-blue-100`
- **Cards/modals:** `rounded-lg border border-slate-200 bg-white` + soft shadow; overlays `bg-slate-950/40`
- **Page containers:** marketing `max-w-6xl mx-auto px-6`; section vertical rhythm `py-16 md:py-24`; grid gaps `gap-6`/`gap-8`
- **Workspace shell:** sidebar `w-[19rem] bg-slate-50 border-r border-slate-200`; header `h-[72px] px-6 border-b border-slate-200`; transcript column `max-w-3xl mx-auto px-8 py-6`
- **Consistency rule:** use the 4px Tailwind scale only (`p-3`, `p-4`, `px-6`, `py-8`, `gap-4`…); no arbitrary odd values except the JERVA signatures above (`19rem`, `72px`, `max-w-3xl`)

---

## 5. Page Specifications

### 5.1 `/login` (and all auth pages) — JERVA-themed

Layout: centered white card on `bg-slate-50` page (adapt existing `AuthCardLayout`).

```
        [JERVA logo mark — 3rem, rounded-lg]
        "Sign in to JERVA"              (text-xl font-semibold text-slate-950)
        "Transcription workspace."      (text-sm text-slate-600 — brand tagline)
  ┌────────────────────────────────────────┐
  │ Email    [ h-11 input, blue-200 border]│
  │ Password [ h-11 input + show toggle   ]│
  │ [ ] Remember me        Forgot password?│
  │ [      Sign in  — h-11 bg-blue-600    ]│
  │ ───────── or continue with ──────────  │
  │ [ 🔑 Sign in with a passkey ]          │
  └────────────────────────────────────────┘
        New here? Create an account →   (text-blue-600 hover:text-blue-700)
```

- Reuse existing Fortify pages (`pages/auth/Login.vue` etc.) — **restyle only**, keep 2FA/passkey flows
- Card: `w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-[0_12px_32px_rgba(15,23,42,0.08)]`
- All form errors: `text-sm text-red-700`; status banners: blue-50/success-green variants
- Register page: same card, fields Name/Email/Password/Confirm; note password rules (prod: min 12, mixed, symbol)

### 5.2 `/workspace` — the core port

Faithful Vue/Inertia port of `resources/views/jerva/pages/partials/transcription-chat-workspace.blade.php`:

- **Sidebar (`w-[19rem] bg-slate-50`)**
  - Brand row: logo + "JERVA Transcriber" (`text-base font-semibold`)
  - `+ Add Transcript` button (`h-10 bg-blue-600 hover:bg-blue-700 text-white`)
  - "RECENT" eyebrow (`text-xs font-semibold uppercase text-slate-600`) + project list
  - Active item: `bg-blue-100 text-blue-900` with `shadow-[inset_3px_0_0_#2563eb]`
  - Footer: plan card ("Free Plan" / current tier) + user menu (replaces desktop's "Sign in" stub)
- **Header (`h-[72px] px-6`)**: "TRANSCRIPT" eyebrow + project title; NO online/offline toggle, NO model picker (server-driven); settings gear → routes to `/settings`
- **Transcript panel (`max-w-3xl`)**: empty state (`text-3xl` headline), live/upload progress (blue-600 progress bar on `bg-blue-100` track, `rounded-full`), sectioned transcript stream
- **Command dock (bottom):** `Live` / `Upload Audio` primary actions + action pill (`Polish` · `Summarize` · `Export ▾` · `Log` · `Pending clips`) — pill uses `shadow-[0_12px_32px_rgba(15,23,42,0.1)]`
- **Modals/slide-overs:** Add Transcript, Polish instructions, Summary, Pending clips (right slide-over, `translate-x-full transition duration-300`), all `rounded-lg shadow-2xl` on `bg-slate-950/40`

**Web adaptation notes:**
- Live mode: browser `MediaRecorder` (webm/opus) → 10–20s chunks → `POST /api/web/transcribe/chunk` (new web controller reusing existing STT services). Show queued chunks in the pending-clips slide-over.
- Upload mode: multipart upload → queued job (`ProcessAsyncTranscriptionJob` exists) → poll job status endpoint (or Laravel Echo later; no broadcasting configured today, so **polling is acceptable for v1**).
- Polish/Summarize/Export: call existing polish/LLM services; export generation server-side (TXT/DOCX/XLSX) with signed download URLs.
- Persist projects/transcripts per user: new tables `transcript_projects`, `transcripts` (+ `transcript_sections`) — see §6.3.

### 5.3 `/price`

- Eyebrow + H1 ("Simple pricing" style), billing-period toggle (Monthly/Yearly) — visual only until billing phase
- 3 plan cards (`rounded-lg border border-slate-200 bg-white p-8`, featured middle card `border-blue-600 ring-2 ring-blue-100 shadow-[0_16px_40px_rgba(15,23,42,0.14)]`):
  - **Free** — e.g. 30 min/month, upload only, community support
  - **Pro** — e.g. 600 min/month, live + upload, Polish/Summarize, all exports
  - **Team** — e.g. pooled minutes, seats, priority processing
- Feature comparison table below (`text-sm`, check icons `text-blue-600`)
- CTA buttons → `/register` (until billing integration lands, then → checkout)
- Plan definitions live in a single config file (§6.4) so the page and entitlements share one source of truth
- FAQ accordion (reka-ui) with `text-slate-700` answers

### 5.4 `/features`

- Hero + alternating feature rows (2-col grid, `gap-12`, `items-center`, image/screenshot side `rounded-lg border border-slate-200 shadow-...`):
  1. Live transcription in the browser
  2. Upload audio (WAV/MP3/M4A/AAC/OGG/FLAC)
  3. Polish — grammar fix + translation (English/Filipino)
  4. Summarize transcripts
  5. Export to TXT/Word/Excel
  6. Multi-provider accuracy/fallback (server-side)
- Each feature block: eyebrow, `text-2xl font-semibold text-slate-950` title, `text-base text-slate-700` body, bullet list with blue check icons
- CTA band at bottom: `bg-blue-50 border border-blue-100 rounded-lg` with `bg-blue-600` button

### 5.5 `/blog`

- Index: `grid md:grid-cols-2 lg:grid-cols-3 gap-6` of post cards (`rounded-lg border border-slate-200`, cover image `aspect-video`, title `text-lg font-semibold text-slate-950`, excerpt `text-sm text-slate-600`, date `text-xs text-slate-600`)
- Post page: prose column `max-w-3xl mx-auto`, headings `text-slate-950`, body `text-slate-700 leading-7`
- **v1 storage:** markdown files under `resources/blog/*.md` rendered via a small controller (no CMS/admin needed yet). Front-matter: title, slug, date, excerpt, cover.

### 5.6 `/download` — JERVA desktop app download

Purpose: let users download the **JERVA Edition desktop app** (the offline-capable Tauri build from `AITranscriber`) — the web and desktop products cross-promote each other.

- Hero: eyebrow + H1 ("Get JERVA for desktop"), body copy `text-slate-700` explaining the difference:
  - **Web** (`/workspace`) — online, nothing to install, works anywhere
  - **Desktop** — offline transcription (local Whisper), Silero VAD, Sherpa speaker separation, files stay on your machine
- Primary download card (`max-w-xl mx-auto rounded-lg border border-slate-200 bg-white p-8 shadow-[0_12px_32px_rgba(15,23,42,0.08)]`):
  - Platform auto-detect (Windows first — the Tauri build targets Windows; show other platforms only if builds exist)
  - Big `h-12 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold` button: "Download for Windows"
  - Below button: version number, file size, release date — `text-sm text-slate-600` (never lighter)
  - Secondary links row (`text-sm text-blue-600 hover:text-blue-700`): "All releases" · "System requirements" · "Update from within the app"
- System requirements section: 2-col grid (`gap-6`) of small cards — OS, RAM, disk space for offline models, optional GPU — `text-sm text-slate-700`
- "Pair with your account" band (`bg-blue-50 border border-blue-100 rounded-lg p-6`, text `text-blue-900`): explains the desktop app can sign in / use the same account as the web workspace; CTA → `/register`
- FAQ mini-accordion (e.g. "Is the desktop app free?", "What's the difference vs the web version?", "How do offline models work?")

**Backend — reuse existing distribution plumbing (do NOT rebuild):**
- The server already has `GET /transcriber/{zipfile}` (`transcriber.update.download`, public) and transcriber-package upload in `/settings/api` admin — this is the update/distribution channel the desktop app already polls
- Add a thin `DownloadController`:
  - `GET /download` → Inertia marketing page, receives latest release metadata (version, size, date, per-platform URLs) injected as props
  - `GET /download/latest?platform=windows` → resolves the newest uploaded JERVA package and redirects/streams the file
- Release metadata source: read from the same transcriber-package storage the admin upload writes to (single source of truth — uploading a new package in admin automatically updates `/download`). If per-platform builds don't exist yet, v1 ships Windows-only and the page hides other platforms gracefully
- Keep the existing `/api/transcribe/update/zipfile` endpoint untouched (the desktop updater depends on it)

### 5.7 Marketing layout (shared shell for `/`, `/features`, `/price`, `/blog`, `/download`)

- Sticky top nav `h-16 border-b border-slate-200 bg-white/90 backdrop-blur`: logo left; links `Features · Price · Blog · Download` (`text-sm font-medium text-slate-700 hover:text-blue-600`); right: `Sign in` (ghost) + `Get started` (`bg-blue-600`)
- Footer: `border-t border-slate-200 bg-slate-50`, 4-column link grid, `text-sm text-slate-600`, copyright row
- Mobile: hamburger → sheet (existing shadcn `sheet` primitive)

---

## 6. Backend / SaaS Foundations

### 6.1 Auth changes

- Enable `Features::registration()` in `config/fortify.php`
- Set Fortify `home` → `/workspace`
- Keep 2FA + passkeys as-is (selling point for SaaS)

### 6.2 New web controllers (thin, reuse `app/Services/*`)

- `Web/WorkspaceController` — project CRUD, workspace page data
- `Web/TranscriptionController` — upload, live-chunk ingest, job status polling (wraps existing provider pipeline + `ProcessAsyncTranscriptionJob`)
- `Web/TranscriptActionController` — polish, summarize, export downloads
- `MarketingController` — landing/features/price; `BlogController` — markdown posts; `DownloadController` — download page + latest-release resolution (§5.6)

### 6.3 New tables

- `transcript_projects` (id, user_id, title, timestamps)
- `transcripts` (id, project_id, source: live|upload, status, duration_seconds, raw_text, cleaned_text, summary_text, audio_path nullable)
- `transcript_sections` (id, transcript_id, position, text, cleaned_text, started_at_ms, ended_at_ms)
- `plans` config (not table — see 6.4), `usage_records` (id, user_id, period, seconds_transcribed, polish_count, summary_count) for quota metering

### 6.4 Plans & entitlements

- `config/plans.php` — single source of truth: tiers (free/pro/team), monthly minute quotas, feature flags (`live`, `polish`, `summarize`, `exports`), price display data for `/price`
- `users.plan` column (default `free`) + `EntitlementService` + middleware (`can.transcribe`, quota checks) enforced in the web controllers
- `/price` reads from `config/plans.php`

### 6.5 Billing (deferred — Phase 6)

- Integrate **PayMongo built-in payment/checkout** later; pricing page + entitlements are built to swap "register CTA" → "PayMongo checkout" without redesign

---

## 7. Accessibility & Contrast Rules (hard requirements)

The user explicitly requires text to never be too light. Enforce:

1. **Body text on white:** minimum `text-slate-700` (`#334155`, 7.5:1). Never use `text-slate-400`/`text-slate-300` for readable content.
2. **Secondary/metadata text:** minimum `text-slate-600` (`#475569`, 5.9:1). `text-slate-500` only for placeholder text and large headings.
3. **On `slate-50` surfaces:** same rules as white.
4. **On blue-600 buttons:** white text only (`text-white`, 4.6:1 at `font-semibold`). Never `text-blue-100` on blue-600.
5. **On blue-50/blue-100 surfaces:** use `text-blue-900`/`text-blue-950`.
6. **Placeholder text:** `placeholder:text-slate-500` (not the default lighter gray).
7. **Focus:** every interactive element must show `focus-visible:ring-2 focus:ring-blue-100` + `focus:border-blue-500` (inputs) — never remove outlines.
8. **Contrast floor:** WCAG AA 4.5:1 for body, 3:1 for large text — verify with a contrast checker before shipping each page.
9. **Icons** never carry meaning alone — pair with labels or tooltips.
10. **Touch targets:** minimum `h-10 w-10`.

---

## 8. Implementation Phases (execute in order)

### Phase 1 — Design tokens & base theme
1. Re-theme `resources/css/app.css` `:root` per §4.1 (keep `.dark` block untouched, app ships light)
2. Set Inertia progress color to `#2563eb` (`app.ts`)
3. Restyle shadcn primitives minimally (button/input/card pick up new CSS vars automatically — verify, don't rewrite)
4. **Accept:** login page renders in JERVA blue; all contrast rules §7 pass

### Phase 2 — Auth pages + registration
1. Restyle `AuthCardLayout` + all `pages/auth/*` per §5.1
2. Enable registration, point Fortify home to `/workspace` (temporarily to `/dashboard` until workspace exists)
3. **Accept:** register → login → logout → forgot-password → 2FA/passkey flows all work on theme

### Phase 3 — Marketing shell + pages
1. Marketing layout (nav/footer per §5.7)
2. `/` landing, `/features`, `/price` (from `config/plans.php`), `/blog` (markdown-driven)
3. `/download` page + `DownloadController` wired to the existing transcriber-package storage (§5.6)
4. **Accept:** all 5 public routes render responsively (mobile → desktop), links work, blog posts render from `.md`, download button serves the latest uploaded JERVA package

### Phase 4 — Workspace data + shell
1. Migrations + models (§6.3), plans config + entitlements (§6.4)
2. `/workspace` route + Inertia page: sidebar, header, empty state, command dock (visual parity with JERVA desktop)
3. Project CRUD (Add Transcript modal, rename, delete, active states)
4. **Accept:** pixel-faithful JERVA shell; projects persist per user

### Phase 5 — Transcription features
1. Upload flow (upload → job → polling → transcript sections render)
2. Live flow (MediaRecorder chunking → ingest → pending clips → sections render)
3. Polish / Summarize / Export (TXT/DOCX/XLSX) / Processing log
4. Quota metering + entitlement enforcement with friendly upgrade prompts (`bg-blue-50 border-blue-100` banner, `text-blue-900`)
5. **Accept:** end-to-end — register free user, transcribe upload + live, polish, summarize, export, hit quota wall gracefully

### Phase 6 — SaaS polish (post-review)
1. Billing integration (PayMongo built-in payment/checkout) + `/settings/billing`
2. Onboarding tour for first workspace visit
3. Optional dark mode (only if desired — JERVA brand is light-first)
4. Landing screenshots from real workspace

---

## 9. Acceptance Checklist (final review)

- [ ] All routes from §3.1 resolve; no 404s in nav
- [ ] `/download` serves the latest JERVA installer; uploading a new package in admin updates the page without code changes
- [ ] Every page passes §7 contrast rules (spot-check with contrast tool)
- [ ] Workspace visually matches JERVA desktop side-by-side (sidebar width, header height, spacing scale, shadows, radii)
- [ ] No offline/desktop features present (no VAD, no diarization, no model picker, no online/offline toggle, no license-key settings)
- [ ] Responsive: 1280px+ desktop, 1024px, 768px (marketing + auth), workspace min 1024px with clear notice below
- [ ] Registration → verification → workspace happy path < 2 minutes
- [ ] Quotas enforced; `/price` and entitlements read from `config/plans.php`
- [ ] `npm run types:check`, `pint`, `phpstan`, and Pest suite pass

---

## 10. Reference Files for Codex Agent

| Purpose | Path |
|---|---|
| JERVA workspace UI (port source) | `AITranscriber\resources\views\jerva\pages\partials\transcription-chat-workspace.blade.php` |
| JERVA CSS overrides | `AITranscriber\resources\css\app.css` |
| JERVA modals (use `$workspace === true` branches) | `AITranscriber\resources\views\shared\modals\*.blade.php` |
| Theme tokens to edit | `TranscriptionServer\resources\css\app.css` |
| Auth pages to restyle | `TranscriptionServer\resources\js\pages\auth\*` |
| Layout system | `TranscriptionServer\resources\js\layouts\*` |
| STT/LLM services to reuse | `TranscriptionServer\app\Services\*` |
| Existing async job | `TranscriptionServer\app\Jobs\ProcessAsyncTranscriptionJob.php` |
| Fortify config | `TranscriptionServer\config\fortify.php` |
