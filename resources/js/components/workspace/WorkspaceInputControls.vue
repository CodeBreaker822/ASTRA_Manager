<script setup lang="ts">
import { Play, Square } from '@lucide/vue';
import { useTemplateRef } from 'vue';
import type { AudioUploadController } from '@/composables/useAudioUpload';
import type { LiveRecorderController } from '@/composables/useLiveRecorder';
import type { WorkspaceMode } from '@/types/workspace';

defineProps<{
    mode: WorkspaceMode;
    hasProject: boolean;
    canUseUpload: boolean;
    upload: AudioUploadController;
    live: LiveRecorderController;
}>();

const emit = defineEmits<{
    fileSelected: [file: File];
    modeSelected: [mode: 'live' | 'upload'];
    toggleLive: [];
}>();

const uploadInput = useTemplateRef<HTMLInputElement>('uploadInput');

const chooseFile = () => {
    emit('modeSelected', 'upload');
    uploadInput.value?.click();
};

const handleUploadPick = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (file) {
        emit('fileSelected', file);
    }

    input.value = '';
};
</script>

<template>
    <input
        ref="uploadInput"
        type="file"
        class="hidden"
        accept="audio/*,.wav,.mp3,.m4a,.aac,.ogg,.flac,.webm"
        @change="handleUploadPick"
    />

    <div
        v-if="mode === 'choose'"
        class="order-1 mx-auto flex w-full flex-wrap items-center justify-center gap-2 rounded-lg border border-blue-100 bg-white px-3 py-3 shadow-[0_12px_32px_rgba(15,23,42,0.1)] sm:w-fit sm:gap-3"
    >
        <button
            type="button"
            class="h-12 min-w-32 flex-1 cursor-pointer rounded-lg border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50 sm:min-w-40 sm:flex-none"
            :disabled="!hasProject"
            @click="emit('modeSelected', 'live')"
        >
            Live
        </button>
        <button
            type="button"
            class="h-12 min-w-32 flex-1 cursor-pointer rounded-lg border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50 sm:min-w-40 sm:flex-none"
            :disabled="!hasProject"
            @click="emit('modeSelected', 'upload')"
        >
            Upload Audio
        </button>
    </div>

    <div
        v-if="mode === 'live'"
        class="order-1 flex w-full flex-wrap items-center justify-center gap-2 rounded-lg border border-blue-100 bg-white px-3 py-3 shadow-[0_12px_32px_rgba(15,23,42,0.1)] transition sm:w-fit sm:gap-3"
    >
        <button
            type="button"
            class="group flex h-12 min-w-32 flex-1 cursor-pointer items-center justify-center gap-3 rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition outline-none hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-300 disabled:cursor-not-allowed disabled:opacity-60 sm:min-w-40 sm:flex-none"
            :disabled="!hasProject && !live.isUnavailable.value"
            :aria-pressed="live.isRecording.value"
            @click="emit('toggleLive')"
        >
            <Square v-if="live.isRecording.value" class="size-4 fill-current" />
            <Play v-else class="size-4 fill-current" />
            <span class="grid text-left leading-none">
                <span class="text-xs font-semibold text-blue-200 uppercase">
                    {{ live.buttonTop.value }}
                </span>
                <span class="mt-1 text-sm font-semibold text-white">
                    {{ live.buttonBottom.value }}
                </span>
            </span>
        </button>
        <div
            v-if="live.isPanelVisible.value"
            class="w-full min-w-0 flex-none sm:w-80"
        >
            <div class="flex min-w-0 items-center gap-2 text-sm">
                <span class="shrink-0 font-semibold text-slate-950">
                    {{ live.activeName.value }}
                </span>
                <span class="min-w-0 truncate text-slate-500">
                    {{ live.currentRangeLabel.value }}
                </span>
                <span class="ml-auto shrink-0 font-semibold text-blue-700">
                    {{ live.elapsedLabel.value }}
                </span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                <div
                    class="h-full rounded-full bg-blue-600 transition-[width] duration-150"
                    :style="{
                        width: `${live.segmentProgress.value}%`,
                    }"
                />
            </div>
            <p class="mt-1 text-xs font-medium text-slate-500">
                {{ live.supportLine.value }}
            </p>
        </div>
    </div>

    <div
        v-if="mode === 'upload'"
        class="order-1 flex w-full flex-wrap items-center justify-center gap-2 rounded-lg border border-blue-100 bg-white px-3 py-3 shadow-[0_12px_32px_rgba(15,23,42,0.1)] transition sm:w-fit sm:gap-3"
    >
        <button
            type="button"
            class="inline-flex h-12 min-w-28 flex-1 cursor-pointer items-center justify-center rounded-lg border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50 sm:min-w-32 sm:flex-none"
            :disabled="
                !canUseUpload ||
                upload.inFlight.value ||
                upload.isPreparing.value
            "
            @click="chooseFile"
        >
            Browse
        </button>
        <div
            v-if="upload.hasFile.value || upload.isActive.value"
            class="w-full min-w-0 flex-none sm:w-80"
        >
            <p class="truncate text-sm font-semibold text-slate-950">
                {{ upload.fileName.value }}
            </p>
            <p class="truncate text-xs text-slate-500">
                {{ upload.metaLine.value || 'WAV, MP3, M4A, AAC, OGG, FLAC.' }}
            </p>
            <p class="text-xs text-slate-500">
                Duration:
                <span class="font-semibold text-slate-700">
                    {{ upload.durationLabel.value }}
                </span>
            </p>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                <div
                    class="h-full rounded-full bg-blue-600 transition-[width] duration-150"
                    :style="{
                        width: `${upload.progressPercent.value}%`,
                    }"
                />
            </div>
        </div>
        <span
            v-if="upload.hasFile.value || upload.isActive.value"
            class="max-w-28 truncate text-xs font-semibold text-slate-700"
        >
            {{ upload.statusLine.value }}
        </span>
        <span
            v-if="upload.hasFile.value || upload.isActive.value"
            class="w-10 text-right text-xs font-semibold text-blue-700"
        >
            {{ upload.progressPercent.value }}%
        </span>
        <button
            v-if="upload.hasFile.value || upload.isActive.value"
            type="button"
            class="h-12 min-w-20 flex-1 cursor-pointer rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-500 sm:flex-none"
            :disabled="!upload.canStart.value"
            @click="upload.start"
        >
            Start
        </button>
        <button
            v-if="upload.canPause.value"
            type="button"
            class="h-12 min-w-20 flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50 sm:flex-none"
            :disabled="!upload.canPause.value"
            @click="upload.pause"
        >
            Pause
        </button>
        <button
            v-if="upload.canContinue.value"
            type="button"
            class="h-12 min-w-24 flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50 sm:flex-none"
            :disabled="!upload.canContinue.value"
            @click="upload.resume"
        >
            Continue
        </button>
        <button
            v-if="upload.canRetry.value"
            type="button"
            class="h-12 min-w-20 flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50 sm:flex-none"
            :disabled="!upload.canRetry.value"
            @click="upload.retry"
        >
            Retry
        </button>
        <button
            v-if="upload.canCancel.value"
            type="button"
            class="h-12 min-w-20 flex-1 cursor-pointer rounded-lg border border-red-200 bg-red-50 px-3 text-sm font-semibold text-red-700 transition hover:border-red-300 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50 sm:flex-none"
            :disabled="!upload.canCancel.value"
            @click="upload.cancel"
        >
            Cancel
        </button>
    </div>
</template>
