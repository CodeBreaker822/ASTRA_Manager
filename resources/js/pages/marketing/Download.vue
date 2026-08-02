<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Check,
    Cpu,
    Download,
    HardDrive,
    Laptop,
    Mic,
    MonitorDown,
    Scissors,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import PricingProof from '@/components/marketing/PricingProof.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import { Button } from '@/components/ui/button';
import type {
    FaqItem,
    MarketingPricing,
    MarketingSeo,
} from '@/types/marketing';

type Release = {
    available: boolean;
    platform: string;
    size: string | null;
    published_at: string | null;
    published_at_iso: string | null;
    download_url: string | null;
};

type DownloadContent = {
    seo: {
        title: string;
        description: string;
    };
    hero: {
        eyebrow: string;
        title: string;
        intro: string;
    };
    download_card: {
        badge: string;
        title: string;
        body: string;
        button_label: string;
        empty_label: string;
        size_unavailable_label: string;
        date_unavailable_label: string;
        metadata_separator: string;
        unavailable_body: string;
        models_link_label: string;
        models_link_url: string;
        requirements_link_label: string;
        requirements_link_url: string;
    };
    benefits_intro: {
        title: string;
        intro: string;
    };
    benefits: Array<{
        icon: keyof typeof benefitIconMap;
        title: string;
        body: string;
    }>;
    models: {
        title: string;
        intro: string;
        name_column: string;
        size_column: string;
        best_for_column: string;
        items: Array<{
            name: string;
            size: string;
            best_for: string;
        }>;
        note: string;
    };
    requirements: Array<{
        icon: keyof typeof requirementIconMap;
        title: string;
        body: string;
    }>;
    requirements_intro: { title: string };
    steps: {
        title: string;
        items: Array<{
            title: string;
            body: string;
        }>;
    };
    account: {
        title: string;
        body: string;
        bullets: string[];
        button_label: string;
        button_url: string;
        pricing_link_label: string;
        pricing_link_url: string;
        pricing_link_suffix: string;
    };
    faq: FaqItem[];
    faq_heading: { title: string };
};

const requirementIconMap = {
    Laptop,
    Cpu,
    HardDrive,
    ShieldCheck,
};

const benefitIconMap = {
    ShieldCheck,
    Mic,
    Scissors,
    Users,
};

const props = defineProps<{
    release: Release;
    content: DownloadContent;
    pricing: MarketingPricing;
    seo: MarketingSeo;
}>();

const requirements = computed(() =>
    props.content.requirements.map((requirement) => ({
        ...requirement,
        icon: requirementIconMap[requirement.icon] ?? Laptop,
    })),
);

const benefits = computed(() =>
    props.content.benefits.map((benefit) => ({
        ...benefit,
        icon: benefitIconMap[benefit.icon] ?? ShieldCheck,
    })),
);
</script>

<template>
    <SeoHead :seo="seo" />

    <main>
        <section class="border-b border-slate-200 bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6 text-center">
                <p
                    class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                >
                    {{ content.hero.eyebrow }}
                </p>
                <h1
                    class="mx-auto mt-4 max-w-4xl text-4xl font-semibold tracking-tight text-slate-950 md:text-5xl"
                >
                    {{ content.hero.title }}
                </h1>
                <p
                    class="mx-auto mt-5 max-w-3xl text-base leading-7 text-slate-700"
                >
                    {{ content.hero.intro }}
                </p>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-xl px-6">
                <div
                    class="rounded-lg border border-slate-200 bg-white p-8 text-center shadow-[0_12px_32px_rgba(15,23,42,0.08)]"
                >
                    <MonitorDown class="mx-auto size-10 text-blue-600" />
                    <p
                        class="mt-4 text-xs font-semibold tracking-wide text-blue-600 uppercase"
                    >
                        {{ content.download_card.badge }}
                    </p>
                    <h2 class="mt-3 text-2xl font-semibold text-slate-950">
                        {{ content.download_card.title }}
                    </h2>
                    <p class="mt-3 text-sm leading-6 text-slate-700">
                        {{ content.download_card.body }}
                    </p>

                    <Button
                        v-if="release.available && release.download_url"
                        as-child
                        size="lg"
                        class="mt-8 w-full"
                    >
                        <a :href="release.download_url">
                            <Download class="size-4" />
                            {{ content.download_card.button_label }}
                        </a>
                    </Button>
                    <Button v-else size="lg" class="mt-8 w-full" disabled>
                        {{ content.download_card.empty_label }}
                    </Button>

                    <div class="mt-4 text-sm text-slate-600">
                        <template v-if="release.available">
                            {{ release.platform }}
                            <span aria-hidden="true">
                                {{ content.download_card.metadata_separator }}
                            </span>
                            {{
                                release.size ??
                                content.download_card.size_unavailable_label
                            }}
                            <span aria-hidden="true">
                                {{ content.download_card.metadata_separator }}
                            </span>
                            {{
                                release.published_at ??
                                content.download_card.date_unavailable_label
                            }}
                        </template>
                        <template v-else>
                            {{ content.download_card.unavailable_body }}
                        </template>
                    </div>

                    <div
                        class="mt-6 flex flex-wrap justify-center gap-x-5 gap-y-2 text-sm"
                    >
                        <a
                            class="font-medium text-blue-600 hover:text-blue-700"
                            :href="content.download_card.models_link_url"
                        >
                            {{ content.download_card.models_link_label }}
                        </a>
                        <a
                            class="font-medium text-blue-600 hover:text-blue-700"
                            :href="content.download_card.requirements_link_url"
                        >
                            {{ content.download_card.requirements_link_label }}
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-slate-50 py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-3xl">
                    <h2
                        class="text-3xl font-semibold tracking-tight text-slate-950"
                    >
                        {{ content.benefits_intro.title }}
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-700">
                        {{ content.benefits_intro.intro }}
                    </p>
                </div>
                <div class="mt-10 grid gap-6 md:grid-cols-2">
                    <article
                        v-for="benefit in benefits"
                        :key="benefit.title"
                        class="rounded-lg border border-slate-200 bg-white p-6"
                    >
                        <component
                            :is="benefit.icon"
                            class="size-5 text-blue-600"
                        />
                        <h3 class="mt-4 text-lg font-semibold text-slate-950">
                            {{ benefit.title }}
                        </h3>
                        <p class="mt-2 text-sm leading-6 text-slate-700">
                            {{ benefit.body }}
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <section id="models" class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-3xl">
                    <h2
                        class="text-3xl font-semibold tracking-tight text-slate-950"
                    >
                        {{ content.models.title }}
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-700">
                        {{ content.models.intro }}
                    </p>
                </div>
                <div
                    class="mt-10 overflow-x-auto rounded-lg border border-slate-200"
                >
                    <table class="w-full min-w-[680px] text-left text-sm">
                        <thead class="bg-blue-50 text-blue-950">
                            <tr>
                                <th class="px-5 py-4 font-semibold">
                                    {{ content.models.name_column }}
                                </th>
                                <th class="px-5 py-4 font-semibold">
                                    {{ content.models.size_column }}
                                </th>
                                <th class="px-5 py-4 font-semibold">
                                    {{ content.models.best_for_column }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            <tr
                                v-for="model in content.models.items"
                                :key="model.name"
                            >
                                <th
                                    scope="row"
                                    class="px-5 py-4 font-semibold text-slate-950"
                                >
                                    {{ model.name }}
                                </th>
                                <td class="px-5 py-4 text-slate-700">
                                    {{ model.size }}
                                </td>
                                <td class="px-5 py-4 leading-6 text-slate-700">
                                    {{ model.best_for }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-5 text-sm leading-6 text-slate-600">
                    {{ content.models.note }}
                </p>
            </div>
        </section>

        <section id="requirements" class="bg-slate-50 py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <h2
                    class="text-3xl font-semibold tracking-tight text-slate-950"
                >
                    {{ content.requirements_intro.title }}
                </h2>
                <div class="mt-8 grid gap-6 md:grid-cols-2">
                    <article
                        v-for="requirement in requirements"
                        :key="requirement.title"
                        class="rounded-lg border border-slate-200 bg-white p-6"
                    >
                        <component
                            :is="requirement.icon"
                            class="size-5 text-blue-600"
                        />
                        <h3 class="mt-4 text-lg font-semibold text-slate-950">
                            {{ requirement.title }}
                        </h3>
                        <p class="mt-2 text-sm leading-6 text-slate-700">
                            {{ requirement.body }}
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <h2
                    class="text-3xl font-semibold tracking-tight text-slate-950"
                >
                    {{ content.steps.title }}
                </h2>
                <ol class="mt-10 grid gap-6 md:grid-cols-3">
                    <li
                        v-for="(step, index) in content.steps.items"
                        :key="step.title"
                        class="rounded-lg border border-slate-200 bg-white p-6"
                    >
                        <span
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white"
                        >
                            {{ index + 1 }}
                        </span>
                        <h3 class="mt-5 text-lg font-semibold text-slate-950">
                            {{ step.title }}
                        </h3>
                        <p class="mt-2 text-sm leading-6 text-slate-700">
                            {{ step.body }}
                        </p>
                    </li>
                </ol>
            </div>
        </section>

        <section class="bg-slate-50 px-6 py-16 md:py-24">
            <div
                class="mx-auto max-w-6xl rounded-lg border border-blue-100 bg-blue-50 p-6"
            >
                <div class="grid gap-6 md:grid-cols-[1fr_auto] md:items-center">
                    <div>
                        <h2 class="text-2xl font-semibold text-blue-950">
                            {{ content.account.title }}
                        </h2>
                        <p class="mt-3 text-sm leading-6 text-blue-900">
                            {{ content.account.body }}
                        </p>
                        <div class="mt-5 grid gap-2 text-sm text-blue-900">
                            <div
                                v-for="bullet in content.account.bullets"
                                :key="bullet"
                                class="flex items-start gap-2"
                            >
                                <Check class="mt-0.5 size-4 text-blue-600" />
                                <span>{{ bullet }}</span>
                            </div>
                        </div>
                    </div>
                    <Button as-child>
                        <Link :href="content.account.button_url">{{
                            content.account.button_label
                        }}</Link>
                    </Button>
                </div>
                <PricingProof :pricing="pricing" class="mt-8" />
                <p class="mt-4 text-center text-sm text-blue-900">
                    <Link
                        :href="content.account.pricing_link_url"
                        class="font-semibold text-blue-700 hover:text-blue-950"
                    >
                        {{ content.account.pricing_link_label }}
                    </Link>
                    {{ content.account.pricing_link_suffix }}
                </p>
            </div>
        </section>

        <section class="bg-white px-6 py-16 md:py-24">
            <div class="mx-auto max-w-3xl">
                <h2
                    class="text-3xl font-semibold tracking-tight text-slate-950"
                >
                    {{ content.faq_heading.title }}
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
