<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Dialog, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { useWorkspaceToast } from '@/composables/useWorkspaceToast';
import {
    csrfToken,
    filenameFromDisposition,
    renderSummaryMarkdown,
} from '@/lib/workspace';
import type { ExportFormat, Transcript } from '@/types/workspace';

const props = defineProps<{
    projectId: number;
    projectTitle: string;
    transcript: Transcript | null;
    globallyDisabled: boolean;
    globallyExporting: boolean;
}>();

const emit = defineEmits<{
    acting: [active: boolean];
    exporting: [active: boolean];
    queued: [];
    transcriptUpdated: [transcript: Transcript];
    upgrade: [message: string];
}>();

const toast = useWorkspaceToast();
const open = ref(false);
const exportFormat = ref<ExportFormat>('txt');
const isSubmitting = ref(false);
const isExporting = ref(false);

const isSummarizing = computed(
    () => props.transcript?.summary_status === 'processing',
);

const hasRawTranscript = computed(
    () =>
        Boolean(props.transcript?.raw_text?.trim()) ||
        (props.transcript?.sections.length ?? 0) > 0,
);

const summaryText = computed(
    () => props.transcript?.summary_text?.trim() ?? '',
);

const summaryReadyForExport = computed(
    () =>
        props.transcript?.summary_status === 'complete' &&
        summaryText.value.length > 0,
);

const statusLabel = computed(() => {
    if (isSummarizing.value) {
        return 'Summarizing...';
    }

    if (props.transcript?.summary_status === 'complete') {
        return 'Complete';
    }

    if (props.transcript?.summary_status === 'failed') {
        return 'Failed';
    }

    return 'Ready';
});

const setActing = (active: boolean) => {
    isSubmitting.value = active;
    emit('acting', active);
};

const summarize = async () => {
    if (!props.transcript) {
        return;
    }

    if (!hasRawTranscript.value) {
        toast.error('The transcript could not be summarized.');

        return;
    }

    emit('upgrade', '');
    setActing(true);

    try {
        const response = await fetch(
            `/workspace/${props.projectId}/transcripts/${props.transcript.id}/summarize`,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({}),
            },
        );
        const payload = (await response.json()) as {
            message?: string;
            transcript?: Transcript;
            upgrade?: boolean;
        };

        if (!response.ok) {
            const message =
                payload.message ?? 'The transcript could not be summarized.';

            if (payload.upgrade) {
                emit('upgrade', message);
            } else {
                toast.error(message);
            }

            return;
        }

        if (payload.transcript) {
            emit('transcriptUpdated', payload.transcript);
        }

        emit('queued');
    } catch {
        toast.error('The transcript could not be summarized.');
    } finally {
        setActing(false);
    }
};

const exportSummary = async () => {
    if (!props.transcript || !summaryReadyForExport.value) {
        toast.error('Create a summary before exporting.');

        return;
    }

    isExporting.value = true;
    emit('exporting', true);

    try {
        const params = new URLSearchParams({
            format: exportFormat.value,
            source: 'summary',
        });
        const response = await fetch(
            `/workspace/${props.projectId}/transcripts/${props.transcript.id}/export?${params.toString()}`,
            {
                headers: {
                    Accept: 'application/octet-stream,application/json',
                },
            },
        );

        if (!response.ok) {
            const payload = (await response.json().catch(() => ({}))) as {
                message?: string;
                upgrade?: boolean;
            };
            const message =
                payload.message ?? 'No summary is ready to export yet.';

            if (payload.upgrade) {
                emit('upgrade', message);
            } else {
                toast.error(message);
            }

            return;
        }

        const blob = await response.blob();
        const filename = filenameFromDisposition(
            response.headers.get('Content-Disposition'),
            `jerva-transcript-${props.transcript.id}.${exportFormat.value}`,
        );
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.click();
        URL.revokeObjectURL(url);
        toast.success(`Export download started: ${filename}`);
    } catch {
        toast.error('No summary is ready to export yet.');
    } finally {
        isExporting.value = false;
        emit('exporting', false);
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <button
                type="button"
                :disabled="
                    !transcript ||
                    globallyDisabled ||
                    isSubmitting ||
                    isSummarizing
                "
                class="inline-flex h-11 cursor-pointer items-center rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                Summarize
            </button>
        </DialogTrigger>
        <DialogContent
            :show-close-button="false"
            overlay-class="bg-blue-950/30"
            class="max-h-[calc(100dvh-2rem)] w-[min(94vw,52rem)] overflow-hidden border-blue-200 bg-white p-0 text-black shadow-2xl sm:max-w-[52rem]"
        >
            <div class="flex h-full max-h-[calc(100dvh-2rem)] flex-col">
                <header
                    class="flex min-h-16 shrink-0 flex-wrap items-center justify-between gap-3 border-b border-blue-100 px-5 py-3"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase text-blue-600">
                            Summary
                        </p>
                        <h2 class="truncate text-base font-semibold text-black">
                            {{ projectTitle }}
                        </h2>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <select
                            v-model="exportFormat"
                            data-summary-export-format
                            class="min-h-10 rounded-lg border border-blue-200 bg-white px-3 py-2 text-xs font-semibold text-blue-900 uppercase outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                        >
                            <option value="txt">TXT</option>
                            <option value="xlsx">Excel</option>
                            <option value="docx">Microsoft Word</option>
                        </select>
                        <button
                            type="button"
                            class="min-h-10 shrink-0 cursor-pointer rounded-lg border border-blue-200 bg-white px-4 py-2 text-sm font-semibold text-blue-900 transition hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="
                                !summaryReadyForExport ||
                                globallyExporting ||
                                isExporting
                            "
                            @click="exportSummary"
                        >
                            Export
                        </button>
                        <button
                            type="button"
                            class="grid h-10 w-10 shrink-0 cursor-pointer place-items-center rounded-lg border border-blue-200 bg-white text-blue-900 transition hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700"
                            aria-label="Close summary"
                            @click="open = false"
                        >
                            <X class="size-4" />
                        </button>
                    </div>
                </header>

                <div
                    class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-b border-blue-100 px-5 py-3"
                >
                    <div
                        class="inline-flex min-h-10 items-center rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-800"
                    >
                        {{ statusLabel }}
                    </div>
                </div>

                <div
                    v-if="isSummarizing"
                    class="h-1 overflow-hidden bg-blue-100"
                >
                    <div
                        class="h-full w-full animate-pulse bg-blue-600"
                    />
                </div>

                <div
                    class="min-h-0 flex-1 overflow-y-auto px-5 py-4 [scrollbar-color:#2563eb_#dbeafe] [scrollbar-width:thin]"
                >
                    <div
                        v-if="summaryText"
                        class="mx-auto max-w-3xl text-sm leading-7 break-words text-black"
                        v-html="renderSummaryMarkdown(summaryText)"
                    />
                    <div
                        v-else-if="isSummarizing"
                        class="mx-auto max-w-3xl text-sm leading-7 break-words text-black"
                    >
                        The summary is being prepared. You may close this window
                        and return later.
                    </div>
                    <div
                        v-else
                        class="mx-auto max-w-3xl text-sm leading-7 break-words text-black"
                    >
                        No summary has been created for this project.
                    </div>
                    <p
                        v-if="transcript?.summary_error_message"
                        class="mx-auto mt-3 max-w-3xl border-l-2 border-blue-500 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800"
                    >
                        {{ transcript.summary_error_message }}
                    </p>
                </div>

                <footer
                    class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-blue-100 px-5 py-4"
                >
                    <p class="text-xs text-blue-900">
                        Starting again replaces this project's existing summary.
                    </p>
                    <button
                        type="button"
                        class="min-h-10 shrink-0 cursor-pointer rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="
                            globallyDisabled || isSubmitting || isSummarizing
                        "
                        @click="summarize"
                    >
                        {{ summaryText ? 'Replace summary' : 'Summarize' }}
                    </button>
                </footer>
            </div>
        </DialogContent>
    </Dialog>
</template>
