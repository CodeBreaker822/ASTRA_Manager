<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    AudioLines,
    BriefcaseBusiness,
    Check,
    Download,
    FileAudio,
    FileOutput,
    GraduationCap,
    Headphones,
    Laptop,
    Mic,
    Podcast,
    SearchCheck,
    ShieldCheck,
    Upload,
} from '@lucide/vue';
import PricingProof from '@/components/marketing/PricingProof.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import { Button } from '@/components/ui/button';
import type {
    FaqItem,
    MarketingPricing,
    MarketingSeo,
} from '@/types/marketing';

type ContentItem = {
    title: string;
    body: string;
};

type AudioToTextContent = {
    seo: {
        title: string;
        description: string;
    };
    hero: {
        eyebrow: string;
        title: string;
        intro: string;
        primary_button_label: string;
        primary_button_url: string;
        secondary_button_label: string;
        secondary_button_url: string;
        note: string;
        formats: string[];
    };
    hero_preview: {
        title: string;
        subtitle: string;
        steps: string[];
        formats_label: string;
    };
    benefits: {
        title: string;
        intro: string;
        items: ContentItem[];
    };
    workflow: {
        title: string;
        intro: string;
        step_label: string;
        steps: ContentItem[];
    };
    use_cases: {
        title: string;
        items: ContentItem[];
    };
    comparison: {
        title: string;
        intro: string;
        label_column: string;
        online_column: string;
        desktop_column: string;
        rows: Array<{
            label: string;
            online: string;
            desktop: string;
        }>;
    };
    limitations: {
        title: string;
        body: string;
        items: string[];
    };
    guides: {
        title: string;
        read_label: string;
        items: Array<{ title: string; url: string }>;
    };
    faq: FaqItem[];
    faq_heading: { title: string };
    cta: {
        title: string;
        body: string;
        primary_button_label: string;
        primary_button_url: string;
        secondary_button_label: string;
        secondary_button_url: string;
    };
};

defineProps<{
    content: AudioToTextContent;
    pricing: MarketingPricing;
    seo: MarketingSeo;
}>();

const benefitIcons = [AudioLines, SearchCheck, Laptop, FileOutput];
const useCaseIcons = [BriefcaseBusiness, Headphones, GraduationCap, Podcast];
</script>

<template>
    <SeoHead :seo="seo" />

    <main>
        <section class="border-b border-slate-200 bg-white">
            <div
                class="mx-auto grid max-w-6xl items-center gap-12 px-6 py-16 md:py-24 lg:grid-cols-[1fr_0.82fr]"
            >
                <div>
                    <p
                        class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                    >
                        {{ content.hero.eyebrow }}
                    </p>
                    <h1
                        class="mt-4 text-4xl leading-tight font-semibold tracking-tight text-slate-950 md:text-5xl"
                    >
                        {{ content.hero.title }}
                    </h1>
                    <p
                        class="mt-5 max-w-2xl text-base leading-7 text-slate-700"
                    >
                        {{ content.hero.intro }}
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <Button as-child size="lg">
                            <Link :href="content.hero.primary_button_url">
                                {{ content.hero.primary_button_label }}
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                        <Button as-child size="lg" variant="outline">
                            <Link :href="content.hero.secondary_button_url">
                                <Download class="size-4" />
                                {{ content.hero.secondary_button_label }}
                            </Link>
                        </Button>
                    </div>

                    <p class="mt-5 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ content.hero.note }}
                    </p>
                </div>

                <div
                    class="rounded-xl border border-blue-100 bg-blue-50 p-6 shadow-[0_18px_48px_rgba(15,23,42,0.08)]"
                >
                    <div class="rounded-lg border border-blue-100 bg-white p-5">
                        <div class="flex items-center gap-4">
                            <span
                                class="flex size-12 items-center justify-center rounded-lg bg-blue-600 text-white"
                            >
                                <FileAudio class="size-6" />
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-slate-950">
                                    {{ content.hero_preview.title }}
                                </p>
                                <p class="mt-1 text-xs text-slate-600">
                                    {{ content.hero_preview.subtitle }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-6 grid gap-3">
                            <div
                                v-for="(label, index) in content.hero_preview
                                    .steps"
                                :key="label"
                                class="flex items-center gap-3 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-700"
                            >
                                <span
                                    class="flex size-7 items-center justify-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700"
                                >
                                    {{ index + 1 }}
                                </span>
                                <span>{{ label }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5">
                        <p class="text-xs font-semibold text-blue-950">
                            {{ content.hero_preview.formats_label }}
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span
                                v-for="format in content.hero.formats"
                                :key="format"
                                class="rounded-full border border-blue-200 bg-white px-3 py-1 text-xs font-semibold text-blue-700"
                            >
                                {{ format }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-slate-50 px-6 py-10">
            <PricingProof :pricing="pricing" class="mx-auto max-w-6xl" />
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-3xl">
                    <h2
                        class="text-3xl font-semibold tracking-tight text-slate-950"
                    >
                        {{ content.benefits.title }}
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-700">
                        {{ content.benefits.intro }}
                    </p>
                </div>
                <div class="mt-10 grid gap-6 md:grid-cols-2">
                    <article
                        v-for="(item, index) in content.benefits.items"
                        :key="item.title"
                        class="rounded-lg border border-slate-200 bg-white p-6"
                    >
                        <component
                            :is="benefitIcons[index] ?? AudioLines"
                            class="size-5 text-blue-600"
                        />
                        <h3 class="mt-4 text-lg font-semibold text-slate-950">
                            {{ item.title }}
                        </h3>
                        <p class="mt-2 text-sm leading-6 text-slate-700">
                            {{ item.body }}
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <section class="bg-slate-50 py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-3xl">
                    <h2
                        class="text-3xl font-semibold tracking-tight text-slate-950"
                    >
                        {{ content.workflow.title }}
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-700">
                        {{ content.workflow.intro }}
                    </p>
                </div>
                <ol class="mt-10 grid gap-6 md:grid-cols-3">
                    <li
                        v-for="(step, index) in content.workflow.steps"
                        :key="step.title"
                        class="rounded-lg border border-slate-200 bg-white p-6"
                    >
                        <component
                            :is="[Upload, AudioLines, FileOutput][index] ?? Mic"
                            class="size-6 text-blue-600"
                        />
                        <p
                            class="mt-5 text-xs font-semibold tracking-wide text-blue-600 uppercase"
                        >
                            {{ content.workflow.step_label }} {{ index + 1 }}
                        </p>
                        <h3 class="mt-2 text-lg font-semibold text-slate-950">
                            {{ step.title }}
                        </h3>
                        <p class="mt-2 text-sm leading-6 text-slate-700">
                            {{ step.body }}
                        </p>
                    </li>
                </ol>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <h2
                    class="max-w-3xl text-3xl font-semibold tracking-tight text-slate-950"
                >
                    {{ content.use_cases.title }}
                </h2>
                <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <article
                        v-for="(item, index) in content.use_cases.items"
                        :key="item.title"
                        class="rounded-lg border border-slate-200 bg-white p-6"
                    >
                        <component
                            :is="useCaseIcons[index] ?? Mic"
                            class="size-5 text-blue-600"
                        />
                        <h3 class="mt-4 text-lg font-semibold text-slate-950">
                            {{ item.title }}
                        </h3>
                        <p class="mt-2 text-sm leading-6 text-slate-700">
                            {{ item.body }}
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <section class="bg-slate-50 py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-3xl">
                    <h2
                        class="text-3xl font-semibold tracking-tight text-slate-950"
                    >
                        {{ content.comparison.title }}
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-700">
                        {{ content.comparison.intro }}
                    </p>
                </div>
                <div
                    class="mt-10 overflow-x-auto rounded-lg border border-slate-200 bg-white"
                >
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="bg-slate-100 text-slate-950">
                            <tr>
                                <th class="px-5 py-4 font-semibold">
                                    {{ content.comparison.label_column }}
                                </th>
                                <th class="px-5 py-4 font-semibold">
                                    {{ content.comparison.online_column }}
                                </th>
                                <th class="px-5 py-4 font-semibold">
                                    {{ content.comparison.desktop_column }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr
                                v-for="row in content.comparison.rows"
                                :key="row.label"
                            >
                                <th
                                    class="px-5 py-4 font-semibold text-slate-950"
                                >
                                    {{ row.label }}
                                </th>
                                <td class="px-5 py-4 leading-6 text-slate-700">
                                    {{ row.online }}
                                </td>
                                <td class="px-5 py-4 leading-6 text-slate-700">
                                    {{ row.desktop }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="bg-blue-950 py-16 text-white md:py-20">
            <div
                class="mx-auto grid max-w-6xl gap-8 px-6 lg:grid-cols-[0.28fr_1fr] lg:items-start"
            >
                <div
                    class="flex size-20 items-center justify-center rounded-lg border border-blue-700 bg-blue-900"
                >
                    <ShieldCheck class="size-9 text-blue-200" />
                </div>
                <div>
                    <h2 class="text-3xl font-semibold">
                        {{ content.limitations.title }}
                    </h2>
                    <p class="mt-4 max-w-3xl text-base leading-7 text-blue-100">
                        {{ content.limitations.body }}
                    </p>
                    <ul class="mt-6 grid gap-3">
                        <li
                            v-for="item in content.limitations.items"
                            :key="item"
                            class="flex items-start gap-3 text-sm leading-6 text-blue-100"
                        >
                            <Check class="mt-1 size-4 shrink-0 text-blue-300" />
                            <span>{{ item }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <h2
                    class="text-3xl font-semibold tracking-tight text-slate-950"
                >
                    {{ content.guides.title }}
                </h2>
                <div class="mt-8 grid gap-4 md:grid-cols-3">
                    <Link
                        v-for="guide in content.guides.items"
                        :key="guide.url"
                        :href="guide.url"
                        class="group rounded-lg border border-slate-200 bg-white p-5 hover:border-blue-200 hover:bg-blue-50"
                    >
                        <FileAudio class="size-5 text-blue-600" />
                        <h3
                            class="mt-4 font-semibold text-slate-950 group-hover:text-blue-700"
                        >
                            {{ guide.title }}
                        </h3>
                        <span
                            class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-blue-600"
                        >
                            {{ content.guides.read_label }}
                            <ArrowRight class="size-4" />
                        </span>
                    </Link>
                </div>
            </div>
        </section>

        <section class="bg-slate-50 py-16 md:py-24">
            <div class="mx-auto max-w-3xl px-6">
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

        <section class="bg-white px-6 py-16 md:py-24">
            <div
                class="mx-auto flex max-w-6xl flex-col items-start justify-between gap-6 rounded-lg border border-blue-100 bg-blue-50 p-6 md:flex-row md:items-center"
            >
                <div>
                    <h2 class="text-2xl font-semibold text-blue-950">
                        {{ content.cta.title }}
                    </h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-blue-900">
                        {{ content.cta.body }}
                    </p>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <Button as-child>
                        <Link :href="content.cta.primary_button_url">
                            {{ content.cta.primary_button_label }}
                        </Link>
                    </Button>
                    <Button as-child variant="outline">
                        <Link :href="content.cta.secondary_button_url">
                            {{ content.cta.secondary_button_label }}
                        </Link>
                    </Button>
                </div>
            </div>
        </section>
    </main>
</template>
