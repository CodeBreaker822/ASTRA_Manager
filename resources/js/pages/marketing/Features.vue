<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Check,
    Download,
    FileAudio,
    FileSpreadsheet,
    FileText,
    Languages,
    Mic,
    Network,
    Sparkles,
} from '@lucide/vue';
import { Button } from '@/components/ui/button';

const features = [
    {
        eyebrow: 'Live',
        icon: Mic,
        title: 'Live transcription in the browser',
        body: 'Record from the browser and send short chunks to the server for online transcription.',
        bullets: [
            'MediaRecorder capture',
            'Queued pending clips',
            'Server-side processing',
        ],
    },
    {
        eyebrow: 'Upload',
        icon: FileAudio,
        title: 'Upload audio',
        body: 'Process common audio formats through the same provider pipeline already used by the server.',
        bullets: [
            'WAV, MP3, M4A, AAC, OGG, FLAC',
            'Async job polling',
            'Progress states',
        ],
    },
    {
        eyebrow: 'Polish',
        icon: Languages,
        title: 'Grammar fixes and translation',
        body: 'Use preset instructions for English, Filipino, grammar cleanup, or a custom instruction.',
        bullets: [
            'Translate to English',
            'Translate to Filipino',
            'Custom cleanup prompts',
        ],
    },
    {
        eyebrow: 'Summaries',
        icon: Sparkles,
        title: 'Summarize transcripts',
        body: 'Summarize either raw or cleaned text with the existing LLM cleaner services.',
        bullets: [
            'Raw or cleaned source',
            'Long transcript splitting',
            'Provider fallback',
        ],
    },
    {
        eyebrow: 'Export',
        icon: FileSpreadsheet,
        title: 'Export to TXT, Word, and Excel',
        body: 'Move transcript work into the formats teams already use for review and reporting.',
        bullets: ['Raw exports', 'Cleaned exports', 'Signed downloads'],
    },
    {
        eyebrow: 'Fallback',
        icon: Network,
        title: 'Multi-provider accuracy',
        body: 'The web workspace reuses configured server providers and fallback behavior.',
        bullets: [
            'AssemblyAI, Deepgram, Groq, and more',
            'Admin-managed ordering',
            'API request logs',
        ],
    },
];
</script>

<template>
    <Head title="Features" />

    <main>
        <section class="border-b border-slate-200 bg-white py-16 md:py-24">
            <div class="mx-auto max-w-6xl px-6">
                <p
                    class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                >
                    Features
                </p>
                <div class="mt-4 grid gap-8 lg:grid-cols-[0.8fr_1fr]">
                    <div>
                        <h1
                            class="text-4xl font-semibold tracking-tight text-slate-950 md:text-5xl"
                        >
                            Online transcription, shaped like the desktop
                            workspace.
                        </h1>
                    </div>
                    <p class="text-base leading-7 text-slate-700">
                        JERVA Web keeps the familiar light workspace, but every
                        transcription action runs through the server. That means
                        upload, live capture, polish, summarize, and export
                        without shipping local models to the browser.
                    </p>
                </div>
            </div>
        </section>

        <section class="bg-white py-16 md:py-24">
            <div class="mx-auto grid max-w-6xl gap-12 px-6">
                <article
                    v-for="(feature, index) in features"
                    :key="feature.title"
                    class="grid gap-8 lg:grid-cols-2 lg:items-center"
                >
                    <div :class="index % 2 === 1 ? 'lg:order-2' : ''">
                        <p
                            class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                        >
                            {{ feature.eyebrow }}
                        </p>
                        <h2 class="mt-3 text-2xl font-semibold text-slate-950">
                            {{ feature.title }}
                        </h2>
                        <p class="mt-4 text-base leading-7 text-slate-700">
                            {{ feature.body }}
                        </p>
                        <div class="mt-6 grid gap-3">
                            <div
                                v-for="bullet in feature.bullets"
                                :key="bullet"
                                class="flex items-start gap-3 text-sm text-slate-700"
                            >
                                <Check class="mt-0.5 size-4 text-blue-600" />
                                <span>{{ bullet }}</span>
                            </div>
                        </div>
                    </div>

                    <div
                        class="rounded-lg border border-slate-200 bg-white p-5 shadow-[0_12px_32px_rgba(15,23,42,0.08)]"
                    >
                        <div
                            class="rounded-lg border border-blue-100 bg-blue-50 p-5"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-600 text-white"
                                >
                                    <component
                                        :is="feature.icon"
                                        class="size-5"
                                    />
                                </span>
                                <div>
                                    <p
                                        class="text-sm font-semibold text-blue-950"
                                    >
                                        {{ feature.title }}
                                    </p>
                                    <p class="text-xs text-blue-900">
                                        JERVA online workflow
                                    </p>
                                </div>
                            </div>
                            <div class="mt-6 grid gap-3">
                                <div class="h-3 rounded-full bg-blue-200" />
                                <div
                                    class="h-3 w-5/6 rounded-full bg-blue-200"
                                />
                                <div
                                    class="h-3 w-2/3 rounded-full bg-blue-200"
                                />
                            </div>
                            <div
                                class="mt-6 flex flex-wrap gap-2 border-t border-blue-100 pt-4"
                            >
                                <span
                                    class="inline-flex h-8 items-center gap-2 rounded-lg border border-blue-200 bg-white px-3 text-xs font-semibold text-blue-900"
                                >
                                    <FileText class="size-3.5" />
                                    Raw
                                </span>
                                <span
                                    class="inline-flex h-8 items-center gap-2 rounded-lg border border-blue-200 bg-white px-3 text-xs font-semibold text-blue-900"
                                >
                                    <Sparkles class="size-3.5" />
                                    Cleaned
                                </span>
                                <span
                                    class="inline-flex h-8 items-center gap-2 rounded-lg border border-blue-200 bg-white px-3 text-xs font-semibold text-blue-900"
                                >
                                    <Download class="size-3.5" />
                                    Export
                                </span>
                            </div>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <section class="bg-white px-6 pb-16 md:pb-24">
            <div
                class="mx-auto flex max-w-6xl flex-col items-start justify-between gap-6 rounded-lg border border-blue-100 bg-blue-50 p-6 md:flex-row md:items-center"
            >
                <div>
                    <h2 class="text-2xl font-semibold text-blue-950">
                        Build your first online transcript.
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-blue-900">
                        Start with upload transcription, then unlock live,
                        polish, summaries, and exports as the workspace grows.
                    </p>
                </div>
                <Button as-child>
                    <Link href="/register">Create account</Link>
                </Button>
            </div>
        </section>
    </main>
</template>
