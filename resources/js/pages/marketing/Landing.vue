<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Check, FileAudio, Mic, Sparkles } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import WorkspacePreview from '@/components/WorkspacePreview.vue';

const capabilities = [
    'Upload audio to the server-side transcription pipeline',
    'Record live browser audio with queued online processing',
    'Polish, summarize, and export clean transcripts',
];
</script>

<template>
    <Head title="JERVA Web" />

    <main>
        <section class="border-b border-slate-200 bg-white">
            <div
                class="mx-auto grid min-h-[calc(100vh-4rem)] max-w-6xl items-center gap-10 px-6 py-16 lg:grid-cols-[1fr_0.92fr]"
            >
                <div>
                    <p
                        class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                    >
                        JERVA Web beta
                    </p>
                    <h1
                        class="mt-4 text-4xl leading-tight font-semibold tracking-tight text-slate-950 md:text-5xl"
                    >
                        JERVA Transcriber
                    </h1>
                    <p
                        class="mt-5 max-w-2xl text-base leading-7 text-slate-700"
                    >
                        A light, ChatGPT-style transcription workspace for
                        online audio processing. Upload recordings, capture live
                        browser audio, polish the result, summarize the meeting,
                        and export the work without installing local models.
                    </p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <Button as-child size="lg">
                            <Link href="/register">
                                Get started
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                        <Button as-child size="lg" variant="outline">
                            <Link href="/price">View pricing</Link>
                        </Button>
                    </div>
                    <div class="mt-8 grid gap-3 text-sm text-slate-700">
                        <div
                            v-for="capability in capabilities"
                            :key="capability"
                            class="flex items-start gap-3"
                        >
                            <Check class="mt-0.5 size-4 text-blue-600" />
                            <span>{{ capability }}</span>
                        </div>
                    </div>
                </div>

                <WorkspacePreview />
            </div>
        </section>

        <section class="bg-slate-50 py-16 md:py-24">
            <div class="mx-auto grid max-w-6xl gap-6 px-6 md:grid-cols-3">
                <div class="rounded-lg border border-slate-200 bg-white p-6">
                    <Mic class="size-5 text-blue-600" />
                    <h2 class="mt-4 text-lg font-semibold text-slate-950">
                        Live online capture
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-700">
                        Browser audio is chunked and sent to the server
                        pipeline. No local Whisper or desktop engine required.
                    </p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-6">
                    <FileAudio class="size-5 text-blue-600" />
                    <h2 class="mt-4 text-lg font-semibold text-slate-950">
                        Upload recordings
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-700">
                        Send WAV, MP3, M4A, AAC, OGG, and FLAC files through the
                        existing multi-provider transcription stack.
                    </p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-6">
                    <Sparkles class="size-5 text-blue-600" />
                    <h2 class="mt-4 text-lg font-semibold text-slate-950">
                        Polish and summarize
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-700">
                        Turn rough transcript text into cleaner notes,
                        translations, summaries, and export-ready documents.
                    </p>
                </div>
            </div>
        </section>
    </main>
</template>
