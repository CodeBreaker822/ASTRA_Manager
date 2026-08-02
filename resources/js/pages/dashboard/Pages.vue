<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import type { RequestPayload } from '@inertiajs/core';
import {
    AlertCircle,
    CheckCircle2,
    ExternalLink,
    FilePenLine,
    LayoutTemplate,
    Save,
    Search,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ContentField from '@/components/cms/ContentField.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import DashboardLayout from '@/layouts/dashboard/Layout.vue';

type JsonPrimitive = string | number | boolean | null;
type JsonValue = JsonPrimitive | JsonObject | JsonValue[];
type JsonObject = { [key: string]: JsonValue };

type PageOption = {
    key: string;
    label: string;
    description: string;
    preview_url: string;
};

const props = defineProps<{
    pageKey: string;
    title: string;
    description: string;
    previewUrl: string;
    pageOptions: PageOption[];
    content: JsonObject;
    schema: JsonObject;
}>();

defineOptions({
    layout: DashboardLayout,
});

const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value)) as T;

const content = ref<JsonObject>(clone(props.content));
const originalContent = ref(JSON.stringify(props.content));
const processing = ref(false);
const recentlySaved = ref(false);
const errors = ref<Record<string, string>>({});
let savedTimer: ReturnType<typeof setTimeout> | null = null;
let removeNavigationGuard: VoidFunction | null = null;
let allowNextVisit = false;
const activeSection = ref(Object.keys(props.schema)[0] ?? '');
const isDirty = computed(
    () => JSON.stringify(content.value) !== originalContent.value,
);

const sectionNames: Record<string, string> = {
    seo: 'Search preview',
    schema: 'Search data',
    brand: 'Brand',
    navigation: 'Header navigation',
    footer: 'Footer',
    pricing_proof: 'Shared pricing facts',
    hero: 'Hero section',
    workspace_preview: 'Workspace preview',
    pricing_note: 'Pricing note',
    paths_intro: 'Workflow choices heading',
    paths: 'Transcription choices',
    workflow: 'How it works',
    use_cases: 'Use cases',
    vad: 'Voice activity detection',
    benefits: 'Benefits',
    benefits_intro: 'Benefits heading',
    hero_preview: 'Hero illustration',
    comparison: 'Comparison',
    limitations: 'Important limitations',
    guides: 'Recommended guides',
    feature_rows: 'Feature sections',
    feature_visual: 'Feature illustration labels',
    download_card: 'Download card',
    models: 'Whisper models',
    requirements: 'System requirements',
    requirements_intro: 'Requirements heading',
    steps: 'Getting started steps',
    account: 'Online account option',
    topics: 'Blog topics',
    index: 'Blog landing labels',
    article: 'Article layout',
    article_cta: 'Article call to action',
    plans_ui: 'Pricing-card labels',
    faq_heading: 'FAQ heading',
    faq: 'Frequently asked questions',
    cta: 'Final call to action',
};

const sectionDescriptions: Record<string, string> = {
    seo: 'Control the page title and summary shown by search engines and social previews.',
    schema: 'Advanced names and descriptions supplied to search engines as structured data.',
    navigation: 'Edit the main menu, sign-in link, and primary header button.',
    pricing_proof: 'These five fact boxes appear on several marketing pages.',
    workspace_preview:
        'Edit the example workspace shown beside the home-page headline.',
    article: 'Labels and safety copy shared by every published blog article.',
    plans_ui:
        'Edit public labels only. Use Pricing Manager to change rates and allowances.',
    faq: 'Add, reorder, or remove questions. Keep answers direct and honest.',
};

const humanize = (key: string): string =>
    sectionNames[key] ??
    key.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

const sections = computed(() =>
    Object.keys(props.schema).map((key) => ({
        key,
        label: humanize(key),
        description:
            sectionDescriptions[key] ??
            `Edit the content used in the ${humanize(key).toLowerCase()} section.`,
    })),
);

const seo = computed(() => {
    const value = content.value.seo;

    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return null;
    }

    const object = value as JsonObject;

    return {
        title: typeof object.title === 'string' ? object.title : '',
        description:
            typeof object.description === 'string' ? object.description : '',
    };
});

const status = computed(() => {
    if (processing.value) {
        return { label: 'Saving…', className: 'text-blue-700', icon: null };
    }

    if (recentlySaved.value) {
        return {
            label: 'Saved',
            className: 'text-emerald-700',
            icon: CheckCircle2,
        };
    }

    if (isDirty.value) {
        return {
            label: 'Unsaved changes',
            className: 'text-amber-700',
            icon: AlertCircle,
        };
    }

    return {
        label: 'All changes saved',
        className: 'text-slate-500',
        icon: CheckCircle2,
    };
});

const errorMessages = computed(() => [...new Set(Object.values(errors.value))]);

const updateSection = (key: string, value: unknown) => {
    content.value[key] = value as JsonValue;
    recentlySaved.value = false;
};

const submit = () => {
    allowNextVisit = true;
    router.put(
        `/dashboard/pages/${props.pageKey}`,
        { content: content.value } as unknown as RequestPayload,
        {
            preserveScroll: true,
            onStart: () => {
                processing.value = true;
                errors.value = {};
            },
            onError: (serverErrors) => {
                errors.value = Object.fromEntries(
                    Object.entries(serverErrors).map(([key, value]) => [
                        key,
                        String(value),
                    ]),
                );
            },
            onSuccess: () => {
                originalContent.value = JSON.stringify(content.value);
                recentlySaved.value = true;

                if (savedTimer) {
                    clearTimeout(savedTimer);
                }

                savedTimer = setTimeout(() => {
                    recentlySaved.value = false;
                }, 3000);
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};

const switchPage = (event: Event) => {
    const target = event.target as HTMLSelectElement;
    const page = target.value;

    if (page === props.pageKey) {
        return;
    }

    if (
        isDirty.value &&
        !window.confirm('Leave this page and discard your unsaved changes?')
    ) {
        target.value = props.pageKey;

        return;
    }

    allowNextVisit = true;
    router.visit(`/dashboard/pages/${page}`);
};

const scrollToSection = (key: string) => {
    activeSection.value = key;
    document.getElementById(`section-${key}`)?.scrollIntoView({
        behavior: 'smooth',
        block: 'start',
    });
};

const beforeUnload = (event: BeforeUnloadEvent) => {
    if (!isDirty.value) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
};

onMounted(() => {
    window.addEventListener('beforeunload', beforeUnload);
    removeNavigationGuard = router.on('before', () => {
        if (allowNextVisit) {
            allowNextVisit = false;

            return true;
        }

        if (!isDirty.value) {
            return true;
        }

        return window.confirm(
            'Leave this page and discard your unsaved changes?',
        );
    });
});
onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', beforeUnload);
    removeNavigationGuard?.();

    if (savedTimer) {
        clearTimeout(savedTimer);
    }
});
</script>

<template>
    <Head :title="`${title} CMS`" />

    <form class="space-y-6" @submit.prevent="submit">
        <div
            class="sticky top-0 z-20 -mx-6 border-b border-slate-200 bg-white/95 px-6 py-3 backdrop-blur"
        >
            <div
                class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-700"
                    >
                        <LayoutTemplate class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <h1
                            class="truncate text-lg font-semibold text-slate-950"
                        >
                            Page Content
                        </h1>
                        <div
                            class="mt-0.5 flex items-center gap-1.5 text-xs"
                            :class="status.className"
                        >
                            <component
                                :is="status.icon"
                                v-if="status.icon"
                                class="size-3.5"
                            />
                            <Spinner v-else class="size-3.5" />
                            {{ status.label }}
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button as-child type="button" variant="outline">
                        <a :href="previewUrl" target="_blank" rel="noreferrer">
                            <ExternalLink class="size-4" />
                            View page
                        </a>
                    </Button>
                    <Button type="submit" :disabled="processing || !isDirty">
                        <Spinner v-if="processing" />
                        <Save v-else class="size-4" />
                        Save changes
                    </Button>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-7xl">
            <div
                class="rounded-xl border border-blue-100 bg-gradient-to-br from-blue-50 to-white p-5"
            >
                <div
                    class="grid gap-4 lg:grid-cols-[minmax(16rem,22rem)_1fr_auto] lg:items-end"
                >
                    <div class="grid gap-2">
                        <label
                            for="page-picker"
                            class="text-xs font-semibold tracking-wide text-blue-700 uppercase"
                        >
                            Page to edit
                        </label>
                        <select
                            id="page-picker"
                            :value="pageKey"
                            class="h-11 rounded-lg border border-blue-200 bg-white px-3 text-sm font-semibold text-slate-950 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                            @change="switchPage"
                        >
                            <option
                                v-for="option in pageOptions"
                                :key="option.key"
                                :value="option.key"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <h2 class="text-xl font-semibold text-slate-950">
                            {{ title }}
                        </h2>
                        <p
                            class="mt-1 max-w-3xl text-sm leading-6 text-slate-600"
                        >
                            {{ description }}
                        </p>
                    </div>
                    <Button
                        v-if="pageKey === 'blog'"
                        as-child
                        type="button"
                        variant="outline"
                    >
                        <a href="/dashboard/blog">
                            <FilePenLine class="size-4" />
                            Edit articles
                        </a>
                    </Button>
                    <Button
                        v-else-if="pageKey === 'pricing'"
                        as-child
                        type="button"
                        variant="outline"
                    >
                        <a href="/dashboard/pricing">
                            <FilePenLine class="size-4" />
                            Edit rates
                        </a>
                    </Button>
                </div>
            </div>
        </div>

        <div
            v-if="errorMessages.length"
            class="mx-auto max-w-7xl rounded-lg border border-red-200 bg-red-50 p-4"
        >
            <div class="flex items-start gap-3">
                <AlertCircle class="mt-0.5 size-5 shrink-0 text-red-600" />
                <div>
                    <h2 class="text-sm font-semibold text-red-950">
                        Some content needs attention
                    </h2>
                    <ul
                        class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-800"
                    >
                        <li v-for="message in errorMessages" :key="message">
                            {{ message }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div
            class="mx-auto grid max-w-7xl gap-6 lg:grid-cols-[15rem_minmax(0,1fr)]"
        >
            <aside class="lg:sticky lg:top-24 lg:self-start">
                <div class="rounded-xl border border-slate-200 bg-white p-3">
                    <p
                        class="px-2 pb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase"
                    >
                        Page sections
                    </p>
                    <nav class="grid gap-1" aria-label="Page sections">
                        <button
                            v-for="section in sections"
                            :key="section.key"
                            type="button"
                            class="rounded-lg px-3 py-2 text-left text-sm transition"
                            :class="
                                activeSection === section.key
                                    ? 'bg-blue-50 font-semibold text-blue-800'
                                    : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950'
                            "
                            @click="scrollToSection(section.key)"
                        >
                            {{ section.label }}
                        </button>
                    </nav>
                </div>
            </aside>

            <div class="grid min-w-0 gap-6">
                <section
                    v-for="section in sections"
                    :id="`section-${section.key}`"
                    :key="section.key"
                    class="scroll-mt-24 rounded-xl border border-slate-200 bg-white shadow-sm"
                    @mouseenter="activeSection = section.key"
                >
                    <div
                        class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4"
                    >
                        <div>
                            <h2 class="text-base font-semibold text-slate-950">
                                {{ section.label }}
                            </h2>
                            <p class="mt-1 text-sm leading-5 text-slate-500">
                                {{ section.description }}
                            </p>
                        </div>
                        <Search
                            v-if="section.key === 'seo'"
                            class="mt-0.5 size-5 shrink-0 text-blue-600"
                        />
                    </div>

                    <div v-if="section.key === 'seo' && seo" class="p-5 pb-0">
                        <div
                            class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                        >
                            <p class="truncate text-xs text-emerald-700">
                                {{ previewUrl }}
                            </p>
                            <p class="mt-1 truncate text-lg text-blue-800">
                                {{ seo.title }}
                            </p>
                            <p
                                class="mt-1 line-clamp-2 text-sm leading-5 text-slate-600"
                            >
                                {{ seo.description }}
                            </p>
                        </div>
                    </div>

                    <div class="p-5">
                        <ContentField
                            :model-value="content[section.key]"
                            :schema="schema[section.key]"
                            :field-key="section.key"
                            :path="`content.${section.key}`"
                            :errors="errors"
                            hide-label
                            @update:model-value="
                                updateSection(section.key, $event)
                            "
                        />
                    </div>
                </section>
            </div>
        </div>
    </form>
</template>
