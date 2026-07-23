<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Check, CreditCard, Gauge, LockKeyhole } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type Plan = {
    key: string;
    name: string;
    tagline: string;
    price_label: string;
    minutes: number;
    cta: string;
    featured: boolean;
    price_per_second: number;
    polish_characters: number;
    summary_characters: number;
    polish_price_per_character: number;
    summary_price_per_character: number;
    free_polish_uses_per_day: number;
    free_summary_uses_per_day: number;
    features: string[];
};

const props = defineProps<{
    billing: {
        provider: string | null;
        checkout_available: boolean;
        portal_available: boolean;
        paymongo_ready: Record<string, boolean>;
    };
    entitlements: {
        plan: {
            key: string;
            name: string;
            minutes: number;
            free_polish_uses_per_day: number;
            free_summary_uses_per_day: number;
            features: Record<string, unknown>;
        };
        usage: {
            period: string;
            minutes_used: number;
            minutes_remaining: number;
            minutes_credit_balance: number;
            seconds_transcribed: number;
            seconds_credit_balance: number;
            polish_count: number;
            summary_count: number;
            free_polish_remaining: number;
            free_summary_remaining: number;
            polish_credit_characters: number;
            summary_credit_characters: number;
        };
    };
    plans: Plan[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Billing settings',
                href: '/settings/billing',
            },
        ],
    },
});

const usagePercent = Math.min(
    100,
    Math.round(
        (props.entitlements.usage.minutes_used /
            Math.max(
                1,
                props.entitlements.plan.minutes +
                    props.entitlements.usage.minutes_credit_balance,
            )) *
            100,
    ),
);
</script>

<template>
    <Head title="Billing settings" />

    <h1 class="sr-only">Billing settings</h1>

    <div class="space-y-8">
        <Heading
            variant="small"
            title="Billing"
            description="Review today's free minutes and buy pay-as-you-go credits"
        />

        <section
            class="grid gap-4 rounded-lg border border-blue-100 bg-blue-50 p-5 text-blue-950 md:grid-cols-[1fr_auto]"
        >
            <div>
                <p
                    class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                >
                    Today's allowance
                </p>
                <h2 class="mt-2 text-xl font-semibold">
                    {{ entitlements.plan.name }}
                </h2>
                <p class="mt-2 text-sm leading-6 text-blue-900">
                    {{ entitlements.usage.minutes_remaining }} of
                    {{
                        entitlements.plan.minutes +
                        entitlements.usage.minutes_credit_balance
                    }}
                    transcription minutes remain for
                    {{ entitlements.usage.period }}. Polish free uses:
                    {{ entitlements.usage.free_polish_remaining }} of
                    {{ entitlements.plan.free_polish_uses_per_day }}. Summarize
                    free uses:
                    {{ entitlements.usage.free_summary_remaining }} of
                    {{ entitlements.plan.free_summary_uses_per_day }}.
                </p>
            </div>
            <Button as-child>
                <Link href="/price">View credits</Link>
            </Button>
        </section>

        <section class="grid gap-4 md:grid-cols-3">
            <article class="rounded-lg border border-slate-200 bg-white p-5">
                <Gauge class="size-5 text-blue-600" />
                <p class="mt-4 text-sm font-semibold text-slate-950">
                    Daily usage
                </p>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-blue-100">
                    <div
                        class="h-full rounded-full bg-blue-600"
                        :style="{ width: `${usagePercent}%` }"
                    />
                </div>
                <p class="mt-3 text-sm text-slate-700">
                    {{ entitlements.usage.minutes_used }} minutes used
                </p>
            </article>

            <article class="rounded-lg border border-slate-200 bg-white p-5">
                <CreditCard class="size-5 text-blue-600" />
                <p class="mt-4 text-sm font-semibold text-slate-950">
                    Credit balance
                </p>
                <p class="mt-2 text-sm leading-6 text-slate-700">
                    {{ entitlements.usage.minutes_credit_balance }} paid
                    minutes, {{ entitlements.usage.polish_credit_characters }}
                    polish characters, and
                    {{ entitlements.usage.summary_credit_characters }} summarize
                    characters available
                </p>
            </article>

            <article class="rounded-lg border border-slate-200 bg-white p-5">
                <LockKeyhole class="size-5 text-blue-600" />
                <p class="mt-4 text-sm font-semibold text-slate-950">
                    Account management
                </p>
                <p class="mt-2 text-sm leading-6 text-slate-700">
                    PayMongo checkout adds minute credits after payment
                    confirmation. No recurring payment is created.
                </p>
            </article>
        </section>

        <section class="grid gap-4 lg:grid-cols-3">
            <article
                v-for="plan in plans"
                :key="plan.key"
                class="rounded-lg border border-slate-200 bg-white p-5"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-slate-950">
                            {{ plan.name }}
                        </h3>
                        <p class="mt-1 text-sm text-slate-700">
                            {{ plan.tagline }}
                        </p>
                    </div>
                    <span
                        v-if="plan.key === 'free'"
                        class="rounded-lg border border-green-200 bg-green-50 px-2 py-1 text-xs font-semibold text-green-700"
                    >
                        Daily free
                    </span>
                </div>

                <p class="mt-5 text-2xl font-semibold text-slate-950">
                    {{ plan.price_label }}
                </p>
                <p class="mt-1 text-sm text-slate-600">
                    {{
                        plan.key === 'free'
                            ? `${plan.minutes} minutes reset every day`
                            : `${plan.minutes} one-time minutes`
                    }}
                </p>

                <div class="mt-5 grid gap-2">
                    <p
                        v-for="feature in plan.features"
                        :key="feature"
                        class="flex gap-2 text-sm leading-6 text-slate-700"
                    >
                        <Check class="mt-1 size-4 shrink-0 text-blue-600" />
                        <span>{{ feature }}</span>
                    </p>
                </div>

                <div v-if="plan.key === 'free'" class="mt-6">
                    <Button class="w-full" variant="outline" disabled>
                        Included daily
                    </Button>
                </div>
                <div v-else class="mt-6 grid gap-2">
                    <Button v-if="billing.paymongo_ready.audio" as-child>
                        <Link
                            href="/settings/billing/checkout"
                            method="post"
                            as="button"
                            :data="{ plan: plan.key, credit_type: 'audio' }"
                        >
                            Buy minutes
                        </Link>
                    </Button>
                    <Button v-else variant="outline" disabled>
                        Configure audio checkout
                    </Button>

                    <Button
                        v-if="billing.paymongo_ready.polish"
                        as-child
                        variant="outline"
                    >
                        <Link
                            href="/settings/billing/checkout"
                            method="post"
                            as="button"
                            :data="{ plan: plan.key, credit_type: 'polish' }"
                        >
                            Buy polish characters
                        </Link>
                    </Button>
                    <Button v-else variant="outline" disabled>
                        Configure polish checkout
                    </Button>

                    <Button
                        v-if="billing.paymongo_ready.summary"
                        as-child
                        variant="outline"
                    >
                        <Link
                            href="/settings/billing/checkout"
                            method="post"
                            as="button"
                            :data="{ plan: plan.key, credit_type: 'summary' }"
                        >
                            Buy summarize characters
                        </Link>
                    </Button>
                    <Button v-else variant="outline" disabled>
                        Configure summarize checkout
                    </Button>
                </div>
            </article>
        </section>
    </div>
</template>
