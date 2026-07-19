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
            features: Record<string, unknown>;
        };
        usage: {
            period: string;
            minutes_used: number;
            minutes_remaining: number;
            seconds_transcribed: number;
            polish_count: number;
            summary_count: number;
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
            Math.max(1, props.entitlements.plan.minutes)) *
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
            description="Review your plan, usage, and SaaS billing status"
        />

        <section
            class="grid gap-4 rounded-lg border border-blue-100 bg-blue-50 p-5 text-blue-950 md:grid-cols-[1fr_auto]"
        >
            <div>
                <p
                    class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                >
                    Current plan
                </p>
                <h2 class="mt-2 text-xl font-semibold">
                    {{ entitlements.plan.name }} Plan
                </h2>
                <p class="mt-2 text-sm leading-6 text-blue-900">
                    {{ entitlements.usage.minutes_remaining }} of
                    {{ entitlements.plan.minutes }} transcription minutes remain
                    for {{ entitlements.usage.period }}.
                </p>
            </div>
            <Button as-child>
                <Link href="/price">Compare plans</Link>
            </Button>
        </section>

        <section class="grid gap-4 md:grid-cols-3">
            <article class="rounded-lg border border-slate-200 bg-white p-5">
                <Gauge class="size-5 text-blue-600" />
                <p class="mt-4 text-sm font-semibold text-slate-950">
                    Monthly usage
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
                    Payment provider
                </p>
                <p class="mt-2 text-sm leading-6 text-slate-700">
                    {{ billing.provider ?? 'paymongo' }}
                </p>
            </article>

            <article class="rounded-lg border border-slate-200 bg-white p-5">
                <LockKeyhole class="size-5 text-blue-600" />
                <p class="mt-4 text-sm font-semibold text-slate-950">
                    Account management
                </p>
                <p class="mt-2 text-sm leading-6 text-slate-700">
                    PayMongo checkout links and webhook handling will appear
                    here after live keys, amounts, and webhook secret are
                    configured.
                </p>
            </article>
        </section>

        <section class="grid gap-4 lg:grid-cols-3">
            <article
                v-for="plan in plans"
                :key="plan.key"
                class="rounded-lg border bg-white p-5"
                :class="
                    plan.key === entitlements.plan.key
                        ? 'border-blue-600 ring-2 ring-blue-100'
                        : 'border-slate-200'
                "
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
                        v-if="plan.key === entitlements.plan.key"
                        class="rounded-lg border border-green-200 bg-green-50 px-2 py-1 text-xs font-semibold text-green-700"
                    >
                        Active
                    </span>
                </div>

                <p class="mt-5 text-2xl font-semibold text-slate-950">
                    {{ plan.price_label }}
                </p>
                <p class="mt-1 text-sm text-slate-600">
                    {{ plan.minutes }} minutes per month
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

                <Button
                    v-if="plan.key === entitlements.plan.key"
                    class="mt-6 w-full"
                    variant="outline"
                    disabled
                >
                    Current plan
                </Button>
                <Button
                    v-else-if="plan.key === 'free'"
                    class="mt-6 w-full"
                    variant="outline"
                    disabled
                >
                    Free plan
                </Button>
                <Button
                    v-else-if="billing.paymongo_ready[plan.key]"
                    as-child
                    class="mt-6 w-full"
                >
                    <Link
                        href="/settings/billing/checkout"
                        method="post"
                        as="button"
                        :data="{ plan: plan.key }"
                    >
                        Pay with PayMongo
                    </Link>
                </Button>
                <Button v-else class="mt-6 w-full" variant="outline" disabled>
                    Configure PayMongo
                </Button>
            </article>
        </section>
    </div>
</template>
