<script setup lang="ts">
import TranscriptActions from '@/components/workspace/TranscriptActions.vue';
import WorkspaceInputControls from '@/components/workspace/WorkspaceInputControls.vue';
import type { AudioUploadController } from '@/composables/useAudioUpload';
import type { LiveRecorderController } from '@/composables/useLiveRecorder';
import type {
    ActiveProject,
    Transcript,
    WorkspaceMode,
} from '@/types/workspace';

defineProps<{
    project: ActiveProject;
    primaryTranscript: Transcript | null;
    displayTitle: string;
    mode: WorkspaceMode;
    activeTranscriptActionMode: string;
    canUseUpload: boolean;
    upload: AudioUploadController;
    live: LiveRecorderController;
}>();

const emit = defineEmits<{
    fileSelected: [file: File];
    modeSelected: [mode: 'live' | 'upload'];
    queued: [];
    toggleLive: [];
    transcriptUpdated: [transcript: Transcript];
    upgrade: [message: string];
}>();
</script>

<template>
    <div
        class="pointer-events-none absolute inset-x-0 bottom-0 px-3 py-3 lg:px-6 lg:py-4"
    >
        <div
            class="pointer-events-auto mx-auto flex w-full max-w-[calc(100%-1rem)] flex-col items-center justify-center gap-3 lg:max-w-[calc(100%-2rem)] lg:gap-4"
        >
            <WorkspaceInputControls
                :mode="mode"
                :has-project="true"
                :can-use-upload="canUseUpload"
                :upload="upload"
                :live="live"
                @file-selected="emit('fileSelected', $event)"
                @mode-selected="emit('modeSelected', $event)"
                @toggle-live="emit('toggleLive')"
            />
            <TranscriptActions
                v-if="activeTranscriptActionMode"
                :project="project"
                :transcript="primaryTranscript"
                :display-title="displayTitle"
                @queued="emit('queued')"
                @transcript-updated="emit('transcriptUpdated', $event)"
                @upgrade="emit('upgrade', $event)"
            />
        </div>
    </div>
</template>
