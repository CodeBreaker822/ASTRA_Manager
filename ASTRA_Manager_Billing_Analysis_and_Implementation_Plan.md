# ASTRA_Manager Billing Repair and Implementation Plan

**Repository:** `CodeBreaker822/ASTRA_Manager`  
**Reviewed commit:** `657f4af075ab2eee0b3cad0f31b4e1683c4a5d64`  
**Commit message:** `update billing`  
**Target implementer:** GLM-4.7-Flash  
**Stack:** Laravel, SQLite/MySQL-compatible migrations, Inertia, Vue  
**Canonical currency for this repair:** PHP  
**Canonical internal money unit:** nanos, where `₱1.00 = 1,000,000,000 nanos`

---

## 1. Mandatory instructions for GLM-4.7-Flash

Do not patch only the visible `price_per_second` exception.

The current branch contains an incomplete billing rewrite. It mixes the old per-feature credit system with a new wallet system, mixes PHP and USD, mixes major and minor currency units, leaves deleted columns in models and services, skips important API endpoints, and charges in non-idempotent locations.

Implement the phases in this document in order.

Do not skip migrations, endpoint tracing, cancellation handling, retry handling, idempotency, ledger records, or tests.

The billing rules are:

1. `charge()` must only run after a transcription, polish, or summarize operation succeeds.
2. A successful transcription is billed even when the transcript text is empty.
3. Empty speech text is not a failed transcription. Billing uses verified audio duration, not transcript character count.
4. Failed provider calls must not debit the wallet.
5. Jobs that end as `failed`, `cancelled`, or `timed_out` must not debit the wallet.
6. A cancellation that wins before successful finalization must release all reservations and must not debit the wallet.
7. A successful finalization that wins before cancellation must be charged exactly once. A later cancellation must return HTTP `409`.
8. Retried jobs and repeated status polling must not produce duplicate charges.
9. Every wallet credit and debit must have an auditable ledger row.
10. The desktop API and the web application must use the same wallet and billing service.
11. The old audio, polish, and summarize credit pools must be removed.
12. Do not use floating-point arithmetic for money.

Before provider execution, call `authorize()` to reserve free allowance and wallet funds.

After a successful result is finalized, call `charge()` exactly once.

On failure or cancellation before successful finalization, call `release()`.

---

## 2. Confirmed defects in the reviewed commit

### 2.1 The reported SQLite exception is real

`app/Http/Controllers/DashboardPricingController.php` still sends this field to `updateOrCreate()`:

```php
'price_per_second' => null,
```

The latest migration drops `plan_tiers.price_per_second`.

Assigning `null` does not skip the field. Laravel still generates:

```sql
update "plan_tiers"
set "price_per_second" = ?
where "id" = ?
```

Remove the array key completely.

### 2.2 Deleted plan fields are still referenced

The following deleted or obsolete fields are still present in one or more of the model, service, configuration, UI, or migration files:

```text
price_per_second
polish_characters
summary_characters
```

Affected files include:

```text
app/Models/PlanTier.php
app/Services/PlanService.php
config/plans.php
app/Services/PayMongoCheckoutService.php
resources/js/pages/settings/Billing.vue
```

### 2.3 Deleted user fields are still referenced

The migration drops:

```text
users.credit_seconds
users.polish_credit_characters
users.summary_credit_characters
```

The current code still reads or casts those fields in:

```text
app/Models/User.php
app/Services/EntitlementService.php
```

`EntitlementService::summaryFor()` will query properties that no longer exist.

### 2.4 Two billing implementations remain active

The new checkout implementation uses:

```text
app/Http/Controllers/Settings/BillingController.php
app/Services/PayMongoCheckoutService.php
app/Http/Controllers/PayMongoWebhookController.php
```

The old implementation remains in:

```text
app/Http/Controllers/Api/PaymentController.php
app/Services/Billing/PaymentService.php
routes/transcription-api.php
```

These implementations use different package rules, webhook event rules, signature rules, and wallet units.

Only one implementation may remain.

### 2.5 `EntitlementService` contains two systems at once

The class still contains the old methods:

```text
canTranscribe()
recordTranscriptionUsage()
canPolish()
canSummarize()
recordPolishUsage()
recordSummaryUsage()
```

It also contains the new methods:

```text
canAfford()
charge()
```

This is not a migration. It is two conflicting billing systems in the same class.

### 2.6 `EntitlementService` calls a method that does not exist

The new methods call:

```php
$this->ratePerUnit($feature)
```

`ratePerUnit()` is defined on `PlanService`, not `EntitlementService`.

Every new affordability check can fail with an undefined-method error.

### 2.7 Audio cost calculation is wrong by a factor of 3,600

The controller passes seconds to `charge()`.

The configured rate is per hour.

The current calculation is effectively:

```php
$cost = $seconds * $pricePerHour;
```

The correct calculation is:

```text
cost = seconds × hourly rate ÷ 3,600
```

At `₱190/hour`, 60 seconds must cost about `₱3.166666667`, not `₱11,400`.

### 2.8 Two-decimal wallet arithmetic makes small usage free or inaccurate

The current code rounds each charge to two decimals:

```php
round($units * $rate, 2)
```

A per-character price such as `0.0002` cannot be represented safely with a two-decimal wallet.

Money must use integer high-precision units.

### 2.9 Free allowance calculation does not split free and paid usage

A request may partly fit within the remaining daily free allowance.

Example:

```text
Free seconds remaining: 10
Request duration: 30 seconds
```

The system must consume 10 free seconds and bill 20 seconds.

The current implementation treats the request as either fully free or fully paid.

### 2.10 `summary` and `summarize` are inconsistent

The new billing feature key is:

```text
summarize
```

Some old helper methods expect:

```text
summary
```

This can throw an invalid-action exception.

Use only:

```text
upload
live
polish
summarize
```

### 2.11 Checkout controller contains immediate runtime errors

`app/Http/Controllers/Settings/BillingController.php` calls:

```php
DB::transaction(...)
```

but does not import:

```php
use Illuminate\Support\Facades\DB;
```

It also assigns `$checkout` inside the closure and reads it after the closure:

```php
DB::transaction(function () {
    $checkout = ...;
});

return redirect()->away($checkout['checkout_url']);
```

The closure must return the checkout result.

### 2.12 Currency and units are inconsistent

The current billing UI and new checkout use USD.

The existing price manager and old PayMongo code use PHP.

The webhook adds raw minor units to `wallet_balance`, while `charge()` compares the wallet with plan prices as if the wallet were major units.

Example:

```text
Payment amount: 100 minor units
Wallet increment: 100
UI displays: 100 / 100 = 1.00
Charge service compares wallet 100 against rate 190
```

The field has no consistent meaning.

Use PHP end-to-end.

### 2.13 Webhook crediting is not safely idempotent

The current webhook checks the transaction status before updating, but it does not lock the transaction and user rows in a database transaction.

Two simultaneous webhook retries can both read `status != paid` and both credit the wallet.

### 2.14 The old API webhook is protected by authentication

`Api\PaymentController` applies `auth:sanctum` in its constructor to every method.

The route exposes:

```text
POST /api/credits/webhook
```

A PayMongo webhook cannot provide the user's Sanctum token.

Remove this route and old controller.

### 2.15 Desktop API endpoints are not billed

The following routes are the actual desktop/API pipeline:

```text
POST /api/transcribe
GET  /api/transcribe/jobs/{job}
POST /api/polish
```

The new billing rewrite did not add wallet authorization, charging, release, or idempotency to these endpoints.

### 2.16 Async transcription jobs are not billed

The following paths can complete transcription:

```text
processAsyncTranscriptionJob()
refreshAsyncTranscriptionJob()
completeAsyncTranscriptionWithFallback()
```

Each success and failure path needs one centralized billing-aware finalizer.

### 2.17 Web transcription persists the result before charging

`WebTranscriptProcessor` currently performs:

```text
persist result
charge wallet
mark complete
```

If charging throws:

```text
the result is already stored
the catch block marks the transcript failed
the user may have a transcript that exists but is shown as failed
```

The billing owner must be consistent and idempotent.

### 2.18 Web transcription always charges upload rate

`WebTranscriptProcessor::recordUsage()` always calls:

```php
charge($user, 'upload', $seconds)
```

A transcript with source `live` must use the live rate.

### 2.19 Successful empty transcripts can avoid charging

The current web code skips charging when:

```php
$seconds === 0
```

Duration must be established from the audio or trusted clip timing before provider execution.

The transcript text may be empty and must not affect the duration charge.

### 2.20 Retryable text jobs can charge more than once

The polish and summarize jobs have:

```php
public int $tries = 3;
```

The result is stored before charging.

A worker crash after wallet deduction but before the job is safely completed can cause a retry and another debit.

Every request needs a stable idempotency key and stored result.

### 2.21 Web cancellation is local only

The current cancellation route marks the local transcript cancelled.

It does not cancel or invalidate the API job.

A remote API job can still complete after local cancellation.

The completion path can then charge unless the API job has a cancellation-aware finalizer.

### 2.22 Legacy character-credit conversion is wrong by 1,000 times

The old field is priced per character:

```text
polish_price_per_character
summary_price_per_character
```

The migration calculates:

```php
($characters / 1000) * $pricePerCharacter
```

The correct calculation is:

```php
$characters * $pricePerCharacter
```

### 2.23 The current wallet field contains ambiguous units

Depending on which code path wrote it, `users.wallet_balance` may represent:

```text
major currency units
minor currency units
hardcoded abstract credits
legacy credit conversion value
```

Do not blindly multiply every existing value by one factor during production migration.

A reconciliation step is mandatory when real payments exist.

---

## 3. Target architecture

### 3.1 One wallet

Use one wallet for all paid features.

User columns:

```text
wallet_balance_nanos
wallet_reserved_nanos
total_earned_nanos
total_spent_nanos
```

Do not put these fields in `$fillable`.

### 3.2 One pricing source

`plan_tiers` remains the canonical pricing source.

Use these rate fields:

```text
upload_price_per_hour
live_price_per_hour
polish_price_per_character
summary_price_per_character
```

Use decimal strings when reading them.

### 3.3 One billing service

Create:

```text
app/Services/Billing/BillingService.php
app/Services/Billing/Money.php
```

The service owns:

```text
quote()
authorize()
charge()
release()
creditWallet()
```

Controllers and jobs must not directly increment or decrement wallet fields.

### 3.4 One operation record per billable request

Create `billing_operations`.

A billing operation stores:

```text
who initiated the request
which feature was used
requested units
free units reserved
paid units
the rate snapshot
the authorized amount
the captured amount
the current state
the idempotency key
the result payload for synchronous idempotent responses
timestamps
```

### 3.5 One wallet ledger

Create `wallet_ledger_entries`.

Every top-up and every debit must create one ledger row.

The user wallet columns are the fast current balance.

The ledger is the audit trail.

### 3.6 Billing is owned by the API execution layer

The web application already calls the API transcription controller through `WebApiTranscriptionClient`.

Therefore:

```text
API endpoint authorizes
provider executes
API success finalizer charges
API failure/cancel finalizer releases
web layer only persists/displays returned results
```

Remove wallet charging from `WebTranscriptProcessor`.

This avoids double charging between the web and desktop routes.

---

## 4. Money representation

### 4.1 Why nanos are required

The configured text rate can be:

```text
₱0.0002 per character
```

A centavo-only wallet cannot represent one character accurately.

Use:

```text
₱1.00 = 1,000,000,000 nanos
₱0.01 = 10,000,000 nanos
₱0.0002 = 200,000 nanos
```

### 4.2 Create `Money`

Create `app/Services/Billing/Money.php`:

```php
<?php

namespace App\Services\Billing;

use InvalidArgumentException;

final class Money
{
    public const NANOS_PER_MAJOR = 1_000_000_000;

    public const NANOS_PER_MINOR = 10_000_000;

    public static function decimalToNanos(string|int $amount): int
    {
        $value = trim((string) $amount);

        if (! preg_match('/^\d+(?:\.\d{1,9})?$/', $value)) {
            throw new InvalidArgumentException('Invalid non-negative money value.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 9), 9, '0');

        return ((int) $whole * self::NANOS_PER_MAJOR) + (int) $fraction;
    }

    public static function nanosToDecimal(int $nanos): string
    {
        if ($nanos < 0) {
            throw new InvalidArgumentException('Money value cannot be negative.');
        }

        $whole = intdiv($nanos, self::NANOS_PER_MAJOR);
        $fraction = $nanos % self::NANOS_PER_MAJOR;

        return $whole.'.'.str_pad((string) $fraction, 9, '0', STR_PAD_LEFT);
    }

    public static function formatNanos(int $nanos): string
    {
        $decimal = self::nanosToDecimal($nanos);
        [$whole, $fraction] = explode('.', $decimal, 2);

        return '₱'.number_format((int) $whole).'.'.substr($fraction, 0, 2);
    }

    public static function audioCostNanos(int $seconds, string|int $hourlyRate): int
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Audio seconds cannot be negative.');
        }

        $rateNanos = self::decimalToNanos($hourlyRate);

        if ($seconds === 0 || $rateNanos === 0) {
            return 0;
        }

        return intdiv(($seconds * $rateNanos) + 3_599, 3_600);
    }

    public static function textCostNanos(int $characters, string|int $pricePerCharacter): int
    {
        if ($characters < 0) {
            throw new InvalidArgumentException('Character count cannot be negative.');
        }

        return $characters * self::decimalToNanos($pricePerCharacter);
    }

    public static function minorToNanos(int $minorUnits): int
    {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('Minor units cannot be negative.');
        }

        return $minorUnits * self::NANOS_PER_MINOR;
    }
}
```

Do not replace this with floats.

---

## 5. Database migrations

Do not rely only on editing old migrations that have already run.

Create new forward migrations.

### 5.1 Add precise wallet fields

Create:

```text
database/migrations/<timestamp>_add_precise_wallet_fields_to_users_table.php
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('wallet_balance_nanos')->default(0);
            $table->unsignedBigInteger('wallet_reserved_nanos')->default(0);
            $table->unsignedBigInteger('total_earned_nanos')->default(0);
            $table->unsignedBigInteger('total_spent_nanos')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'wallet_balance_nanos',
                'wallet_reserved_nanos',
                'total_earned_nanos',
                'total_spent_nanos',
            ]);
        });
    }
};
```

Keep the old wallet columns temporarily for reconciliation.

### 5.2 Add reservation fields to daily usage

Create:

```text
database/migrations/<timestamp>_add_billing_reservations_to_usage_records_table.php
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->unsignedInteger('free_seconds_reserved')->default(0);
            $table->unsignedInteger('free_polish_reserved')->default(0);
            $table->unsignedInteger('free_summary_reserved')->default(0);
            $table->unique(['user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'period']);
            $table->dropColumn([
                'free_seconds_reserved',
                'free_polish_reserved',
                'free_summary_reserved',
            ]);
        });
    }
};
```

If the unique index already exists, check the existing migration before adding it.

### 5.3 Create billing operations

Create:

```text
database/migrations/<timestamp>_create_billing_operations_table.php
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_id')->nullable()->constrained('a_p_i_s')->nullOnDelete();
            $table->string('feature', 32);
            $table->string('status', 24)->default('authorized');
            $table->string('idempotency_key', 160)->unique();
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 120)->nullable();
            $table->unsignedBigInteger('requested_units');
            $table->unsignedBigInteger('free_units')->default(0);
            $table->unsignedBigInteger('paid_units')->default(0);
            $table->unsignedBigInteger('rate_nanos')->default(0);
            $table->unsignedBigInteger('authorized_amount_nanos')->default(0);
            $table->unsignedBigInteger('captured_amount_nanos')->default(0);
            $table->string('currency', 3)->default('PHP');
            $table->unsignedInteger('authorization_attempts')->default(1);
            $table->json('metadata')->nullable();
            $table->json('result_payload')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_operations');
    }
};
```

### 5.4 Create wallet ledger

Create:

```text
database/migrations/<timestamp>_create_wallet_ledger_entries_table.php
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('billing_operation_id')->nullable();
            $table->foreignId('billing_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 16);
            $table->string('type', 40);
            $table->unsignedBigInteger('amount_nanos');
            $table->unsignedBigInteger('balance_after_nanos');
            $table->string('currency', 3)->default('PHP');
            $table->string('idempotency_key', 180)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('billing_operation_id')
                ->references('id')
                ->on('billing_operations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
    }
};
```

### 5.5 Extend billing transactions

Add unambiguous payment fields:

```text
amount_minor
wallet_credit_nanos
provider_event_id
```

```php
Schema::table('billing_transactions', function (Blueprint $table): void {
    $table->unsignedBigInteger('amount_minor')->nullable();
    $table->unsignedBigInteger('wallet_credit_nanos')->nullable();
    $table->string('provider_event_id')->nullable()->unique();
});
```

Keep the old `amount` field temporarily.

### 5.6 Extend API transcription jobs

Add:

```text
request_id
billing_operation_id
billing_feature
duration_seconds
```

```php
Schema::table('api_transcription_jobs', function (Blueprint $table): void {
    $table->string('request_id', 100)->nullable();
    $table->uuid('billing_operation_id')->nullable();
    $table->string('billing_feature', 32)->default('upload');
    $table->unsignedInteger('duration_seconds')->default(0);

    $table->foreign('billing_operation_id')
        ->references('id')
        ->on('billing_operations')
        ->nullOnDelete();

    $table->unique(['api_id', 'request_id']);
});
```

Use the actual table name used by `ApiTranscriptionJob`.

### 5.7 Add request IDs for web text actions

Add to `transcripts`:

```text
billing_request_id
polish_request_id
summary_request_id
```

```php
Schema::table('transcripts', function (Blueprint $table): void {
    $table->uuid('billing_request_id')->nullable()->unique();
    $table->uuid('polish_request_id')->nullable();
    $table->uuid('summary_request_id')->nullable();
});
```

Each newly requested polish or summary action gets a new UUID.

A job retry reuses the same UUID.

---

## 6. Models

### 6.1 Create `BillingOperation`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingOperation extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'api_id',
        'feature',
        'status',
        'idempotency_key',
        'subject_type',
        'subject_id',
        'requested_units',
        'free_units',
        'paid_units',
        'rate_nanos',
        'authorized_amount_nanos',
        'captured_amount_nanos',
        'currency',
        'authorization_attempts',
        'metadata',
        'result_payload',
        'authorized_at',
        'captured_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_units' => 'integer',
            'free_units' => 'integer',
            'paid_units' => 'integer',
            'rate_nanos' => 'integer',
            'authorized_amount_nanos' => 'integer',
            'captured_amount_nanos' => 'integer',
            'authorization_attempts' => 'integer',
            'metadata' => 'array',
            'result_payload' => 'array',
            'authorized_at' => 'datetime',
            'captured_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function api(): BelongsTo
    {
        return $this->belongsTo(API::class, 'api_id');
    }
}
```

### 6.2 Create `WalletLedgerEntry`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WalletLedgerEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'billing_operation_id',
        'billing_transaction_id',
        'direction',
        'type',
        'amount_nanos',
        'balance_after_nanos',
        'currency',
        'idempotency_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_nanos' => 'integer',
            'balance_after_nanos' => 'integer',
            'metadata' => 'array',
        ];
    }
}
```

### 6.3 Fix `User`

Remove legacy properties, fillable fields, and casts:

```text
credit_seconds
polish_credit_characters
summary_credit_characters
```

Add casts:

```php
'wallet_balance_nanos' => 'integer',
'wallet_reserved_nanos' => 'integer',
'total_earned_nanos' => 'integer',
'total_spent_nanos' => 'integer',
```

Do not make wallet fields mass assignable.

### 6.4 Fix `UsageRecord`

Add casts:

```php
'free_seconds_reserved' => 'integer',
'free_polish_reserved' => 'integer',
'free_summary_reserved' => 'integer',
```

### 6.5 Fix `PlanTier`

Remove:

```text
price_per_second
polish_characters
summary_characters
```

Use decimal string casts:

```php
'upload_price_per_hour' => 'decimal:8',
'live_price_per_hour' => 'decimal:8',
'llm_price' => 'decimal:8',
'polish_price_per_character' => 'decimal:8',
'summary_price_per_character' => 'decimal:8',
```

---

## 7. Replace `EntitlementService`

Delete wallet mutation logic from the current `EntitlementService`.

Keep it only for:

```text
feature availability
export availability
current free allowance summary
wallet summary
```

It must not directly debit or reserve money.

Example:

```php
<?php

namespace App\Services;

use App\Models\UsageRecord;
use App\Models\User;
use App\Services\Billing\Money;
use Illuminate\Support\Carbon;

class EntitlementService
{
    public function __construct(private readonly PlanService $plans) {}

    public function allows(User $user, string $feature): bool
    {
        return in_array($feature, ['upload', 'live', 'polish', 'summarize'], true);
    }

    public function allowsExport(User $user, string $format): bool
    {
        return in_array($format, ['txt', 'docx', 'xlsx'], true);
    }

    public function usageForCurrentPeriod(User $user): UsageRecord
    {
        UsageRecord::query()->insertOrIgnore([
            'user_id' => $user->id,
            'period' => Carbon::now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return UsageRecord::query()
            ->where('user_id', $user->id)
            ->where('period', Carbon::now()->toDateString())
            ->firstOrFail();
    }

    public function summaryFor(User $user): array
    {
        $usage = $this->usageForCurrentPeriod($user);
        $free = $this->plans->plan('free') ?? [];

        $freeSecondsLimit = max(0, (int) ($free['minutes'] ?? 0) * 60);
        $usedFreeSeconds = min($freeSecondsLimit, (int) $usage->seconds_transcribed);

        return [
            'wallet' => [
                'balance_nanos' => (int) $user->wallet_balance_nanos,
                'reserved_nanos' => (int) $user->wallet_reserved_nanos,
                'available_nanos' => max(
                    0,
                    (int) $user->wallet_balance_nanos - (int) $user->wallet_reserved_nanos,
                ),
                'balance_formatted' => Money::formatNanos((int) $user->wallet_balance_nanos),
                'available_formatted' => Money::formatNanos(max(
                    0,
                    (int) $user->wallet_balance_nanos - (int) $user->wallet_reserved_nanos,
                )),
            ],
            'usage' => [
                'period' => $usage->period,
                'seconds_transcribed' => (int) $usage->seconds_transcribed,
                'free_seconds_remaining' => max(
                    0,
                    $freeSecondsLimit
                    - $usedFreeSeconds
                    - (int) $usage->free_seconds_reserved,
                ),
                'polish_count' => (int) $usage->polish_count,
                'summary_count' => (int) $usage->summary_count,
                'free_polish_remaining' => max(
                    0,
                    (int) ($free['free_polish_uses_per_day'] ?? 0)
                    - min(
                        (int) ($free['free_polish_uses_per_day'] ?? 0),
                        (int) $usage->polish_count,
                    )
                    - (int) $usage->free_polish_reserved,
                ),
                'free_summary_remaining' => max(
                    0,
                    (int) ($free['free_summary_uses_per_day'] ?? 0)
                    - min(
                        (int) ($free['free_summary_uses_per_day'] ?? 0),
                        (int) $usage->summary_count,
                    )
                    - (int) $usage->free_summary_reserved,
                ),
            ],
        ];
    }
}
```

---

## 8. Create `BillingService`

Create:

```text
app/Services/Billing/BillingService.php
```

### 8.1 Required public methods

```php
quote(User $user, string $feature, int $units): array
authorize(
    User $user,
    string $feature,
    int $units,
    string $idempotencyKey,
    ?API $api = null,
    ?string $subjectType = null,
    string|int|null $subjectId = null,
    array $metadata = [],
): BillingOperation
charge(BillingOperation|string $operation, ?array $resultPayload = null): BillingOperation
release(BillingOperation|string $operation, ?string $reason = null): BillingOperation
creditWallet(
    User $user,
    int $amountNanos,
    string $idempotencyKey,
    ?BillingTransaction $transaction = null,
    array $metadata = [],
): WalletLedgerEntry
```

### 8.2 Feature rules

Canonical feature names:

```text
upload
live
polish
summarize
```

Unit rules:

```text
upload: seconds
live: seconds
polish: source characters
summarize: source characters
```

### 8.3 Authorization implementation

Use a transaction.

Lock:

```text
billing operation
user
current usage record
```

Do not debit the wallet.

Reserve:

```text
free allowance
wallet amount
```

Core implementation:

```php
<?php

namespace App\Services\Billing;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\API;
use App\Models\BillingOperation;
use App\Models\BillingTransaction;
use App\Models\UsageRecord;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Services\PlanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class BillingService
{
    public function __construct(private readonly PlanService $plans) {}

    public function authorize(
        User $user,
        string $feature,
        int $units,
        string $idempotencyKey,
        ?API $api = null,
        ?string $subjectType = null,
        string|int|null $subjectId = null,
        array $metadata = [],
    ): BillingOperation {
        $this->validateFeature($feature);

        if ($units <= 0) {
            throw new InvalidArgumentException('Billable units must be greater than zero.');
        }

        if (blank($idempotencyKey)) {
            throw new InvalidArgumentException('An idempotency key is required.');
        }

        return DB::transaction(function () use (
            $user,
            $feature,
            $units,
            $idempotencyKey,
            $api,
            $subjectType,
            $subjectId,
            $metadata,
        ): BillingOperation {
            $existing = BillingOperation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ((int) $existing->user_id !== (int) $user->id) {
                    throw new RuntimeException('Idempotency key belongs to another user.');
                }

                if ($existing->status === 'captured') {
                    return $existing;
                }

                if ($existing->status === 'authorized') {
                    return $existing;
                }
            }

            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            UsageRecord::query()->insertOrIgnore([
                'user_id' => $lockedUser->id,
                'period' => Carbon::now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $usage = UsageRecord::query()
                ->where('user_id', $lockedUser->id)
                ->where('period', Carbon::now()->toDateString())
                ->lockForUpdate()
                ->firstOrFail();

            $allocation = $this->allocation($usage, $feature, $units);
            $rate = $this->plans->rateForFeature($feature);
            $rateNanos = Money::decimalToNanos($rate);
            $amountNanos = $this->costNanos($feature, $allocation['paid_units'], $rate);

            $availableNanos = max(
                0,
                (int) $lockedUser->wallet_balance_nanos
                - (int) $lockedUser->wallet_reserved_nanos,
            );

            if ($amountNanos > $availableNanos) {
                throw new InsufficientWalletBalanceException();
            }

            $this->reserveFreeAllowance(
                $usage,
                $feature,
                $allocation['free_units'],
            );

            if ($amountNanos > 0) {
                $lockedUser->increment('wallet_reserved_nanos', $amountNanos);
            }

            if ($existing && $existing->status === 'released') {
                $existing->forceFill([
                    'status' => 'authorized',
                    'feature' => $feature,
                    'requested_units' => $units,
                    'free_units' => $allocation['free_units'],
                    'paid_units' => $allocation['paid_units'],
                    'rate_nanos' => $rateNanos,
                    'authorized_amount_nanos' => $amountNanos,
                    'captured_amount_nanos' => 0,
                    'authorization_attempts' => (int) $existing->authorization_attempts + 1,
                    'metadata' => $metadata,
                    'result_payload' => null,
                    'authorized_at' => now(),
                    'captured_at' => null,
                    'released_at' => null,
                ])->save();

                return $existing->fresh();
            }

            return BillingOperation::query()->create([
                'id' => (string) Str::uuid(),
                'user_id' => $lockedUser->id,
                'api_id' => $api?->id,
                'feature' => $feature,
                'status' => 'authorized',
                'idempotency_key' => $idempotencyKey,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId === null ? null : (string) $subjectId,
                'requested_units' => $units,
                'free_units' => $allocation['free_units'],
                'paid_units' => $allocation['paid_units'],
                'rate_nanos' => $rateNanos,
                'authorized_amount_nanos' => $amountNanos,
                'captured_amount_nanos' => 0,
                'currency' => 'PHP',
                'authorization_attempts' => 1,
                'metadata' => $metadata,
                'authorized_at' => now(),
            ]);
        });
    }

    public function charge(
        BillingOperation|string $operation,
        ?array $resultPayload = null,
    ): BillingOperation {
        $operationId = $operation instanceof BillingOperation
            ? $operation->id
            : $operation;

        return DB::transaction(function () use ($operationId, $resultPayload): BillingOperation {
            $lockedOperation = BillingOperation::query()
                ->whereKey($operationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOperation->status === 'captured') {
                return $lockedOperation;
            }

            if ($lockedOperation->status !== 'authorized') {
                throw new RuntimeException('Only an authorized billing operation can be charged.');
            }

            $lockedUser = User::query()
                ->whereKey($lockedOperation->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $usage = UsageRecord::query()
                ->where('user_id', $lockedUser->id)
                ->where('period', Carbon::now()->toDateString())
                ->lockForUpdate()
                ->firstOrFail();

            $amountNanos = (int) $lockedOperation->authorized_amount_nanos;

            if ((int) $lockedUser->wallet_reserved_nanos < $amountNanos) {
                throw new RuntimeException('Reserved wallet amount is inconsistent.');
            }

            if ((int) $lockedUser->wallet_balance_nanos < $amountNanos) {
                throw new RuntimeException('Wallet balance is lower than its authorized charge.');
            }

            $this->captureUsage($usage, $lockedOperation);

            if ($amountNanos > 0) {
                $lockedUser->decrement('wallet_reserved_nanos', $amountNanos);
                $lockedUser->decrement('wallet_balance_nanos', $amountNanos);
                $lockedUser->increment('total_spent_nanos', $amountNanos);

                WalletLedgerEntry::query()->firstOrCreate(
                    ['idempotency_key' => 'capture:'.$lockedOperation->id],
                    [
                        'id' => (string) Str::uuid(),
                        'user_id' => $lockedUser->id,
                        'billing_operation_id' => $lockedOperation->id,
                        'direction' => 'debit',
                        'type' => $lockedOperation->feature,
                        'amount_nanos' => $amountNanos,
                        'balance_after_nanos' => (int) $lockedUser->fresh()->wallet_balance_nanos,
                        'currency' => 'PHP',
                        'metadata' => [
                            'requested_units' => (int) $lockedOperation->requested_units,
                            'free_units' => (int) $lockedOperation->free_units,
                            'paid_units' => (int) $lockedOperation->paid_units,
                        ],
                    ],
                );
            }

            $lockedOperation->forceFill([
                'status' => 'captured',
                'captured_amount_nanos' => $amountNanos,
                'result_payload' => $resultPayload,
                'captured_at' => now(),
                'released_at' => null,
            ])->save();

            return $lockedOperation->fresh();
        });
    }

    public function release(
        BillingOperation|string $operation,
        ?string $reason = null,
    ): BillingOperation {
        $operationId = $operation instanceof BillingOperation
            ? $operation->id
            : $operation;

        return DB::transaction(function () use ($operationId, $reason): BillingOperation {
            $lockedOperation = BillingOperation::query()
                ->whereKey($operationId)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedOperation->status, ['released', 'captured'], true)) {
                return $lockedOperation;
            }

            $lockedUser = User::query()
                ->whereKey($lockedOperation->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $usage = UsageRecord::query()
                ->where('user_id', $lockedUser->id)
                ->where('period', Carbon::now()->toDateString())
                ->lockForUpdate()
                ->firstOrFail();

            $this->releaseUsageReservation($usage, $lockedOperation);

            $amountNanos = (int) $lockedOperation->authorized_amount_nanos;

            if ($amountNanos > 0) {
                if ((int) $lockedUser->wallet_reserved_nanos < $amountNanos) {
                    throw new RuntimeException('Reserved wallet amount is inconsistent.');
                }

                $lockedUser->decrement('wallet_reserved_nanos', $amountNanos);
            }

            $metadata = is_array($lockedOperation->metadata)
                ? $lockedOperation->metadata
                : [];

            $metadata['release_reason'] = $reason;

            $lockedOperation->forceFill([
                'status' => 'released',
                'metadata' => $metadata,
                'released_at' => now(),
            ])->save();

            return $lockedOperation->fresh();
        });
    }

    public function creditWallet(
        User $user,
        int $amountNanos,
        string $idempotencyKey,
        ?BillingTransaction $transaction = null,
        array $metadata = [],
    ): WalletLedgerEntry {
        if ($amountNanos <= 0) {
            throw new InvalidArgumentException('Wallet credit must be greater than zero.');
        }

        return DB::transaction(function () use (
            $user,
            $amountNanos,
            $idempotencyKey,
            $transaction,
            $metadata,
        ): WalletLedgerEntry {
            $existing = WalletLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedUser->increment('wallet_balance_nanos', $amountNanos);
            $lockedUser->increment('total_earned_nanos', $amountNanos);
            $lockedUser->refresh();

            return WalletLedgerEntry::query()->create([
                'id' => (string) Str::uuid(),
                'user_id' => $lockedUser->id,
                'billing_transaction_id' => $transaction?->id,
                'direction' => 'credit',
                'type' => 'wallet_topup',
                'amount_nanos' => $amountNanos,
                'balance_after_nanos' => (int) $lockedUser->wallet_balance_nanos,
                'currency' => 'PHP',
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata,
            ]);
        });
    }

    private function validateFeature(string $feature): void
    {
        if (! in_array($feature, ['upload', 'live', 'polish', 'summarize'], true)) {
            throw new InvalidArgumentException('Unknown billable feature.');
        }
    }

    private function costNanos(string $feature, int $paidUnits, string $rate): int
    {
        return match ($feature) {
            'upload', 'live' => Money::audioCostNanos($paidUnits, $rate),
            'polish', 'summarize' => Money::textCostNanos($paidUnits, $rate),
            default => throw new InvalidArgumentException('Unknown billable feature.'),
        };
    }

    private function allocation(UsageRecord $usage, string $feature, int $units): array
    {
        $freePlan = $this->plans->plan('free') ?? [];

        if (in_array($feature, ['upload', 'live'], true)) {
            $limit = max(0, (int) ($freePlan['minutes'] ?? 0) * 60);
            $consumed = min($limit, (int) $usage->seconds_transcribed);
            $remaining = max(
                0,
                $limit - $consumed - (int) $usage->free_seconds_reserved,
            );
            $freeUnits = min($units, $remaining);

            return [
                'free_units' => $freeUnits,
                'paid_units' => $units - $freeUnits,
            ];
        }

        $limit = $feature === 'polish'
            ? max(0, (int) ($freePlan['free_polish_uses_per_day'] ?? 0))
            : max(0, (int) ($freePlan['free_summary_uses_per_day'] ?? 0));

        $completed = $feature === 'polish'
            ? min($limit, (int) $usage->polish_count)
            : min($limit, (int) $usage->summary_count);

        $reserved = $feature === 'polish'
            ? (int) $usage->free_polish_reserved
            : (int) $usage->free_summary_reserved;

        $hasFreeAction = ($limit - $completed - $reserved) > 0;

        return [
            'free_units' => $hasFreeAction ? 1 : 0,
            'paid_units' => $hasFreeAction ? 0 : $units,
        ];
    }

    private function reserveFreeAllowance(
        UsageRecord $usage,
        string $feature,
        int $freeUnits,
    ): void {
        if ($freeUnits <= 0) {
            return;
        }

        match ($feature) {
            'upload', 'live' => $usage->increment('free_seconds_reserved', $freeUnits),
            'polish' => $usage->increment('free_polish_reserved'),
            'summarize' => $usage->increment('free_summary_reserved'),
            default => null,
        };
    }

    private function captureUsage(
        UsageRecord $usage,
        BillingOperation $operation,
    ): void {
        $feature = $operation->feature;
        $freeUnits = (int) $operation->free_units;

        if (in_array($feature, ['upload', 'live'], true)) {
            if ($freeUnits > 0) {
                $usage->decrement('free_seconds_reserved', $freeUnits);
            }

            $usage->increment('seconds_transcribed', (int) $operation->requested_units);

            return;
        }

        if ($feature === 'polish') {
            if ($freeUnits > 0) {
                $usage->decrement('free_polish_reserved');
            }

            $usage->increment('polish_count');

            return;
        }

        if ($feature === 'summarize') {
            if ($freeUnits > 0) {
                $usage->decrement('free_summary_reserved');
            }

            $usage->increment('summary_count');
        }
    }

    private function releaseUsageReservation(
        UsageRecord $usage,
        BillingOperation $operation,
    ): void {
        $freeUnits = (int) $operation->free_units;

        if ($freeUnits <= 0) {
            return;
        }

        match ($operation->feature) {
            'upload', 'live' => $usage->decrement('free_seconds_reserved', $freeUnits),
            'polish' => $usage->decrement('free_polish_reserved'),
            'summarize' => $usage->decrement('free_summary_reserved'),
            default => null,
        };
    }
}
```

Before finalizing, add explicit underflow checks before every reservation decrement.

---

## 9. Fix the insufficient-balance exception

Use HTTP `402`, not `422`.

```php
<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class InsufficientWalletBalanceException extends RuntimeException
{
    public function __construct(?string $message = null, ?Throwable $previous = null)
    {
        parent::__construct(
            $message ?? 'Insufficient wallet balance. Please add funds to continue.',
            402,
            $previous,
        );
    }
}
```

Add exception rendering in Laravel bootstrap or the exception handler so API requests receive:

```json
{
  "message": "Insufficient wallet balance. Please add funds to continue.",
  "upgrade": true
}
```

with status `402`.

---

## 10. Audio duration must be verified before authorization

Create:

```text
app/Services/AudioDurationService.php
```

Use `ffprobe`.

Do not use transcript text to decide duration.

Do not trust only a client-provided `duration_seconds` value for paid external API requests.

```php
<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\Process\Process;

final class AudioDurationService
{
    public function secondsForUpload(UploadedFile|string $audio): int
    {
        $path = $audio instanceof UploadedFile
            ? $audio->getRealPath()
            : $audio;

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new RuntimeException('Audio file could not be read.');
        }

        $binary = (string) config('services.ffprobe.binary', 'ffprobe');

        $process = new Process([
            $binary,
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);

        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Audio duration could not be determined.');
        }

        $duration = (float) trim($process->getOutput());

        if (! is_finite($duration) || $duration <= 0) {
            throw new RuntimeException('Audio duration is invalid.');
        }

        return (int) ceil($duration);
    }

    public function totalSeconds(array $files): int
    {
        $seconds = 0;

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile && ! is_string($file)) {
                throw new RuntimeException('Audio file is invalid.');
            }

            $seconds += $this->secondsForUpload($file);
        }

        if ($seconds <= 0) {
            throw new RuntimeException('Audio duration could not be determined.');
        }

        return $seconds;
    }
}
```

Add:

```php
'ffprobe' => [
    'binary' => env('FFPROBE_BINARY', 'ffprobe'),
],
```

to `config/services.php`.

---

## 11. API request idempotency

### 11.1 Add request fields

`POST /api/transcribe`:

```text
request_id
billing_feature
```

Validation:

```php
'request_id' => ['required', 'string', 'max:100'],
'billing_feature' => ['nullable', 'string', 'in:upload,live'],
```

`POST /api/polish`:

```text
request_id
task
```

Validation:

```php
'request_id' => ['required', 'string', 'max:100'],
'task' => ['nullable', 'string', 'in:polish,summarize'],
```

### 11.2 Idempotency keys

Transcription sync:

```text
api:{api_id}:transcribe:{request_id}
```

Transcription async:

```text
api:{api_id}:transcribe-job:{request_id}
```

Polish:

```text
api:{api_id}:polish:{request_id}
```

Summarize:

```text
api:{api_id}:summarize:{request_id}
```

### 11.3 Duplicate behavior

If an operation is already captured and has `result_payload`:

```text
return the stored result
do not call the provider
do not charge again
```

If an async job already exists for the same `api_id + request_id`:

```text
return the existing job ID and status
do not create another job
do not reserve again
```

If an operation is currently authorized:

```text
return HTTP 409 with "Request is already processing."
```

If an operation was released after a failed attempt:

```text
allow the same request ID to reauthorize
increment authorization_attempts
do not retain an old wallet reservation
```

---

## 12. Integrate billing into `Api\TranscriptionController`

### 12.1 Resolve the billed user

After license validation:

```php
$user = $license->user()->first();

if (! $user instanceof User) {
    return response()->json(['message' => 'License owner was not found.'], 403);
}
```

### 12.2 Synchronous transcription flow

Required order:

```text
validate request
normalize files
verify duration with ffprobe
check provider availability
find an existing idempotent result
authorize billing
execute provider pipeline
build successful response payload
charge with result payload
return response
```

Failure order:

```text
catch provider/pipeline exception
release billing operation
return failure
```

Do not release a captured operation.

Example structure:

```php
$operation = null;

try {
    $durationSeconds = $audioDuration->totalSeconds($files);
    $feature = (string) ($validated['billing_feature'] ?? 'upload');
    $idempotencyKey = "api:{$license->id}:transcribe:{$validated['request_id']}";

    $existing = BillingOperation::query()
        ->where('idempotency_key', $idempotencyKey)
        ->first();

    if ($existing?->status === 'captured' && is_array($existing->result_payload)) {
        return response()->json($existing->result_payload);
    }

    if ($existing?->status === 'authorized') {
        return response()->json(['message' => 'Request is already processing.'], 409);
    }

    $operation = $billing->authorize(
        user: $user,
        feature: $feature,
        units: $durationSeconds,
        idempotencyKey: $idempotencyKey,
        api: $license,
        subjectType: 'api_transcription',
        subjectId: $validated['request_id'],
        metadata: [
            'response_mode' => 'sync',
            'duration_seconds' => $durationSeconds,
        ],
    );

    $responsePayload = $this->performSuccessfulTranscription(...);

    $billing->charge($operation, $responsePayload);

    return response()->json($responsePayload);
} catch (InsufficientWalletBalanceException $exception) {
    return response()->json([
        'message' => $exception->getMessage(),
        'upgrade' => true,
    ], 402);
} catch (Throwable $exception) {
    if ($operation) {
        $billing->release($operation, $exception->getMessage());
    }

    return $this->providerFailureResponse(...);
}
```

A response with empty `text` is still charged when the provider completed successfully.

### 12.3 Async transcription creation

Before creating a new job:

```text
check existing job by api_id + request_id
verify audio duration
authorize billing
create job with billing_operation_id
dispatch or submit provider job
```

If job creation or provider submission fails:

```text
release billing operation
delete stored audio
mark/create failure response
```

Store:

```php
'billing_operation_id' => $operation->id,
'billing_feature' => $feature,
'duration_seconds' => $durationSeconds,
'request_id' => $validated['request_id'],
```

### 12.4 Centralize async finalization

Create methods:

```text
finalizeApiJobSuccess()
finalizeApiJobFailure()
finalizeApiJobCancellation()
```

Every existing async success path must call `finalizeApiJobSuccess()`.

Every final failed path must call `finalizeApiJobFailure()`.

Do not directly set `status = completed` elsewhere.

Success finalizer:

```php
private function finalizeApiJobSuccess(
    ApiTranscriptionJob $job,
    array $resultPayload,
): void {
    DB::transaction(function () use ($job, $resultPayload): void {
        $lockedJob = ApiTranscriptionJob::query()
            ->whereKey($job->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($lockedJob->status === 'completed') {
            return;
        }

        if ($lockedJob->status === 'cancelled') {
            if ($lockedJob->billing_operation_id) {
                app(BillingService::class)->release(
                    $lockedJob->billing_operation_id,
                    'Job was cancelled before successful finalization.',
                );
            }

            return;
        }

        if (! in_array($lockedJob->status, ['queued', 'processing'], true)) {
            return;
        }

        $lockedJob->forceFill([
            'result_payload' => $resultPayload,
            'error_message' => null,
            'status_code' => 200,
        ])->save();

        app(BillingService::class)->charge(
            $lockedJob->billing_operation_id,
            $resultPayload,
        );

        $lockedJob->forceFill([
            'status' => 'completed',
            'finished_at' => now(),
        ])->save();
    });
}
```

Failure finalizer:

```php
private function finalizeApiJobFailure(
    ApiTranscriptionJob $job,
    string $message,
    int $statusCode,
): void {
    DB::transaction(function () use ($job, $message, $statusCode): void {
        $lockedJob = ApiTranscriptionJob::query()
            ->whereKey($job->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (in_array($lockedJob->status, ['completed', 'cancelled', 'failed'], true)) {
            return;
        }

        if ($lockedJob->billing_operation_id) {
            app(BillingService::class)->release(
                $lockedJob->billing_operation_id,
                $message,
            );
        }

        $lockedJob->forceFill([
            'status' => 'failed',
            'result_payload' => null,
            'error_message' => $message,
            'status_code' => max(400, $statusCode),
            'finished_at' => now(),
        ])->save();
    });
}
```

The success finalizer must be used in:

```text
processAsyncTranscriptionJob()
refreshAsyncTranscriptionJob() when RunPod returns COMPLETED
completeAsyncTranscriptionWithFallback() when fallback succeeds
```

The failure finalizer must be used in:

```text
queue job failed()
RunPod FAILED/CANCELLED/TIMED_OUT when fallback also fails
all-provider failure
missing license
missing audio
unhandled terminal exception
```

### 12.5 Async cancellation endpoint

Add:

```text
POST /api/transcribe/jobs/{job}/cancel
```

The endpoint must authenticate by license and verify job ownership.

```php
public function cancelTranscriptionJob(
    Request $request,
    string $job,
    BillingService $billing,
): JsonResponse {
    $license = $this->licenseFor($request, 'post');

    if ($license instanceof JsonResponse) {
        return $license;
    }

    return DB::transaction(function () use ($license, $job, $billing): JsonResponse {
        $lockedJob = ApiTranscriptionJob::query()
            ->whereKey($job)
            ->where('api_id', $license->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedJob) {
            return response()->json(['message' => 'Transcription job was not found.'], 404);
        }

        if ($lockedJob->status === 'completed') {
            return response()->json([
                'message' => 'The transcription already completed and was billed.',
            ], 409);
        }

        if ($lockedJob->status === 'cancelled') {
            return response()->json([
                'message' => 'Transcription job is already cancelled.',
                'status' => 'cancelled',
            ]);
        }

        if ($lockedJob->billing_operation_id) {
            $billing->release(
                $lockedJob->billing_operation_id,
                'User cancelled the transcription.',
            );
        }

        $lockedJob->forceFill([
            'status' => 'cancelled',
            'result_payload' => null,
            'error_message' => null,
            'status_code' => 200,
            'finished_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Transcription job was cancelled.',
            'status' => 'cancelled',
        ]);
    });
}
```

If the provider supports remote cancellation, call it after the local state is safely marked cancelled.

Remote provider cancellation failure must not restore the local billable state.

### 12.6 Status endpoint

Return `cancelled` explicitly.

Do not call refresh logic for a cancelled job.

Repeated polling of completed jobs must not call `charge()` again.

---

## 13. Integrate billing into `POST /api/polish`

The endpoint supports both tasks.

Canonical mapping:

```php
$feature = ($validated['task'] ?? 'polish') === 'summarize'
    ? 'summarize'
    : 'polish';
```

Calculate source characters before provider execution.

For chunks, sum the source text from all normalized chunks.

Do not bill output characters.

Required order:

```text
validate
resolve source text
count source characters
check existing idempotent result
authorize
execute provider
validate successful result
charge with result payload
return
```

On provider failure:

```text
release
return failure
```

Example:

```php
$sourceCharacters = mb_strlen($sourceText);
$idempotencyKey = "api:{$license->id}:{$feature}:{$validated['request_id']}";

$existing = BillingOperation::query()
    ->where('idempotency_key', $idempotencyKey)
    ->first();

if ($existing?->status === 'captured' && is_array($existing->result_payload)) {
    return response()->json($existing->result_payload);
}

$operation = $billing->authorize(
    user: $user,
    feature: $feature,
    units: $sourceCharacters,
    idempotencyKey: $idempotencyKey,
    api: $license,
    subjectType: 'api_text_action',
    subjectId: $validated['request_id'],
    metadata: [
        'source_characters' => $sourceCharacters,
    ],
);

try {
    $resultPayload = $this->performTextAction(...);

    if (! $this->isValidSuccessfulTextResult($resultPayload)) {
        throw new RuntimeException('Text action did not return a valid result.');
    }

    $billing->charge($operation, $resultPayload);

    return response()->json($resultPayload);
} catch (Throwable $exception) {
    $billing->release($operation, $exception->getMessage());

    return response()->json([
        'message' => 'All configured text-fixer providers are unavailable.',
    ], 503);
}
```

---

## 14. Web application changes

### 14.1 Remove unreliable affordability gates

Remove `can.transcribe` middleware from:

```text
workspace upload
workspace chunk
```

The middleware checks one second before the actual duration is known and uses upload pricing for live requests.

The API authorization is the authoritative gate.

The web controller may show a quote, but it must not be trusted as the final wallet check.

### 14.2 Remove `canAfford()` calls

Remove the new `canAfford()` calls from:

```text
app/Http/Controllers/Web/TranscriptionController.php
app/Http/Controllers/Web/TranscriptActionController.php
```

Handle an API `402` response instead.

### 14.3 Update `WebApiTranscriptionClient`

Add stable request IDs and billing feature.

Queue signature:

```php
public function queue(
    User $user,
    array $clips,
    ?string $languageCode,
    string $requestId,
    string $billingFeature,
): array
```

Polish signature:

```php
public function polish(
    User $user,
    string $text,
    array $chunks,
    string $instruction,
    string $task,
    string $requestId,
): array
```

Send:

```text
request_id
billing_feature
task
```

If the API returns `402`, throw `InsufficientWalletBalanceException`.

Do not replace it with a generic processing error.

Add:

```php
public function cancelJob(User $user, string $jobId): array
```

calling:

```text
POST /api/transcribe/jobs/{job}/cancel
```

### 14.4 Generate stable request IDs

When a transcript is queued:

```php
'billing_request_id' => (string) Str::uuid(),
```

Use that UUID for all retries of the same transcription.

When polish is requested:

```php
$polishRequestId = (string) Str::uuid();

$transcript->forceFill([
    'polish_request_id' => $polishRequestId,
    'polish_status' => 'processing',
])->save();

ProcessWebPolishJob::dispatchAfterResponse(
    $transcript->id,
    $instruction,
    $polishRequestId,
);
```

When summarize is requested:

```php
$summaryRequestId = (string) Str::uuid();

$transcript->forceFill([
    'summary_request_id' => $summaryRequestId,
    'summary_status' => 'processing',
])->save();

ProcessWebSummarizeJob::dispatchAfterResponse(
    $transcript->id,
    $source,
    $summaryRequestId,
);
```

A job retry must reuse the same ID.

A new user-requested polish or summary gets a new ID.

### 14.5 Remove charging from `WebTranscriptProcessor`

Delete:

```text
recordUsage()
charge() after polish
charge() after summarize
```

The API layer already owns billing.

Do not charge twice.

### 14.6 Keep successful empty transcription

`persistTranscriptionResult()` may store:

```php
'raw_text' => '',
```

and still mark the transcript completed when the API job completed successfully.

Do not fail only because text is empty.

The duration remains billable.

### 14.7 Fix web cancellation

Current local cancellation must call API cancellation.

Order:

```text
find API job ID
call API cancel
if API returns completed/409, do not mark local cancelled
if API confirms cancelled, mark local transcript cancelled
```

Example behavior:

```php
if ($apiJobId !== '') {
    $result = $client->cancelJob($request->user(), $apiJobId);

    if (($result['status'] ?? null) !== 'cancelled') {
        return response()->json([
            'message' => 'The transcription already completed and was billed.',
        ], 409);
    }
}

$processor->appendLog($transcript, 'cancelled', 'Cancelled');
```

---

## 15. Fix checkout and PayMongo

### 15.1 Keep one webhook

Keep:

```text
POST /paymongo/webhook
app/Http/Controllers/PayMongoWebhookController.php
```

Remove:

```text
POST /api/credits/webhook
app/Http/Controllers/Api/PaymentController.php
app/Services/Billing/PaymentService.php
```

Also remove unused package routes, services, imports, and SDK configuration that belong only to the old implementation.

### 15.2 Use PHP

Checkout line item:

```php
'currency' => 'PHP',
```

Billing transaction:

```php
'currency' => 'PHP',
```

UI:

```text
₱
```

Do not divide prices by 100 when the price manager stores major PHP values.

### 15.3 Validate top-up in major PHP input

Use a string input with up to two decimals.

Example request:

```json
{
  "amount": "500.00"
}
```

Convert to centavos without float arithmetic.

Create helper:

```php
public static function majorToMinor(string $amount): int
{
    $value = trim($amount);

    if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
        throw new InvalidArgumentException('Invalid payment amount.');
    }

    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $fraction = str_pad($fraction, 2, '0');

    return ((int) $whole * 100) + (int) $fraction;
}
```

Use a configured minimum, such as:

```text
₱100.00
```

Do not use the current USD `$1.00` validation.

### 15.4 Fix checkout closure scope

```php
use Illuminate\Support\Facades\DB;
```

```php
$checkout = DB::transaction(function () use (
    $user,
    $amountMinor,
    $payMongo,
): array {
    $transaction = BillingTransaction::query()->create([
        'user_id' => $user->id,
        'provider' => 'paymongo',
        'plan' => 'wallet_topup',
        'reference' => 'JERVA-'.$user->id.'-'.Str::upper(Str::random(12)),
        'status' => 'pending',
        'amount_minor' => $amountMinor,
        'wallet_credit_nanos' => Money::minorToNanos($amountMinor),
        'currency' => 'PHP',
    ]);

    try {
        $checkout = $payMongo->createWalletTopupCheckout(
            $user,
            $amountMinor,
            $transaction,
        );
    } catch (Throwable $exception) {
        $transaction->forceFill([
            'status' => 'failed',
            'payload' => ['error' => 'Checkout could not be started.'],
        ])->save();

        throw $exception;
    }

    $transaction->forceFill([
        'checkout_session_id' => $checkout['session_id'],
        'checkout_url' => $checkout['checkout_url'],
        'payload' => $checkout['payload'],
        'status' => 'checkout_created',
    ])->save();

    return $checkout;
});

return redirect()->away($checkout['checkout_url']);
```

### 15.5 Remove old checkout methods

Delete from `PayMongoCheckoutService`:

```text
createCheckoutSession()
amountFor()
isConfiguredFor()
characterAmount()
descriptionFor()
```

Keep only wallet top-up checkout.

Remove all metadata related to:

```text
credit_type
credit_minutes
polish_characters
summary_characters
```

Metadata should be:

```php
'metadata' => [
    'user_id' => (string) $user->id,
    'billing_transaction_id' => (string) $transaction->id,
    'wallet_topup_amount_minor' => (string) $amountMinor,
],
```

### 15.6 Webhook must verify and lock

Webhook flow:

```text
verify signature
ignore unsupported events
extract provider event ID
locate transaction using metadata transaction ID first
lock transaction
if already paid, return 200
verify currency and paid amount
lock user
credit wallet through BillingService
mark transaction paid
commit
```

Do not directly call:

```php
$user->increment('wallet_balance', ...)
```

Example:

```php
public function __invoke(
    Request $request,
    BillingService $billing,
): JsonResponse {
    if (! $this->hasValidSignature($request)) {
        return response()->json(['message' => 'Invalid signature.'], 401);
    }

    $payload = $request->json()->all();
    $eventType = (string) data_get($payload, 'data.attributes.type', '');

    if (! in_array($eventType, [
        'checkout_session.payment.paid',
        'link.payment.paid',
    ], true)) {
        return response()->json(['message' => 'Ignored.']);
    }

    $eventId = (string) data_get($payload, 'data.id', '');
    $resource = data_get($payload, 'data.attributes.data', []);
    $transactionId = data_get(
        $resource,
        'attributes.metadata.billing_transaction_id',
    );

    if (! is_numeric($transactionId)) {
        return response()->json(['message' => 'Transaction metadata is missing.'], 422);
    }

    return DB::transaction(function () use (
        $payload,
        $eventId,
        $resource,
        $transactionId,
        $billing,
    ): JsonResponse {
        $transaction = BillingTransaction::query()
            ->whereKey((int) $transactionId)
            ->lockForUpdate()
            ->first();

        if (! $transaction) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        if ($transaction->status === 'paid') {
            return response()->json(['message' => 'Payment already recorded.']);
        }

        $currency = strtoupper((string) data_get(
            $resource,
            'attributes.line_items.0.currency',
            data_get($resource, 'attributes.currency', ''),
        ));

        if ($currency !== 'PHP' || $transaction->currency !== 'PHP') {
            return response()->json(['message' => 'Payment currency does not match.'], 422);
        }

        $amountNanos = (int) $transaction->wallet_credit_nanos;

        $billing->creditWallet(
            user: $transaction->user()->firstOrFail(),
            amountNanos: $amountNanos,
            idempotencyKey: 'paymongo:'.$transaction->id,
            transaction: $transaction,
            metadata: [
                'provider_event_id' => $eventId,
            ],
        );

        $transaction->forceFill([
            'provider_event_id' => $eventId !== '' ? $eventId : null,
            'status' => 'paid',
            'payload' => $payload,
            'paid_at' => now(),
        ])->save();

        return response()->json(['message' => 'Payment recorded.']);
    });
}
```

Validate the exact PayMongo payload amount path against a real test webhook and compare it with `amount_minor`.

Do not trust metadata alone for the paid amount.

---

## 16. Billing page

### 16.1 Backend props

Return already formatted values:

```php
'wallet' => [
    'balance_nanos' => (int) $user->wallet_balance_nanos,
    'reserved_nanos' => (int) $user->wallet_reserved_nanos,
    'available_nanos' => max(
        0,
        (int) $user->wallet_balance_nanos
        - (int) $user->wallet_reserved_nanos,
    ),
    'balance_formatted' => Money::formatNanos((int) $user->wallet_balance_nanos),
    'available_formatted' => Money::formatNanos(max(
        0,
        (int) $user->wallet_balance_nanos
        - (int) $user->wallet_reserved_nanos,
    )),
],
```

### 16.2 Remove cent conversion

Delete UI logic like:

```typescript
balance / 100
upload_price_per_hour / 100
live_price_per_hour / 100
```

Plan rates are major PHP values.

Display:

```text
₱190.00/hour
₱240.00/hour
₱0.20/1,000 characters
```

For text display:

```typescript
const perThousand = Number(ratePerCharacter) * 1000;
```

This display conversion is not used for billing arithmetic.

### 16.3 Fix top-up form

Do not use a `Link` POST without an amount.

Use the form submit button.

Recommended presets:

```text
₱100
₱250
₱500
₱1,000
```

Post a decimal string.

Example:

```typescript
const form = useForm({
    amount: '500.00',
});
```

### 16.4 Show reserved balance

When a request is processing:

```text
Balance: ₱500.00
Reserved: ₱25.00
Available: ₱475.00
```

This makes pending async work understandable.

---

## 17. Pricing manager cleanup

Remove all references and labels for:

```text
credit packs
audio minutes included for PAYG
text character credit packs
buy minutes
```

The free tier may use `minutes` as its daily allowance.

The PAYG `minutes` field should be `0` and should not define a purchased pack.

Update page copy:

```text
Free tier sets daily allowances.
Pay-as-you-go sets usage rates.
```

Do not call the PAYG card a credit pack.

---

## 18. Legacy data reconciliation

The current `wallet_balance` cannot be safely converted with one global multiplier.

### 18.1 Development or test database

When there are no real paid users:

```text
run a database backup
run migrate:fresh
seed plan rates
seed a known nanos wallet for testing
```

This is the safest path.

### 18.2 Production or real payment data

Do not drop old wallet fields yet.

Create an Artisan command:

```text
php artisan billing:reconcile-wallets
```

It must produce a CSV or JSON report containing:

```text
user ID
old wallet_balance
old total earned/spent
paid BillingTransaction rows
currency
amount
status
webhook payload type
legacy credit fields if still present
proposed wallet_balance_nanos
reason for proposal
manual review required flag
```

Rules:

1. New wallet top-up transaction stored in minor units:
   `wallet_credit_nanos = amount_minor × 10,000,000`.
2. Legacy per-character credits:
   `characters × rate_per_character`.
3. Legacy audio seconds:
   `seconds × rate_per_hour ÷ 3,600`.
4. Hardcoded old abstract credit packages cannot be automatically treated as PHP without an explicit business rule.
5. A user with mixed source types requires manual review.
6. Do not delete old fields until the report is approved and imported.

### 18.3 Correct the old backfill formula

If legacy fields still exist, the text formulas are:

```php
$polishNanos = $user->polish_credit_characters
    * Money::decimalToNanos((string) $paygPlan->polish_price_per_character);

$summaryNanos = $user->summary_credit_characters
    * Money::decimalToNanos((string) $paygPlan->summary_price_per_character);
```

Do not divide by 1,000.

---

## 19. Cleanup list

After the new system passes tests, remove:

```text
app/Http/Controllers/Api/PaymentController.php
app/Services/Billing/PaymentService.php
POST /api/credits/webhook
old credit package API routes
old credit package frontend code
old per-feature checkout code
old per-feature metadata
EntitlementService old credit methods
users.credit_seconds
users.polish_credit_characters
users.summary_credit_characters
users.wallet_balance
users.total_earned_credits
users.total_spent_credits
plan_tiers.price_per_second
plan_tiers.polish_characters
plan_tiers.summary_characters
config plan keys for those fields
PlanTier model references
PlanService references
User model references
```

Do not drop ambiguous old wallet columns before reconciliation.

---

## 20. Required tests

Create dedicated billing tests.

### 20.1 Pricing schema tests

1. Saving pricing does not reference `price_per_second`.
2. `PlanTier` does not expose removed fields.
3. `PlanService` returns decimal strings.
4. Cache is cleared after pricing update.

### 20.2 Money tests

At `₱190/hour`:

```text
60 seconds = 3,166,666,667 nanos
3,600 seconds = 190,000,000,000 nanos
0 seconds = 0 nanos
```

At `₱0.0002/character`:

```text
1 character = 200,000 nanos
1,000 characters = 200,000,000 nanos
```

### 20.3 Authorization tests

1. Free-only audio reserves free seconds and no wallet.
2. Paid-only audio reserves wallet.
3. Part-free audio reserves the correct split.
4. Free polish reserves one action.
5. Paid polish reserves wallet based on source characters.
6. Insufficient wallet returns `402`.
7. Concurrent authorizations cannot overuse wallet balance.
8. Concurrent authorizations cannot overuse free allowance.

### 20.4 Charge tests

1. Charge debits wallet exactly once.
2. Repeated charge returns the captured operation without another debit.
3. Charge consumes reserved free allowance.
4. Charge increments usage totals.
5. Charge creates one ledger entry.
6. Charge stores the result payload.

### 20.5 Release tests

1. Release does not debit wallet.
2. Release removes wallet reservation.
3. Release removes free reservation.
4. Repeated release is harmless.
5. Release after capture does not refund or change the captured result.

### 20.6 Synchronous transcription tests

1. Successful transcription with text charges.
2. Successful transcription with empty text charges verified duration.
3. Provider failure releases and does not charge.
4. All providers unavailable does not charge.
5. Invalid audio duration fails before provider execution and does not charge.
6. Duplicate request ID returns stored result and one charge.
7. Upload uses upload rate.
8. Live uses live rate.

### 20.7 Async transcription tests

1. Job creation authorizes but does not charge.
2. Completed job charges exactly once.
3. Repeated status polling does not charge again.
4. Failed job releases.
5. RunPod failed plus failed fallback releases.
6. RunPod completed charges.
7. Cancel before completion releases and does not charge.
8. Completion before cancel charges and cancel returns `409`.
9. Queue retry does not create another operation.
10. Worker `failed()` calls the failure finalizer.

### 20.8 Polish and summarize tests

1. First free polish succeeds without wallet debit.
2. First free summarize succeeds without wallet debit.
3. Failed free action releases the reservation and does not consume the free use.
4. Paid polish bills source characters.
5. Paid summarize bills source characters.
6. `summarize` uses the correct counter and rate.
7. Retry with the same request ID returns the stored result and does not charge twice.
8. A new user-requested action with a new request ID creates a new charge.

### 20.9 PayMongo tests

1. Checkout uses PHP.
2. Checkout stores `amount_minor`.
3. Checkout closure returns the checkout result.
4. Missing `DB` import is fixed.
5. Valid webhook credits nanos.
6. Duplicate webhook credits once.
7. Two concurrent webhook deliveries credit once.
8. Amount mismatch is rejected.
9. Currency mismatch is rejected.
10. Invalid signature is rejected.
11. Unsupported event is ignored.
12. Old `/api/credits/webhook` route no longer exists.

### 20.10 Web integration tests

1. Web upload sends stable request ID.
2. Web live sends `billing_feature=live`.
3. Web API `402` is shown as insufficient balance.
4. Web processor does not debit wallet.
5. Empty successful transcript becomes completed.
6. Web cancellation cancels the API job.
7. Web cancellation does not mark a completed billed transcript cancelled.
8. Polish job retry reuses the request ID.
9. Summary job retry reuses the request ID.

---

## 21. Implementation order

GLM must follow this exact order.

### Phase A: restore basic application correctness

1. Remove `price_per_second` from `DashboardPricingController`.
2. Remove deleted plan fields from `PlanTier`.
3. Remove deleted plan reads from `PlanService`.
4. Remove deleted user fields from `User`.
5. Remove old pool logic from `EntitlementService`.
6. Fix checkout import and closure return.
7. Confirm the pricing page can save.

### Phase B: precise wallet foundation

1. Add nanos wallet fields.
2. Add usage reservation fields.
3. Create billing operations.
4. Create wallet ledger.
5. Extend billing transactions.
6. Extend API jobs.
7. Add request IDs to transcripts.
8. Create models.
9. Create `Money`.
10. Create `BillingService`.
11. Add unit tests.

### Phase C: API billing

1. Add `request_id`.
2. Add `billing_feature`.
3. Verify audio duration.
4. Add sync authorization/capture/release.
5. Add async authorization.
6. Add centralized async success/failure finalizers.
7. Add cancellation endpoint.
8. Add text-action billing.
9. Add idempotent result return.
10. Add API tests.

### Phase D: web integration

1. Send request IDs.
2. Remove unreliable can-afford checks.
3. Propagate API `402`.
4. Remove web processor charging.
5. Add API cancellation call.
6. Keep empty successful transcription.
7. Update retryable jobs.
8. Add web tests.

### Phase E: PayMongo

1. Remove old payment controller and service.
2. Use PHP.
3. Use amount minor units.
4. Convert top-up to nanos.
5. Lock webhook processing.
6. Use ledger credit.
7. Add duplicate webhook tests.
8. Fix billing UI.

### Phase F: data reconciliation and cleanup

1. Back up the database.
2. Run reconciliation.
3. Verify balances.
4. Remove old wallet and credit fields.
5. Remove dead routes and code.
6. Run full test suite.
7. Deploy only after all billing tests pass.

---

## 22. Acceptance criteria

The repair is complete only when all of these are true:

```text
Pricing can be saved on SQLite without missing-column errors.
No production code references price_per_second.
No production code references legacy per-feature user credits.
There is one PayMongo webhook.
There is one wallet service.
All wallet mutations create ledger entries.
Money calculations do not use floats.
Desktop transcription is billed.
Web transcription is billed once through the API layer.
Async transcription is billed only on successful completion.
Empty successful transcription is billed by audio duration.
Failed transcription is not billed.
Cancelled transcription is not billed when cancellation wins first.
Repeated polling does not duplicate billing.
Retryable jobs do not duplicate billing.
Live and upload use different configured rates.
Polish and summarize use source characters.
Free allowance can be partly consumed before paid usage.
Concurrent requests cannot overspend wallet or free allowance.
PayMongo duplicate webhooks cannot double-credit.
The billing UI uses PHP and consistent units.
All required tests pass.
```

---

## 23. Commands to run after implementation

Use the repository's configured PHP and Node environments.

```bash
php artisan optimize:clear
php artisan migrate
php artisan route:list
php artisan test --filter=Billing
php artisan test --filter=Transcription
php artisan test --filter=PayMongo
php artisan test
npm run typecheck
npm run build
```

If the project does not define `typecheck`, use its existing TypeScript validation script.

Do not run `migrate:fresh` against real data.

---

## 24. Final warning to the implementing AI

Do not solve this by adding `price_per_second` back.

Do not solve this by changing `null` to `0`.

Do not keep both wallet systems.

Do not charge in both the web processor and API controller.

Do not determine billing success from whether transcript text is empty.

Do not debit before provider success.

Do not allow cancellation and completion to update the same job without row locking.

Do not use two-decimal floats for the wallet.

Do not modify only old migrations and assume an existing SQLite database will change.

Implement the forward migrations, billing operation state, reservations, idempotent finalizers, and tests described above.
