<script setup lang="ts">
import { FileAudio, FileText, Mic, Sparkles } from '@lucide/vue';
import { Button } from '@/components/ui/button';
</script>

<template>
    <div
        class="rounded-lg border border-slate-200 bg-white shadow-[0_12px_32px_rgba(15,23,42,0.08)]"
        aria-label="JERVA workspace preview"
    >
        <div
            class="grid min-h-[32rem] overflow-hidden rounded-lg md:grid-cols-[13rem_1fr]"
        >
            <aside
                class="border-b border-slate-200 bg-slate-50 p-4 md:border-r md:border-b-0"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-600 text-white"
                    >
                        <Mic class="size-5" />
                    </span>
                    <div>
                        <p class="text-sm font-semibold text-slate-950">
                            JERVA Transcriber
                        </p>
                        <p class="text-xs text-slate-600">Web workspace</p>
                    </div>
                </div>
                <button
                    class="mt-6 flex h-10 w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white"
                >
                    <FileAudio class="size-4" />
                    Add Transcript
                </button>
                <p
                    class="mt-6 text-xs font-semibold tracking-wide text-slate-600 uppercase"
                >
                    Recent
                </p>
                <div class="mt-3 grid gap-2">
                    <div
                        class="rounded-lg bg-blue-100 px-3 py-2 text-sm font-medium text-blue-900 shadow-[inset_3px_0_0_#2563eb]"
                    >
                        Client intake call
                    </div>
                    <div class="rounded-lg px-3 py-2 text-sm text-slate-700">
                        Product standup
                    </div>
                    <div class="rounded-lg px-3 py-2 text-sm text-slate-700">
                        Lecture notes
                    </div>
                </div>
            </aside>

            <section class="flex flex-col">
                <header
                    class="flex h-[72px] items-center justify-between border-b border-slate-200 px-6"
                >
                    <div>
                        <p
                            class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                        >
                            Transcript
                        </p>
                        <h2 class="text-lg font-semibold text-slate-950">
                            Client intake call
                        </h2>
                    </div>
                    <Sparkles class="size-5 text-blue-600" />
                </header>

                <div class="mx-auto w-full max-w-3xl flex-1 px-8 py-6">
                    <div
                        class="rounded-lg border border-blue-100 bg-blue-50 p-4"
                    >
                        <p class="text-sm font-semibold text-blue-950">
                            Processing online
                        </p>
                        <div
                            class="mt-3 h-2 overflow-hidden rounded-full bg-blue-100"
                        >
                            <div
                                class="h-full w-2/3 rounded-full bg-blue-600"
                            />
                        </div>
                    </div>
                    <div class="mt-6 grid gap-4">
                        <article
                            class="rounded-lg border border-slate-200 bg-white p-4"
                        >
                            <p class="text-sm leading-6 text-slate-700">
                                We need the final transcript cleaned,
                                summarized, and exported before the review call.
                            </p>
                        </article>
                        <article
                            class="rounded-lg border border-slate-200 bg-white p-4"
                        >
                            <p class="text-sm leading-6 text-slate-700">
                                Action items: send the revised scope, confirm
                                file naming, and prepare the client-ready notes.
                            </p>
                        </article>
                    </div>
                </div>

                <div class="border-t border-slate-200 px-6 py-4">
                    <div
                        class="mx-auto flex max-w-3xl flex-wrap items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white p-2 shadow-[0_12px_32px_rgba(15,23,42,0.1)]"
                    >
                        <Button size="sm">Live</Button>
                        <Button size="sm" variant="outline"
                            >Upload Audio</Button
                        >
                        <Button size="sm" variant="ghost">Polish</Button>
                        <Button size="sm" variant="ghost">
                            <FileText class="size-4" />
                            Export
                        </Button>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
