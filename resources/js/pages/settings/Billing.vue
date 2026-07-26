<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check, DollarSign, Plus } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { notifyError } from '@/lib/notify';

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
    billing: {
        checkout_available: boolean;
    };
    walletBalance: number;
    plans: PlanTier[];
    topup: {
        wallet_currency: 'USD';
        checkout_currency: 'PHP';
        usd_to_php_rate: number | null;
        payment_method_types: string[];
        pass_on_fees: boolean;
    };
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

const form = useForm({
    amount: null as number | null,
});
const billingError = computed(
    () => (form.errors as Record<string, string | undefined>).billing,
);

const paygPlan = props.plans.find((p) => p.key === 'payg') as
    PlanTier | undefined;

const formatDollars = (amount: number, maximumFractionDigits = 2) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits,
    }).format(amount);
};

const formattedBalance = (balance: number) => formatDollars(balance / 100);
const hourlyRate = (amount: number | undefined) =>
    `${formatDollars(amount ?? 0)}/hour`;
const perThousandCharactersRate = (amount: number | undefined) =>
    `${formatDollars((amount ?? 0) * 1000, 4)}/1K chars`;
const formatPesos = (amount: number) =>
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
const estimatedCheckoutAmount = computed(() => {
    const amount = Number(form.amount ?? 0);

    return amount > 0 && props.topup.usd_to_php_rate
        ? amount * props.topup.usd_to_php_rate
        : null;
});
const paymentMethods = computed(() =>
    props.topup.payment_method_types
        .map((method) => method.replaceAll('_', ' ').toUpperCase())
        .join(', '),
);

const handleTopup = () => {
    if (!props.billing.checkout_available) {
        notifyError('PayMongo checkout is not configured.');

        return;
    }

    if (!form.amount || form.amount < 1.0) {
        const message = 'Minimum top-up is $1.00.';
        form.setError('amount', message);
        notifyError(message);

        return;
    }

    form.clearErrors();
    form.post('/settings/billing/checkout', {
        onError: (errors) => {
            const message =
                Object.values(errors).at(0) ?? 'Unable to start checkout.';
            notifyError(message);
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
            description="Free tier first, then credit balance"
        />

        <section
            class="grid gap-4 rounded-lg border border-green-200 bg-green-50 p-5 text-green-950"
        >
            <div class="flex items-center gap-2">
                <DollarSign class="size-5 text-green-700" />
                <div>
                    <p
                        class="text-xs font-semibold tracking-wide text-green-600 uppercase"
                    >
                        Credit Balance
                    </p>
                    <p class="text-xl font-bold">
                        {{ formattedBalance(walletBalance) }}
                    </p>
                </div>
            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <article
                class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-950">
                            Free Tier
                        </h2>
                        <p class="mt-1 text-sm text-slate-600">
                            Used before credit balance is charged
                        </p>
                    </div>
                    <span
                        class="rounded-lg border border-green-200 bg-green-50 px-3 py-1 text-xs font-semibold text-green-700"
                    >
                        Daily Free
                    </span>
                </div>

                <div class="mt-6 space-y-4">
                    <div class="flex items-start gap-3">
                        <Check class="mt-0.5 size-5 shrink-0 text-green-600" />
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                60 transcription minutes per day
                            </p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Resets at midnight
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3">
                        <Check class="mt-0.5 size-5 shrink-0 text-green-600" />
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                3 polishing uses per day
                            </p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                No credit balance deducted
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3">
                        <Check class="mt-0.5 size-5 shrink-0 text-green-600" />
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                3 summarization uses per day
                            </p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                No credit balance deducted
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3">
                        <Check class="mt-0.5 size-5 shrink-0 text-green-600" />
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                TXT, Word, Excel exports
                            </p>
                        </div>
                    </div>
                </div>
            </article>

            <article
                v-if="paygPlan"
                class="rounded-lg border border-blue-200 bg-blue-50 p-6 shadow-sm"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-950">
                            Pay-as-you-go
                        </h2>
                        <p class="mt-1 text-sm text-slate-600">
                            These rates are deducted from credit balance
                        </p>
                    </div>
                    <span
                        class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700"
                    >
                        Active
                    </span>
                </div>

                <div class="mt-6 space-y-4">
                    <div
                        class="flex items-start justify-between rounded-lg bg-white p-4"
                    >
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                Audio Upload
                            </p>
                            <p class="text-xs text-slate-500">
                                Charged after free minutes
                            </p>
                        </div>
                        <p class="text-sm font-bold text-slate-950">
                            {{ hourlyRate(paygPlan.upload_price_per_hour) }}
                        </p>
                    </div>

                    <div
                        class="flex items-start justify-between rounded-lg bg-white p-4"
                    >
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                Live Recording
                            </p>
                            <p class="text-xs text-slate-500">
                                Charged after free minutes
                            </p>
                        </div>
                        <p class="text-sm font-bold text-slate-950">
                            {{ hourlyRate(paygPlan.live_price_per_hour) }}
                        </p>
                    </div>

                    <div
                        class="flex items-start justify-between rounded-lg bg-white p-4"
                    >
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                Polishing
                            </p>
                            <p class="text-xs text-slate-500">
                                Charged after free uses
                            </p>
                        </div>
                        <p class="text-sm font-bold text-slate-950">
                            {{
                                perThousandCharactersRate(
                                    paygPlan.polish_price_per_character,
                                )
                            }}
                        </p>
                    </div>

                    <div
                        class="flex items-start justify-between rounded-lg bg-white p-4"
                    >
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                Summarization
                            </p>
                            <p class="text-xs text-slate-500">
                                Charged after free uses
                            </p>
                        </div>
                        <p class="text-sm font-bold text-slate-950">
                            {{
                                perThousandCharactersRate(
                                    paygPlan.summary_price_per_character,
                                )
                            }}
                        </p>
                    </div>
                </div>

                <div class="mt-8">
                    <label class="text-sm font-semibold text-slate-700">
                        Add credit balance
                    </label>

                    <div class="mt-3 flex gap-2">
                        <input
                            v-model.number="form.amount"
                            type="number"
                            min="1"
                            step="0.01"
                            placeholder="Amount in USD"
                            class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            @keyup.enter="handleTopup"
                        />
                        <Button
                            :disabled="
                                form.processing || !billing.checkout_available
                            "
                            @click="handleTopup"
                        >
                            <Plus class="mr-2 size-4" />
                            Top Up
                        </Button>
                    </div>

                    <p
                        v-if="form.errors.amount"
                        class="mt-2 text-xs text-red-600"
                    >
                        {{ form.errors.amount }}
                    </p>
                    <p v-if="billingError" class="mt-2 text-xs text-red-600">
                        {{ billingError }}
                    </p>
                    <p
                        v-if="!billing.checkout_available"
                        class="mt-2 text-xs text-red-600"
                    >
                        PayMongo checkout is not configured.
                    </p>
                    <p
                        v-else-if="estimatedCheckoutAmount"
                        class="mt-2 text-xs text-slate-500"
                    >
                        PayMongo charges
                        {{ formatPesos(estimatedCheckoutAmount) }} through
                        {{ paymentMethods }} before PayMongo fees.
                        {{
                            topup.pass_on_fees
                                ? 'PayMongo fees are added at checkout.'
                                : 'PayMongo fees may be deducted from settlement.'
                        }}
                        Your wallet receives {{ formatDollars(form.amount ?? 0) }}
                        after payment confirmation.
                    </p>
                    <p
                        v-else-if="billing.checkout_available"
                        class="mt-2 text-xs text-slate-500"
                    >
                        Live USD to PHP rate and PayMongo fees are checked when
                        checkout starts.
                    </p>
                </div>
            </article>
        </section>
    </div>
</template>
