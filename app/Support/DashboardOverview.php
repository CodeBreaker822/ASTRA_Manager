<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Turns the raw analytics figures into the display-ready strings the dashboard
 * renders. The raw array stays untouched for anything that reads the numbers;
 * this only adds the formatted view of them so blade never does arithmetic.
 */
class DashboardOverview
{
    /** Trend columns the chart shows regardless of the selected range. */
    private const VISIBLE_TREND_DAYS = 14;

    public const DAY_OPTIONS = [7, 30, 90];

    /**
     * @param  array<string, mixed>|null  $analytics
     * @return array<string, mixed>|null
     */
    public static function from(?array $analytics): ?array
    {
        if ($analytics === null) {
            return null;
        }

        /** @var array<string, int> $sales */
        $sales = $analytics['sales'];
        /** @var array<string, int> $users */
        $users = $analytics['users'];

        $paid = $sales['paid_topups_cents'];

        return [
            'days' => $analytics['days'],
            'realization_rate' => $paid > 0
                ? min(100, (int) round(($sales['realized_credits_cents'] / $paid) * 100))
                : 0,
            'realized_credits' => self::money($sales['realized_credits_cents']),
            'refundable_balance' => self::money($sales['refundable_balance_cents']),
            'paid_topups' => self::money($paid),
            'paid_transactions' => $sales['paid_transactions'],
            'tracked_charges' => self::money($sales['tracked_charges_cents']),
            'pending_topups' => self::money($sales['pending_topups_cents']),
            'registered_users' => self::compact($users['registered']),
            'active_users' => $users['active'],
            'verified_users' => $users['verified'],
            'reconciliation' => self::reconciliation($sales),
            'trend' => self::trend($analytics),
            'popular_pages' => self::popularPages($analytics),
            'recent_sales' => self::recentSales($analytics),
        ];
    }

    /**
     * @param  array<string, int>  $sales
     * @return array{needed: bool, historical: string, excess: string}
     */
    private static function reconciliation(array $sales): array
    {
        return [
            'needed' => $sales['historical_untracked_cents'] > 0 || $sales['balance_excess_cents'] > 0,
            'historical' => self::money($sales['historical_untracked_cents']),
            'excess' => self::money($sales['balance_excess_cents']),
        ];
    }

    /**
     * @param  array<string, mixed>  $analytics
     * @return array{days: int, points: array<int, array{label: string, title: string, paid_height: string, consumed_height: string}>}
     */
    private static function trend(array $analytics): array
    {
        /** @var array<int, array<string, mixed>> $points */
        $points = array_slice($analytics['trend'], -self::VISIBLE_TREND_DAYS);

        $ceiling = max(1, ...array_merge(
            array_column($points, 'paid_topups_cents'),
            array_column($points, 'tracked_charges_cents'),
        ));

        return [
            'days' => min(self::VISIBLE_TREND_DAYS, (int) $analytics['days']),
            'points' => array_map(function (array $point) use ($ceiling): array {
                $paid = (int) $point['paid_topups_cents'];
                $consumed = (int) $point['tracked_charges_cents'];
                $label = self::dateLabel($point['date']);

                return [
                    'label' => $label,
                    'title' => $label.': '.self::money($paid).' paid, '.self::money($consumed).' consumed',
                    'paid_height' => self::barHeight($paid, $ceiling),
                    'consumed_height' => self::barHeight($consumed, $ceiling),
                ];
            }, $points),
        ];
    }

    /**
     * @param  array<string, mixed>  $analytics
     * @return array<int, array{path: string, route: string, human_visits: string, authenticated_visits: string}>
     */
    private static function popularPages(array $analytics): array
    {
        /** @var array<int, array<string, mixed>> $pages */
        $pages = $analytics['popular_pages'];

        return array_map(fn (array $page): array => [
            'path' => (string) $page['path'],
            'route' => (string) $page['route'],
            'human_visits' => self::compact((int) $page['human_visits']),
            'authenticated_visits' => self::compact((int) $page['authenticated_visits']),
        ], $pages);
    }

    /**
     * @param  array<string, mixed>  $analytics
     * @return array<int, array{email: string, reference: string, paid_at: string, amount: string}>
     */
    private static function recentSales(array $analytics): array
    {
        /** @var array<int, array<string, mixed>> $sales */
        $sales = $analytics['recent_sales'];

        return array_map(fn (array $sale): array => [
            'email' => (string) ($sale['email'] ?: 'Deleted user'),
            'reference' => (string) $sale['reference'],
            'paid_at' => self::dateLabel($sale['paid_at'], true),
            'amount' => self::money((int) $sale['amount_cents']),
        ], $sales);
    }

    private static function barHeight(int $cents, int $ceiling): string
    {
        return $cents <= 0 ? '0%' : max(4, ($cents / $ceiling) * 100).'%';
    }

    private static function money(int $cents): string
    {
        return Money::format($cents / 100, 'USD', 2, 2);
    }

    /**
     * Large counts collapse to 1.2K / 3.4M so the cards keep their width.
     */
    private static function compact(int $value): string
    {
        if ($value < 10_000) {
            return number_format($value);
        }

        foreach ([1_000_000_000 => 'B', 1_000_000 => 'M', 1_000 => 'K'] as $unit => $suffix) {
            if ($value >= $unit) {
                return rtrim(rtrim(number_format($value / $unit, 1), '0'), '.').$suffix;
            }
        }

        return (string) $value;
    }

    private static function dateLabel(mixed $value, bool $includeTime = false): string
    {
        if (! is_string($value) || $value === '') {
            return '—';
        }

        return Carbon::parse($value)->format($includeTime ? 'M j, g:i A' : 'M j');
    }
}
