<?php

namespace App\Services;

use App\Models\BillingTransaction;
use App\Models\PageVisitDailyStat;
use App\Models\UsageRecord;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DashboardAnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function dashboard(int $days): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $start = now()->startOfDay()->subDays($days - 1);
        $accounts = $this->accountTotals();

        return [
            'days' => $days,
            'generated_at' => now()->toISOString(),
            'sales' => [
                'currency' => 'USD',
                'paid_topups_cents' => (int) ($accounts->paid_topups_cents ?? 0),
                'refundable_balance_cents' => (int) ($accounts->refundable_balance_cents ?? 0),
                'realized_credits_cents' => (int) ($accounts->realized_credits_cents ?? 0),
                'tracked_charges_cents' => (int) ($accounts->tracked_charges_cents ?? 0),
                'historical_untracked_cents' => (int) ($accounts->historical_untracked_cents ?? 0),
                'balance_excess_cents' => (int) ($accounts->balance_excess_cents ?? 0),
                'pending_topups_cents' => $this->pendingTopupsCents(),
                'paid_transactions' => (int) ($accounts->paid_transactions ?? 0),
            ],
            'users' => [
                'registered' => (int) ($accounts->registered_users ?? 0),
                'verified' => (int) ($accounts->verified_users ?? 0),
                'active' => (int) ($accounts->active_users ?? 0),
            ],
            'trend' => $this->trend($start, $days),
            'popular_pages' => $this->popularPages($start),
            'recent_sales' => $this->recentSales(),
        ];
    }

    /**
     * @return list<string>
     */
    public function userAuditHeaders(): array
    {
        return [
            'user_id',
            'email',
            'status',
            'plan',
            'registered_at',
            'paid_topups_usd',
            'current_refundable_balance_usd',
            'inferred_consumed_credits_usd',
            'exact_tracked_charges_usd',
            'historical_or_adjustment_gap_usd',
            'upload_charges_usd',
            'live_charges_usd',
            'polish_charges_usd',
            'summary_charges_usd',
            'pending_topups_usd',
            'paid_transaction_count',
            'seconds_transcribed',
            'polish_uses',
            'summary_uses',
            'last_paid_at',
            'last_usage_at',
        ];
    }

    /**
     * @return Generator<int, list<int|string>>
     */
    public function userAuditRows(): Generator
    {
        foreach ($this->userAuditQuery()->orderBy('users.id')->lazy(250) as $row) {
            $paidCents = (int) ($row->paid_cents ?? 0);
            $balanceCents = Money::decimalDollarsToUsdCents($row->wallet_balance ?? 0);
            $trackedCents = (int) ($row->charged_cents ?? 0);
            $inferredConsumedCents = max(0, $paidCents - $balanceCents);
            $gapCents = $paidCents - $balanceCents - $trackedCents;

            yield [
                (int) $row->id,
                (string) $row->email,
                (string) ($row->user_status ?: 'active'),
                (string) ($row->plan ?: 'free'),
                (string) $row->created_at,
                $this->decimal($paidCents),
                $this->decimal($balanceCents),
                $this->decimal($inferredConsumedCents),
                $this->decimal($trackedCents),
                $this->signedDecimal($gapCents),
                $this->decimal((int) ($row->upload_charged_cents ?? 0)),
                $this->decimal((int) ($row->live_charged_cents ?? 0)),
                $this->decimal((int) ($row->polish_charged_cents ?? 0)),
                $this->decimal((int) ($row->summary_charged_cents ?? 0)),
                $this->decimal((int) ($row->pending_cents ?? 0)),
                (int) ($row->paid_transaction_count ?? 0),
                (int) ($row->seconds_transcribed ?? 0),
                (int) ($row->polish_count ?? 0),
                (int) ($row->summary_count ?? 0),
                (string) ($row->last_paid_at ?? ''),
                (string) ($row->last_usage_at ?? ''),
            ];
        }
    }

    private function accountTotals(): object
    {
        $paid = $this->paidTopupsByUser();
        $charges = $this->usageByUser();
        $wallet = 'ROUND(COALESCE(users.wallet_balance, 0) * 100)';
        $paidAmount = 'COALESCE(paid_totals.paid_cents, 0)';
        $tracked = 'COALESCE(usage_totals.charged_cents, 0)';
        $gap = "{$paidAmount} - {$wallet} - {$tracked}";

        return DB::table('users')
            ->leftJoinSub($paid, 'paid_totals', 'paid_totals.user_id', '=', 'users.id')
            ->leftJoinSub($charges, 'usage_totals', 'usage_totals.user_id', '=', 'users.id')
            ->selectRaw('COUNT(users.id) AS registered_users')
            ->selectRaw('SUM(CASE WHEN users.email_verified_at IS NOT NULL THEN 1 ELSE 0 END) AS verified_users')
            ->selectRaw("SUM(CASE WHEN users.user_status IS NULL OR users.user_status = 'active' THEN 1 ELSE 0 END) AS active_users")
            ->selectRaw("COALESCE(SUM({$paidAmount}), 0) AS paid_topups_cents")
            ->selectRaw('COALESCE(SUM(COALESCE(paid_totals.paid_transaction_count, 0)), 0) AS paid_transactions')
            ->selectRaw("COALESCE(SUM({$wallet}), 0) AS refundable_balance_cents")
            ->selectRaw("COALESCE(SUM({$tracked}), 0) AS tracked_charges_cents")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$paidAmount} > {$wallet} THEN {$paidAmount} - {$wallet} ELSE 0 END), 0) AS realized_credits_cents")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$gap} > 0 THEN {$gap} ELSE 0 END), 0) AS historical_untracked_cents")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$gap} < 0 THEN -({$gap}) ELSE 0 END), 0) AS balance_excess_cents")
            ->first() ?? (object) [];
    }

    private function pendingTopupsCents(): int
    {
        return (int) BillingTransaction::query()
            ->where('plan', 'wallet_topup')
            ->where('currency', 'USD')
            ->whereIn('status', ['pending', 'checkout_created'])
            ->sum('amount');
    }

    /**
     * @return list<array<string, int|string>>
     */
    private function trend(CarbonInterface $start, int $days): array
    {
        $topups = BillingTransaction::query()
            ->where('plan', 'wallet_topup')
            ->where('currency', 'USD')
            ->where('status', 'paid')
            ->where('paid_at', '>=', $start)
            ->selectRaw('DATE(paid_at) AS day, SUM(amount) AS total')
            ->groupBy('day')
            ->pluck('total', 'day');
        $charges = UsageRecord::query()
            ->where('period', '>=', $start->toDateString())
            ->selectRaw('period AS day, SUM(charged_cents) AS total')
            ->groupBy('period')
            ->pluck('total', 'day');
        $visits = PageVisitDailyStat::query()
            ->where('visit_date', '>=', $start->toDateString())
            ->selectRaw('visit_date AS day, SUM(total_visits - bot_visits) AS total')
            ->groupBy('visit_date')
            ->pluck('total', 'day');
        $registrations = User::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(range(0, $days - 1))
            ->map(function (int $offset) use ($start, $topups, $charges, $visits, $registrations): array {
                $day = $start->copy()->addDays($offset)->toDateString();

                return [
                    'date' => $day,
                    'paid_topups_cents' => (int) ($topups[$day] ?? 0),
                    'tracked_charges_cents' => (int) ($charges[$day] ?? 0),
                    'visits' => (int) ($visits[$day] ?? 0),
                    'registrations' => (int) ($registrations[$day] ?? 0),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, int|string>>
     */
    private function popularPages(CarbonInterface $start): array
    {
        return PageVisitDailyStat::query()
            ->where('visit_date', '>=', $start->toDateString())
            ->select(['route_name', 'path'])
            ->selectRaw('SUM(total_visits - bot_visits) AS human_visits')
            ->selectRaw('SUM(authenticated_visits) AS authenticated_visits')
            ->selectRaw('SUM(guest_visits) AS guest_visits')
            ->selectRaw('SUM(bot_visits) AS bot_visits')
            ->groupBy('route_name', 'path')
            ->orderByDesc('human_visits')
            ->limit(10)
            ->get()
            ->map(fn (PageVisitDailyStat $stat): array => [
                'route' => (string) $stat->route_name,
                'path' => (string) $stat->path,
                'human_visits' => (int) $stat->getAttribute('human_visits'),
                'authenticated_visits' => (int) $stat->getAttribute('authenticated_visits'),
                'guest_visits' => (int) $stat->getAttribute('guest_visits'),
                'bot_visits' => (int) $stat->getAttribute('bot_visits'),
            ])
            ->all();
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    private function recentSales(): array
    {
        return BillingTransaction::query()
            ->with('user:id,email')
            ->where('plan', 'wallet_topup')
            ->where('currency', 'USD')
            ->where('status', 'paid')
            ->latest('paid_at')
            ->limit(6)
            ->get()
            ->map(fn (BillingTransaction $transaction): array => [
                'id' => $transaction->id,
                'email' => $transaction->user?->email,
                'amount_cents' => $transaction->amount,
                'reference' => $transaction->reference,
                'paid_at' => $transaction->paid_at?->toISOString(),
            ])
            ->all();
    }

    private function userAuditQuery(): Builder
    {
        $pending = BillingTransaction::query()
            ->select('user_id')
            ->selectRaw('SUM(amount) AS pending_cents')
            ->where('plan', 'wallet_topup')
            ->where('currency', 'USD')
            ->whereIn('status', ['pending', 'checkout_created'])
            ->groupBy('user_id');

        return DB::table('users')
            ->leftJoinSub($this->paidTopupsByUser(), 'paid_totals', 'paid_totals.user_id', '=', 'users.id')
            ->leftJoinSub($pending, 'pending_totals', 'pending_totals.user_id', '=', 'users.id')
            ->leftJoinSub($this->usageByUser(), 'usage_totals', 'usage_totals.user_id', '=', 'users.id')
            ->select([
                'users.id',
                'users.email',
                'users.user_status',
                'users.plan',
                'users.wallet_balance',
                'users.created_at',
            ])
            ->selectRaw('COALESCE(paid_totals.paid_cents, 0) AS paid_cents')
            ->selectRaw('COALESCE(paid_totals.paid_transaction_count, 0) AS paid_transaction_count')
            ->selectRaw('paid_totals.last_paid_at')
            ->selectRaw('COALESCE(pending_totals.pending_cents, 0) AS pending_cents')
            ->selectRaw('COALESCE(usage_totals.seconds_transcribed, 0) AS seconds_transcribed')
            ->selectRaw('COALESCE(usage_totals.polish_count, 0) AS polish_count')
            ->selectRaw('COALESCE(usage_totals.summary_count, 0) AS summary_count')
            ->selectRaw('COALESCE(usage_totals.charged_cents, 0) AS charged_cents')
            ->selectRaw('COALESCE(usage_totals.upload_charged_cents, 0) AS upload_charged_cents')
            ->selectRaw('COALESCE(usage_totals.live_charged_cents, 0) AS live_charged_cents')
            ->selectRaw('COALESCE(usage_totals.polish_charged_cents, 0) AS polish_charged_cents')
            ->selectRaw('COALESCE(usage_totals.summary_charged_cents, 0) AS summary_charged_cents')
            ->selectRaw('usage_totals.last_usage_at');
    }

    private function paidTopupsByUser(): Builder
    {
        return DB::table('billing_transactions')
            ->select('user_id')
            ->selectRaw('SUM(amount) AS paid_cents')
            ->selectRaw('COUNT(*) AS paid_transaction_count')
            ->selectRaw('MAX(paid_at) AS last_paid_at')
            ->where('plan', 'wallet_topup')
            ->where('currency', 'USD')
            ->where('status', 'paid')
            ->groupBy('user_id');
    }

    private function usageByUser(): Builder
    {
        return DB::table('usage_records')
            ->select('user_id')
            ->selectRaw('SUM(seconds_transcribed) AS seconds_transcribed')
            ->selectRaw('SUM(polish_count) AS polish_count')
            ->selectRaw('SUM(summary_count) AS summary_count')
            ->selectRaw('SUM(charged_cents) AS charged_cents')
            ->selectRaw('SUM(upload_charged_cents) AS upload_charged_cents')
            ->selectRaw('SUM(live_charged_cents) AS live_charged_cents')
            ->selectRaw('SUM(polish_charged_cents) AS polish_charged_cents')
            ->selectRaw('SUM(summary_charged_cents) AS summary_charged_cents')
            ->selectRaw('MAX(updated_at) AS last_usage_at')
            ->groupBy('user_id');
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function signedDecimal(int $cents): string
    {
        return ($cents > 0 ? '+' : '').$this->decimal($cents);
    }
}
