<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    Download,
    FileText,
    ListChecks,
    Play,
    Settings,
    Sparkles,
    Square,
    X,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref, useTemplateRef, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import SettingsModal from '@/components/SettingsModal.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ProcessingButton from '@/components/workspace/ProcessingButton.vue';
import WorkspaceToast from '@/components/workspace/WorkspaceToast.vue';
import { useAudioUpload } from '@/composables/useAudioUpload';
import { useLiveRecorder } from '@/composables/useLiveRecorder';
import { useSettingsModal } from '@/composables/useSettingsModal';
import { useTranscriptPolling } from '@/composables/useTranscriptPolling';
import { useWorkspaceToast } from '@/composables/useWorkspaceToast';

type Project = {
    id: number;
    title: string;
    updated_at: string | null;
    transcripts_count: number;
};

type TranscriptSection = {
    id: number;
    position: number;
    text: string;
    cleaned_text: string | null;
    started_at_ms: number | null;
    ended_at_ms: number | null;
};

type Transcript = {
    id: number;
    source: string;
    status: string;
    duration_seconds: number;
    raw_text: string | null;
    cleaned_text: string | null;
    summary_text: string | null;
    polish_status: 'idle' | 'processing' | 'complete' | 'failed';
    polish_error_message: string | null;
    summary_status: 'idle' | 'processing' | 'complete' | 'failed';
    summary_error_message: string | null;
    processing_log: Array<{
        status: string;
        message: string;
        created_at: string;
        context?: Record<string, unknown>;
    }>;
    sections: TranscriptSection[];
};

type ActiveProject = Project & {
    transcripts: Transcript[];
};

const props = defineProps<{
    projects: Project[];
    activeProject: ActiveProject | null;
    entitlements: {
        plan: {
            key: string;
            name: string;
            minutes: number;
            free_polish_uses_per_day: number;
            free_summary_uses_per_day: number;
            features: Record<string, unknown>;
        };
        usage: {
            period: string;
            minutes_used: number;
            minutes_remaining: number;
            minutes_credit_balance: number;
            seconds_transcribed: number;
            seconds_credit_balance: number;
            polish_count: number;
            summary_count: number;
            free_polish_remaining: number;
            free_summary_remaining: number;
            polish_credit_characters: number;
            summary_credit_characters: number;
        };
    };
}>();

const page = usePage();
const toast = useWorkspaceToast();
const { settingsHref } = useSettingsModal();
const createOpen = ref(false);
const polishOpen = ref(false);
const summaryOpen = ref(false);
const logOpen = ref(false);
const workspaceMode = ref<'choose' | 'live' | 'upload'>('choose');
const polishPreset = ref<
    'english' | 'filipino' | 'grammar' | 'translate_fix' | 'custom'
>('grammar');
const polishCustomInstruction = ref('');
const polishError = ref('');
const summarySource = ref<'raw' | 'cleaned'>('raw');
const exportOpen = ref(false);
const exportSource = ref<'raw' | 'cleaned' | 'summary'>('raw');
const isExporting = ref(false);
const uploadInput = useTemplateRef<HTMLInputElement>('uploadInput');
const logTrigger = useTemplateRef<HTMLButtonElement>('logTrigger');
const logPanel = useTemplateRef<HTMLElement>('logPanel');
const localProject = ref<ActiveProject | null>(props.activeProject);
const isActing = ref(false);
const upgradeBanner = ref('');

const userName = computed(() => {
    const user = page.props.auth?.user;

    return user?.name ?? 'JERVA user';
});

const totalTranscriptionMinutes = computed(
    () =>
        props.entitlements.plan.minutes +
        props.entitlements.usage.minutes_credit_balance,
);

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

const displayProject = computed(
    () => localProject.value ?? props.activeProject,
);

const hasTranscriptContent = computed(
    () => transcriptDisplayItems.value.length > 0,
);

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

const transcriptRows = computed(() =>
    transcriptDisplayItems.value.flatMap((transcript) => {
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

const emptyPanel = computed(() => {
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

const hasRawTranscript = computed(
    () =>
        Boolean(primaryTranscript.value?.raw_text?.trim()) ||
        (primaryTranscript.value?.sections.length ?? 0) > 0,
);

const isPolishing = computed(
    () => primaryTranscript.value?.polish_status === 'processing',
);

const isSummarizing = computed(
    () => primaryTranscript.value?.summary_status === 'processing',
);

const summaryStatusLabel = computed(() => {
    if (isSummarizing.value) {
        return 'Summarizing...';
    }

    if (primaryTranscript.value?.summary_status === 'complete') {
        return 'Complete';
    }

    if (primaryTranscript.value?.summary_status === 'failed') {
        return 'Failed';
    }

    return 'Ready';
});

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
        Boolean(props.entitlements.plan.features.upload) &&
        Boolean(displayProject.value),
);

const canUseLive = computed(
    () =>
        Boolean(props.entitlements.plan.features.live) &&
        Boolean(displayProject.value),
);

const createForm = useForm({
    title: '',
});

const polishPresets = [
    {
        key: 'english',
        label: 'Translate to English',
        instruction:
            'Translate every non-English part of the transcript into clear English. Treat Cebuano, Bisaya, Filipino, Tagalog, and mixed code-switching as source language. Do not leave source-language words untranslated unless they are names, offices, agencies, titles, acronyms, places, or proper nouns. Preserve meaning, speaker intent, numbers, and time order.',
    },
    {
        key: 'filipino',
        label: 'Translate to Filipino',
        instruction:
            'Translate every non-Filipino part of the transcript into clear Filipino. Treat English, Cebuano, Bisaya, and mixed code-switching as source language. Do not leave source-language words untranslated unless they are names, offices, agencies, titles, acronyms, places, or proper nouns. Preserve meaning, speaker intent, numbers, and time order.',
    },
    {
        key: 'grammar',
        label: 'Fix grammar',
        instruction:
            'Fix grammar, spelling, punctuation, capitalization, and obvious speech-to-text mistakes without translating the transcript. Preserve the original language choices, meaning, names, titles, numbers, and time order.',
    },
    {
        key: 'translate_fix',
        label: 'Translate and fix',
        instruction:
            'Translate every non-English sentence, phrase, or word into polished English, then fix grammar, spelling, punctuation, capitalization, and obvious speech-to-text mistakes. Preserve meaning, speaker intent, names, titles, numbers, and time order.',
    },
] as const;

watch(
    () => props.activeProject,
    (project) => {
        localProject.value = project;
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
        if (previous !== 'processing') {
            return;
        }

        if (status === 'failed') {
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

watch(
    () => logOpen.value,
    (open) => {
        document.body.style.overflow = open ? 'hidden' : '';
    },
);

watch(polishOpen, (open) => {
    if (open && polishCustomInstruction.value.trim() === '') {
        selectPolishPreset(polishPresets[2]);
    }
});

const createProject = () => {
    createForm.post('/workspace', {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            createOpen.value = false;
            workspaceMode.value = 'choose';
        },
    });
};

const showFlashToast = () => {
    const flash = page.props.flash as
        | {
              success?: string;
              toast?: { type?: string; message?: string };
          }
        | undefined;

    if (flash?.toast?.message) {
        if (flash.toast.type === 'error') {
            toast.error(flash.toast.message);
        } else {
            toast.success(flash.toast.message);
        }

        return;
    }

    if (flash?.success) {
        toast.success(flash.success);
    }
};

const csrfToken = () =>
    document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';

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
    };
    localProject.value = {
        ...payload.project,
        updated_at: displayProject.value.updated_at,
        transcripts_count: payload.project.transcripts.length,
    };
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
        ...(displayProject.value as ActiveProject),
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
    onTranscript: addTranscriptToLocal,
    onQueued: startPolling,
    onUpgrade: (message) => {
        upgradeBanner.value = message;
    },
    onToastError: (message) => {
        toast.error(message);
    },
});

const chooseUpload = () => {
    workspaceMode.value = 'upload';
    uploadInput.value?.click();
};

const chooseLiveMode = () => {
    workspaceMode.value = 'live';
};

const chooseUploadMode = () => {
    workspaceMode.value = 'upload';
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

const handleUploadPick = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file || !displayProject.value) {
        return;
    }

    await upload.selectFile(file);
    input.value = '';
};

const selectPolishPreset = (preset: (typeof polishPresets)[number]) => {
    polishPreset.value = preset.key;
    polishCustomInstruction.value = preset.instruction;
    polishError.value = '';
};

const editCustomPolishInstruction = () => {
    polishPreset.value = 'custom';
    polishError.value = '';
};

const polishTranscript = async () => {
    if (!displayProject.value || !primaryTranscript.value) {
        return;
    }

    if (!hasRawTranscript.value) {
        toast.error('No raw transcript is ready to polish yet.');

        return;
    }

    if (polishCustomInstruction.value.trim().length < 3) {
        polishError.value = 'Enter instructions before polishing.';

        return;
    }

    isActing.value = true;
    upgradeBanner.value = '';
    const response = await fetch(
        `/workspace/${displayProject.value.id}/transcripts/${primaryTranscript.value.id}/polish`,
        {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                preset: polishPreset.value,
                instruction: polishCustomInstruction.value,
            }),
        },
    );
    const payload = (await response.json()) as {
        message?: string;
        transcript?: Transcript;
        upgrade?: boolean;
    };
    isActing.value = false;

    if (!response.ok) {
        if (payload.upgrade) {
            upgradeBanner.value =
                payload.message ?? 'Transcript could not be polished.';

            return;
        }

        toast.error(payload.message ?? 'Transcript could not be polished.');

        return;
    }

    if (payload.transcript) {
        addTranscriptToLocal(payload.transcript);
    }

    polishOpen.value = false;
    startPolling();
};

const summarizeTranscript = async (source: 'raw' | 'cleaned') => {
    if (!displayProject.value || !primaryTranscript.value) {
        return;
    }

    if (!hasRawTranscript.value) {
        toast.error('The transcript could not be summarized.');

        return;
    }

    isActing.value = true;
    upgradeBanner.value = '';
    const response = await fetch(
        `/workspace/${displayProject.value.id}/transcripts/${primaryTranscript.value.id}/summarize`,
        {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ source }),
        },
    );
    const payload = (await response.json()) as {
        message?: string;
        transcript?: Transcript;
        upgrade?: boolean;
    };
    isActing.value = false;

    if (!response.ok) {
        if (payload.upgrade) {
            upgradeBanner.value =
                payload.message ?? 'The transcript could not be summarized.';

            return;
        }

        toast.error(
            payload.message ?? 'The transcript could not be summarized.',
        );

        return;
    }

    if (payload.transcript) {
        addTranscriptToLocal(payload.transcript);
    }

    startPolling();
};

const exportTranscript = async (
    format: 'txt' | 'docx' | 'xlsx',
    source: 'raw' | 'cleaned' | 'summary' = exportSource.value,
) => {
    if (!displayProject.value || !primaryTranscript.value) {
        return;
    }

    if (source === 'cleaned' && !primaryTranscript.value.cleaned_text) {
        toast.error(
            'Polish the transcript before exporting the cleaned version.',
        );

        return;
    }

    if (source === 'summary' && !primaryTranscript.value.summary_text) {
        toast.error('Create a summary before exporting.');

        return;
    }

    if (!hasRawTranscript.value) {
        toast.error('No transcription is ready to export yet.');

        return;
    }

    isExporting.value = true;
    const response = await fetch(
        `/workspace/${displayProject.value.id}/transcripts/${primaryTranscript.value.id}/export?format=${format}&source=${source}`,
        {
            headers: {
                Accept: 'application/octet-stream,application/json',
            },
        },
    );
    isExporting.value = false;

    if (!response.ok) {
        const payload = (await response.json().catch(() => ({}))) as {
            message?: string;
            upgrade?: boolean;
        };

        if (payload.upgrade) {
            upgradeBanner.value =
                payload.message ?? 'No transcription is ready to export yet.';

            return;
        }

        toast.error(
            payload.message ?? 'No transcription is ready to export yet.',
        );

        return;
    }

    const blob = await response.blob();
    const filename = filenameFromDisposition(
        response.headers.get('Content-Disposition'),
        `jerva-transcript-${primaryTranscript.value.id}.${format}`,
    );
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
    exportOpen.value = false;
    toast.success(`Export download started: ${filename}`);
};

const filenameFromDisposition = (header: string | null, fallback: string) => {
    const match = header?.match(/filename="?([^"]+)"?/i);

    return match?.[1] ?? fallback;
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

const openLog = async () => {
    logOpen.value = true;
    await nextTick();
    logPanel.value?.focus();
};

const closeLog = () => {
    logOpen.value = false;
    logTrigger.value?.focus();
};

onMounted(() => {
    showFlashToast();

    if (pendingTranscripts.value.length > 0) {
        startPolling();
    }
});

watch(() => page.props.flash, showFlashToast);
</script>

<template>
    <Head title="Workspace" />
    <WorkspaceToast />

    <main class="min-h-screen bg-white text-[14px] leading-5 text-slate-950">
        <div
            class="flex min-h-screen items-center justify-center bg-slate-50 p-6 lg:hidden"
        >
            <div
                class="max-w-md rounded-lg border border-blue-100 bg-blue-50 p-6 text-center"
            >
                <h1 class="text-xl font-semibold text-blue-950">
                    Workspace needs more room
                </h1>
                <p class="mt-3 text-sm leading-6 text-blue-900">
                    JERVA Web beta is optimized for tablets and desktops at
                    1024px and wider. Marketing and auth pages remain fully
                    responsive.
                </p>
            </div>
        </div>

        <div class="hidden min-h-screen lg:flex">
            <aside
                class="flex w-[19rem] shrink-0 flex-col border-r border-slate-200 bg-slate-50"
            >
                <div class="border-b border-slate-200 p-4">
                    <div class="flex h-[72px] items-center gap-3 px-2">
                        <img
                            src="/JervaLogo.png"
                            alt="JERVA Transcriber"
                            class="h-10 w-10 shrink-0 object-contain"
                        />
                        <div class="min-w-0">
                            <h1 class="text-base font-semibold text-slate-950">
                                JERVA Transcriber
                            </h1>
                        </div>
                    </div>

                    <Dialog v-model:open="createOpen">
                        <DialogTrigger as-child>
                            <button
                                type="button"
                                class="mt-5 flex h-11 w-full cursor-pointer items-center justify-center rounded-lg bg-blue-600 px-3 text-sm font-semibold text-white transition hover:bg-blue-700"
                            >
                                Add Transcript
                            </button>
                        </DialogTrigger>
                        <DialogContent
                            class="max-w-md border-slate-200 bg-white p-4 shadow-2xl"
                            :show-close-button="false"
                        >
                            <DialogHeader>
                                <DialogTitle
                                    class="text-base font-semibold text-slate-950"
                                >
                                    Add Transcript
                                </DialogTitle>
                            </DialogHeader>
                            <form
                                class="mt-4 grid gap-4"
                                @submit.prevent="createProject"
                            >
                                <div class="grid gap-2">
                                    <Label for="project-title">
                                        Transcript name
                                    </Label>
                                    <Input
                                        id="project-title"
                                        v-model="createForm.title"
                                        autofocus
                                        placeholder="Project or conversation name"
                                    />
                                    <InputError
                                        :message="createForm.errors.title"
                                    />
                                </div>
                                <DialogFooter class="gap-2">
                                    <button
                                        type="button"
                                        class="h-10 cursor-pointer rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                        @click="createOpen = false"
                                    >
                                        Cancel
                                    </button>
                                    <ProcessingButton
                                        type="submit"
                                        :loading="createForm.processing"
                                        class="h-10 cursor-pointer rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700"
                                    >
                                        Add
                                    </ProcessingButton>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <div class="flex-1 overflow-y-auto p-4">
                    <p
                        class="text-xs font-semibold tracking-wide text-slate-600 uppercase"
                    >
                        Recent
                    </p>
                    <div class="mt-3 grid gap-2">
                        <Link
                            v-for="project in projects"
                            :key="project.id"
                            :href="`/workspace/${project.id}`"
                            class="flex min-h-11 w-full cursor-pointer items-center rounded-lg px-3 py-2 text-left text-sm leading-5 transition"
                            :class="
                                displayProject?.id === project.id
                                    ? 'bg-blue-100 font-semibold text-blue-800 shadow-[inset_3px_0_0_#2563eb]'
                                    : 'text-slate-950 hover:bg-blue-50 hover:text-blue-700'
                            "
                        >
                            <span class="block truncate font-medium">
                                {{ project.title }}
                            </span>
                        </Link>

                        <div
                            v-if="projects.length === 0"
                            class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600"
                        >
                            No transcripts yet.
                        </div>
                    </div>
                </div>

                <div class="border-t border-slate-200 p-4">
                    <Link
                        :href="settingsHref('billing')"
                        preserve-scroll
                        preserve-state
                        replace
                        class="block rounded-lg border border-blue-100 bg-blue-50 p-4 transition-colors hover:border-blue-200 hover:bg-blue-100"
                    >
                        <p class="text-sm font-semibold text-blue-950">
                            Today's allowance
                        </p>
                        <p class="mt-1 text-xs text-blue-900">
                            {{ entitlements.usage.minutes_remaining }} of
                            {{ totalTranscriptionMinutes }} minutes remaining
                        </p>
                    </Link>
                    <div class="mt-4 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p
                                class="truncate text-sm font-semibold text-slate-950"
                            >
                                {{ userName }}
                            </p>
                            <p class="text-xs text-slate-600">Signed in</p>
                        </div>
                        <Button as-child size="icon" variant="ghost">
                            <Link
                                :href="settingsHref('profile')"
                                preserve-scroll
                                preserve-state
                                replace
                                aria-label="Settings"
                            >
                                <Settings class="size-5" />
                            </Link>
                        </Button>
                    </div>
                </div>
            </aside>

            <section class="relative flex min-w-0 flex-1 flex-col bg-white">
                <header
                    class="flex h-[72px] shrink-0 items-center justify-between border-b border-slate-200 px-6"
                >
                    <div class="min-w-0">
                        <p
                            class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                        >
                            Transcript
                        </p>
                        <h2
                            class="truncate text-lg font-semibold text-slate-950"
                        >
                            {{ projectTitle }}
                        </h2>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Button as-child variant="outline" size="icon">
                            <Link
                                :href="settingsHref('profile')"
                                preserve-scroll
                                preserve-state
                                replace
                                aria-label="Settings"
                            >
                                <Settings class="size-5" />
                            </Link>
                        </Button>
                    </div>
                </header>

                <div class="flex-1 overflow-y-auto pb-28">
                    <div class="h-full px-8 py-6">
                        <div
                            v-if="upgradeBanner"
                            class="mb-4 flex items-center justify-between gap-4 rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900"
                        >
                            <span>{{ upgradeBanner }}</span>
                            <Link
                                :href="settingsHref('billing')"
                                preserve-scroll
                                preserve-state
                                replace
                                class="font-semibold text-blue-700"
                            >
                                View plans
                            </Link>
                        </div>

                        <template v-if="hasTranscriptContent && displayProject">
                            <article
                                v-for="row in transcriptRows"
                                :key="row.id"
                                class="w-full border-b border-slate-200 py-4 last:border-b-0"
                            >
                                <div
                                    class="flex w-full flex-col gap-3 md:flex-row md:items-start md:gap-6"
                                >
                                    <div
                                        class="shrink-0 text-sm leading-6 font-medium tracking-[0.02em] text-blue-600 md:w-60"
                                    >
                                        {{ row.range }}
                                    </div>
                                    <p
                                        class="min-w-0 flex-1 text-sm leading-6 break-words whitespace-pre-line text-slate-950"
                                    >
                                        {{ row.text }}
                                    </p>
                                </div>
                            </article>
                            <div
                                v-if="
                                    displayProject.transcripts.some(
                                        (transcript) =>
                                            transcript.status === 'failed',
                                    )
                                "
                                class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4"
                            >
                                <p class="text-sm font-semibold text-red-700">
                                    Audio upload could not be processed.
                                </p>
                                <Button
                                    v-if="upload.canRetry.value"
                                    class="mt-3 border border-slate-200 bg-white text-slate-700 hover:border-blue-300 hover:bg-blue-50"
                                    variant="outline"
                                    @click="upload.retry"
                                >
                                    Retry
                                </Button>
                            </div>
                        </template>

                        <div
                            v-else
                            class="mx-auto flex min-h-[calc(100vh-14rem)] max-w-3xl flex-col items-center justify-center text-center"
                        >
                            <p
                                class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                            >
                                {{ emptyPanel.eyebrow }}
                            </p>
                            <h3
                                class="mt-4 text-3xl font-semibold text-slate-950"
                            >
                                {{ emptyPanel.title }}
                            </h3>
                            <p
                                class="mt-4 max-w-xl text-sm leading-6 text-blue-950"
                            >
                                {{ emptyPanel.copy }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-if="displayProject"
                    class="pointer-events-none absolute inset-x-0 bottom-0 border-t border-slate-200 bg-white/95 px-6 py-4"
                >
                    <div
                        class="pointer-events-auto mx-auto flex max-w-[calc(100%-2rem)] flex-col items-center justify-center gap-4"
                    >
                        <input
                            ref="uploadInput"
                            type="file"
                            class="hidden"
                            accept="audio/*,.wav,.mp3,.m4a,.aac,.ogg,.flac,.webm"
                            @change="handleUploadPick"
                        />
                        <template v-if="workspaceMode === 'choose'">
                            <div
                                class="order-1 mx-auto flex w-fit max-w-[calc(100%-2rem)] items-center justify-center gap-3 rounded-lg border border-blue-100 bg-white px-3 py-3 shadow-[0_12px_32px_rgba(15,23,42,0.1)]"
                            >
                                <button
                                    type="button"
                                    class="h-12 min-w-40 cursor-pointer rounded-lg border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="!displayProject"
                                    @click="chooseLiveMode"
                                >
                                    Live
                                </button>
                                <button
                                    type="button"
                                    class="h-12 min-w-40 cursor-pointer rounded-lg border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="!displayProject"
                                    @click="chooseUploadMode"
                                >
                                    Upload Audio
                                </button>
                            </div>
                        </template>

                        <template v-if="workspaceMode === 'live'">
                            <div
                                class="order-1 flex w-fit max-w-[calc(100%-2rem)] items-center gap-3 rounded-lg border border-blue-100 bg-white px-3 py-3 shadow-[0_12px_32px_rgba(15,23,42,0.1)] transition"
                            >
                                <button
                                    type="button"
                                    class="group flex h-12 min-w-40 cursor-pointer items-center justify-center gap-3 rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition outline-none hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-300 disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="
                                        !displayProject &&
                                        !live.isUnavailable.value
                                    "
                                    :aria-pressed="live.isRecording.value"
                                    @click="toggleLive"
                                >
                                    <Square
                                        v-if="live.isRecording.value"
                                        class="size-4 fill-current"
                                    />
                                    <Play v-else class="size-4 fill-current" />
                                    <span class="grid text-left leading-none">
                                        <span
                                            class="text-xs font-semibold text-blue-200 uppercase"
                                        >
                                            {{ live.buttonTop.value }}
                                        </span>
                                        <span
                                            class="mt-1 text-sm font-semibold text-white"
                                        >
                                            {{ live.buttonBottom.value }}
                                        </span>
                                    </span>
                                </button>
                                <div
                                    v-if="live.isPanelVisible.value"
                                    class="w-80 min-w-0 flex-none"
                                >
                                    <div
                                        class="flex min-w-0 items-center gap-2 text-sm"
                                    >
                                        <span
                                            class="shrink-0 font-semibold text-slate-950"
                                        >
                                            {{ live.activeName.value }}
                                        </span>
                                        <span
                                            class="min-w-0 truncate text-slate-500"
                                        >
                                            {{ live.currentRangeLabel.value }}
                                        </span>
                                        <span
                                            class="ml-auto shrink-0 font-semibold text-blue-700"
                                        >
                                            {{ live.elapsedLabel.value }}
                                        </span>
                                    </div>
                                    <div
                                        class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"
                                    >
                                        <div
                                            class="h-full rounded-full bg-blue-600 transition-[width] duration-150"
                                            :style="{
                                                width: `${live.segmentProgress.value}%`,
                                            }"
                                        />
                                    </div>
                                    <p
                                        class="mt-1 text-xs font-medium text-slate-500"
                                    >
                                        {{ live.supportLine.value }}
                                    </p>
                                </div>
                            </div>
                        </template>

                        <template v-if="workspaceMode === 'upload'">
                            <div
                                class="order-1 flex w-fit max-w-[calc(100%-2rem)] flex-wrap items-center justify-center gap-3 rounded-lg border border-blue-100 bg-white px-3 py-3 shadow-[0_12px_32px_rgba(15,23,42,0.1)] transition"
                            >
                                <button
                                    type="button"
                                    class="inline-flex h-12 min-w-32 shrink-0 cursor-pointer items-center justify-center rounded-lg border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="
                                        !canUseUpload ||
                                        upload.inFlight.value ||
                                        upload.isPreparing.value
                                    "
                                    @click="chooseUpload"
                                >
                                    Browse
                                </button>
                                <div
                                    v-if="
                                        upload.hasFile.value ||
                                        upload.isActive.value
                                    "
                                    class="w-80 min-w-0 flex-none"
                                >
                                    <p
                                        class="truncate text-sm font-semibold text-slate-950"
                                    >
                                        {{ upload.fileName.value }}
                                    </p>
                                    <p class="truncate text-xs text-slate-500">
                                        {{
                                            upload.metaLine.value ||
                                            'WAV, MP3, M4A, AAC, OGG, FLAC.'
                                        }}
                                    </p>
                                    <p class="text-xs text-slate-500">
                                        Duration:
                                        <span
                                            class="font-semibold text-slate-700"
                                        >
                                            {{ upload.durationLabel.value }}
                                        </span>
                                    </p>
                                    <div
                                        class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"
                                    >
                                        <div
                                            class="h-full rounded-full bg-blue-600 transition-[width] duration-150"
                                            :style="{
                                                width: `${upload.progressPercent.value}%`,
                                            }"
                                        />
                                    </div>
                                </div>
                                <span
                                    v-if="
                                        upload.hasFile.value ||
                                        upload.isActive.value
                                    "
                                    class="max-w-28 truncate text-xs font-semibold text-slate-700"
                                >
                                    {{ upload.statusLine.value }}
                                </span>
                                <span
                                    v-if="
                                        upload.hasFile.value ||
                                        upload.isActive.value
                                    "
                                    class="w-10 text-right text-xs font-semibold text-blue-700"
                                >
                                    {{ upload.progressPercent.value }}%
                                </span>
                                <button
                                    v-if="
                                        upload.hasFile.value ||
                                        upload.isActive.value
                                    "
                                    type="button"
                                    class="h-12 min-w-20 cursor-pointer rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-500"
                                    :disabled="!upload.canStart.value"
                                    @click="upload.start"
                                >
                                    Start
                                </button>
                                <button
                                    v-if="upload.canPause.value"
                                    type="button"
                                    class="h-12 min-w-20 cursor-pointer rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="!upload.canPause.value"
                                    @click="upload.pause"
                                >
                                    Pause
                                </button>
                                <button
                                    v-if="upload.canContinue.value"
                                    type="button"
                                    class="h-12 min-w-24 cursor-pointer rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="!upload.canContinue.value"
                                    @click="upload.resume"
                                >
                                    Continue
                                </button>
                                <button
                                    v-if="upload.canRetry.value"
                                    type="button"
                                    class="h-12 min-w-20 cursor-pointer rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="!upload.canRetry.value"
                                    @click="upload.retry"
                                >
                                    Retry
                                </button>
                                <button
                                    v-if="upload.canCancel.value"
                                    type="button"
                                    class="h-12 min-w-20 cursor-pointer rounded-lg border border-red-200 bg-red-50 px-3 text-sm font-semibold text-red-700 transition hover:border-red-300 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="!upload.canCancel.value"
                                    @click="upload.cancel"
                                >
                                    Cancel
                                </button>
                            </div>
                        </template>
                        <template v-if="activeTranscriptActionMode">
                            <div
                                class="order-2 flex items-center gap-2 rounded-lg border border-blue-100 bg-white p-1.5 shadow-[0_12px_32px_rgba(15,23,42,0.08)]"
                            >
                                <Dialog v-model:open="polishOpen">
                                    <DialogTrigger as-child>
                                        <button
                                            type="button"
                                            :disabled="
                                                !primaryTranscript ||
                                                isActing ||
                                                isPolishing
                                            "
                                            class="inline-flex h-11 cursor-pointer items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            <Sparkles class="size-4" />
                                            {{
                                                isPolishing
                                                    ? 'Polishing'
                                                    : 'Polish'
                                            }}
                                        </button>
                                    </DialogTrigger>
                                    <DialogContent
                                        class="border-slate-200 bg-white shadow-2xl"
                                    >
                                        <DialogHeader>
                                            <p
                                                class="text-xs font-semibold text-blue-600 uppercase"
                                            >
                                                Polish transcript
                                            </p>
                                            <DialogTitle
                                                >Instructions</DialogTitle
                                            >
                                        </DialogHeader>
                                        <div class="grid gap-4">
                                            <div class="grid gap-2">
                                                <Label>Preset</Label>
                                                <div
                                                    class="grid gap-2 sm:grid-cols-2"
                                                >
                                                    <Button
                                                        v-for="preset in polishPresets"
                                                        :key="preset.key"
                                                        type="button"
                                                        variant="outline"
                                                        :class="
                                                            polishPreset ===
                                                            preset.key
                                                                ? 'border-blue-300 bg-blue-50 text-blue-700'
                                                                : ''
                                                        "
                                                        @click="
                                                            selectPolishPreset(
                                                                preset,
                                                            )
                                                        "
                                                    >
                                                        {{ preset.label }}
                                                    </Button>
                                                </div>
                                            </div>
                                            <div class="grid gap-2">
                                                <Label for="custom-polish">
                                                    Custom instructions
                                                </Label>
                                                <textarea
                                                    id="custom-polish"
                                                    v-model="
                                                        polishCustomInstruction
                                                    "
                                                    maxlength="2000"
                                                    class="min-h-32 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                                                    @input="
                                                        editCustomPolishInstruction
                                                    "
                                                />
                                                <p
                                                    v-if="polishError"
                                                    class="text-sm text-red-700"
                                                >
                                                    {{ polishError }}
                                                </p>
                                                <p
                                                    v-if="
                                                        primaryTranscript?.cleaned_text
                                                    "
                                                    class="text-sm text-slate-600"
                                                >
                                                    Polishing again replaces the
                                                    current polished transcript.
                                                </p>
                                            </div>
                                        </div>
                                        <DialogFooter>
                                            <Button
                                                variant="outline"
                                                @click="polishOpen = false"
                                            >
                                                Cancel
                                            </Button>
                                            <Button
                                                :disabled="
                                                    isActing || isPolishing
                                                "
                                                @click="polishTranscript"
                                            >
                                                {{
                                                    isPolishing
                                                        ? 'Polishing'
                                                        : 'Polish'
                                                }}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                                <Dialog v-model:open="summaryOpen">
                                    <DialogTrigger as-child>
                                        <button
                                            type="button"
                                            :disabled="
                                                !primaryTranscript ||
                                                isActing ||
                                                isSummarizing
                                            "
                                            class="inline-flex h-11 cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            <FileText class="size-4" />
                                            Summarize
                                        </button>
                                    </DialogTrigger>
                                    <DialogContent
                                        class="border-slate-200 bg-white shadow-2xl"
                                    >
                                        <DialogHeader>
                                            <DialogTitle>
                                                {{ summaryStatusLabel }}
                                            </DialogTitle>
                                            <DialogDescription
                                                class="text-slate-600"
                                            >
                                                The summary is being prepared.
                                                You may close this window and
                                                return later.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <div class="grid gap-4">
                                            <div
                                                v-if="
                                                    primaryTranscript?.summary_text
                                                "
                                                class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm leading-6 text-blue-950"
                                            >
                                                {{
                                                    primaryTranscript.summary_text
                                                }}
                                            </div>
                                            <p
                                                v-else-if="!isSummarizing"
                                                class="text-sm text-slate-600"
                                            >
                                                No summary has been created for
                                                this project.
                                            </p>
                                            <p
                                                v-if="
                                                    primaryTranscript?.summary_text
                                                "
                                                class="text-sm text-slate-600"
                                            >
                                                Starting again replaces this
                                                project's existing summary.
                                            </p>
                                            <div class="grid gap-2">
                                                <Label>Source</Label>
                                                <div
                                                    class="grid grid-cols-2 gap-2"
                                                >
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        :class="
                                                            summarySource ===
                                                            'raw'
                                                                ? 'border-blue-300 bg-blue-50 text-blue-700'
                                                                : ''
                                                        "
                                                        @click="
                                                            summarySource =
                                                                'raw'
                                                        "
                                                    >
                                                        Raw transcript
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        :class="
                                                            summarySource ===
                                                            'cleaned'
                                                                ? 'border-blue-300 bg-blue-50 text-blue-700'
                                                                : ''
                                                        "
                                                        @click="
                                                            summarySource =
                                                                'cleaned'
                                                        "
                                                    >
                                                        Cleaned transcript
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                        <DialogFooter>
                                            <Button
                                                variant="outline"
                                                @click="summaryOpen = false"
                                            >
                                                Cancel
                                            </Button>
                                            <Button
                                                @click="
                                                    summarizeTranscript(
                                                        summarySource,
                                                    )
                                                "
                                                :disabled="
                                                    isActing || isSummarizing
                                                "
                                            >
                                                {{
                                                    primaryTranscript?.summary_text
                                                        ? 'Replace summary'
                                                        : 'Summarize'
                                                }}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                                <div class="relative">
                                    <button
                                        type="button"
                                        :disabled="
                                            !primaryTranscript || isExporting
                                        "
                                        class="inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50"
                                        @click="exportOpen = !exportOpen"
                                    >
                                        <Download class="size-4" />
                                        {{
                                            isExporting ? 'Exporting' : 'Export'
                                        }}
                                    </button>
                                    <div
                                        v-if="exportOpen"
                                        class="absolute right-0 bottom-14 z-40 w-56 rounded-lg border border-slate-200 bg-white p-2 shadow-2xl"
                                    >
                                        <div
                                            class="mb-2 grid grid-cols-3 gap-1"
                                        >
                                            <button
                                                type="button"
                                                class="rounded border border-slate-200 px-2 py-1 text-xs text-slate-700"
                                                :class="
                                                    exportSource === 'raw'
                                                        ? 'border-blue-300 bg-blue-50 text-blue-700'
                                                        : ''
                                                "
                                                @click="exportSource = 'raw'"
                                            >
                                                Raw
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded border border-slate-200 px-2 py-1 text-xs text-slate-700"
                                                :class="
                                                    exportSource === 'cleaned'
                                                        ? 'border-blue-300 bg-blue-50 text-blue-700'
                                                        : ''
                                                "
                                                @click="
                                                    exportSource = 'cleaned'
                                                "
                                            >
                                                Cleaned
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded border border-slate-200 px-2 py-1 text-xs text-slate-700"
                                                :class="
                                                    exportSource === 'summary'
                                                        ? 'border-blue-300 bg-blue-50 text-blue-700'
                                                        : ''
                                                "
                                                @click="
                                                    exportSource = 'summary'
                                                "
                                            >
                                                Summary
                                            </button>
                                        </div>
                                        <div class="grid gap-1">
                                            <Button
                                                variant="outline"
                                                @click="exportTranscript('txt')"
                                            >
                                                TXT
                                            </Button>
                                            <Button
                                                variant="outline"
                                                @click="
                                                    exportTranscript('docx')
                                                "
                                            >
                                                Microsoft Word
                                            </Button>
                                            <Button
                                                variant="outline"
                                                @click="
                                                    exportTranscript('xlsx')
                                                "
                                            >
                                                Excel
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                                <button
                                    ref="logTrigger"
                                    type="button"
                                    :disabled="!primaryTranscript"
                                    aria-label="Processing log"
                                    title="Processing log"
                                    class="inline-flex h-11 min-w-11 cursor-pointer items-center justify-center rounded-lg border border-slate-200 bg-white px-3 text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="openLog"
                                >
                                    <ListChecks class="size-4" />
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </section>

            <div
                class="fixed inset-0 z-50 bg-slate-950/40 transition-opacity"
                :class="
                    logOpen ? 'opacity-100' : 'pointer-events-none opacity-0'
                "
                @click="closeLog"
            />
            <aside
                ref="logPanel"
                class="fixed top-0 right-0 z-50 h-full w-96 border-l border-slate-200 bg-white shadow-2xl transition duration-300"
                :class="logOpen ? 'translate-x-0' : 'translate-x-full'"
                aria-label="Processing log"
                role="dialog"
                aria-modal="true"
                tabindex="-1"
                @keydown.esc="closeLog"
            >
                <header
                    class="flex h-[72px] items-center justify-between border-b border-slate-200 px-6"
                >
                    <div>
                        <p
                            class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                        >
                            Log
                        </p>
                        <h2 class="text-lg font-semibold text-slate-950">
                            Processing log
                        </h2>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Close processing log"
                        @click="closeLog"
                    >
                        <X class="size-5" />
                    </Button>
                </header>
                <div class="grid gap-3 p-6">
                    <article
                        v-for="entry in primaryTranscript?.processing_log ?? []"
                        :key="`${entry.status}-${entry.created_at}`"
                        class="rounded-lg border border-slate-200 bg-white p-4"
                    >
                        <p class="text-sm font-semibold text-slate-950">
                            {{ entry.status }}
                        </p>
                        <p class="mt-1 text-sm leading-6 text-slate-700">
                            {{ entry.message }}
                        </p>
                        <p class="mt-2 text-xs text-slate-600">
                            {{ entry.created_at }}
                        </p>
                    </article>
                    <div
                        v-if="
                            (primaryTranscript?.processing_log ?? []).length ===
                            0
                        "
                        class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900"
                    >
                        No processing logs found for {{ projectTitle }}.
                    </div>
                </div>
            </aside>
        </div>
        <SettingsModal />
    </main>
</template>
