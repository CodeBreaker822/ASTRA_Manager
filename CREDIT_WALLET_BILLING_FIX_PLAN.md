# Credit Wallet & Billing Fix Plan — ASTRA_Manager (JERVA)

**Repo:** `CodeBreaker822/ASTRA_Manager`
**Scope:** `/settings/billing`, wallet/credit balance model, checkout flow, price manager
**Author:** Generated from a full read of the current codebase (Laravel + Inertia + Vue)
**Status:** Draft for review — see "Open Decisions" before implementation starts

this repo is from TranscriptionServer latest commits
---

## 0. TL;DR

The AI that built this feature accidentally created **two separate, disconnected credit
systems** at once, and never finished wiring either of them together:

| | System A — "per-feature pools" (this is the one actually running today) | System B — "unified wallet" (dead code, not linked to any UI) |
|---|---|---|
| Storage | `users.credit_seconds`, `users.polish_credit_characters`, `users.summary_credit_characters` | `users.wallet_balance`, `users.total_earned_credits`, `users.total_spent_credits` |
| Top-up | `Settings\BillingController` → `PayMongoCheckoutService` → `PayMongoWebhookController` | `Api\PaymentController` → `Services\Billing\PaymentService` |
| Deduct | `EntitlementService::recordTranscriptionUsage/recordPolishUsage/recordSummaryUsage` | **nothing ever deducts from it** |
| UI | `resources/js/pages/settings/Billing.vue` — 3 separate "Buy X" buttons per plan | none — not reachable from any page |
| Checkout unit | User must pick `credit_type = audio | polish | summary` **before** paying | Fixed PHP packages (₱50/₱100/₱200/₱500) unrelated to plan pricing |

This is exactly the confusion you're describing: polishing and summarizing were turned into
**their own separate "mini-wallets"** with their own "buy" button, instead of one balance that
gets debited by $ (or ₱) amount no matter what feature is used.

**The fix:** collapse everything into **one balance column** (`wallet_balance`), deduct it in
money terms using the rates already defined in the Price Manager (`plan_tiers` table), and
rebuild `/settings/billing` as two simple cards, exactly like your example. Full plan below.

---

## 1. Root Cause — File-by-File Evidence

### 1.1 Three per-feature balances instead of one wallet

`app/Services/EntitlementService.php`
```php
$creditSecondsToUse = min((int) $lockedUser->credit_seconds, ...);
$lockedUser->decrement('credit_seconds', $creditSecondsToUse);   // minutes pool
...
$lockedUser->decrement($creditColumn, $characters);              // polish_credit_characters OR summary_credit_characters
```
Three independent counters (`credit_seconds`, `polish_credit_characters`,
`summary_credit_characters`) live on `users`, each deducted by a different unit
(seconds, characters, characters). There is no shared "money" concept anywhere in this file.

### 1.2 Checkout forces the user to pre-select which pool they're topping up

`app/Http/Controllers/Settings/BillingController.php`
```php
$validated = $request->validate([
    'plan' => ['required', 'string', 'in:payg'],
    'credit_type' => ['nullable', 'string', 'in:audio,polish,summary'],
]);
```
`resources/js/pages/settings/Billing.vue` renders this as **three separate buttons** on the
Pay-as-you-go card:
```html
<Button>Buy minutes</Button>
<Button>Buy polish characters</Button>
<Button>Buy summarize characters</Button>
```
Each button is a full separate PayMongo checkout session, with its own price
(`PayMongoCheckoutService::amountFor()`), its own webhook credit-crediting branch
(`PayMongoWebhookController`), and its own line in the balance summary. This is the
"billing became separate instead of just deducting the wallet" problem you're describing —
confirmed in code.

### 1.3 A second, unrelated wallet system already exists — and is dead

`database/migrations/2026_07_25_051205_add_credit_balance_to_users_table.php` added:
```php
$table->decimal('wallet_balance', 15, 2)->default('0.00')->unsigned();
$table->unsignedInteger('total_earned_credits')->default(0);
$table->unsignedInteger('total_spent_credits')->default(0);
```
`app/Services/Billing/PaymentService.php` adds to `wallet_balance` after a PayMongo
`payment.captured` webhook, using **hardcoded packages that don't reference the Price
Manager at all**:
```php
private const CREDIT_PACKAGES = [
    50 => 5,   // PHP 50 for 5 credits
    100 => 10, // PHP 100 for 10 credits
    200 => 25, // PHP 200 for 25 credits
    500 => 50, // PHP 500 for 50 credits
];
```
This is wired to `Api\PaymentController` (`routes/transcription-api.php`,
`/api/credits/packages`, `/api/credits/purchase`, `/api/credits/balance`,
`/api/credits/webhook`) — **but no frontend page calls any of these routes.** Nothing in
`EntitlementService` ever reads or decrements `wallet_balance`. It is a fully-built,
fully-orphaned parallel implementation. This is almost certainly what confused the AI (and
now you) — there are literally two different "credits" concepts in the same codebase,
and the newer one (the actual unified wallet you want) was never connected to anything.

### 1.4 The Price Manager already exists — good news, keep it

`app/Http/Controllers/DashboardPricingController.php` + `resources/js/pages/dashboard/Pricing.vue`
(gate: `cms.manage-pricing`) already lets an admin edit, per tier:
- `upload_price_per_hour`, `live_price_per_hour`, `llm_price`
- `polish_price_per_character`, `summary_price_per_character`
- `minutes`, `free_polish_uses_per_day`, `free_summary_uses_per_day`
- `polish_characters`, `summary_characters` (**these two "characters" fields are the
  per-pack sizing that should be deleted — see §4**)

This writes to the `plan_tiers` table, which `PlanService::tiers()` reads (cached forever
under `plans.all`, correctly busted via `$plans->forget()` on save). **This is your "price
manager" — it already works and should stay the single source of truth for rates.** We
just need billing/usage code to read $ rates from it instead of maintaining separate
character/second inventories.

### 1.5 Current rates are configured in PHP (₱), your example is in $

`config/plans.php` (fallback / seed source) and `plan_tiers` currently store PHP-denominated
per-unit rates, e.g. `upload_price_per_hour => 190`, `polish_price_per_character => 0.0002`.
Your example in the request uses USD (`$0.10`/hour, `$0.05`/1000 chars). See **Open Decision
#1** — this plan does not silently change currency; it flags the decision explicitly.

---

## 2. Target Design

### 2.1 One balance, period.

- Keep **one** column: `users.wallet_balance` (decimal, already exists).
- Delete the concept of `credit_seconds`, `polish_credit_characters`,
  `summary_credit_characters` as *purchasable inventories*. Every paid feature — upload,
  live recording, polish, summarize — is billed the same way: **compute cost in money using
  the Price Manager rate, deduct that amount from `wallet_balance`.**
- Free-tier daily allowances (60 minutes/day, 3 polish/day, 3 summarize/day) stay exactly as
  they are today — tracked by `UsageRecord` counters, reset by `period` date, entirely
  separate from the paid wallet. Nothing changes about "free" except cosmetic copy.

### 2.2 One "Add funds" flow, not three checkouts.

- `/settings/billing/checkout` stops accepting `credit_type`. It accepts an **amount** (a
  preset chip like ₱100 / ₱250 / ₱500 / ₱1000, or a custom amount within admin-configured
  min/max).
- PayMongo checkout session metadata carries only `{ user_id, amount }`.
- Webhook does exactly one thing on success: `wallet_balance += amount`. No branching by
  feature type, ever.

### 2.3 `/settings/billing` becomes two cards, matching your example.

```
┌─────────────────────────────┐   ┌───────────────────────────────────────────┐
│ Free Tier                    │   │ Pay-as-you-go                              │
│ ─────────────                │   │ ─────────────                              │
│ Free · 60 minutes/day        │   │ All Free Tier benefits, plus:              │
│ Free · 3 Polishing/day       │   │ Audio Upload — 1h / $0.10                  │
│ Free · 3 Summarization/day   │   │ Live Recording — 1h / $0.12                │
│ TXT, Word, Excel export      │   │ Polishing — 1,000 chars / $0.05            │
│                               │   │ Summarization — 1,000 chars / $0.10        │
│ [Included daily]             │   │                                             │
└─────────────────────────────┘   │ Wallet balance: $12.40                     │
                                   │ [₱100] [₱250] [₱500] [Custom] → Add funds  │
                                   └───────────────────────────────────────────┘
```
Every number on Card 2 is rendered **directly from `PlanService::plan('payg')`** (i.e. the
Price Manager), so editing a rate in the admin dashboard instantly updates this card — no
code changes needed to change a price, which is exactly what you asked for.

### 2.4 Deduction logic (all features, one code path)

```php
// EntitlementService (new unified method)
public function charge(User $user, string $feature, float $units): void
{
    $rate = $this->ratePerUnit($feature); // from PlanService, e.g. $/hour, $/1000 chars
    $cost = round($units * $rate, 2);

    // 1. Free daily allowance first (existing UsageRecord counters, unchanged)
    if ($this->hasFreeAllowanceRemaining($user, $feature, $units)) {
        $this->consumeFreeAllowance($user, $feature, $units);
        return;
    }

    // 2. Otherwise, deduct money from the single wallet
    DB::transaction(function () use ($user, $cost) {
        $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        if ($locked->wallet_balance < $cost) {
            throw new InsufficientWalletBalanceException();
        }
        $locked->decrement('wallet_balance', $cost);
        $locked->increment('total_spent_credits', $cost); // keep for "lifetime spent" stat if wanted
    });
}
```
`upload`, `live`, `polish`, `summarize` all call the same `charge()` method with their own
unit count and feature key. No more per-feature columns, no more per-feature checkout.

---

## 3. Database Changes

### Migration 1 — add the money-rate columns the wallet needs (if not already present)
`plan_tiers` already has `upload_price_per_hour`, `live_price_per_hour`,
`polish_price_per_character`, `summary_price_per_character` — no schema change needed here,
these become the canonical $/unit rates used by `charge()`.

### Migration 2 — backfill: convert existing per-feature balances into wallet dollars
Before dropping the old columns, convert what users already own into wallet money at
**today's PAYG rate**, so nobody loses paid balance they already bought:

```php
// database/migrations/2026_08_xx_backfill_wallet_balance_from_legacy_pools.php
DB::table('users')->orderBy('id')->chunk(200, function ($users) use ($payg) {
    foreach ($users as $u) {
        $minutesValue   = ($u->credit_seconds / 3600) * $payg->upload_price_per_hour;
        $polishValue    = ($u->polish_credit_characters / 1000) * $payg->polish_price_per_character_per_1k;
        $summaryValue   = ($u->summary_credit_characters / 1000) * $payg->summary_price_per_character_per_1k;

        DB::table('users')->where('id', $u->id)->increment(
            'wallet_balance', round($minutesValue + $polishValue + $summaryValue, 2)
        );
    }
});
```
Log every conversion to a new `wallet_backfill_log` table (or `BillingTransaction` with
`plan = 'legacy_backfill'`) so support can audit "why did my balance change" questions.

### Migration 3 — drop the legacy per-feature pool columns
```php
Schema::table('users', function (Blueprint $table) {
    $table->dropColumn(['credit_seconds', 'polish_credit_characters', 'summary_credit_characters']);
});
```
Run **only after** Migration 2 has been verified in production (see rollout phases, §7).

### Migration 4 — clean up `plan_tiers`
Drop `polish_characters` / `summary_characters` (pack-sizing columns) — they represented
"how many characters does a purchased pack contain," which no longer means anything once
top-ups are pure dollar amounts. Keep the `*_price_per_character` rate columns.

### Migration 5 — add wallet top-up configuration (admin-editable)
```php
Schema::create('wallet_topup_presets', function (Blueprint $table) {
    $table->id();
    $table->unsignedInteger('amount'); // in minor units (centavos/cents)
    $table->unsignedInteger('sort_order')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
Schema::table('users', function (Blueprint $table) {
    // already exists: wallet_balance, total_earned_credits, total_spent_credits — keep as-is
});
```
Seed with your example presets (e.g. 100 / 250 / 500 / 1000) — editable from the Price
Manager screen so support/finance can adjust top-up chips without a deploy.

---

## 4. Backend Changes (file-by-file)

| File | Change |
|---|---|
| `app/Services/EntitlementService.php` | Replace `canTranscribe/recordTranscriptionUsage/canPolish/canSummarize/recordPolishUsage/recordSummaryUsage` internals with one `charge(User $user, string $feature, float $units)` + one `canAfford(User $user, string $feature, float $units): bool`. Free-daily-allowance logic (via `UsageRecord`) stays, wallet deduction replaces the three credit columns. |
| `app/Http/Controllers/Settings/BillingController.php` | `checkout()` no longer accepts `credit_type`. Accepts `amount` (validated against `wallet_topup_presets` or an admin-configured min/max custom range). |
| `app/Services/PayMongoCheckoutService.php` | `createCheckoutSession()` / `amountFor()` collapse to a single "top up wallet by $X" description. Delete `characterAmount()`, the `credit_type` branches, and per-type `descriptionFor()` cases. |
| `app/Http/Controllers/PayMongoWebhookController.php` | On `checkout_session.payment.paid`, do exactly: `$transaction->user->increment('wallet_balance', $amountInMajorUnits)`. Delete the `polish` / `summary` / `audio` branching entirely. |
| `app/Services/Billing/PaymentService.php` + `app/Http/Controllers/Api/PaymentController.php` | **Recommended: delete both files** (dead, duplicate, hardcoded packages disconnected from Price Manager). If there's a reason to keep the PayMongo-SDK-based flow instead of the checkout-session flow, pick **one** implementation and delete the other — do not keep both. See Open Decision #2. |
| `routes/transcription-api.php` | Remove the four orphaned `/api/credits/*` routes if `PaymentController` is deleted. |
| `app/Http/Controllers/DashboardPricingController.php` | Extend validation to also manage `wallet_topup_presets` (amount chips) in the same admin screen. Remove `polish_characters` / `summary_characters` from the validated payload (no longer meaningful). |
| `app/Services/PlanService.php` | Add a small helper `ratePerUnit(string $feature): float` reading from the cached `payg` tier so `EntitlementService` and the frontend both pull from one place. |
| `app/Models/User.php` | Remove `credit_seconds`, `polish_credit_characters`, `summary_credit_characters` from `$fillable`/casts once columns are dropped (Migration 3). Keep `wallet_balance`, `total_earned_credits`, `total_spent_credits`. |
| New: `app/Exceptions/InsufficientWalletBalanceException.php` | Thrown by `charge()`, caught in controllers to return a friendly "Add funds to continue" response instead of a generic 500/422. |

---

## 5. Frontend Changes

### `resources/js/pages/settings/Billing.vue` — full rewrite

- **Card 1 — Free Tier**: static list generated from `plans.free.features`
  (already exists in `config/plans.php` / `plan_tiers`), just re-copy to match your bullet
  style: *"Free · 60 minutes per day", "Free · 3 Polishing", "Free · 3 Summarization"*.
- **Card 2 — Pay-as-you-go**: bullet list built from the PAYG tier's rate fields —
  `upload_price_per_hour`, `live_price_per_hour`, `polish_price_per_character` (× 1000 for
  display), `summary_price_per_character` (× 1000 for display) — labelled exactly like your
  example (`Audio Upload 1h/$0.10`, `Polishing 1000 Char/$0.05`, etc). **Delete the three
  "Buy minutes / Buy polish / Buy summarize" buttons entirely.**
- Add a **wallet balance banner** at the top of Card 2 (or the page header) showing
  `wallet_balance`, and a single **"Add funds"** control: preset chips from
  `wallet_topup_presets` + a custom-amount input, both posting to the simplified
  `/settings/billing/checkout` with `{ amount }` only.
- Update the "Today's allowance" strip to stop referencing `minutes_credit_balance` /
  `polish_credit_characters` / `summary_credit_characters` and instead show
  "Free minutes remaining today" + "Wallet balance: $X.XX".

### `resources/js/pages/dashboard/Pricing.vue`

- Remove the `polish_characters` / `summary_characters` pack-size inputs.
- Add a small "Wallet top-up presets" editor (list of amounts, add/remove/reorder) backed
  by `wallet_topup_presets`.

### `resources/js/pages/workspace/Index.vue`, `resources/js/components/SettingsModal.vue`

- Replace references to `polish_credit_characters` / `summary_credit_characters` with a
  single `wallet_balance` display (or remove the balance display from these secondary
  surfaces entirely if `/settings/billing` is meant to be the one place balance is shown —
  recommend the latter, for simplicity).

---

## 6. Open Decisions (need your input before implementation)

1. **Currency.** Everything today (`plan_tiers`, PayMongo, `wallet_balance`) is PHP (₱).
   Your example prices are in $. Do we: (a) keep ₱ and just relabel your example numbers into
   pesos, (b) switch the whole system to USD, or (c) store in USD internally and convert to
   the user's local currency for display/charging? **Recommend (a)** — smallest, least risky
   change — but confirm.
2. **Which top-up implementation survives — `PayMongoCheckoutService`
   (checkout-session based, currently linked to the UI) or `Billing\PaymentService`
   (PayMongo-SDK/payment-intent based, currently orphaned)?** Recommend keeping
   `PayMongoCheckoutService` since it's the one actually wired to `/settings/billing` and
   already has webhook signature verification in place, and deleting `PaymentService` +
   `Api\PaymentController` outright.
3. **Backfill conversion rate.** Migration 2 converts existing `credit_seconds` /
   `*_credit_characters` into wallet dollars using **current** PAYG rates. If rates have
   changed since those credits were purchased, users could end up with more or less than
   they originally paid for. Acceptable, or do we need to look up the historical
   `BillingTransaction` amount per user instead (more accurate, more complex)?
4. **Minimum top-up / preset amounts.** What should the actual preset chips be? (Example
   used ₱100/₱250/₱500/₱1000 as placeholders.)
5. **Do secondary surfaces (workspace sidebar, settings modal) still show a balance at all**,
   or does balance live only on `/settings/billing` from now on?

---

## 7. Rollout Plan (safe order of operations)

**Phase 1 — Backend plumbing, no user-facing change**
1. Add `EntitlementService::charge()` / `canAfford()` alongside (not replacing) existing
   methods; unit test against `plan_tiers` rates.
2. Add `wallet_topup_presets` table + seeder.
3. Ship Migration 2 (backfill) behind a feature flag; run in staging, diff totals against
   `BillingTransaction` history to validate correctness.

**Phase 2 — Cut over checkout & webhook**
4. Update `BillingController`, `PayMongoCheckoutService`, `PayMongoWebhookController` to the
   amount-only flow. Deploy behind a flag if possible; smoke test a real ₱1 top-up in
   PayMongo test mode.
5. Switch `EntitlementService` call sites (`WebTranscriptProcessor`,
   `Web/TranscriptionController`, `Web/TranscriptActionController`,
   `Middleware/CanTranscribe`) from the old per-feature methods to `charge()`/`canAfford()`.

**Phase 3 — Frontend**
6. Rewrite `Billing.vue` (two-card layout, single Add Funds control).
7. Update `Pricing.vue` admin screen (drop pack-size fields, add top-up presets editor).
8. Update workspace/settings-modal balance displays per Open Decision #5.

**Phase 4 — Cleanup**
9. Delete `Api\PaymentController`, `Services\Billing\PaymentService`, and the
   `/api/credits/*` routes (pending Open Decision #2).
10. Run Migration 3 (drop `credit_seconds`, `polish_credit_characters`,
    `summary_credit_characters`) and Migration 4 (drop `plan_tiers.polish_characters` /
    `summary_characters`).
11. Remove now-dead TypeScript props (`minutes_credit_balance`, `polish_credit_characters`,
    `summary_credit_characters`) from `Billing.vue`, `Index.vue`, `SettingsModal.vue` types.

---

## 8. Testing Checklist

- [ ] Free-tier daily allowance still resets correctly at midnight per `UsageRecord.period`.
- [ ] Using upload/live/polish/summarize beyond the free daily allowance deducts the correct
      $ amount from `wallet_balance` (unit-test each feature's rate math independently).
- [ ] Attempting to use a paid feature with `wallet_balance` below the required cost throws
      `InsufficientWalletBalanceException` and the UI shows an "Add funds" prompt (not a
      generic error).
- [ ] Editing a rate in the Price Manager (`dashboard/Pricing.vue`) immediately changes the
      displayed price on `/settings/billing` Card 2 (no cache staleness — verify
      `PlanService::forget()` fires on save).
- [ ] PayMongo checkout for a top-up amount → webhook fires → `wallet_balance` increases by
      exactly the paid amount, once (test webhook idempotency/replay).
- [ ] Legacy users with existing `credit_seconds` / `polish_credit_characters` /
      `summary_credit_characters` see the expected converted `wallet_balance` after
      Migration 2, and a `BillingTransaction`/log row explains the conversion.
- [ ] `/api/credits/*` routes return 404 after cleanup (confirm nothing external still calls
      them — check any mobile/desktop clients before deleting).
- [ ] Concurrent usage (`lockForUpdate`) can't push `wallet_balance` negative under race
      conditions — write a test that fires two simultaneous `charge()` calls near the balance
      floor.

---

## 9. Summary of What Gets Deleted

- `users.credit_seconds`, `users.polish_credit_characters`, `users.summary_credit_characters`
- `plan_tiers.polish_characters`, `plan_tiers.summary_characters`
- The 3-button "Buy minutes / Buy polish / Buy summarize" UI in `Billing.vue`
- The `credit_type` parameter across `BillingController`, `PayMongoCheckoutService`,
  `PayMongoWebhookController`
- `app/Services/Billing/PaymentService.php`, `app/Http/Controllers/Api/PaymentController.php`,
  and their routes (pending Open Decision #2)

## 10. Summary of What Survives Unchanged

- `plan_tiers` table + `DashboardPricingController` + `PlanService` — your Price Manager,
  already correctly built, just gets a couple of columns removed.
- Daily free-allowance mechanics (`UsageRecord`, `polish_count`, `summary_count`,
  `seconds_transcribed`) — this part was never the problem and needs no redesign.
- `wallet_balance`, `total_earned_credits`, `total_spent_credits` columns — these already
  exist; they just need to actually be used everywhere instead of only by dead code.
