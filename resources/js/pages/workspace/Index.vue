<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import SettingsModal from '@/components/SettingsModal.vue';
import TranscriptContent from '@/components/workspace/TranscriptContent.vue';
import WorkspaceDock from '@/components/workspace/WorkspaceDock.vue';
import WorkspaceHeader from '@/components/workspace/WorkspaceHeader.vue';
import WorkspaceSidebar from '@/components/workspace/WorkspaceSidebar.vue';
import { useAudioUpload } from '@/composables/useAudioUpload';
import { useLiveRecorder } from '@/composables/useLiveRecorder';
import { useRecordingSettings } from '@/composables/useRecordingSettings';
import { useTranscriptPolling } from '@/composables/useTranscriptPolling';
import { useWorkspaceToast } from '@/composables/useWorkspaceToast';
import { csrfToken } from '@/lib/workspace';
import type {
    ActiveProject,
    EmptyWorkspacePanel,
    Entitlements,
    Project,
    Transcript,
    TranscriptRow,
    TranscriptSection,
    WorkspaceMode,
} from '@/types/workspace';

const props = defineProps<{
    projects: Project[];
    activeProject: ActiveProject | null;
    entitlements: Entitlements;
}>();

const page = usePage();
const toast = useWorkspaceToast();
const workspaceMode = ref<WorkspaceMode>('choose');
const localProject = ref<ActiveProject | null>(props.activeProject);
const localEntitlements = ref<Entitlements>(props.entitlements);
const upgradeBanner = ref('');
const { captureScreenAudio } = useRecordingSettings();

const displayProject = computed(
    () => localProject.value ?? props.activeProject,
);

const userEmail = computed(() => {
    const user = page.props.auth?.user;

    return user?.email ?? 'JERVA user';
});

const projectTitle = computed(() => {
    if (!displayProject.value) {
        return 'Welcome';
    }

    if (workspaceMode.value === 'upload') {
        return `${displayProject.value.title} - Upload transcript`;
    }

    if (workspaceMode.value === 'live') {
        return `${displayProject.value.title} - Live transcript`;
    }

    return displayProject.value.title;
});

const transcriptDisplayItems = computed(
    () =>
        displayProject.value?.transcripts.filter(
            (transcript) =>
                transcript.raw_text ||
                transcript.cleaned_text ||
                transcript.summary_text ||
                transcript.sections.length > 0,
        ) ?? [],
);

const transcriptRows = computed<TranscriptRow[]>(() =>
    transcriptDisplayItems.value.flatMap((transcript) => {
        if (transcript.cleaned_text?.trim()) {
            return [
                {
                    id: `${transcript.id}-polished`,
                    range: transcriptRangeLabel(transcript),
                    text: transcript.cleaned_text,
                    transcript,
                },
            ];
        }

        if (transcript.sections.length > 0) {
            return transcript.sections.map((section) => ({
                id: `${transcript.id}-${section.id}`,
                range: sectionRangeLabel(section),
                text: section.cleaned_text ?? section.text,
                transcript,
            }));
        }

        return [
            {
                id: `${transcript.id}-raw`,
                range: '',
                text:
                    transcript.cleaned_text ??
                    transcript.raw_text ??
                    transcript.summary_text ??
                    '',
                transcript,
            },
        ];
    }),
);

const liveTranscriptCount = computed(
    () =>
        transcriptDisplayItems.value.filter(
            (transcript) => transcript.source === 'live',
        ).length,
);

const uploadTranscriptCount = computed(
    () =>
        transcriptDisplayItems.value.filter(
            (transcript) => transcript.source === 'upload',
        ).length,
);

const activeTranscriptActionMode = computed(() => {
    if (workspaceMode.value === 'live' && liveTranscriptCount.value > 0) {
        return 'live';
    }

    if (workspaceMode.value === 'upload' && uploadTranscriptCount.value > 0) {
        return 'upload';
    }

    return '';
});

const emptyPanel = computed<EmptyWorkspacePanel>(() => {
    if (!displayProject.value) {
        return {
            eyebrow: 'Transcription workspace',
            title: 'Hi, what are we transcribing today?',
            copy: "Start a transcript from the left, then choose Live or Upload Audio. I'll keep the transcript here so you can polish, summarize, export, or review the processing log when it's ready.",
        };
    }

    if (workspaceMode.value === 'live') {
        return {
            eyebrow: 'Live transcript',
            title: 'Ready when you are.',
            copy: 'Press Live below to start capturing audio. Your transcript will appear here as each section finishes.',
        };
    }

    if (workspaceMode.value === 'upload') {
        return {
            eyebrow: 'Upload transcript',
            title: "Drop in an audio file and I'll organize the transcript.",
            copy: 'Choose Upload Audio below, browse for a file, and the finished transcript will appear here.',
        };
    }

    return {
        eyebrow: 'Transcription workspace',
        title: 'Great. How do you want to add audio?',
        copy: "Choose Live if you're recording now, or Upload Audio if the file is already on your computer.",
    };
});

const primaryTranscript = computed(
    () =>
        displayProject.value?.transcripts.find(
            (transcript) =>
                transcript.source === workspaceMode.value &&
                transcript.status === 'completed',
        ) ??
        displayProject.value?.transcripts.find(
            (transcript) => transcript.source === workspaceMode.value,
        ) ??
        displayProject.value?.transcripts.find(
            (transcript) => transcript.status === 'completed',
        ) ??
        displayProject.value?.transcripts[0] ??
        null,
);

const pendingTranscripts = computed(
    () =>
        displayProject.value?.transcripts.filter((transcript) =>
            ['queued', 'processing'].includes(transcript.status),
        ) ?? [],
);

const hasPendingWork = computed(
    () =>
        pendingTranscripts.value.length > 0 ||
        (displayProject.value?.transcripts.some(
            (transcript) =>
                transcript.polish_status === 'processing' ||
                transcript.summary_status === 'processing',
        ) ??
            false),
);

const canUseUpload = computed(
    () =>
        Boolean(localEntitlements.value.plan.features.upload) &&
        Boolean(displayProject.value),
);

const canUseLive = computed(
    () =>
        Boolean(localEntitlements.value.plan.features.live) &&
        Boolean(displayProject.value),
);

const refreshStatus = async () => {
    if (!displayProject.value) {
        return;
    }

    const response = await fetch(
        `/workspace/${displayProject.value.id}/status`,
        {
            headers: {
                Accept: 'application/json',
            },
        },
    );

    if (!response.ok) {
        return;
    }

    const payload = (await response.json()) as {
        project: ActiveProject;
        entitlements?: Entitlements;
    };
    localProject.value = {
        ...payload.project,
        updated_at: displayProject.value.updated_at,
        transcripts_count: payload.project.transcripts.length,
    };

    if (payload.entitlements) {
        localEntitlements.value = payload.entitlements;
    }

    upload.syncTranscripts(payload.project.transcripts);
};

const addTranscriptToLocal = (transcript: Transcript) => {
    if (!displayProject.value) {
        return;
    }

    const transcripts = displayProject.value.transcripts.filter(
        (existing) => existing.id !== transcript.id,
    );

    localProject.value = {
        ...displayProject.value,
        transcripts: [transcript, ...transcripts],
        transcripts_count: transcripts.length + 1,
    };
};

const { startPolling, stopPolling } = useTranscriptPolling(
    hasPendingWork,
    refreshStatus,
);

const upload = useAudioUpload({
    csrfToken,
    projectId: () => displayProject.value?.id ?? null,
    onTranscript: addTranscriptToLocal,
    onQueued: startPolling,
    onUpgrade: (message) => {
        upgradeBanner.value = message;
    },
    onSuccess: (message) => {
        toast.success(message);
    },
    onError: (message) => {
        toast.error(message);
    },
});

const live = useLiveRecorder({
    csrfToken,
    projectId: () => displayProject.value?.id ?? null,
    canUseLive: () => canUseLive.value,
    captureScreenAudio: () => captureScreenAudio.value,
    onTranscript: addTranscriptToLocal,
    onQueued: startPolling,
    onUpgrade: (message) => {
        upgradeBanner.value = message;
    },
    onToastError: (message) => {
        toast.error(message);
    },
});

const selectMode = (mode: 'live' | 'upload') => {
    workspaceMode.value = mode;
};

const toggleLive = async () => {
    upgradeBanner.value = '';

    if (!canUseLive.value && !live.isRecording.value) {
        upgradeBanner.value =
            'Live transcription is not available for this account.';

        return;
    }

    await live.toggle();
};

const selectUploadFile = async (file: File) => {
    if (!displayProject.value) {
        return;
    }

    workspaceMode.value = 'upload';
    await upload.selectFile(file);
};

const formatTranscriptTime = (ms: number | null) => {
    const seconds = Math.max(0, Math.floor((ms ?? 0) / 1000));
    const minutes = Math.floor(seconds / 60);
    const rest = String(seconds % 60).padStart(2, '0');

    return `${String(minutes).padStart(2, '0')}:${rest}`;
};

const sectionRangeLabel = (section: TranscriptSection) => {
    if (section.started_at_ms === null && section.ended_at_ms === null) {
        return '';
    }

    return `${formatTranscriptTime(section.started_at_ms)}-${formatTranscriptTime(section.ended_at_ms)}`;
};

const transcriptRangeLabel = (transcript: Transcript) => {
    const first = transcript.sections[0];
    const last = transcript.sections.at(-1);

    if (!first || !last) {
        return '';
    }

    return sectionRangeLabel({
        ...first,
        ended_at_ms: last.ended_at_ms,
    });
};

watch(
    () => props.activeProject,
    (project) => {
        localProject.value = project;
    },
);

watch(
    () => props.entitlements,
    (entitlements) => {
        localEntitlements.value = entitlements;
    },
);

watch(
    () => displayProject.value?.id,
    () => {
        const transcripts = displayProject.value?.transcripts ?? [];
        const existingMode =
            transcripts.find((transcript) => transcript.source === 'live')
                ?.source ??
            transcripts.find((transcript) => transcript.source === 'upload')
                ?.source;

        workspaceMode.value =
            existingMode === 'live' || existingMode === 'upload'
                ? existingMode
                : 'choose';
    },
    { immediate: true },
);

watch(
    () => primaryTranscript.value?.polish_status,
    (status, previous) => {
        if (previous !== 'processing') {
            return;
        }

        if (status === 'complete') {
            toast.success('Transcript polished.');
        }

        if (status === 'failed') {
            toast.error('Transcript could not be polished.');
        }
    },
);

watch(
    () => primaryTranscript.value?.summary_status,
    (status, previous) => {
        if (previous === 'processing' && status === 'failed') {
            toast.error('The transcript could not be summarized.');
        }
    },
);

watch(
    () => hasPendingWork.value,
    (pending) => {
        if (pending) {
            startPolling();
        } else {
            stopPolling();
            upload.finish();
        }
    },
);

onMounted(() => {
    void refreshStatus();

    if (pendingTranscripts.value.length > 0) {
        startPolling();
    }
});
</script>

<template>
    <Head title="Workspace" />

    <main
        class="min-h-dvh bg-white text-[14px] leading-5 text-slate-950 lg:h-screen lg:min-h-0 lg:overflow-hidden"
    >
        <div
            class="flex min-h-dvh flex-col overflow-y-auto lg:h-screen lg:min-h-0 lg:flex-row lg:overflow-hidden"
        >
            <WorkspaceSidebar
                :projects="projects"
                :active-project-id="displayProject?.id ?? null"
                :entitlements="localEntitlements"
                :user-email="userEmail"
                @project-created="workspaceMode = 'choose'"
            />

            <section
                class="relative flex min-h-[70dvh] min-w-0 flex-1 flex-col bg-white lg:min-h-0"
            >
                <WorkspaceHeader :title="projectTitle" />
                <TranscriptContent
                    :project="displayProject"
                    :rows="transcriptRows"
                    :empty-panel="emptyPanel"
                    :can-retry="upload.canRetry.value"
                    :upgrade-message="upgradeBanner"
                    @retry="upload.retry"
                />
                <WorkspaceDock
                    v-if="displayProject"
                    :project="displayProject"
                    :primary-transcript="primaryTranscript"
                    :display-title="projectTitle"
                    :mode="workspaceMode"
                    :active-transcript-action-mode="activeTranscriptActionMode"
                    :can-use-upload="canUseUpload"
                    :upload="upload"
                    :live="live"
                    @file-selected="selectUploadFile"
                    @mode-selected="selectMode"
                    @queued="startPolling"
                    @toggle-live="toggleLive"
                    @transcript-updated="addTranscriptToLocal"
                    @upgrade="upgradeBanner = $event"
                />
            </section>
        </div>

        <SettingsModal />
    </main>
</template>
