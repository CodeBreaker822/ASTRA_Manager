<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    BriefcaseBusiness,
    Check,
    Download,
    GraduationCap,
    Mic,
    Podcast,
    Scissors,
} from '@lucide/vue';
import PricingProof from '@/components/marketing/PricingProof.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import WorkspacePreview from '@/components/WorkspacePreview.vue';
import { Button } from '@/components/ui/button';
import type {
    FaqItem,
    MarketingPricing,
    MarketingSeo,
} from '@/types/marketing';

type PathCard = {
    eyebrow: string;
    title: string;
    body: string;
    bullets: string[];
    button_label: string;
    button_url: string;
};

type ContentItem = {
    title: string;
    body: string;
};

type HomeContent = {
    seo: {
        title: string;
        description: string;
    };
    hero: {
        eyebrow: string;
        title: string;
        intro: string;
        online_button_label: string;
        desktop_button_label: string;
    };
    paths: PathCard[];
    workflow: {
        title: string;
        intro: string;
        steps: ContentItem[];
    };
    use_cases: {
        title: string;
        intro: string;
        items: ContentItem[];
    };
    vad: {
        eyebrow: string;
        title: string;
        body: string;
        note: string;
    };
    faq: FaqItem[];
    cta: {
        title: string;
        body: string;
        online_button_label: string;
        desktop_button_label: string;
    };
};

defineProps<{
    content: HomeContent;
    pricing: MarketingPricing;
    seo: MarketingSeo;
}>();

const useCaseIcons = [BriefcaseBusiness, GraduationCap, Podcast];
</script>

<template>
    <SeoHead :seo="seo" />

    <main>
        <section class="border-b border-slate-200 bg-white">
            <div
                class="mx-auto grid max-w-6xl items-center gap-12 px-6 py-16 md:py-24 lg:grid-cols-[1fr_0.92fr]"
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
                            <Link href="/register">
                                {{ content.hero.online_button_label }}
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                        <Button as-child size="lg" variant="outline">
                            <Link href="/download">
                                <Download class="size-4" />
                                {{ content.hero.desktop_button_label }}
                            </Link>
                        </Button>
                    </div>
                    <p class="mt-5 text-sm leading-6 text-slate-600">
                        No required subscription. Use the free Windows app, the
                        daily online allowance, or pay only for extra hosted
                        processing.
                    </p>
                </div>

                <WorkspacePreview />
            </div>
        </section>

        <section class="bg-slate-50 px-6 py-10">
            <PricingProof :pricing="pricing" class="mx-auto max-w-6xl" />
            <p
                class="mx-auto mt-4 max-w-6xl text-center text-sm text-slate-600"
            >
                Need more than the daily allowance?
                <Link
                    href="/price"
                    class="font-semibold text-blue-600 hover:text-blue-700"
                >
                    Compare online pricing
                </Link>
                .
            </p>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-3xl">
                    <p
                        class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                    >
                        Choose your workflow
                    </p>
                    <h2
                        class="mt-3 text-3xl font-semibold tracking-tight text-slate-950"
                    >
                        Online convenience or free local processing
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-700">
                        You do not have to force every recording through the
                        same route. Pick the option that makes sense for the
                        computer and audio in front of you.
                    </p>
                </div>

                <div class="mt-10 grid gap-6 lg:grid-cols-2">
                    <article
                        v-for="path in content.paths"
                        :key="path.title"
                        class="flex flex-col rounded-lg border border-slate-200 bg-white p-6 shadow-[0_12px_32px_rgba(15,23,42,0.06)]"
                    >
                        <p
                            class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                        >
                            {{ path.eyebrow }}
                        </p>
                        <h3 class="mt-3 text-2xl font-semibold text-slate-950">
                            {{ path.title }}
                        </h3>
                        <p class="mt-4 text-sm leading-6 text-slate-700">
                            {{ path.body }}
                        </p>
                        <div class="mt-6 grid gap-3">
                            <div
                                v-for="bullet in path.bullets"
                                :key="bullet"
                                class="flex items-start gap-3 text-sm text-slate-700"
                            >
                                <Check class="mt-0.5 size-4 text-blue-600" />
                                <span>{{ bullet }}</span>
                            </div>
                        </div>
                        <Button as-child class="mt-8 self-start">
                            <Link :href="path.button_url">
                                {{ path.button_label }}
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
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

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-3xl">
                    <h2
                        class="text-3xl font-semibold tracking-tight text-slate-950"
                    >
                        {{ content.use_cases.title }}
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-700">
                        {{ content.use_cases.intro }}
                    </p>
                </div>
                <div class="mt-10 grid gap-6 md:grid-cols-3">
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

        <section class="bg-blue-950 py-16 text-white md:py-20">
            <div
                class="mx-auto grid max-w-6xl gap-8 px-6 lg:grid-cols-[0.7fr_1fr] lg:items-center"
            >
                <div
                    class="flex h-24 w-24 items-center justify-center rounded-lg border border-blue-700 bg-blue-900"
                >
                    <Scissors class="size-10 text-blue-200" />
                </div>
                <div>
                    <p
                        class="text-xs font-semibold tracking-wide text-blue-300 uppercase"
                    >
                        {{ content.vad.eyebrow }}
                    </p>
                    <h2 class="mt-3 text-3xl font-semibold">
                        {{ content.vad.title }}
                    </h2>
                    <p class="mt-4 max-w-3xl text-base leading-7 text-blue-100">
                        {{ content.vad.body }}
                    </p>
                    <p class="mt-4 text-sm leading-6 text-blue-300">
                        {{ content.vad.note }}
                    </p>
                </div>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto max-w-3xl px-6">
                <h2
                    class="text-3xl font-semibold tracking-tight text-slate-950"
                >
                    JERVA transcription FAQ
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

        <section class="bg-slate-50 px-6 py-16 md:py-24">
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
                        <Link href="/register">{{
                            content.cta.online_button_label
                        }}</Link>
                    </Button>
                    <Button as-child variant="outline">
                        <Link href="/download">{{
                            content.cta.desktop_button_label
                        }}</Link>
                    </Button>
                </div>
            </div>
        </section>
    </main>
</template>
