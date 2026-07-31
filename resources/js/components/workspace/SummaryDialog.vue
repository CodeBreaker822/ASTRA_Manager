<script setup lang="ts">
import { Download, FileText, Sparkles, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { useWorkspaceToast } from '@/composables/useWorkspaceToast';
import {
    csrfToken,
    filenameFromDisposition,
    renderSummaryMarkdown,
} from '@/lib/workspace';
import type {
    ExportFormat,
    SummarySource,
    Transcript,
} from '@/types/workspace';

const props = defineProps<{
    projectId: number;
    projectTitle: string;
    transcript: Transcript | null;
    globallyDisabled: boolean;
    globallyExporting: boolean;
    summarySource: SummarySource;
}>();

const emit = defineEmits<{
    acting: [active: boolean];
    exporting: [active: boolean];
    queued: [];
    transcriptUpdated: [transcript: Transcript];
    upgrade: [message: string];
    'update:summarySource': [source: SummarySource];
}>();

const toast = useWorkspaceToast();
const open = ref(false);
const exportFormat = ref<ExportFormat>('txt');
const isSubmitting = ref(false);
const isExporting = ref(false);
const source = computed({
    get: () => props.summarySource,
    set: (value: SummarySource) => emit('update:summarySource', value),
});

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

const sourceLabel = computed(() =>
    source.value === 'cleaned' ? 'Cleaned transcript' : 'Raw transcript',
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
                body: JSON.stringify({ source: source.value }),
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
            summary_source: source.value,
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
                class="inline-flex h-11 cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <FileText class="size-4" />
                Summarize
            </button>
        </DialogTrigger>
        <DialogContent
            :show-close-button="false"
            class="max-h-[calc(100dvh-2rem)] w-[min(94vw,52rem)] overflow-hidden border-blue-200 bg-white p-0 text-slate-950 shadow-2xl sm:max-w-[52rem]"
        >
            <div class="flex h-full max-h-[calc(100dvh-2rem)] flex-col">
                <header
                    class="flex flex-col gap-3 border-b border-blue-100 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="min-w-0">
                        <p
                            class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                        >
                            Summary
                        </p>
                        <h2
                            class="truncate text-lg font-semibold text-slate-950"
                        >
                            {{ projectTitle }}
                        </h2>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <select
                            v-model="exportFormat"
                            data-summary-export-format
                            class="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 transition outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                        >
                            <option value="txt">TXT</option>
                            <option value="docx">Microsoft Word</option>
                            <option value="xlsx">Excel</option>
                        </select>
                        <Button
                            type="button"
                            class="h-10"
                            :disabled="
                                !summaryReadyForExport ||
                                globallyExporting ||
                                isExporting
                            "
                            @click="exportSummary"
                        >
                            <Download class="mr-2 size-4" />
                            {{ isExporting ? 'Exporting' : 'Export' }}
                        </Button>
                        <button
                            type="button"
                            class="inline-flex size-10 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700"
                            aria-label="Close summary"
                            @click="open = false"
                        >
                            <X class="size-4" />
                        </button>
                    </div>
                </header>

                <div
                    class="flex flex-col gap-3 border-b border-blue-100 bg-blue-50/60 px-5 py-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <label
                        class="flex flex-col gap-1 text-sm font-medium text-slate-700 sm:flex-row sm:items-center"
                    >
                        <span>Source</span>
                        <select
                            v-model="source"
                            class="h-10 rounded-lg border border-blue-200 bg-white px-3 text-sm font-medium text-slate-800 transition outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                        >
                            <option value="raw">Raw transcript</option>
                            <option value="cleaned">Cleaned transcript</option>
                        </select>
                    </label>
                    <div
                        class="inline-flex items-center gap-2 rounded-full border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700"
                    >
                        <span
                            class="size-2 rounded-full"
                            :class="
                                isSummarizing
                                    ? 'bg-amber-400'
                                    : transcript?.summary_status === 'failed'
                                      ? 'bg-red-500'
                                      : summaryReadyForExport
                                        ? 'bg-emerald-500'
                                        : 'bg-blue-400'
                            "
                        />
                        {{ statusLabel }}
                    </div>
                </div>

                <div
                    v-if="isSummarizing"
                    class="h-1 overflow-hidden bg-blue-100"
                >
                    <div
                        class="h-full w-1/2 animate-pulse rounded-r-full bg-blue-600"
                    />
                </div>

                <div class="min-h-[18rem] flex-1 overflow-y-auto px-5 py-5">
                    <div
                        v-if="summaryText"
                        class="rounded-lg border border-blue-100 bg-white px-5 py-4 shadow-sm"
                    >
                        <div
                            class="space-y-1"
                            v-html="renderSummaryMarkdown(summaryText)"
                        />
                    </div>
                    <div
                        v-else-if="isSummarizing"
                        class="flex min-h-56 items-center justify-center rounded-lg border border-blue-100 bg-blue-50 px-5 text-center text-sm leading-6 text-blue-900"
                    >
                        The summary is being prepared. You may close this window
                        and return later.
                    </div>
                    <div
                        v-else
                        class="flex min-h-56 items-center justify-center rounded-lg border border-dashed border-blue-200 bg-blue-50/70 px-5 text-center text-sm leading-6 text-slate-600"
                    >
                        No summary has been created for this project.
                    </div>
                    <p
                        v-if="transcript?.summary_error_message"
                        class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
                    >
                        {{ transcript.summary_error_message }}
                    </p>
                </div>

                <footer
                    class="flex flex-col gap-3 border-t border-blue-100 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="text-sm text-slate-600">
                        Starting again replaces this project's existing summary.
                    </p>
                    <Button
                        type="button"
                        class="h-11"
                        :disabled="
                            globallyDisabled || isSubmitting || isSummarizing
                        "
                        @click="summarize"
                    >
                        <Sparkles class="mr-2 size-4" />
                        {{
                            summaryText
                                ? 'Replace summary'
                                : `Summarize ${sourceLabel}`
                        }}
                    </Button>
                </footer>
            </div>
        </DialogContent>
    </Dialog>
</template>
