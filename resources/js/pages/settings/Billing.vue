<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { DollarSign, Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { useCurrency } from '@/composables/useCurrency';
import type { Plan } from '@/types';

type PlanTier = {
    key: string;
    name: string;
    tagline: string;
    upload_price_per_hour: number;
    live_price_per_hour: number;
    polish_price_per_character: number;
    summary_price_per_character: number;
    minutes: number;
    free_polish_uses_per_day: number;
    free_summary_uses_per_day: number;
};

const props = defineProps<{
    walletBalance: number;
    plans: Plan[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Billing',
                href: '/settings/billing',
            },
        ],
    },
});

const currency = useCurrency();
const form = useForm({
    amount: null as number | null,
});

const paygPlan = props.plans.find(p => p.key === 'payg') as PlanTier | undefined;

const formattedBalance = (balance: number) => {
    return currency.fromCents(balance);
};

const handleTopup = () => {
    // Form value is in USD dollars (e.g., 10.50)
    if (!form.amount || form.amount < 1.00) {
        alert('Minimum top-up is $1.00');
        return;
    }
    form.post('/settings/billing/checkout', {
        onError: (errors) => {
            console.error('Top-up failed:', errors);
        },
    });
};
</script>

<template>
    <Head title="Billing" />

    <h1 class="sr-only">Billing</h1>

    <div class="space-y-8">
        <Heading
            variant="small"
            title="Billing"
            description="Free tier benefits and pay-as-you-go pricing"
        />

        <!-- Wallet Balance Banner -->
        <section
            v-if="walletBalance > 0"
            class="grid gap-4 rounded-lg border border-green-200 bg-green-50 p-5 text-green-950"
        >
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <DollarSign class="size-5 text-green-700" />
                    <div>
                        <p class="text-xs font-semibold tracking-wide text-green-600 uppercase">
                            Wallet Balance
                        </p>
                        <p class="text-xl font-bold">
                            {{ formattedBalance(walletBalance) }}
                        </p>
                    </div>
                </div>
                <Link href="/settings/billing/checkout" method="post">
                    <Button>Top Up</Button>
                </Link>
            </div>
        </section>

        <!-- Two-Card Layout -->
        <section class="grid gap-6 lg:grid-cols-2">
            <!-- Free Tier Card -->
            <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-950">Free Tier</h2>
                        <p class="mt-1 text-sm text-slate-600">Perfect for trying out the service</p>
                    </div>
                    <span
                        class="rounded-lg border border-green-200 bg-green-50 px-3 py-1 text-xs font-semibold text-green-700"
                    >
                        Daily Free
                    </span>
                </div>

                <div class="mt-6 space-y-4">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 size-5 shrink-0 text-green-600">✓</span>
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                60 transcription minutes per day
                            </p>
                            <p class="text-xs text-slate-500 mt-0.5">Resets at midnight</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 size-5 shrink-0 text-green-600">✓</span>
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                3 Polishing uses per day
                            </p>
                            <p class="text-xs text-slate-500 mt-0.5">Up to 1,000 characters each</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 size-5 shrink-0 text-green-600">✓</span>
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                3 Summarization uses per day
                            </p>
                            <p class="text-xs text-slate-500 mt-0.5">Up to 1,000 characters each</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 size-5 shrink-0 text-green-600">✓</span>
                        <div>
                            <p class="text-sm font-semibold text-slate-950">TXT, Word, Excel exports</p>
                        </div>
                    </div>
                </div>

                <div class="mt-8">
                    <Button class="w-full" variant="outline" disabled>
                        Included with your account
                    </Button>
                </div>
            </article>

            <!-- Pay-as-you-go Card -->
            <article
                v-if="paygPlan"
                class="rounded-lg border border-blue-200 bg-blue-50 p-6 shadow-sm"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-950">Pay-as-you-go</h2>
                        <p class="mt-1 text-sm text-slate-600">Add funds to your wallet as needed</p>
                    </div>
                    <span
                        class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700"
                    >
                        Recommended
                    </span>
                </div>

                <div class="mt-6 space-y-4">
                    <!-- Audio Upload Pricing -->
                    <div class="flex items-start justify-between rounded-lg bg-white p-4">
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                Audio Upload
                            </p>
                            <p class="text-xs text-slate-500">1 hour of recording</p>
                        </div>
                        <p class="text-sm font-bold text-slate-950">
                            {{ currency.formatWithSuffix(paygPlan?.upload_price_per_hour || 0, '/hour') }}
                        </p>
                    </div>

                    <!-- Live Recording Pricing -->
                    <div class="flex items-start justify-between rounded-lg bg-white p-4">
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                Live Recording
                            </p>
                            <p class="text-xs text-slate-500">1 hour of live recording</p>
                        </div>
                        <p class="text-sm font-bold text-slate-950">
                            {{ currency.formatWithSuffix(paygPlan?.live_price_per_hour || 0, '/hour') }}
                        </p>
                    </div>

                    <!-- Polishing Pricing -->
                    <div class="flex items-start justify-between rounded-lg bg-white p-4">
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                Polishing
                            </p>
                            <p class="text-xs text-slate-500">Per 1,000 characters</p>
                        </div>
                        <p class="text-sm font-bold text-slate-950">
                            {{ currency.formatWithSuffix(paygPlan?.polish_price_per_character || 0, '/1K chars') }}
                        </p>
                    </div>

                    <!-- Summarization Pricing -->
                    <div class="flex items-start justify-between rounded-lg bg-white p-4">
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                Summarization
                            </p>
                            <p class="text-xs text-slate-500">Per 1,000 characters</p>
                        </div>
                        <p class="text-sm font-bold text-slate-950">
                            {{ currency.formatWithSuffix(paygPlan?.summary_price_per_character || 0, '/1K chars') }}
                        </p>
                    </div>
                </div>

                <div class="mt-8">
                    <label class="text-sm font-semibold text-slate-700">
                        Add funds to your wallet
                    </label>

                    <!-- Custom Top-up Input (no presets) -->
                    <div class="mt-3 flex gap-2">
                        <input
                            v-model.number="form.amount"
                            type="number"
                            min="1"
                            step="0.01"
                            placeholder="Custom amount (minimum $1.00)"
                            class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            @keyup.enter="handleTopup"
                        />
                        <Button @click="handleTopup">
                            <Plus class="mr-2 size-4" />
                            Top Up
                        </Button>
                    </div>

                    <p class="mt-2 text-xs text-slate-500">
                        Amounts are charged in USD. Funds are added to your wallet immediately after payment confirmation.
                    </p>
                </div>
            </article>
        </section>
    </div>
</template>