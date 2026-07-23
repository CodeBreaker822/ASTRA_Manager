<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

type Plan = {
    key: string;
    name: string;
    tagline: string;
    monthly_price: number | null;
    yearly_price: number | null;
    price_label: string;
    price_per_second: number;
    minutes: number;
    free_polish_uses_per_day: number;
    free_summary_uses_per_day: number;
    polish_characters: number;
    summary_characters: number;
    cta: string;
    featured: boolean;
    features: string[];
};

const props = defineProps<{
    plans: Plan[];
    comparison: Record<string, string[]>;
    content: {
        hero: {
            eyebrow: string;
            title: string;
            intro: string;
        };
        faq: Array<{
            question: string;
            answer: string;
        }>;
    };
}>();

const planPrice = (plan: Plan): string => {
    return plan.price_label;
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
                    {{ content.hero.eyebrow }}
                </p>
                <h1
                    class="mx-auto mt-4 max-w-3xl text-4xl font-semibold tracking-tight text-slate-950 md:text-5xl"
                >
                    {{ content.hero.title }}
                </h1>
                <p
                    class="mx-auto mt-5 max-w-2xl text-base leading-7 text-slate-700"
                >
                    {{ content.hero.intro }}
                </p>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto grid max-w-6xl gap-6 px-6 lg:grid-cols-3">
                <article
                    v-for="plan in plans"
                    :key="plan.key"
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
                    </div>
                    <p class="mt-2 text-sm text-slate-600">
                        {{
                            plan.key === 'free'
                                ? `${plan.minutes.toLocaleString()} minutes, ${plan.free_polish_uses_per_day} polish, and ${plan.free_summary_uses_per_day} summarize reset every day`
                                : `${plan.minutes.toLocaleString()} minutes, ${plan.polish_characters.toLocaleString()} polish characters, or ${plan.summary_characters.toLocaleString()} summarize characters`
                        }}
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
                    Compare credit packs
                </h2>
                <div
                    class="mt-8 overflow-hidden rounded-lg border border-slate-200 bg-white"
                >
                    <div
                        class="grid grid-cols-[1.4fr_repeat(2,1fr)] border-b border-slate-200 bg-slate-50"
                    >
                        <div class="p-4 text-sm font-semibold text-slate-950">
                            Feature
                        </div>
                        <div
                            v-for="plan in plans"
                            :key="plan.key"
                            class="p-4 text-sm font-semibold text-slate-950"
                        >
                            {{ plan.name }}
                        </div>
                    </div>
                    <div
                        v-for="[feature, enabledPlans] in comparisonRows"
                        :key="feature"
                        class="grid grid-cols-[1.4fr_repeat(2,1fr)] border-b border-slate-200 last:border-b-0"
                    >
                        <div class="p-4 text-sm text-slate-700">
                            {{ feature }}
                        </div>
                        <div
                            v-for="plan in plans"
                            :key="`${feature}-${plan.key}`"
                            class="p-4 text-sm text-slate-700"
                        >
                            <Check
                                v-if="enabledPlans.includes(plan.key)"
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
                        v-for="(item, index) in content.faq"
                        :key="item.question"
                        class="rounded-lg border border-slate-200 bg-white p-4"
                        :open="index === 0"
                    >
                        <summary
                            class="cursor-pointer text-sm font-semibold text-slate-950"
                        >
                            {{ item.question }}
                        </summary>
                        <p class="mt-3 text-sm leading-6 text-slate-700">
                            {{ item.answer }}
                        </p>
                    </details>
                </div>
            </div>
        </section>
    </main>
</template>
