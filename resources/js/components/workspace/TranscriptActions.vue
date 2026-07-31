<script setup lang="ts">
import { ref } from 'vue';
import PolishTranscriptDialog from '@/components/workspace/PolishTranscriptDialog.vue';
import ProcessingLogDrawer from '@/components/workspace/ProcessingLogDrawer.vue';
import SummaryDialog from '@/components/workspace/SummaryDialog.vue';
import TranscriptExportMenu from '@/components/workspace/TranscriptExportMenu.vue';
import type {
    ActiveProject,
    SummarySource,
    Transcript,
} from '@/types/workspace';

defineProps<{
    project: ActiveProject;
    transcript: Transcript | null;
    displayTitle: string;
}>();

const emit = defineEmits<{
    queued: [];
    transcriptUpdated: [transcript: Transcript];
    upgrade: [message: string];
}>();

const isActing = ref(false);
const isExporting = ref(false);
const summarySource = ref<SummarySource>('raw');
</script>

<template>
    <div
        class="order-2 flex w-full flex-wrap items-center justify-center gap-2 rounded-lg border border-blue-100 bg-white p-1.5 shadow-[0_12px_32px_rgba(15,23,42,0.08)] sm:w-fit"
    >
        <PolishTranscriptDialog
            :project-id="project.id"
            :transcript="transcript"
            :globally-disabled="isActing"
            @acting="isActing = $event"
            @queued="emit('queued')"
            @transcript-updated="emit('transcriptUpdated', $event)"
            @upgrade="emit('upgrade', $event)"
        />
        <SummaryDialog
            :project-id="project.id"
            :project-title="project.title"
            :transcript="transcript"
            :globally-disabled="isActing"
            :globally-exporting="isExporting"
            :summary-source="summarySource"
            @acting="isActing = $event"
            @exporting="isExporting = $event"
            @queued="emit('queued')"
            @transcript-updated="emit('transcriptUpdated', $event)"
            @update:summary-source="summarySource = $event"
            @upgrade="emit('upgrade', $event)"
        />
        <TranscriptExportMenu
            :project-id="project.id"
            :transcript="transcript"
            :summary-source="summarySource"
            :globally-exporting="isExporting"
            @exporting="isExporting = $event"
            @upgrade="emit('upgrade', $event)"
        />
        <ProcessingLogDrawer
            :project-title="displayTitle"
            :transcript="transcript"
        />
    </div>
</template>
