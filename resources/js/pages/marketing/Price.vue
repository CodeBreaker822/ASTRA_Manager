<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';

type Plan = {
    name: string;
    tagline: string;
    monthly_price: number | null;
    yearly_price: number | null;
    price_label: string;
    minutes: number;
    cta: string;
    featured: boolean;
    features: string[];
};

const props = defineProps<{
    plans: Plan[];
    comparison: Record<string, string[]>;
}>();

const billingPeriod = ref<'monthly' | 'yearly'>('monthly');

const planPrice = (plan: Plan): string => {
    if (plan.monthly_price === null) {
        return plan.price_label;
    }

    const price =
        billingPeriod.value === 'yearly'
            ? plan.yearly_price
            : plan.monthly_price;

    return `$${price}`;
};

const comparisonRows = computed(() => Object.entries(props.comparison));
</script>

<template>
    <Head title="Price" />

    <main>
        <section class="border-b border-slate-200 bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6 text-center">
                <p
                    class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                >
                    Price
                </p>
                <h1
                    class="mx-auto mt-4 max-w-3xl text-4xl font-semibold tracking-tight text-slate-950 md:text-5xl"
                >
                    Simple pricing
                </h1>
                <p
                    class="mx-auto mt-5 max-w-2xl text-base leading-7 text-slate-700"
                >
                    Start with free upload transcription. Upgrade when your
                    workflow needs live capture, polishing, summaries, and every
                    export format.
                </p>

                <div
                    class="mx-auto mt-8 inline-flex rounded-lg border border-slate-200 bg-white p-1"
                    aria-label="Billing period"
                >
                    <button
                        type="button"
                        class="h-10 rounded-lg px-4 text-sm font-semibold transition-colors"
                        :class="
                            billingPeriod === 'monthly'
                                ? 'bg-blue-600 text-white'
                                : 'text-slate-700 hover:bg-blue-50 hover:text-blue-700'
                        "
                        @click="billingPeriod = 'monthly'"
                    >
                        Monthly
                    </button>
                    <button
                        type="button"
                        class="h-10 rounded-lg px-4 text-sm font-semibold transition-colors"
                        :class="
                            billingPeriod === 'yearly'
                                ? 'bg-blue-600 text-white'
                                : 'text-slate-700 hover:bg-blue-50 hover:text-blue-700'
                        "
                        @click="billingPeriod = 'yearly'"
                    >
                        Yearly
                    </button>
                </div>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto grid max-w-6xl gap-6 px-6 lg:grid-cols-3">
                <article
                    v-for="plan in plans"
                    :key="plan.name"
                    class="rounded-lg border bg-white p-8"
                    :class="
                        plan.featured
                            ? 'border-blue-600 shadow-[0_16px_40px_rgba(15,23,42,0.14)] ring-2 ring-blue-100'
                            : 'border-slate-200'
                    "
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-xl font-semibold text-slate-950">
                                {{ plan.name }}
                            </h2>
                            <p class="mt-2 text-sm leading-6 text-slate-700">
                                {{ plan.tagline }}
                            </p>
                        </div>
                        <span
                            v-if="plan.featured"
                            class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-900"
                        >
                            Popular
                        </span>
                    </div>

                    <div class="mt-8">
                        <span class="text-4xl font-semibold text-slate-950">
                            {{ planPrice(plan) }}
                        </span>
                        <span
                            v-if="plan.monthly_price !== null"
                            class="text-sm text-slate-600"
                        >
                            /month
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-slate-600">
                        {{ plan.minutes.toLocaleString() }} minutes included
                    </p>

                    <Button as-child class="mt-8 w-full">
                        <Link href="/register">{{ plan.cta }}</Link>
                    </Button>

                    <div class="mt-8 grid gap-3">
                        <div
                            v-for="feature in plan.features"
                            :key="feature"
                            class="flex items-start gap-3 text-sm text-slate-700"
                        >
                            <Check class="mt-0.5 size-4 text-blue-600" />
                            <span>{{ feature }}</span>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <section class="bg-slate-50 py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <h2
                    class="text-3xl font-semibold tracking-tight text-slate-950"
                >
                    Compare plans
                </h2>
                <div
                    class="mt-8 overflow-hidden rounded-lg border border-slate-200 bg-white"
                >
                    <div
                        class="grid grid-cols-[1.4fr_repeat(3,1fr)] border-b border-slate-200 bg-slate-50"
                    >
                        <div class="p-4 text-sm font-semibold text-slate-950">
                            Feature
                        </div>
                        <div
                            v-for="plan in plans"
                            :key="plan.name"
                            class="p-4 text-sm font-semibold text-slate-950"
                        >
                            {{ plan.name }}
                        </div>
                    </div>
                    <div
                        v-for="[feature, enabledPlans] in comparisonRows"
                        :key="feature"
                        class="grid grid-cols-[1.4fr_repeat(3,1fr)] border-b border-slate-200 last:border-b-0"
                    >
                        <div class="p-4 text-sm text-slate-700">
                            {{ feature }}
                        </div>
                        <div
                            v-for="plan in plans"
                            :key="`${feature}-${plan.name}`"
                            class="p-4 text-sm text-slate-700"
                        >
                            <Check
                                v-if="
                                    enabledPlans.includes(
                                        plan.name.toLowerCase(),
                                    )
                                "
                                class="size-4 text-blue-600"
                                aria-label="Included"
                            />
                            <span v-else class="text-slate-600">-</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-3xl px-6">
                <h2
                    class="text-3xl font-semibold tracking-tight text-slate-950"
                >
                    FAQ
                </h2>
                <div class="mt-8 grid gap-4">
                    <details
                        class="rounded-lg border border-slate-200 bg-white p-4"
                        open
                    >
                        <summary
                            class="cursor-pointer text-sm font-semibold text-slate-950"
                        >
                            Can I use JERVA Web offline?
                        </summary>
                        <p class="mt-3 text-sm leading-6 text-slate-700">
                            No. The web edition is online-only and uses the
                            server provider pipeline. Offline Whisper remains in
                            the desktop app.
                        </p>
                    </details>
                    <details
                        class="rounded-lg border border-slate-200 bg-white p-4"
                    >
                        <summary
                            class="cursor-pointer text-sm font-semibold text-slate-950"
                        >
                            Is billing active in beta?
                        </summary>
                        <p class="mt-3 text-sm leading-6 text-slate-700">
                            Not yet. These plan definitions prepare the UI and
                            entitlement structure for the later billing phase.
                        </p>
                    </details>
                    <details
                        class="rounded-lg border border-slate-200 bg-white p-4"
                    >
                        <summary
                            class="cursor-pointer text-sm font-semibold text-slate-950"
                        >
                            What happens when I reach my quota?
                        </summary>
                        <p class="mt-3 text-sm leading-6 text-slate-700">
                            The workspace will show a friendly upgrade prompt
                            once quota middleware is added in the workspace
                            phase.
                        </p>
                    </details>
                </div>
            </div>
        </section>
    </main>
</template>
