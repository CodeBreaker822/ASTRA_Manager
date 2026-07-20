# No Manual Code Plan — CMS & RBAC for JERVA Web

> **For:** Codex Agent (implementation)
> **Project:** `TranscriptionServer` (`D:\Transcriber Project\TranscriptionServer`)
> **Depends on:** `JERVA_WEB_SAAS_PLAN.md` (Phases 1–6, complete per `report.md`)
> **Goal:** All public marketing content — **Blog, Pricing, Features page, Download page** — becomes editable from an in-app **Dashboard panel** by users with the right permissions. Zero code edits needed to change content. **Workspace and Home (landing) stay hardcoded.**

---

## 1. Current State (verified)

### 1.1 RBAC system that already exists (the method to follow)

| Piece | Location | Behavior |
|---|---|---|
| Gate definitions | `app/Gates/UserGates.php`, `app/Gates/APIManagerGates.php` | Static `register()` calling `Gate::define('<name>', fn (User $user) => self::checkPermission($user, '<name>'))` |
| Permission check | `app/Traits/HasGatePermissions.php` | `checkPermission` / `checkAnyPermission` / `checkAllPermissions` — chain: `users.position_id` → `user_permissions.permission_name` rows |
| Security logging | `app/Traits/Gates.php` | `logUnauthorizedAccess()` (rate-limited audit via `AuditLogService`) + `getAllGates()` which **auto-enumerates every registered gate into the Users.vue checkbox grid** — new gates appear in the admin UI with zero frontend work |
| Assignment UI | `settings/Users.vue` + `UserManagerController` | Positions (roles) get permission checkboxes; users inherit from their position |
| Super admin | `config/admin.php` + `Gate::before` in `AppServiceProvider` | `ADMIN_EMAIL` + `ADMIN_ACESS` env vars; bypasses every gate; can be turned off by setting `ADMIN_ACESS=false` |
| Frontend flags | `HandleInertiaRequests::share()` | `auth.isAdmin`, `auth.canManageApi`, `auth.canManageUsers` booleans; settings `Layout.vue` filters nav items by them |
| Naming | mixed | dot-style (`user.manage-users`) and dash-style (`API-manage_api`) coexist; `getAllGates()` parses both. **New gates use dot-style, category `cms`** |

### 1.2 Content that is hardcoded today (what becomes editable)

| Content | Today | Target |
|---|---|---|
| Blog posts | Markdown files `resources/blog/*.md`, hand-rolled front-matter, fields `title/slug/date/excerpt/cover/html` | DB table + Dashboard CRUD |
| Pricing | `config/plans.php` (tiers, entitlements, comparison) | DB tables with **config as fallback** |
| Pricing FAQ + hero copy | Hardcoded in `marketing/Price.vue` | DB page content |
| Features page | 100% hardcoded in `marketing/Features.vue` (hero, 6 feature rows, CTA) | DB page content |
| Download page copy | Hero, requirements, "Pair with your account", FAQ hardcoded in `marketing/Download.vue` (release/version stays dynamic from package storage) | DB page content |
| Landing `/` and `/workspace` | Hardcoded / app code | **Stays hardcoded (explicitly out of scope)** |

### 1.3 Consumers that must keep working (do not break shapes)

- `EntitlementService` reads `config('plans.tiers')` + `config('plans.default')` with exact keys (`entitlements.upload|live|polish|summarize|exports|team`, `minutes`)
- `BillingController::edit` passes tiers **with** `key`; `MarketingController::price` passes tiers **without** keys (`array_values`) and `Price.vue` matches comparison rows by `plan.name.toLowerCase()`
- PayMongo real-money amounts come from **env** (`PAYMONGO_PRO_AMOUNT` / `PAYMONGO_TEAM_AMOUNT`), fully decoupled from display prices
- `BlogShow.vue` renders `v-html="post.html"`; HTML is produced by `Str::markdown(..., ['html_input' => 'strip', 'allow_unsafe_links' => false])` — **keep this exact sanitization for DB-sourced markdown**

---

## 2. Scope

### 2.1 In scope

1. **CMS RBAC** — new `cms.*` gates following the existing gate method exactly; auto-surfaced in the existing Positions & Gates UI
2. **Dashboard panel** (`/dashboard`) — new permission-gated admin home inside the app, with nav filtered by gates (same pattern as settings `Layout.vue`)
3. **Blog manager** — list/create/edit/delete/publish posts, markdown body with preview, cover image upload, draft/published states
4. **Pricing manager** — edit tiers (name, tagline, prices, minutes, CTA, featured, feature bullets, entitlements) and the comparison table + pricing page hero/FAQ
5. **Pages manager** — edit Features page and Download page content via structured forms (not free-form HTML — preserves the JERVA design system)
6. **Seeders/migration path** — existing 2 blog `.md` files and current `config/plans.php` values seeded into DB as the starting content

### 2.2 Out of scope (do NOT touch)

- ❌ `/workspace` (the app itself) and `/` landing page — remain code-managed
- ❌ User/position management internals (already works — CMS gates just appear in it)
- ❌ The API manager (`/settings/api`) — already gated, stays Blade
- ❌ Entitlement **logic** — only the data source changes (config → DB with config fallback)
- ❌ PayMongo checkout amounts — stay env-driven in v1 (see §6.4 warning callout)
- ❌ WYSIWYG/rich-text editors, content versioning/history, SEO meta fields beyond excerpt, multi-language content

---

## 3. RBAC Design (follows the existing method exactly)

### 3.1 New gates — `app/Gates/CmsGates.php`

New class mirroring `UserGates.php` structure (static `register()`, `use Gates, HasGatePermissions`), registered in `AppServiceProvider::configureDefaults()` after the existing registrations:

| Gate string | Grants |
|---|---|
| `cms.view` | Access the Dashboard panel shell (nav item visible; still need a manage-gate for each section) |
| `cms.manage-blog` | Blog post CRUD + publish/unpublish + cover upload |
| `cms.manage-pricing` | Edit plan tiers, comparison table, pricing hero + FAQ |
| `cms.manage-pages` | Edit Features page + Download page content |

Naming rationale: dot-style like `user.manage-users`; category `cms` parses cleanly through `getAllGates()` (`manage` is in its action-word list), so **all four gates automatically appear as checkboxes in `settings/Users.vue` Positions & Gates grid** — no assignment-UI work required.

### 3.2 Enforcement (same two patterns already in the codebase)

1. **Route middleware** (like `routes/transcription-web.php`): wrap dashboard CMS route groups — `Route::middleware(['auth', 'verified', 'can:cms.manage-blog'])->group(...)` per section
2. **Controller `Gate::authorize(...)`** on mutating methods (like `UserManagerController`) as defense-in-depth

Unauthorized hits get the existing `logUnauthorizedAccess` audit trail for free (it's inside `checkPermission`).

### 3.3 Super admin (unchanged — keep the kill switch)

- `Gate::before` already bypasses **all** gates — including the new `cms.*` ones — when `ADMIN_ACESS=true` and the user's email matches `ADMIN_EMAIL`
- **Keep the `ADMIN_ACESS` env name as-is** (typo included) for compatibility; optionally have `config/admin.php` fall back: `env('ADMIN_ACESS', env('ADMIN_ACCESS', false))`
- Turning it off (`ADMIN_ACESS=false`) instantly demotes that account to whatever its position permissions allow — this behavior must be preserved and covered by a test

### 3.4 Frontend flags (follow the existing shared-props pattern)

Extend `HandleInertiaRequests::share()` `auth` array with booleans, exactly like the existing three:

```
canAccessDashboard  // any of: cms.view, cms.manage-*, user.manage-users, API-manage_api
canManageBlog       // cms.manage-blog
canManagePricing    // cms.manage-pricing
canManagePages      // cms.manage-pages
```

Add matching fields to `resources/js/types/auth.ts`. Filter Dashboard nav items with these flags (same as settings `Layout.vue` does for Users/API).

### 3.5 Suggested starter positions (seeder, optional)

| Position | Permissions |
|---|---|
| Content Editor | `cms.view`, `cms.manage-blog` |
| Content Manager | `cms.view`, `cms.manage-blog`, `cms.manage-pricing`, `cms.manage-pages` |

Super admin can edit/delete these — they're just seeded defaults created via the same position+permissions mechanism.

---

## 4. Dashboard Panel (`/dashboard`)

### 4.1 Routing & layout

- `/dashboard` stops being a blind `Route::redirect` to `/workspace` and becomes the CMS home:
  - User with **any** admin/cms gate → Dashboard panel
  - Everyone else → redirected to `/workspace` (preserve current behavior for regular users)
- Routes:

| Route | Page | Gate |
|---|---|---|
| `GET /dashboard` | Panel home (cards linking to permitted sections) | `canAccessDashboard` (any gate) |
| `GET/POST /dashboard/blog`, `GET/PUT/DELETE /dashboard/blog/{post}`, `POST /dashboard/blog/{post}/publish` | Blog manager | `cms.manage-blog` |
| `GET/PUT /dashboard/pricing` | Pricing manager | `cms.manage-pricing` |
| `GET/PUT /dashboard/pages/features`, `GET/PUT /dashboard/pages/download` | Pages manager | `cms.manage-pages` |

- Layout: reuse the app shell with its own sub-nav, mirroring `layouts/settings/Layout.vue` — items: **Blog · Pricing · Pages** (filtered by flags) plus cross-links to **Users** (`canManageUsers`) and **API** (`canManageApi`) so the panel becomes the single admin home
- App sidebar: add a "Dashboard" item shown only when `auth.canAccessDashboard`; rename the current sidebar "Dashboard" item (which points at the workspace redirect) to **"Workspace"** to kill the naming collision
- Design: same JERVA system — white cards, `rounded-lg`, `border-slate-200`, blue-600 primary, `text-slate-700` body minimum, `h-11` inputs, `h-10` buttons. Manager pages are `max-w-5xl mx-auto px-6 py-8`

### 4.2 Blog manager page

- **List view:** table (title, status badge `draft` slate-100 / `published` blue-100 text-blue-800, date, author), row actions Edit/Publish/Delete; `+ New post` primary button
- **Editor:** structured form —
  - Title (`h-11` input), Slug (auto-derived from title, editable, unique validation)
  - Excerpt (textarea, `text-sm`)
  - Cover image (file input → `public` disk, jpg/png/webp ≤ 2MB; preview thumbnail)
  - Body = **markdown textarea + live preview pane** (same `Str::markdown` server-side render for preview; no new editor dependency)
  - Status toggle (Draft/Published) + Published date
- Delete = soft delete with confirm dialog (shadcn `dialog`)

### 4.3 Pricing manager page

- One card per tier (Free/Pro/Team) with fields matching the existing config shape: name, tagline, monthly_price, yearly_price, price_label, minutes, cta, featured toggle, feature bullets (4 text inputs), entitlement toggles (upload/live/polish/summarize, export format checkboxes txt/docx/xlsx, team flag)
- Comparison table editor: rows of {feature label, per-tier checkmarks}
- Pricing hero (eyebrow/title/subtitle) + FAQ editor (repeatable Q/A rows, add/remove)
- **Warning banner** (`bg-blue-50 border-blue-100 text-blue-900`): "Displayed prices are marketing copy. Actual PayMongo checkout amounts come from `PAYMONGO_*_AMOUNT` env vars — keep them in sync."
- Save = full validate + DB transaction + cache invalidation

### 4.4 Pages manager (Features / Download)

Structured forms per page — fields map 1:1 to the current hardcoded blocks, so the JERVA layout can never be broken by content:

- **Features:** hero (eyebrow/title/intro); 6 feature rows (eyebrow, icon picker from a **whitelisted lucide set**, title, body, 3 bullets); CTA band (title, button label)
- **Download:** hero copy; download-button labels; 4 requirement cards (icon whitelist, title, body); "Pair with your account" band (title, body, 2 bullets, CTA); FAQ (repeatable Q/A)
- The dynamic release data (version/size/download URL) stays code-driven from package storage — shown read-only in the form for context

---

## 5. Data Model

### 5.1 New tables

**`blog_posts`**: `id`, `title`, `slug` (unique), `excerpt` (nullable), `body_markdown` (text), `cover_path` (nullable), `status` (`draft`|`published`, indexed), `published_at` (nullable timestamp), `author_id` (FK users, nullOnDelete), `softDeletes()`, `timestamps()`

**`plan_tiers`**: `id`, `key` (unique: `free|pro|team`), `name`, `tagline`, `monthly_price` (int nullable), `yearly_price` (int nullable), `price_label`, `minutes` (int), `cta`, `featured` (bool), `features` (JSON), `entitlements` (JSON), `sort_order` (int), `is_active` (bool), `timestamps()`

**`plan_comparison_rows`**: `id`, `label`, `tier_keys` (JSON array), `sort_order`, `timestamps()`

**`page_contents`**: `id`, `page` (`features|download`), `section` (e.g. `hero`, `feature_rows`, `cta`, `requirements`, `faq`), `content` (JSON), `updated_by` (FK users nullable), `timestamps()`, unique(`page`,`section`)

### 5.2 Content resolution strategy (critical: zero-downtime fallback)

- **`PlanService`** (new, wraps EntitlementService's source): loads tiers + comparison from DB; **merges over `config/plans.php` defaults**; missing table/rows → pure config behavior (today's behavior). Result shape is **byte-identical** to `config('plans.tiers')` so `EntitlementService`, `BillingController`, `PayMongoWebhookController`, `Price.vue`, `Billing.vue` consume it untouched
- **Blog:** public `BlogController` queries `published` posts only; markdown rendered with the **same** `Str::markdown` safe options; `cover` finally rendered on `BlogIndex.vue` (currently plumbed but unused) with the existing icon placeholder as fallback
- **Pages:** marketing controllers fetch `page_contents` per section; missing sections → fall back to the **current hardcoded copy extracted into `config/marketing.php`** (move today's strings there, so fallback = current site verbatim)
- **Caching:** `plans.all` and `page.{page}.{section}` cache keys, forgotten on every manager save; blog index cached briefly (`blog.published`, 60s) — keeps public pages at one query or less

### 5.3 Seeders

- `BlogPostSeeder` — imports the 2 existing `resources/blog/*.md` (front-matter → columns, body → `body_markdown`, status `published`)
- `PlanTierSeeder` — inserts the current `config/plans.php` tiers + comparison verbatim
- `PageContentSeeder` — inserts current Features/Download hardcoded copy verbatim
- `CmsPositionSeeder` — the two §3.5 starter positions
- After seeding, the public site must render **identically** to before (acceptance test: snapshot/diff the rendered pages pre/post)

---

## 6. Implementation Phases

### Phase 1 — CMS gates + Dashboard shell
1. `CmsGates.php` + registration; verify all 4 gates appear in the Users.vue Positions & Gates grid
2. `HandleInertiaRequests` flags + TS types; sidebar "Workspace" rename + conditional "Dashboard" item
3. `/dashboard` route group, panel layout with filtered sub-nav, panel home with permission-based section cards; non-permitted users redirect to `/workspace`
4. Seed starter positions
5. **Accept:** super admin (env) sees everything; a user with only `cms.manage-blog` sees Blog only; gates enforce server-side (403 otherwise); `ADMIN_ACESS=false` kill-switch test passes

### Phase 2 — Blog manager
1. Migration + model + seeder (imports existing `.md` files)
2. Public `BlogController` → DB (published only, same markdown sanitization); cover rendered on index
3. Dashboard blog CRUD + publish toggle + cover upload + markdown preview
4. **Accept:** public blog renders identically post-seed; create/edit/publish/unpublish/delete all from panel; no code needed to ship a new post

### Phase 3 — Pricing manager
1. `plan_tiers` + `plan_comparison_rows` migrations + models + seeder (current config verbatim)
2. `PlanService` with config fallback + caching; rewire `EntitlementService`, `MarketingController::price`, `BillingController`, `PayMongoWebhookController` to it
3. Dashboard pricing editor (tiers, comparison, hero, FAQ) + env-amount warning banner
4. Fix the comparison-matching fragility: pass tier `key` to `Price.vue` and match on key instead of `name.toLowerCase()`
5. **Accept:** price page + billing page + entitlements behave identically post-seed; editing a price/feature/minute in the panel reflects on `/price`, `/settings/billing`, and quota enforcement after save; quota changes apply on next request (cache invalidated)

### Phase 4 — Pages manager
1. `page_contents` migration + model + seeder; extract today's hardcoded Features/Download copy to `config/marketing.php` fallback
2. `Features.vue` / `Download.vue` consume controller-provided content props (fallback = config)
3. Dashboard structured editors for both pages
4. **Accept:** both pages render identically post-seed; every text block/FAQ/requirement/feature-row editable from panel; Download release data still dynamic

### Phase 5 — Hardening & review
1. Pest coverage: gate allow/deny per section, super-admin bypass, kill-switch, CRUD, public rendering, config-fallback path (simulate empty tables), cache invalidation, markdown XSS safety (`<script>` in body must not survive)
2. `pint` / `phpstan` / `vue-tsc` / full Pest / Vite build all green
3. **Accept:** §7 checklist complete

---

## 7. Acceptance Checklist

- [ ] All four `cms.*` gates visible and assignable in the existing Positions & Gates grid
- [ ] `ADMIN_EMAIL` + `ADMIN_ACESS=true` account can access/edit everything; `ADMIN_ACESS=false` instantly revokes it (tested)
- [ ] A position with only `cms.manage-blog` can fully manage blog posts and gets 403 on pricing/pages routes
- [ ] Blog: new post published from panel appears on `/blog` with correct markdown rendering and sanitization; cover image displays
- [ ] Pricing: editing tier price/minutes/features in panel updates `/price`, `/settings/billing`, and entitlement enforcement; comparison rows match by tier key
- [ ] Pages: all Features and Download copy editable; layout/spacing/theme unchanged (structured forms only — no free HTML)
- [ ] Fallback: with emptied tables, site renders from `config/plans.php` + `config/marketing.php` exactly as today
- [ ] `/` landing and `/workspace` untouched by the CMS (still code-managed)
- [ ] PayMongo amounts still env-driven; warning banner present on pricing editor
- [ ] Regular users still land on `/workspace`; sidebar shows Dashboard only to permissioned users
- [ ] Full Pest suite + `vue-tsc` + Vite build pass; new code passes `pint` and `phpstan`

---

## 8. Reference Files for Codex Agent

| Purpose | Path |
|---|---|
| Gate pattern to copy | `app/Gates/UserGates.php`, `app/Gates/APIManagerGates.php` |
| Permission traits | `app/Traits/HasGatePermissions.php`, `app/Traits/Gates.php` |
| Super admin | `config/admin.php`, `app/Providers/AppServiceProvider.php` (`configureDefaults`) |
| Assignment UI (auto-consumes new gates) | `app/Http/Controllers/UserManagerController.php`, `resources/js/pages/settings/Users.vue` |
| Shared auth flags | `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/types/auth.ts` |
| Nav filtering pattern | `resources/js/layouts/settings/Layout.vue` |
| Blog today | `app/Http/Controllers/BlogController.php`, `resources/blog/*.md`, `resources/js/pages/marketing/Blog{Index,Show}.vue` |
| Pricing today | `config/plans.php`, `app/Services/EntitlementService.php`, `app/Http/Controllers/{MarketingController,Settings/BillingController,PayMongoWebhookController}.php`, `resources/js/pages/marketing/Price.vue` |
| Pages today | `resources/js/pages/marketing/{Features,Download}.vue`, `app/Http/Controllers/DownloadController.php` |
| Route middleware pattern | `routes/transcription-web.php` (`can:API-manage_api` group) |
