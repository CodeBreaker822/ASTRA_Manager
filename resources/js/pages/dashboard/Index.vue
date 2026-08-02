<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowUpRight, Download, FileText, Newspaper, Tags } from '@lucide/vue';
import { computed } from 'vue';
import DashboardLayout from '@/layouts/dashboard/Layout.vue';

type TrendPoint = {
    date: string;
    paid_topups_cents: number;
    tracked_charges_cents: number;
    visits: number;
    registrations: number;
};

type PopularPage = {
    route: string;
    path: string;
    human_visits: number;
    authenticated_visits: number;
    guest_visits: number;
    bot_visits: number;
};

type RecentSale = {
    id: number;
    email: string | null;
    amount_cents: number;
    reference: string;
    paid_at: string | null;
};

type Analytics = {
    days: number;
    generated_at: string;
    sales: {
        currency: string;
        paid_topups_cents: number;
        refundable_balance_cents: number;
        realized_credits_cents: number;
        tracked_charges_cents: number;
        historical_untracked_cents: number;
        balance_excess_cents: number;
        pending_topups_cents: number;
        paid_transactions: number;
    };
    users: {
        registered: number;
        verified: number;
        active: number;
    };
    trend: TrendPoint[];
    popular_pages: PopularPage[];
    recent_sales: RecentSale[];
};

const props = defineProps<{
    analytics: Analytics | null;
}>();

defineOptions({
    layout: DashboardLayout,
});

const page = usePage();

const shortcuts = [
    {
        title: 'Blog',
        body: 'Manage public posts',
        href: '/dashboard/blog',
        icon: Newspaper,
        visible: () => page.props.auth.canManageBlog,
    },
    {
        title: 'Pricing',
        body: 'Manage plans and rates',
        href: '/dashboard/pricing',
        icon: Tags,
        visible: () => page.props.auth.canManagePricing,
    },
    {
        title: 'Pages',
        body: 'Edit public page content',
        href: '/dashboard/pages/home',
        icon: FileText,
        visible: () => page.props.auth.canManagePages,
    },
];

const visibleTrend = computed(() => props.analytics?.trend.slice(-14) ?? []);
const visibleTrendDays = computed(() =>
    Math.min(14, props.analytics?.days ?? 14),
);
const maximumDailyMoney = computed(() =>
    Math.max(
        1,
        ...visibleTrend.value.flatMap((point) => [
            point.paid_topups_cents,
            point.tracked_charges_cents,
        ]),
    ),
);

const realizationRate = computed(() => {
    const paid = props.analytics?.sales.paid_topups_cents ?? 0;
    const realized = props.analytics?.sales.realized_credits_cents ?? 0;

    return paid > 0 ? Math.min(100, Math.round((realized / paid) * 100)) : 0;
});

function money(cents: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
    }).format(cents / 100);
}

function compactNumber(value: number): string {
    return new Intl.NumberFormat('en-US', {
        notation: value >= 10_000 ? 'compact' : 'standard',
        maximumFractionDigits: 1,
    }).format(value);
}

function dateLabel(value: string | null, includeTime = false): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        ...(includeTime ? { hour: 'numeric', minute: '2-digit' } : undefined),
    }).format(new Date(value));
}

function barHeight(cents: number): string {
    if (cents <= 0) {
        return '0%';
    }

    return `${Math.max(4, (cents / maximumDailyMoney.value) * 100)}%`;
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="mx-auto max-w-[1600px] space-y-5">
        <section
            v-if="analytics"
            class="space-y-5 text-slate-900 dark:text-slate-100"
        >
            <header
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h1 class="text-xl font-semibold">Business overview</h1>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Realized credit revenue, wallet liability, traffic, and
                        registrations.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-slate-400">Trend:</span>
                    <div
                        class="inline-flex rounded-md border border-slate-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-900"
                    >
                        <Link
                            v-for="days in [7, 30, 90]"
                            :key="days"
                            :href="`/dashboard?days=${days}`"
                            class="rounded px-2.5 py-1.5 text-xs font-medium"
                            :class="
                                analytics.days === days
                                    ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900'
                                    : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'
                            "
                            preserve-scroll
                        >
                            {{ days }}d
                        </Link>
                    </div>
                    <a
                        href="/dashboard/analytics/users.csv"
                        class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-blue-700"
                    >
                        <Download class="size-4" />
                        Export user audit
                    </a>
                </div>
            </header>

            <div
                class="grid grid-cols-2 overflow-hidden rounded-lg border border-slate-200 bg-white lg:grid-cols-4 dark:border-slate-700 dark:bg-slate-900"
            >
                <div
                    class="border-r border-b border-slate-200 p-4 lg:border-b-0 dark:border-slate-700"
                >
                    <p
                        class="text-xs font-medium text-slate-500 dark:text-slate-400"
                    >
                        Realized credits · lifetime
                    </p>
                    <p
                        class="mt-1 text-xl font-semibold text-emerald-600 dark:text-emerald-400"
                    >
                        {{ money(analytics.sales.realized_credits_cents) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-400">
                        {{ realizationRate }}% of paid funding consumed
                    </p>
                </div>
                <div
                    class="border-b border-slate-200 p-4 lg:border-r lg:border-b-0 dark:border-slate-700"
                >
                    <p
                        class="text-xs font-medium text-slate-500 dark:text-slate-400"
                    >
                        Refundable balances · current
                    </p>
                    <p
                        class="mt-1 text-xl font-semibold text-amber-600 dark:text-amber-400"
                    >
                        {{ money(analytics.sales.refundable_balance_cents) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-400">
                        Current wallet liability
                    </p>
                </div>
                <div
                    class="border-r border-slate-200 p-4 dark:border-slate-700"
                >
                    <p
                        class="text-xs font-medium text-slate-500 dark:text-slate-400"
                    >
                        Paid top-ups · lifetime
                    </p>
                    <p class="mt-1 text-xl font-semibold">
                        {{ money(analytics.sales.paid_topups_cents) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-400">
                        {{ analytics.sales.paid_transactions }} confirmed
                        payments
                    </p>
                </div>
                <div class="p-4">
                    <p
                        class="text-xs font-medium text-slate-500 dark:text-slate-400"
                    >
                        Registered users · total
                    </p>
                    <p class="mt-1 text-xl font-semibold">
                        {{ compactNumber(analytics.users.registered) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-400">
                        {{ analytics.users.active }} active ·
                        {{ analytics.users.verified }} verified
                    </p>
                </div>
            </div>

            <div
                v-if="
                    analytics.sales.historical_untracked_cents > 0 ||
                    analytics.sales.balance_excess_cents > 0
                "
                class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200"
            >
                <span class="font-semibold">Reconciliation review:</span>
                {{ money(analytics.sales.historical_untracked_cents) }} was
                consumed before exact charge tracking, and
                {{ money(analytics.sales.balance_excess_cents) }} in balances or
                adjustments is not reconciled to tracked paid funding. The user
                audit export identifies each affected account.
            </div>

            <div class="grid gap-5 xl:grid-cols-3">
                <section
                    class="rounded-lg border border-slate-200 bg-white p-4 xl:col-span-2 dark:border-slate-700 dark:bg-slate-900"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <h2 class="text-sm font-semibold">
                                Sales and consumption
                            </h2>
                            <p
                                class="mt-1 text-xs text-slate-500 dark:text-slate-400"
                            >
                                Last {{ visibleTrendDays }} days shown; exact
                                consumed charges begin with this dashboard
                                update.
                            </p>
                        </div>
                        <div
                            class="flex items-center gap-3 text-xs text-slate-500"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <span
                                    class="size-2 rounded-sm bg-blue-500"
                                ></span>
                                Paid
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <span
                                    class="size-2 rounded-sm bg-emerald-500"
                                ></span>
                                Consumed
                            </span>
                        </div>
                    </div>

                    <div class="mt-5 overflow-x-auto pb-1">
                        <div
                            class="flex h-40 min-w-[560px] items-end gap-2 border-b border-slate-200 dark:border-slate-700"
                        >
                            <div
                                v-for="point in visibleTrend"
                                :key="point.date"
                                class="group flex h-full min-w-7 flex-1 flex-col justify-end"
                                :title="`${dateLabel(point.date)}: ${money(point.paid_topups_cents)} paid, ${money(point.tracked_charges_cents)} consumed`"
                            >
                                <div
                                    class="flex flex-1 items-end justify-center gap-0.5"
                                >
                                    <div
                                        class="w-2.5 rounded-t bg-blue-500 transition-all"
                                        :style="{
                                            height: barHeight(
                                                point.paid_topups_cents,
                                            ),
                                        }"
                                    ></div>
                                    <div
                                        class="w-2.5 rounded-t bg-emerald-500 transition-all"
                                        :style="{
                                            height: barHeight(
                                                point.tracked_charges_cents,
                                            ),
                                        }"
                                    ></div>
                                </div>
                                <span
                                    class="mt-2 truncate text-center text-[10px] text-slate-400"
                                >
                                    {{ dateLabel(point.date) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 text-xs sm:grid-cols-3">
                        <div
                            class="rounded-md bg-slate-50 p-3 dark:bg-slate-800/70"
                        >
                            <span class="text-slate-500 dark:text-slate-400"
                                >Exact tracked charges</span
                            >
                            <p class="mt-1 font-semibold">
                                {{
                                    money(analytics.sales.tracked_charges_cents)
                                }}
                            </p>
                        </div>
                        <div
                            class="rounded-md bg-slate-50 p-3 dark:bg-slate-800/70"
                        >
                            <span class="text-slate-500 dark:text-slate-400"
                                >Pending checkouts</span
                            >
                            <p class="mt-1 font-semibold">
                                {{
                                    money(analytics.sales.pending_topups_cents)
                                }}
                            </p>
                        </div>
                        <div
                            class="rounded-md bg-slate-50 p-3 dark:bg-slate-800/70"
                        >
                            <span class="text-slate-500 dark:text-slate-400"
                                >Realized basis</span
                            >
                            <p class="mt-1 font-semibold">
                                Paid top-ups − current balance
                            </p>
                        </div>
                    </div>

                    <p class="mt-3 text-[11px] leading-5 text-slate-400">
                        “Realized credits” is gross consumed wallet revenue, not
                        net profit; provider and operating costs are not stored
                        by billing.
                    </p>
                </section>

                <section
                    class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
                >
                    <div
                        class="border-b border-slate-200 px-4 py-3 dark:border-slate-700"
                    >
                        <h2 class="text-sm font-semibold">Popular pages</h2>
                        <p
                            class="mt-1 text-xs text-slate-500 dark:text-slate-400"
                        >
                            Human visits in the last {{ analytics.days }} days
                        </p>
                    </div>
                    <div class="max-h-[330px] overflow-auto">
                        <table class="w-full text-left text-xs">
                            <thead
                                class="sticky top-0 bg-slate-50 text-slate-500 dark:bg-slate-800 dark:text-slate-400"
                            >
                                <tr>
                                    <th class="px-4 py-2 font-medium">Page</th>
                                    <th
                                        class="px-3 py-2 text-right font-medium"
                                    >
                                        Visits
                                    </th>
                                    <th
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Users
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-slate-100 dark:divide-slate-800"
                            >
                                <tr v-if="analytics.popular_pages.length === 0">
                                    <td
                                        colspan="3"
                                        class="px-4 py-8 text-center text-slate-400"
                                    >
                                        Visit data will appear as pages are
                                        opened.
                                    </td>
                                </tr>
                                <tr
                                    v-for="item in analytics.popular_pages"
                                    :key="`${item.route}:${item.path}`"
                                >
                                    <td class="max-w-52 px-4 py-2.5">
                                        <p
                                            class="truncate font-medium"
                                            :title="item.path"
                                        >
                                            {{ item.path }}
                                        </p>
                                        <p
                                            class="mt-0.5 truncate text-[10px] text-slate-400"
                                        >
                                            {{ item.route }}
                                        </p>
                                    </td>
                                    <td
                                        class="px-3 py-2.5 text-right font-semibold"
                                    >
                                        {{ compactNumber(item.human_visits) }}
                                    </td>
                                    <td
                                        class="px-4 py-2.5 text-right text-slate-500"
                                    >
                                        {{
                                            compactNumber(
                                                item.authenticated_visits,
                                            )
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <section
                class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
            >
                <div
                    class="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-700"
                >
                    <div>
                        <h2 class="text-sm font-semibold">Recent paid sales</h2>
                        <p
                            class="mt-1 text-xs text-slate-500 dark:text-slate-400"
                        >
                            Latest confirmed wallet funding
                        </p>
                    </div>
                    <span class="text-xs text-slate-400">USD</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead
                            class="bg-slate-50 text-slate-500 dark:bg-slate-800 dark:text-slate-400"
                        >
                            <tr>
                                <th class="px-4 py-2 font-medium">User</th>
                                <th class="px-4 py-2 font-medium">Reference</th>
                                <th class="px-4 py-2 font-medium">Paid</th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Amount
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-slate-100 dark:divide-slate-800"
                        >
                            <tr v-if="analytics.recent_sales.length === 0">
                                <td
                                    colspan="4"
                                    class="px-4 py-7 text-center text-slate-400"
                                >
                                    No paid top-ups yet.
                                </td>
                            </tr>
                            <tr
                                v-for="sale in analytics.recent_sales"
                                :key="sale.id"
                            >
                                <td
                                    class="px-4 py-2.5 font-medium whitespace-nowrap"
                                >
                                    {{ sale.email || 'Deleted user' }}
                                </td>
                                <td
                                    class="px-4 py-2.5 font-mono text-[11px] whitespace-nowrap text-slate-500"
                                >
                                    {{ sale.reference }}
                                </td>
                                <td
                                    class="px-4 py-2.5 whitespace-nowrap text-slate-500"
                                >
                                    {{ dateLabel(sale.paid_at, true) }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right font-semibold whitespace-nowrap text-emerald-600 dark:text-emerald-400"
                                >
                                    {{ money(sale.amount_cents) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <section v-if="shortcuts.some((item) => item.visible())">
            <h2
                v-if="analytics"
                class="mb-2 text-xs font-semibold tracking-wide text-slate-400 uppercase"
            >
                Management
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="shortcut in shortcuts.filter((item) =>
                        item.visible(),
                    )"
                    :key="shortcut.title"
                    :href="shortcut.href"
                    class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 transition hover:border-blue-300 hover:bg-blue-50 dark:border-slate-700 dark:bg-slate-900 dark:hover:border-blue-700 dark:hover:bg-blue-950/30"
                >
                    <component
                        :is="shortcut.icon"
                        class="size-4 text-blue-600 dark:text-blue-400"
                    />
                    <div class="min-w-0 flex-1">
                        <p
                            class="text-sm font-semibold text-slate-950 dark:text-slate-100"
                        >
                            {{ shortcut.title }}
                        </p>
                        <p
                            class="truncate text-xs text-slate-500 dark:text-slate-400"
                        >
                            {{ shortcut.body }}
                        </p>
                    </div>
                    <ArrowUpRight class="size-4 text-slate-400" />
                </Link>
            </div>
        </section>
    </div>
</template>
