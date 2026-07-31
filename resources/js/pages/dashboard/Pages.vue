<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import DashboardLayout from '@/layouts/dashboard/Layout.vue';

type SeoContent = { title: string; description: string };
type HeroContent = {
    eyebrow: string;
    title: string;
    intro: string;
    online_button_label?: string;
    desktop_button_label?: string;
};
type FaqItem = { question: string; answer: string };
type TextItem = { title: string; body: string };
type IconItem = TextItem & { icon: string };
type FeatureRow = IconItem & {
    eyebrow: string;
    bullets: string[];
};

type HomeContent = {
    seo: SeoContent;
    hero: HeroContent & {
        online_button_label: string;
        desktop_button_label: string;
    };
    paths: Array<
        TextItem & {
            eyebrow: string;
            bullets: string[];
            button_label: string;
            button_url: string;
        }
    >;
    workflow: { title: string; intro: string; steps: TextItem[] };
    use_cases: { title: string; intro: string; items: TextItem[] };
    vad: { eyebrow: string; title: string; body: string; note: string };
    faq: FaqItem[];
    cta: {
        title: string;
        body: string;
        online_button_label: string;
        desktop_button_label: string;
    };
};

type FeaturesContent = {
    seo: SeoContent;
    hero: HeroContent;
    feature_rows: FeatureRow[];
    comparison: {
        title: string;
        intro: string;
        rows: Array<{ label: string; online: string; desktop: string }>;
    };
    faq: FaqItem[];
    cta: {
        title: string;
        body: string;
        online_button_label: string;
        desktop_button_label: string;
    };
};

type DownloadContent = {
    seo: SeoContent;
    hero: HeroContent;
    download_card: {
        title: string;
        body: string;
        button_label: string;
        empty_label: string;
    };
    benefits: IconItem[];
    models: {
        title: string;
        intro: string;
        items: Array<{ name: string; size: string; best_for: string }>;
        note: string;
    };
    requirements: IconItem[];
    steps: { title: string; items: TextItem[] };
    account: {
        title: string;
        body: string;
        bullets: string[];
        button_label: string;
    };
    faq: FaqItem[];
};

type PageFormContent = HomeContent | FeaturesContent | DownloadContent;

const props = defineProps<{
    pageKey: 'home' | 'features' | 'download';
    title: string;
    content: PageFormContent;
}>();

defineOptions({ layout: DashboardLayout });

const form = useForm({
    content: JSON.parse(JSON.stringify(props.content)) as PageFormContent,
});
const isHome = computed(() => props.pageKey === 'home');
const isFeatures = computed(() => props.pageKey === 'features');
const isDownload = computed(() => props.pageKey === 'download');
const homeContent = computed(() => form.content as HomeContent);
const featuresContent = computed(() => form.content as FeaturesContent);
const downloadContent = computed(() => form.content as DownloadContent);
const faqContent = computed(() => {
    if (isHome.value) return homeContent.value.faq;
    if (isFeatures.value) return featuresContent.value.faq;
    return downloadContent.value.faq;
});

const submit = () => {
    form.put(`/dashboard/pages/${props.pageKey}`, { preserveScroll: true });
};
</script>

<template>
    <Head :title="`${title} Page Manager`" />

    <form class="space-y-6" @submit.prevent="submit">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-slate-950">
                    {{ title }}
                </h1>
                <p class="mt-1 text-sm text-slate-700">
                    Manage the public copy and search preview for this page.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="page in ['home', 'features', 'download']"
                    :key="page"
                    as-child
                    :variant="pageKey === page ? 'default' : 'outline'"
                >
                    <Link :href="`/dashboard/pages/${page}`">
                        {{ page.charAt(0).toUpperCase() + page.slice(1) }}
                    </Link>
                </Button>
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    <Save v-else class="size-4" />
                    Save
                </Button>
            </div>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-950">
                Search appearance
            </h2>
            <p class="mt-1 text-sm text-slate-600">
                Keep the title specific and the description natural. Canonical,
                social, and robots tags are generated automatically.
            </p>
            <div class="mt-4 grid gap-4">
                <div class="grid gap-2">
                    <Label for="seo-title">SEO title</Label>
                    <Input
                        id="seo-title"
                        v-model="form.content.seo.title"
                        class="h-11"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="seo-description">Meta description</Label>
                    <textarea
                        id="seo-description"
                        v-model="form.content.seo.description"
                        rows="3"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                    />
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-950">Hero</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="hero-eyebrow">Eyebrow</Label>
                    <Input
                        id="hero-eyebrow"
                        v-model="form.content.hero.eyebrow"
                        class="h-11"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="hero-title">H1 title</Label>
                    <Input
                        id="hero-title"
                        v-model="form.content.hero.title"
                        class="h-11"
                    />
                </div>
                <div class="grid gap-2 md:col-span-2">
                    <Label for="hero-intro">Introduction</Label>
                    <textarea
                        id="hero-intro"
                        v-model="form.content.hero.intro"
                        rows="4"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                    />
                </div>
                <template v-if="isHome">
                    <div class="grid gap-2">
                        <Label>Online button</Label>
                        <Input
                            v-model="homeContent.hero.online_button_label"
                            class="h-11"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Desktop button</Label>
                        <Input
                            v-model="homeContent.hero.desktop_button_label"
                            class="h-11"
                        />
                    </div>
                </template>
            </div>
        </section>

        <template v-if="isHome">
            <section class="grid gap-4 md:grid-cols-2">
                <article
                    v-for="(path, index) in homeContent.paths"
                    :key="index"
                    class="rounded-lg border border-slate-200 bg-white p-5"
                >
                    <h2 class="text-base font-semibold text-slate-950">
                        Transcription option {{ index + 1 }}
                    </h2>
                    <div class="mt-4 grid gap-3">
                        <Input v-model="path.eyebrow" placeholder="Eyebrow" />
                        <Input v-model="path.title" placeholder="Title" />
                        <textarea
                            v-model="path.body"
                            rows="4"
                            class="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                            placeholder="Description"
                        />
                        <Input
                            v-for="(_, bulletIndex) in path.bullets"
                            :key="bulletIndex"
                            v-model="path.bullets[bulletIndex]"
                            placeholder="Benefit"
                        />
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Input
                                v-model="path.button_label"
                                placeholder="Button label"
                            />
                            <Input
                                v-model="path.button_url"
                                placeholder="/destination"
                            />
                        </div>
                    </div>
                </article>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-950">Workflow</h2>
                <div class="mt-4 grid gap-3">
                    <Input v-model="homeContent.workflow.title" />
                    <textarea
                        v-model="homeContent.workflow.intro"
                        rows="3"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                    />
                    <div class="grid gap-3 md:grid-cols-3">
                        <article
                            v-for="(step, index) in homeContent.workflow.steps"
                            :key="index"
                            class="grid gap-3 rounded-lg bg-slate-50 p-4"
                        >
                            <Input v-model="step.title" />
                            <textarea
                                v-model="step.body"
                                rows="4"
                                class="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                            />
                        </article>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-950">
                    Use cases
                </h2>
                <div class="mt-4 grid gap-3">
                    <Input v-model="homeContent.use_cases.title" />
                    <textarea
                        v-model="homeContent.use_cases.intro"
                        rows="3"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                    />
                    <div class="grid gap-3 md:grid-cols-3">
                        <article
                            v-for="(item, index) in homeContent.use_cases.items"
                            :key="index"
                            class="grid gap-3 rounded-lg bg-slate-50 p-4"
                        >
                            <Input v-model="item.title" />
                            <textarea
                                v-model="item.body"
                                rows="4"
                                class="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                            />
                        </article>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-950">
                    Voice activity detection
                </h2>
                <div class="mt-4 grid gap-3">
                    <Input v-model="homeContent.vad.eyebrow" />
                    <Input v-model="homeContent.vad.title" />
                    <textarea
                        v-model="homeContent.vad.body"
                        rows="4"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                    />
                    <Input v-model="homeContent.vad.note" />
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-950">
                    Final call to action
                </h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <Input v-model="homeContent.cta.title" />
                    <textarea
                        v-model="homeContent.cta.body"
                        rows="3"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm md:col-span-2"
                    />
                    <Input v-model="homeContent.cta.online_button_label" />
                    <Input v-model="homeContent.cta.desktop_button_label" />
                </div>
            </section>
        </template>

        <template v-if="isFeatures">
            <section class="grid gap-4">
                <article
                    v-for="(row, index) in featuresContent.feature_rows"
                    :key="index"
                    class="rounded-lg border border-slate-200 bg-white p-5"
                >
                    <h2 class="text-base font-semibold text-slate-950">
                        Feature {{ index + 1 }}
                    </h2>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <Input v-model="row.eyebrow" placeholder="Eyebrow" />
                        <select
                            v-model="row.icon"
                            class="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm"
                        >
                            <option
                                v-for="icon in [
                                    'Network',
                                    'Mic',
                                    'Languages',
                                    'FileAudio',
                                    'Sparkles',
                                    'FileSpreadsheet',
                                ]"
                                :key="icon"
                                :value="icon"
                            >
                                {{ icon }}
                            </option>
                        </select>
                        <Input
                            v-model="row.title"
                            class="md:col-span-2"
                            placeholder="Title"
                        />
                        <textarea
                            v-model="row.body"
                            rows="3"
                            class="rounded-lg border border-slate-200 px-3 py-2 text-sm md:col-span-2"
                        />
                        <Input
                            v-for="(_, bulletIndex) in row.bullets"
                            :key="bulletIndex"
                            v-model="row.bullets[bulletIndex]"
                            placeholder="Benefit"
                        />
                    </div>
                </article>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-950">
                    Online versus desktop
                </h2>
                <div class="mt-4 grid gap-3">
                    <Input v-model="featuresContent.comparison.title" />
                    <textarea
                        v-model="featuresContent.comparison.intro"
                        rows="3"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                    />
                    <div
                        v-for="(row, index) in featuresContent.comparison.rows"
                        :key="index"
                        class="grid gap-3 rounded-lg bg-slate-50 p-4 md:grid-cols-3"
                    >
                        <Input v-model="row.label" placeholder="Category" />
                        <Input v-model="row.online" placeholder="Online" />
                        <Input v-model="row.desktop" placeholder="Desktop" />
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-950">
                    Final call to action
                </h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <Input v-model="featuresContent.cta.title" />
                    <textarea
                        v-model="featuresContent.cta.body"
                        rows="3"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm md:col-span-2"
                    />
                    <Input v-model="featuresContent.cta.online_button_label" />
                    <Input v-model="featuresContent.cta.desktop_button_label" />
                </div>
            </section>
        </template>

        <template v-if="isDownload">
            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-950">
                    Download card
                </h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <Input v-model="downloadContent.download_card.title" />
                    <Input
                        v-model="downloadContent.download_card.button_label"
                    />
                    <Input
                        v-model="downloadContent.download_card.empty_label"
                    />
                    <textarea
                        v-model="downloadContent.download_card.body"
                        rows="3"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm md:col-span-2"
                    />
                </div>
            </section>

            <section class="grid gap-4 md:grid-cols-2">
                <article
                    v-for="(benefit, index) in downloadContent.benefits"
                    :key="index"
                    class="rounded-lg border border-slate-200 bg-white p-5"
                >
                    <h2 class="text-base font-semibold text-slate-950">
                        Benefit {{ index + 1 }}
                    </h2>
                    <div class="mt-4 grid gap-3">
                        <Input v-model="benefit.icon" placeholder="Icon name" />
                        <Input v-model="benefit.title" />
                        <textarea
                            v-model="benefit.body"
                            rows="3"
                            class="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                        />
                    </div>
                </article>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-950">
                    Whisper models
                </h2>
                <div class="mt-4 grid gap-3">
                    <Input v-model="downloadContent.models.title" />
                    <textarea
                        v-model="downloadContent.models.intro"
                        rows="3"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                    />
                    <div
                        v-for="(model, index) in downloadContent.models.items"
                        :key="index"
                        class="grid gap-3 rounded-lg bg-slate-50 p-4 md:grid-cols-[1fr_1fr_2fr]"
                    >
                        <Input v-model="model.name" placeholder="Model" />
                        <Input v-model="model.size" placeholder="Size" />
                        <Input
                            v-model="model.best_for"
                            placeholder="Best for"
                        />
                    </div>
                    <Input
                        v-model="downloadContent.models.note"
                        placeholder="Accuracy and performance note"
                    />
                </div>
            </section>

            <section class="grid gap-4 md:grid-cols-2">
                <article
                    v-for="(requirement, index) in downloadContent.requirements"
                    :key="index"
                    class="rounded-lg border border-slate-200 bg-white p-5"
                >
                    <h2 class="text-base font-semibold text-slate-950">
                        Requirement {{ index + 1 }}
                    </h2>
                    <div class="mt-4 grid gap-3">
                        <Input
                            v-model="requirement.icon"
                            placeholder="Icon name"
                        />
                        <Input v-model="requirement.title" />
                        <textarea
                            v-model="requirement.body"
                            rows="3"
                            class="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                        />
                    </div>
                </article>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-950">
                    First transcript steps
                </h2>
                <div class="mt-4 grid gap-3">
                    <Input v-model="downloadContent.steps.title" />
                    <div class="grid gap-3 md:grid-cols-3">
                        <article
                            v-for="(step, index) in downloadContent.steps.items"
                            :key="index"
                            class="grid gap-3 rounded-lg bg-slate-50 p-4"
                        >
                            <Input v-model="step.title" />
                            <textarea
                                v-model="step.body"
                                rows="4"
                                class="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                            />
                        </article>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-950">
                    Optional online account
                </h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <Input v-model="downloadContent.account.title" />
                    <Input v-model="downloadContent.account.button_label" />
                    <textarea
                        v-model="downloadContent.account.body"
                        rows="3"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm md:col-span-2"
                    />
                    <Input
                        v-for="(_, index) in downloadContent.account.bullets"
                        :key="index"
                        v-model="downloadContent.account.bullets[index]"
                    />
                </div>
            </section>
        </template>

        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-950">
                Frequently asked questions
            </h2>
            <div class="mt-4 grid gap-4">
                <article
                    v-for="(item, index) in faqContent"
                    :key="index"
                    class="grid gap-3 rounded-lg bg-slate-50 p-4"
                >
                    <Input v-model="item.question" placeholder="Question" />
                    <textarea
                        v-model="item.answer"
                        rows="3"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                        placeholder="Answer"
                    />
                </article>
            </div>
        </section>
    </form>
</template>
