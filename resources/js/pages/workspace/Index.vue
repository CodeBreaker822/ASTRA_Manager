<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Clock3,
    Download,
    FileAudio,
    FileText,
    ListChecks,
    Mic,
    PanelRight,
    Pencil,
    Play,
    Plus,
    Settings,
    Sparkles,
    Square,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref, useTemplateRef, watch } from 'vue';
import InputError from '@/components/InputError.vue';
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
            features: Record<string, unknown>;
        };
        usage: {
            period: string;
            minutes_used: number;
            minutes_remaining: number;
            seconds_transcribed: number;
            polish_count: number;
            summary_count: number;
        };
    };
}>();

const page = usePage();
const toast = useWorkspaceToast();
const createOpen = ref(false);
const renameOpen = ref(false);
const onboardingOpen = ref(false);
const pendingOpen = ref(false);
const polishOpen = ref(false);
const summaryOpen = ref(false);
const logOpen = ref(false);
const deleteOpen = ref(false);
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
const pendingTrigger = useTemplateRef<HTMLButtonElement>('pendingTrigger');
const logTrigger = useTemplateRef<HTMLButtonElement>('logTrigger');
const pendingPanel = useTemplateRef<HTMLElement>('pendingPanel');
const logPanel = useTemplateRef<HTMLElement>('logPanel');
const localProject = ref<ActiveProject | null>(props.activeProject);
const isActing = ref(false);
const upgradeBanner = ref('');

const userName = computed(() => {
    const user = page.props.auth?.user;

    return user?.name ?? 'JERVA user';
});

const projectTitle = computed(() => displayProject.value?.title ?? 'Welcome');

const displayProject = computed(
    () => localProject.value ?? props.activeProject,
);

const hasTranscriptContent = computed(
    () =>
        displayProject.value?.transcripts.some(
            (transcript) =>
                transcript.raw_text ||
                transcript.cleaned_text ||
                transcript.summary_text ||
                ['queued', 'processing', 'failed'].includes(
                    transcript.status,
                ) ||
                transcript.sections.length > 0,
        ) ?? false,
);

const primaryTranscript = computed(
    () =>
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

const renameForm = useForm({
    title: props.activeProject?.title ?? '',
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
    () => props.activeProject?.title,
    (title) => {
        renameForm.title = title ?? '';
    },
);

watch(
    () => props.activeProject,
    (project) => {
        localProject.value = project;
    },
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
    () => pendingOpen.value || logOpen.value,
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
        },
    });
};

const renameProject = () => {
    if (!props.activeProject) {
        return;
    }

    renameForm.put(`/workspace/${props.activeProject.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            renameOpen.value = false;
        },
    });
};

const deleteProject = () => {
    if (!props.activeProject) {
        return;
    }

    router.delete(`/workspace/${props.activeProject.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
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
const uploadClips = computed(() => upload.clips.value);
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
const liveClips = computed(() => live.clips.value);

const chooseUpload = () => {
    uploadInput.value?.click();
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

const latestProcessingMessage = (transcript: Transcript) =>
    [...transcript.processing_log].reverse().find((entry) => entry.message)
        ?.message ?? 'Transcribing';

const openPending = async () => {
    pendingOpen.value = true;
    await nextTick();
    pendingPanel.value?.focus();
};

const closePending = () => {
    pendingOpen.value = false;
    pendingTrigger.value?.focus();
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

const dismissOnboarding = () => {
    onboardingOpen.value = false;
    localStorage.setItem('jerva.workspace.onboarded', 'true');
};

onMounted(() => {
    showFlashToast();
    onboardingOpen.value =
        localStorage.getItem('jerva.workspace.onboarded') !== 'true';

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
            <Dialog v-model:open="onboardingOpen">
                <DialogContent class="border-slate-200 bg-white shadow-2xl">
                    <DialogHeader>
                        <DialogTitle
                            class="text-base font-semibold text-slate-950"
                        >
                            Welcome to JERVA Web
                        </DialogTitle>
                        <DialogDescription class="text-sm text-slate-600">
                            Your browser workspace is online-only and uses the
                            server transcription pipeline.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-3">
                        <div
                            class="rounded-lg border border-blue-100 bg-blue-50 p-4"
                        >
                            <p class="text-sm font-semibold text-blue-950">
                                Create or open a transcript project
                            </p>
                            <p class="mt-1 text-sm leading-6 text-blue-900">
                                Projects keep uploads, live clips, exports, and
                                processing logs together.
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-blue-100 bg-blue-50 p-4"
                        >
                            <p class="text-sm font-semibold text-blue-950">
                                Capture audio online
                            </p>
                            <p class="mt-1 text-sm leading-6 text-blue-900">
                                Use Live for browser chunks or Upload Audio for
                                an existing file.
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-blue-100 bg-blue-50 p-4"
                        >
                            <p class="text-sm font-semibold text-blue-950">
                                Finish the transcript
                            </p>
                            <p class="mt-1 text-sm leading-6 text-blue-900">
                                Polish, summarize, export, or inspect the
                                processing log from the dock.
                            </p>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button @click="dismissOnboarding">Start work</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <aside
                class="flex w-[19rem] shrink-0 flex-col border-r border-slate-200 bg-slate-50"
            >
                <div class="border-b border-slate-200 p-4">
                    <div class="flex items-center gap-3">
                        <span
                            class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-600 text-white"
                        >
                            <Mic class="size-5" />
                        </span>
                        <div>
                            <h1 class="text-base font-semibold text-slate-950">
                                JERVA Transcriber
                            </h1>
                            <p class="text-xs text-slate-600">
                                Online workspace
                            </p>
                        </div>
                    </div>

                    <Dialog v-model:open="createOpen">
                        <DialogTrigger as-child>
                            <Button class="mt-5 w-full">
                                <Plus class="size-4" />
                                Add Transcript
                            </Button>
                        </DialogTrigger>
                        <DialogContent
                            class="border-slate-200 bg-white shadow-2xl"
                        >
                            <DialogHeader>
                                <DialogTitle
                                    class="text-base font-semibold text-slate-950"
                                >
                                    Add Transcript
                                </DialogTitle>
                                <DialogDescription
                                    class="text-sm text-slate-600"
                                >
                                    Create a project for upload or live
                                    transcription.
                                </DialogDescription>
                            </DialogHeader>
                            <form
                                class="grid gap-4"
                                @submit.prevent="createProject"
                            >
                                <div class="grid gap-2">
                                    <Label for="project-title">Title</Label>
                                    <Input
                                        id="project-title"
                                        v-model="createForm.title"
                                        autofocus
                                        placeholder="Client intake call"
                                    />
                                    <InputError
                                        :message="createForm.errors.title"
                                    />
                                </div>
                                <DialogFooter>
                                    <ProcessingButton
                                        type="submit"
                                        :loading="createForm.processing"
                                    >
                                        Create project
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
                            class="rounded-lg px-3 py-3 text-sm transition-colors hover:bg-blue-50 hover:text-blue-700"
                            :class="
                                displayProject?.id === project.id
                                    ? 'bg-blue-100 text-blue-900 shadow-[inset_3px_0_0_#2563eb]'
                                    : 'text-slate-700'
                            "
                        >
                            <span class="block truncate font-medium">
                                {{ project.title }}
                            </span>
                            <span class="mt-1 block text-xs text-slate-600">
                                {{ project.transcripts_count }} transcripts
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
                        href="/settings/billing"
                        class="block rounded-lg border border-blue-100 bg-blue-50 p-4 transition-colors hover:border-blue-200 hover:bg-blue-100"
                    >
                        <p class="text-sm font-semibold text-blue-950">
                            {{ entitlements.plan.name }} Plan
                        </p>
                        <p class="mt-1 text-xs text-blue-900">
                            {{ entitlements.usage.minutes_remaining }} of
                            {{ entitlements.plan.minutes }} minutes remaining
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
                                href="/settings/profile"
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
                    <div class="flex items-center gap-2">
                        <Dialog v-if="displayProject" v-model:open="renameOpen">
                            <DialogTrigger as-child>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    aria-label="Rename transcript"
                                >
                                    <Pencil class="size-4" />
                                </Button>
                            </DialogTrigger>
                            <DialogContent
                                class="border-slate-200 bg-white shadow-2xl"
                            >
                                <DialogHeader>
                                    <DialogTitle
                                        class="text-base font-semibold text-slate-950"
                                    >
                                        Rename Transcript
                                    </DialogTitle>
                                    <DialogDescription
                                        class="text-sm text-slate-600"
                                    >
                                        Update the project title shown in the
                                        sidebar and header.
                                    </DialogDescription>
                                </DialogHeader>
                                <form
                                    class="grid gap-4"
                                    @submit.prevent="renameProject"
                                >
                                    <div class="grid gap-2">
                                        <Label for="rename-title">Title</Label>
                                        <Input
                                            id="rename-title"
                                            v-model="renameForm.title"
                                            placeholder="Transcript title"
                                        />
                                        <InputError
                                            :message="renameForm.errors.title"
                                        />
                                    </div>
                                    <DialogFooter>
                                        <ProcessingButton
                                            type="submit"
                                            :loading="renameForm.processing"
                                        >
                                            Save changes
                                        </ProcessingButton>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                        <Dialog v-if="displayProject" v-model:open="deleteOpen">
                            <DialogTrigger as-child>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    aria-label="Delete transcript"
                                >
                                    <Trash2 class="size-4 text-red-700" />
                                </Button>
                            </DialogTrigger>
                            <DialogContent
                                class="border-slate-200 bg-white shadow-2xl"
                            >
                                <DialogHeader>
                                    <DialogTitle
                                        class="text-base font-semibold text-slate-950"
                                    >
                                        Delete transcript?
                                    </DialogTitle>
                                    <DialogDescription
                                        class="text-sm text-slate-600"
                                    >
                                        This permanently deletes '{{
                                            displayProject.title
                                        }}' and its transcripts. This cannot be
                                        undone.
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter>
                                    <Button
                                        variant="outline"
                                        @click="deleteOpen = false"
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        class="border border-red-200 bg-red-50 text-red-700 hover:bg-red-100"
                                        @click="deleteProject"
                                    >
                                        Delete
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                        <Button as-child variant="ghost" size="icon">
                            <Link
                                href="/settings/profile"
                                aria-label="Settings"
                            >
                                <Settings class="size-5" />
                            </Link>
                        </Button>
                    </div>
                </header>

                <div class="flex-1 overflow-y-auto pb-28">
                    <div class="mx-auto max-w-3xl px-8 py-6">
                        <div
                            v-if="upgradeBanner"
                            class="mb-4 flex items-center justify-between gap-4 rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900"
                        >
                            <span>{{ upgradeBanner }}</span>
                            <Link
                                href="/settings/billing"
                                class="font-semibold text-blue-700"
                            >
                                View plans
                            </Link>
                        </div>

                        <template v-if="hasTranscriptContent && displayProject">
                            <div
                                v-for="transcript in displayProject.transcripts"
                                :key="transcript.id"
                                class="mb-6 rounded-lg border border-slate-200 bg-white p-5"
                            >
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <div>
                                        <p
                                            class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                                        >
                                            {{ transcript.source }}
                                        </p>
                                        <h3
                                            class="mt-1 text-base font-semibold text-slate-950"
                                        >
                                            {{ transcript.status }}
                                        </h3>
                                    </div>
                                    <span
                                        class="rounded-lg border px-3 py-1 text-xs font-semibold"
                                        :class="
                                            transcript.status === 'failed'
                                                ? 'border-red-200 bg-red-50 text-red-700'
                                                : [
                                                        'queued',
                                                        'processing',
                                                    ].includes(
                                                        transcript.status,
                                                    )
                                                  ? 'border-blue-200 bg-blue-50 text-blue-900'
                                                  : 'border-green-200 bg-green-50 text-green-700'
                                        "
                                    >
                                        {{ transcript.status }}
                                    </span>
                                </div>
                                <div
                                    v-if="
                                        ['queued', 'processing'].includes(
                                            transcript.status,
                                        )
                                    "
                                    class="mt-4 rounded-lg border border-blue-100 bg-blue-50 p-4"
                                >
                                    <div
                                        class="flex items-center justify-between gap-3"
                                    >
                                        <p
                                            class="text-sm font-semibold text-blue-950"
                                        >
                                            {{
                                                latestProcessingMessage(
                                                    transcript,
                                                )
                                            }}
                                        </p>
                                        <span
                                            class="rounded-full border border-blue-200 bg-blue-50 px-2.5 py-1 text-[0.68rem] font-semibold text-blue-700 uppercase"
                                        >
                                            Processing
                                        </span>
                                    </div>
                                    <div
                                        class="mt-3 h-1 overflow-hidden rounded-full bg-blue-100"
                                    >
                                        <div
                                            class="h-full w-full animate-pulse bg-blue-600"
                                        />
                                    </div>
                                </div>
                                <div
                                    v-if="transcript.status === 'failed'"
                                    class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4"
                                >
                                    <p
                                        class="text-sm font-semibold text-red-700"
                                    >
                                        Failed
                                    </p>
                                    <p class="mt-2 text-sm text-red-700">
                                        {{
                                            latestProcessingMessage(transcript)
                                        }}
                                    </p>
                                    <Button
                                        v-if="upload.canRetry"
                                        class="mt-3 border border-slate-200 bg-white text-slate-700 hover:border-blue-300 hover:bg-blue-50"
                                        variant="outline"
                                        @click="upload.retry"
                                    >
                                        Retry
                                    </Button>
                                </div>
                                <div
                                    v-if="
                                        transcript.polish_status ===
                                            'processing' ||
                                        transcript.summary_status ===
                                            'processing'
                                    "
                                    class="mt-4 rounded-lg border border-blue-100 bg-blue-50 p-4"
                                >
                                    <p
                                        class="text-sm font-semibold text-blue-950"
                                    >
                                        Processing
                                    </p>
                                    <div
                                        class="mt-3 h-1 overflow-hidden rounded-full bg-blue-100"
                                    >
                                        <div
                                            class="h-full w-full animate-pulse bg-blue-600"
                                        />
                                    </div>
                                </div>
                                <div class="mt-4 grid gap-4">
                                    <p
                                        v-if="transcript.raw_text"
                                        class="text-sm leading-6 text-slate-700"
                                    >
                                        {{ transcript.raw_text }}
                                    </p>
                                    <div
                                        v-if="transcript.cleaned_text"
                                        class="rounded-lg border border-blue-100 bg-blue-50 p-4"
                                    >
                                        <p
                                            class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                                        >
                                            Cleaned
                                        </p>
                                        <p
                                            class="mt-2 text-sm leading-6 text-blue-950"
                                        >
                                            {{ transcript.cleaned_text }}
                                        </p>
                                    </div>
                                    <div
                                        v-if="transcript.summary_text"
                                        class="rounded-lg border border-slate-200 bg-white p-4"
                                    >
                                        <p
                                            class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                                        >
                                            Summary
                                        </p>
                                        <p
                                            class="mt-2 text-sm leading-6 text-slate-700"
                                        >
                                            {{ transcript.summary_text }}
                                        </p>
                                    </div>
                                    <p
                                        v-for="section in transcript.sections"
                                        :key="section.id"
                                        class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm leading-6 text-blue-950"
                                    >
                                        {{
                                            section.cleaned_text ?? section.text
                                        }}
                                    </p>
                                </div>
                            </div>
                        </template>

                        <div
                            v-else
                            class="flex min-h-[calc(100vh-14rem)] flex-col items-center justify-center text-center"
                        >
                            <p
                                class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                            >
                                {{
                                    displayProject
                                        ? 'Upload transcript'
                                        : 'Transcription workspace'
                                }}
                            </p>
                            <h3
                                class="mt-4 text-3xl font-semibold text-slate-950"
                            >
                                {{
                                    displayProject
                                        ? 'Great. How do you want to add audio?'
                                        : 'Hi, what are we transcribing today?'
                                }}
                            </h3>
                            <p
                                class="mt-4 max-w-xl text-sm leading-6 text-blue-950"
                            >
                                {{
                                    displayProject
                                        ? "Choose Live if you're recording now, or Upload Audio if the file is already on your computer."
                                        : "Start a transcript from the left, then choose Live or Upload Audio. I'll keep the transcript here so you can polish, summarize, export, or review the processing log when it's ready."
                                }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-if="live.isPanelVisible"
                    class="pointer-events-none absolute inset-x-0 px-6"
                    :style="{
                        bottom:
                            upload.isActive || upload.hasFile
                                ? '18.5rem'
                                : '6rem',
                    }"
                >
                    <div
                        class="pointer-events-auto mx-auto max-w-3xl rounded-lg border border-blue-100 bg-blue-50 p-4 shadow-[0_12px_32px_rgba(15,23,42,0.1)]"
                    >
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm font-semibold text-slate-950">
                                    {{ live.activeName }}
                                </p>
                                <p class="mt-1 text-xs text-blue-900">
                                    {{ live.currentRangeLabel }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p
                                    class="font-mono text-lg font-semibold text-slate-950"
                                >
                                    {{ live.elapsedLabel }}
                                </p>
                                <p class="text-xs text-blue-900">
                                    {{ live.supportLine }}
                                </p>
                            </div>
                        </div>
                        <div
                            class="mt-3 h-2 overflow-hidden rounded-full bg-blue-100"
                        >
                            <div
                                class="h-full rounded-full bg-blue-600 transition-[width] duration-150"
                                :style="{
                                    width: `${live.segmentProgress}%`,
                                }"
                            />
                        </div>
                    </div>
                </div>

                <div
                    v-if="upload.isActive || upload.hasFile"
                    class="pointer-events-none absolute inset-x-0 bottom-24 px-6"
                >
                    <div
                        class="pointer-events-auto mx-auto max-w-3xl rounded-lg border border-slate-200 bg-white p-4 shadow-[0_12px_32px_rgba(15,23,42,0.1)]"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p
                                    class="truncate text-sm font-semibold text-slate-950"
                                >
                                    {{ upload.fileName }}
                                </p>
                                <p class="mt-1 text-xs text-slate-600">
                                    Duration: {{ upload.durationLabel }}
                                </p>
                                <p class="mt-2 text-sm text-blue-900">
                                    {{ upload.statusLine }}
                                </p>
                            </div>
                            <p class="shrink-0 text-xs text-slate-600">
                                {{ upload.metaLine }}
                            </p>
                        </div>
                        <div
                            class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100"
                        >
                            <div
                                class="h-full rounded-full bg-blue-600 transition-[width] duration-150"
                                :style="{
                                    width: `${upload.progressPercent}%`,
                                }"
                            />
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <Button
                                class="h-12 min-w-32 border border-blue-200 bg-blue-50 text-blue-700 hover:border-blue-300 hover:bg-blue-100"
                                variant="outline"
                                @click="chooseUpload"
                            >
                                Browse
                            </Button>
                            <Button
                                v-if="upload.canStart"
                                class="h-12 bg-blue-600 text-white hover:bg-blue-700"
                                @click="upload.start"
                            >
                                Start
                            </Button>
                            <Button
                                v-if="upload.canPause"
                                class="h-12 border border-slate-200 bg-white text-slate-700 hover:border-blue-300 hover:bg-blue-50"
                                variant="outline"
                                @click="upload.pause"
                            >
                                Pause
                            </Button>
                            <Button
                                v-if="upload.canContinue"
                                class="h-12 border border-slate-200 bg-white text-slate-700 hover:border-blue-300 hover:bg-blue-50"
                                variant="outline"
                                @click="upload.resume"
                            >
                                Continue
                            </Button>
                            <Button
                                v-if="upload.canRetry"
                                class="h-12 border border-slate-200 bg-white text-slate-700 hover:border-blue-300 hover:bg-blue-50"
                                variant="outline"
                                @click="upload.retry"
                            >
                                Retry
                            </Button>
                            <Button
                                v-if="upload.canCancel"
                                class="h-12 border border-red-200 bg-red-50 text-red-700 hover:border-red-300 hover:bg-red-100"
                                variant="outline"
                                @click="upload.cancel"
                            >
                                Cancel
                            </Button>
                        </div>
                    </div>
                </div>

                <div
                    class="pointer-events-none absolute inset-x-0 bottom-0 border-t border-slate-200 bg-white/95 px-6 py-4"
                >
                    <div
                        class="pointer-events-auto mx-auto flex max-w-3xl flex-wrap items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white p-2 shadow-[0_12px_32px_rgba(15,23,42,0.1)]"
                    >
                        <input
                            ref="uploadInput"
                            type="file"
                            class="hidden"
                            accept="audio/*,.wav,.mp3,.m4a,.aac,.ogg,.flac,.webm"
                            @change="handleUploadPick"
                        />
                        <Button
                            class="h-12 min-w-40 bg-blue-600 text-white transition hover:scale-[1.01] hover:bg-blue-700"
                            :disabled="!canUseLive && !live.isUnavailable"
                            :aria-pressed="live.isRecording"
                            @click="live.toggle"
                        >
                            <Square
                                v-if="live.isRecording"
                                class="size-4 fill-current"
                            />
                            <Play v-else class="size-4 fill-current" />
                            <span class="grid text-left leading-none">
                                <span
                                    class="text-xs font-semibold uppercase"
                                    :class="
                                        live.isRecording || live.isUnavailable
                                            ? 'text-rose-300'
                                            : 'text-blue-200'
                                    "
                                >
                                    {{ live.buttonTop }}
                                </span>
                                <span
                                    class="mt-1 text-sm font-semibold"
                                    :class="
                                        live.isRecording ||
                                        live.isUnavailable ||
                                        live.isRequesting
                                            ? 'text-rose-50'
                                            : 'text-white'
                                    "
                                >
                                    {{ live.buttonBottom }}
                                </span>
                            </span>
                        </Button>
                        <Button
                            :disabled="
                                !canUseUpload ||
                                upload.inFlight ||
                                upload.isPreparing
                            "
                            variant="outline"
                            @click="chooseUpload"
                        >
                            <FileAudio class="size-4" />
                            Upload Audio
                        </Button>
                        <Dialog v-model:open="polishOpen">
                            <DialogTrigger as-child>
                                <Button
                                    :disabled="
                                        !primaryTranscript ||
                                        isActing ||
                                        isPolishing
                                    "
                                    variant="ghost"
                                >
                                    <Sparkles class="size-4" />
                                    {{ isPolishing ? 'Polishing' : 'Polish' }}
                                </Button>
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
                                    <DialogTitle>Instructions</DialogTitle>
                                </DialogHeader>
                                <div class="grid gap-4">
                                    <div class="grid gap-2">
                                        <Label>Preset</Label>
                                        <div class="grid gap-2 sm:grid-cols-2">
                                            <Button
                                                v-for="preset in polishPresets"
                                                :key="preset.key"
                                                type="button"
                                                variant="outline"
                                                :class="
                                                    polishPreset === preset.key
                                                        ? 'border-blue-300 bg-blue-50 text-blue-700'
                                                        : ''
                                                "
                                                @click="
                                                    selectPolishPreset(preset)
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
                                            v-model="polishCustomInstruction"
                                            maxlength="2000"
                                            class="min-h-32 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                                            @input="editCustomPolishInstruction"
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
                                            Polishing again replaces the current
                                            polished transcript.
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
                                        :disabled="isActing || isPolishing"
                                        @click="polishTranscript"
                                    >
                                        {{
                                            isPolishing ? 'Polishing' : 'Polish'
                                        }}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                        <Dialog v-model:open="summaryOpen">
                            <DialogTrigger as-child>
                                <Button
                                    :disabled="
                                        !primaryTranscript ||
                                        isActing ||
                                        isSummarizing
                                    "
                                    variant="ghost"
                                >
                                    <FileText class="size-4" />
                                    Summarize
                                </Button>
                            </DialogTrigger>
                            <DialogContent
                                class="border-slate-200 bg-white shadow-2xl"
                            >
                                <DialogHeader>
                                    <DialogTitle>
                                        {{ summaryStatusLabel }}
                                    </DialogTitle>
                                    <DialogDescription class="text-slate-600">
                                        The summary is being prepared. You may
                                        close this window and return later.
                                    </DialogDescription>
                                </DialogHeader>
                                <div class="grid gap-4">
                                    <div
                                        v-if="isSummarizing"
                                        class="h-1 overflow-hidden rounded-full bg-blue-100"
                                    >
                                        <div
                                            class="h-full w-full animate-pulse bg-blue-600"
                                        />
                                    </div>
                                    <div
                                        v-if="primaryTranscript?.summary_text"
                                        class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm leading-6 text-blue-950"
                                    >
                                        {{ primaryTranscript.summary_text }}
                                    </div>
                                    <p
                                        v-else-if="!isSummarizing"
                                        class="text-sm text-slate-600"
                                    >
                                        No summary has been created for this
                                        project.
                                    </p>
                                    <p
                                        v-if="primaryTranscript?.summary_text"
                                        class="text-sm text-slate-600"
                                    >
                                        Starting again replaces this project's
                                        existing summary.
                                    </p>
                                    <div class="grid gap-2">
                                        <Label>Source</Label>
                                        <div class="grid grid-cols-2 gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                :class="
                                                    summarySource === 'raw'
                                                        ? 'border-blue-300 bg-blue-50 text-blue-700'
                                                        : ''
                                                "
                                                @click="summarySource = 'raw'"
                                            >
                                                Raw transcript
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                :class="
                                                    summarySource === 'cleaned'
                                                        ? 'border-blue-300 bg-blue-50 text-blue-700'
                                                        : ''
                                                "
                                                @click="
                                                    summarySource = 'cleaned'
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
                                            summarizeTranscript(summarySource)
                                        "
                                        :disabled="isActing || isSummarizing"
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
                            <ProcessingButton
                                :disabled="!primaryTranscript || isExporting"
                                :loading="isExporting"
                                variant="ghost"
                                @click="exportOpen = !exportOpen"
                            >
                                <Download class="size-4" />
                                Export
                            </ProcessingButton>
                            <div
                                v-if="exportOpen"
                                class="absolute right-0 bottom-14 z-40 w-56 rounded-lg border border-slate-200 bg-white p-2 shadow-2xl"
                            >
                                <div class="mb-2 grid grid-cols-3 gap-1">
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
                                        @click="exportSource = 'cleaned'"
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
                                        @click="exportSource = 'summary'"
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
                                        @click="exportTranscript('docx')"
                                    >
                                        Microsoft Word
                                    </Button>
                                    <Button
                                        variant="outline"
                                        @click="exportTranscript('xlsx')"
                                    >
                                        Excel
                                    </Button>
                                </div>
                            </div>
                        </div>
                        <Button
                            ref="logTrigger"
                            :disabled="!primaryTranscript"
                            variant="ghost"
                            @click="openLog"
                        >
                            <ListChecks class="size-4" />
                            Log
                        </Button>
                        <Button
                            ref="pendingTrigger"
                            variant="ghost"
                            @click="openPending"
                        >
                            <PanelRight class="size-4" />
                            Pending clips
                        </Button>
                    </div>
                </div>
            </section>

            <div
                class="fixed inset-0 z-50 bg-slate-950/40 transition-opacity"
                :class="
                    pendingOpen
                        ? 'opacity-100'
                        : 'pointer-events-none opacity-0'
                "
                @click="closePending"
            />
            <aside
                ref="pendingPanel"
                class="fixed top-0 right-0 z-50 h-full w-96 border-l border-slate-200 bg-white shadow-2xl transition duration-300"
                :class="pendingOpen ? 'translate-x-0' : 'translate-x-full'"
                aria-label="Pending clips"
                role="dialog"
                aria-modal="true"
                tabindex="-1"
                @keydown.esc="closePending"
            >
                <header
                    class="flex h-[72px] items-center justify-between border-b border-slate-200 px-6"
                >
                    <div>
                        <p
                            class="text-xs font-semibold tracking-wide text-blue-600 uppercase"
                        >
                            Queue
                        </p>
                        <h2 class="text-lg font-semibold text-slate-950">
                            Pending clips
                        </h2>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Close pending clips"
                        @click="closePending"
                    >
                        <X class="size-5" />
                    </Button>
                </header>
                <div class="p-6">
                    <div
                        v-if="
                            pendingTranscripts.length === 0 &&
                            uploadClips.length === 0 &&
                            liveClips.length === 0
                        "
                        class="rounded-lg border border-dashed border-blue-200 bg-blue-50 p-4"
                    >
                        <Clock3 class="size-5 text-blue-600" />
                        <p class="mt-3 text-sm text-blue-900">
                            No recordings yet.
                        </p>
                    </div>
                    <div v-else class="grid gap-3">
                        <article
                            v-for="clip in liveClips"
                            :key="`live-${clip.index}`"
                            class="rounded-lg border border-blue-100 bg-blue-50 p-4"
                        >
                            <p
                                class="text-xs font-semibold text-blue-600 uppercase"
                            >
                                Clip {{ clip.index + 1 }}
                            </p>
                            <p
                                class="mt-1 text-lg font-semibold text-slate-900"
                            >
                                {{ clip.rangeLabel }}
                            </p>
                            <div class="mt-3 flex items-center justify-between">
                                <span
                                    class="rounded-full border px-2.5 py-1 text-[0.68rem] font-semibold uppercase"
                                    :class="
                                        clip.status === 'Waiting'
                                            ? 'border-slate-200 bg-white text-slate-600'
                                            : clip.status === 'Sending'
                                              ? 'border-blue-200 bg-blue-50 text-blue-700'
                                              : clip.status === 'Saved'
                                                ? 'border-green-200 bg-green-50 text-green-800'
                                                : 'border-red-200 bg-red-50 text-red-700'
                                    "
                                >
                                    {{ clip.status }}
                                </span>
                            </div>
                            <div
                                class="mt-3 h-1.5 overflow-hidden rounded-full bg-blue-100"
                            >
                                <div
                                    class="h-full rounded-full bg-blue-600"
                                    :style="{ width: `${clip.progress}%` }"
                                />
                            </div>
                        </article>
                        <article
                            v-for="clip in uploadClips"
                            :key="`upload-${clip.index}`"
                            class="rounded-lg border border-blue-100 bg-blue-50 p-4"
                        >
                            <p
                                class="text-xs font-semibold text-blue-600 uppercase"
                            >
                                Clip {{ clip.index + 1 }}
                            </p>
                            <p
                                class="mt-1 text-lg font-semibold text-slate-900"
                            >
                                {{ clip.rangeLabel }}
                            </p>
                            <div class="mt-3 flex items-center justify-between">
                                <span
                                    class="rounded-full border px-2.5 py-1 text-[0.68rem] font-semibold uppercase"
                                    :class="
                                        clip.status === 'Waiting'
                                            ? 'border-slate-200 bg-white text-slate-600'
                                            : clip.status === 'Sending' ||
                                                clip.status === 'Processing'
                                              ? 'border-blue-200 bg-blue-50 text-blue-700'
                                              : clip.status === 'Complete'
                                                ? 'border-green-200 bg-green-50 text-green-800'
                                                : 'border-red-200 bg-red-50 text-red-700'
                                    "
                                >
                                    {{ clip.status }}
                                </span>
                                <span class="text-xs text-blue-900">
                                    {{ clip.meta }}
                                </span>
                            </div>
                            <div
                                class="mt-3 h-1.5 overflow-hidden rounded-full bg-blue-100"
                            >
                                <div
                                    class="h-full rounded-full bg-blue-600"
                                    :style="{
                                        width:
                                            clip.status === 'Complete'
                                                ? '100%'
                                                : clip.status === 'Waiting'
                                                  ? '0%'
                                                  : '66%',
                                    }"
                                />
                            </div>
                        </article>
                        <article
                            v-for="transcript in pendingTranscripts"
                            :key="transcript.id"
                            class="rounded-lg border border-blue-100 bg-blue-50 p-4"
                        >
                            <p class="text-sm font-semibold text-blue-950">
                                {{ transcript.source }} clip
                            </p>
                            <p class="mt-1 text-sm text-blue-900">
                                {{ transcript.status }}
                            </p>
                        </article>
                    </div>
                </div>
            </aside>

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
    </main>
</template>
