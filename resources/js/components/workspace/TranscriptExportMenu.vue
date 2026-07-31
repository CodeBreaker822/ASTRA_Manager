<script setup lang="ts">
import { Download } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useWorkspaceToast } from '@/composables/useWorkspaceToast';
import { filenameFromDisposition } from '@/lib/workspace';
import type {
    ExportFormat,
    SummarySource,
    Transcript,
    TranscriptContentSource,
} from '@/types/workspace';

const props = defineProps<{
    projectId: number;
    transcript: Transcript | null;
    summarySource: SummarySource;
    globallyExporting: boolean;
}>();

const emit = defineEmits<{
    exporting: [active: boolean];
    upgrade: [message: string];
}>();

const toast = useWorkspaceToast();
const open = ref(false);
const source = ref<TranscriptContentSource>('raw');
const isExporting = ref(false);

const hasRawTranscript = computed(
    () =>
        Boolean(props.transcript?.raw_text?.trim()) ||
        (props.transcript?.sections.length ?? 0) > 0,
);

const exportTranscript = async (format: ExportFormat) => {
    if (!props.transcript) {
        return;
    }

    if (source.value === 'cleaned' && !props.transcript.cleaned_text) {
        toast.error(
            'Polish the transcript before exporting the cleaned version.',
        );

        return;
    }

    if (source.value === 'summary' && !props.transcript.summary_text) {
        toast.error('Create a summary before exporting.');

        return;
    }

    if (!hasRawTranscript.value) {
        toast.error('No transcription is ready to export yet.');

        return;
    }

    isExporting.value = true;
    emit('exporting', true);

    try {
        const params = new URLSearchParams({
            format,
            source: source.value,
        });

        if (source.value === 'summary') {
            params.set('summary_source', props.summarySource);
        }

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
                payload.message ?? 'No transcription is ready to export yet.';

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
            `jerva-transcript-${props.transcript.id}.${format}`,
        );
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.click();
        URL.revokeObjectURL(url);
        open.value = false;
        toast.success(`Export download started: ${filename}`);
    } catch {
        toast.error('No transcription is ready to export yet.');
    } finally {
        isExporting.value = false;
        emit('exporting', false);
    }
};
</script>

<template>
    <div class="relative">
        <button
            type="button"
            :disabled="!transcript || globallyExporting || isExporting"
            class="inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50"
            @click="open = !open"
        >
            <Download class="size-4" />
            {{ isExporting ? 'Exporting' : 'Export' }}
        </button>
        <div
            v-if="open"
            class="absolute right-0 bottom-14 z-40 w-56 rounded-lg border border-slate-200 bg-white p-2 shadow-2xl"
        >
            <div class="mb-2 grid grid-cols-3 gap-1">
                <button
                    type="button"
                    class="rounded border border-slate-200 px-2 py-1 text-xs text-slate-700"
                    :class="
                        source === 'raw'
                            ? 'border-blue-300 bg-blue-50 text-blue-700'
                            : ''
                    "
                    @click="source = 'raw'"
                >
                    Raw
                </button>
                <button
                    type="button"
                    class="rounded border border-slate-200 px-2 py-1 text-xs text-slate-700"
                    :class="
                        source === 'cleaned'
                            ? 'border-blue-300 bg-blue-50 text-blue-700'
                            : ''
                    "
                    @click="source = 'cleaned'"
                >
                    Cleaned
                </button>
                <button
                    type="button"
                    class="rounded border border-slate-200 px-2 py-1 text-xs text-slate-700"
                    :class="
                        source === 'summary'
                            ? 'border-blue-300 bg-blue-50 text-blue-700'
                            : ''
                    "
                    @click="source = 'summary'"
                >
                    Summary
                </button>
            </div>
            <div class="grid gap-1">
                <Button variant="outline" @click="exportTranscript('txt')">
                    TXT
                </Button>
                <Button variant="outline" @click="exportTranscript('docx')">
                    Microsoft Word
                </Button>
                <Button variant="outline" @click="exportTranscript('xlsx')">
                    Excel
                </Button>
            </div>
        </div>
    </div>
</template>
