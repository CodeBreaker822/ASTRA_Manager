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
    Plus,
    Settings,
    Sparkles,
    Trash2,
    X,
} from '@lucide/vue';
import {
    computed,
    onMounted,
    onUnmounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
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
const createOpen = ref(false);
const renameOpen = ref(false);
const onboardingOpen = ref(false);
const pendingOpen = ref(false);
const polishOpen = ref(false);
const summaryOpen = ref(false);
const logOpen = ref(false);
const uploadInput = useTemplateRef<HTMLInputElement>('uploadInput');
const localProject = ref<ActiveProject | null>(props.activeProject);
const isUploading = ref(false);
const isRecording = ref(false);
const isActing = ref(false);
const actionMessage = ref('');
const actionError = ref('');
const mediaRecorder = ref<MediaRecorder | null>(null);
const recordingStartedAt = ref<number | null>(null);
let pollTimer: number | null = null;

const userName = computed(() => {
    const user = page.props.auth?.user;

    return user?.name ?? 'JERVA user';
});

const projectTitle = computed(
    () => displayProject.value?.title ?? 'New transcript',
);

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

const pendingTranscripts = computed(
    () =>
        displayProject.value?.transcripts.filter((transcript) =>
            ['queued', 'processing'].includes(transcript.status),
        ) ?? [],
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
    () => pendingTranscripts.value.length,
    (count) => {
        if (count > 0) {
            startPolling();
        }
    },
);

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
    });
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

const startPolling = () => {
    if (pollTimer !== null) {
        return;
    }

    pollTimer = window.setInterval(() => {
        void refreshStatus();
    }, 5000);
};

const stopPolling = () => {
    if (pollTimer === null) {
        return;
    }

    window.clearInterval(pollTimer);
    pollTimer = null;
};

const uploadAudio = async (
    file: File,
    endpoint: string,
    extra: Record<string, string> = {},
) => {
    if (!displayProject.value) {
        return;
    }

    actionError.value = '';
    actionMessage.value = '';
    isUploading.value = true;

    const form = new FormData();
    form.append('audio', file);

    for (const [key, value] of Object.entries(extra)) {
        form.append(key, value);
    }

    const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: form,
    });
    const payload = (await response.json()) as {
        message?: string;
        transcript?: Transcript;
    };

    isUploading.value = false;

    if (!response.ok) {
        actionError.value = payload.message ?? 'Transcription request failed.';

        return;
    }

    actionMessage.value = payload.message ?? 'Transcription queued.';

    if (payload.transcript) {
        localProject.value = {
            ...(displayProject.value as ActiveProject),
            transcripts: [
                payload.transcript,
                ...(displayProject.value?.transcripts ?? []),
            ],
            transcripts_count:
                (displayProject.value?.transcripts.length ?? 0) + 1,
        };
    }

    startPolling();
};

const chooseUpload = () => {
    uploadInput.value?.click();
};

const handleUploadPick = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file || !displayProject.value) {
        return;
    }

    await uploadAudio(file, `/workspace/${displayProject.value.id}/upload`);
    input.value = '';
};

const toggleLive = async () => {
    if (!displayProject.value) {
        return;
    }

    if (isRecording.value) {
        mediaRecorder.value?.stop();
        isRecording.value = false;

        return;
    }

    actionError.value = '';
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    const recorder = new MediaRecorder(stream, {
        mimeType: MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
            ? 'audio/webm;codecs=opus'
            : 'audio/webm',
    });

    recorder.ondataavailable = (event) => {
        if (!displayProject.value || event.data.size === 0) {
            return;
        }

        const durationSeconds = recordingStartedAt.value
            ? Math.max(
                  1,
                  Math.round((Date.now() - recordingStartedAt.value) / 1000),
              )
            : 0;
        recordingStartedAt.value = Date.now();

        void uploadAudio(
            new File([event.data], `live-${Date.now()}.webm`, {
                type: event.data.type,
            }),
            `/workspace/${displayProject.value.id}/chunk`,
            { duration_seconds: String(durationSeconds) },
        );
    };
    recorder.onstop = () => {
        stream.getTracks().forEach((track) => track.stop());
        recordingStartedAt.value = null;
    };

    mediaRecorder.value = recorder;
    recordingStartedAt.value = Date.now();
    recorder.start(15000);
    isRecording.value = true;
};

const polishTranscript = async (preset: string, instruction = '') => {
    if (!displayProject.value || !primaryTranscript.value) {
        return;
    }

    isActing.value = true;
    actionError.value = '';
    const response = await fetch(
        `/workspace/${displayProject.value.id}/transcripts/${primaryTranscript.value.id}/polish`,
        {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ preset, instruction }),
        },
    );
    const payload = (await response.json()) as { message?: string };
    isActing.value = false;

    if (!response.ok) {
        actionError.value = payload.message ?? 'Polish failed.';

        return;
    }

    actionMessage.value = payload.message ?? 'Transcript polished.';
    polishOpen.value = false;
    await refreshStatus();
};

const summarizeTranscript = async (source: 'raw' | 'cleaned') => {
    if (!displayProject.value || !primaryTranscript.value) {
        return;
    }

    isActing.value = true;
    actionError.value = '';
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
    const payload = (await response.json()) as { message?: string };
    isActing.value = false;

    if (!response.ok) {
        actionError.value = payload.message ?? 'Summarize failed.';

        return;
    }

    actionMessage.value = payload.message ?? 'Transcript summarized.';
    summaryOpen.value = false;
    await refreshStatus();
};

const exportTranscript = (format: 'txt' | 'docx' | 'xlsx', source = 'raw') => {
    if (!displayProject.value || !primaryTranscript.value) {
        return;
    }

    window.location.href = `/workspace/${displayProject.value.id}/transcripts/${primaryTranscript.value.id}/export?format=${format}&source=${source}`;
};

const dismissOnboarding = () => {
    onboardingOpen.value = false;
    localStorage.setItem('jerva.workspace.onboarded', 'true');
};

onMounted(() => {
    onboardingOpen.value =
        localStorage.getItem('jerva.workspace.onboarded') !== 'true';

    if (pendingTranscripts.value.length > 0) {
        startPolling();
    }
});

onUnmounted(() => {
    stopPolling();
    mediaRecorder.value?.stop();
});
</script>

<template>
    <Head title="Workspace" />

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
                                    <Button
                                        type="submit"
                                        :disabled="createForm.processing"
                                    >
                                        Create project
                                    </Button>
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
                                        <Button
                                            type="submit"
                                            :disabled="renameForm.processing"
                                        >
                                            Save changes
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                        <Button
                            v-if="displayProject"
                            variant="outline"
                            size="icon"
                            aria-label="Delete transcript"
                            @click="deleteProject"
                        >
                            <Trash2 class="size-4 text-red-700" />
                        </Button>
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
                            v-if="actionMessage"
                            class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700"
                        >
                            {{ actionMessage }}
                        </div>
                        <div
                            v-if="actionError"
                            class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
                        >
                            {{ actionError }}
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
                                    <p
                                        class="text-sm font-semibold text-blue-950"
                                    >
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
                                JERVA Web
                            </p>
                            <h3
                                class="mt-4 text-3xl font-semibold text-slate-950"
                            >
                                Hi, what are we transcribing today?
                            </h3>
                            <p
                                class="mt-4 max-w-xl text-sm leading-6 text-blue-950"
                            >
                                Create a transcript project, then use live or
                                upload transcription from the command dock.
                            </p>
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
                            :disabled="!canUseLive || isUploading"
                            @click="toggleLive"
                        >
                            <Mic class="size-4" />
                            {{ isRecording ? 'Stop Live' : 'Live' }}
                        </Button>
                        <Button
                            :disabled="!canUseUpload || isUploading"
                            variant="outline"
                            @click="chooseUpload"
                        >
                            <FileAudio class="size-4" />
                            {{ isUploading ? 'Uploading' : 'Upload Audio' }}
                        </Button>
                        <Dialog v-model:open="polishOpen">
                            <DialogTrigger as-child>
                                <Button
                                    :disabled="!primaryTranscript || isActing"
                                    variant="ghost"
                                >
                                    <Sparkles class="size-4" />
                                    Polish
                                </Button>
                            </DialogTrigger>
                            <DialogContent
                                class="border-slate-200 bg-white shadow-2xl"
                            >
                                <DialogHeader>
                                    <DialogTitle>Polish Transcript</DialogTitle>
                                    <DialogDescription class="text-slate-600">
                                        Choose a preset instruction.
                                    </DialogDescription>
                                </DialogHeader>
                                <div class="grid gap-2">
                                    <Button
                                        variant="outline"
                                        @click="polishTranscript('english')"
                                    >
                                        Translate to English
                                    </Button>
                                    <Button
                                        variant="outline"
                                        @click="polishTranscript('filipino')"
                                    >
                                        Translate to Filipino
                                    </Button>
                                    <Button
                                        variant="outline"
                                        @click="polishTranscript('grammar')"
                                    >
                                        Fix grammar
                                    </Button>
                                    <Button
                                        variant="outline"
                                        @click="
                                            polishTranscript('translate_fix')
                                        "
                                    >
                                        Translate + fix
                                    </Button>
                                </div>
                            </DialogContent>
                        </Dialog>
                        <Dialog v-model:open="summaryOpen">
                            <DialogTrigger as-child>
                                <Button
                                    :disabled="!primaryTranscript || isActing"
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
                                    <DialogTitle
                                        >Summarize Transcript</DialogTitle
                                    >
                                    <DialogDescription class="text-slate-600">
                                        Select which transcript source to
                                        summarize.
                                    </DialogDescription>
                                </DialogHeader>
                                <div class="grid gap-2">
                                    <Button
                                        variant="outline"
                                        @click="summarizeTranscript('raw')"
                                    >
                                        Raw source
                                    </Button>
                                    <Button
                                        variant="outline"
                                        @click="summarizeTranscript('cleaned')"
                                    >
                                        Cleaned source
                                    </Button>
                                </div>
                            </DialogContent>
                        </Dialog>
                        <Dialog>
                            <DialogTrigger as-child>
                                <Button
                                    :disabled="!primaryTranscript"
                                    variant="ghost"
                                >
                                    <Download class="size-4" />
                                    Export
                                </Button>
                            </DialogTrigger>
                            <DialogContent
                                class="border-slate-200 bg-white shadow-2xl"
                            >
                                <DialogHeader>
                                    <DialogTitle>Export Transcript</DialogTitle>
                                    <DialogDescription class="text-slate-600">
                                        Download the active transcript.
                                    </DialogDescription>
                                </DialogHeader>
                                <div class="grid gap-2">
                                    <Button
                                        variant="outline"
                                        @click="exportTranscript('txt')"
                                    >
                                        TXT
                                    </Button>
                                    <Button
                                        variant="outline"
                                        @click="
                                            exportTranscript('docx', 'cleaned')
                                        "
                                    >
                                        Word
                                    </Button>
                                    <Button
                                        variant="outline"
                                        @click="
                                            exportTranscript('xlsx', 'cleaned')
                                        "
                                    >
                                        Excel
                                    </Button>
                                </div>
                            </DialogContent>
                        </Dialog>
                        <Button
                            :disabled="!primaryTranscript"
                            variant="ghost"
                            @click="logOpen = true"
                        >
                            <ListChecks class="size-4" />
                            Log
                        </Button>
                        <Button variant="ghost" @click="pendingOpen = true">
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
                @click="pendingOpen = false"
            />
            <aside
                class="fixed top-0 right-0 z-50 h-full w-96 border-l border-slate-200 bg-white shadow-2xl transition duration-300"
                :class="pendingOpen ? 'translate-x-0' : 'translate-x-full'"
                aria-label="Pending clips"
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
                        @click="pendingOpen = false"
                    >
                        <X class="size-5" />
                    </Button>
                </header>
                <div class="p-6">
                    <div
                        v-if="pendingTranscripts.length === 0"
                        class="rounded-lg border border-blue-100 bg-blue-50 p-4"
                    >
                        <Clock3 class="size-5 text-blue-600" />
                        <p class="mt-3 text-sm font-semibold text-blue-950">
                            No pending clips
                        </p>
                        <p class="mt-2 text-sm leading-6 text-blue-900">
                            Live and upload processing queues are clear.
                        </p>
                    </div>
                    <div v-else class="grid gap-3">
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
                @click="logOpen = false"
            />
            <aside
                class="fixed top-0 right-0 z-50 h-full w-96 border-l border-slate-200 bg-white shadow-2xl transition duration-300"
                :class="logOpen ? 'translate-x-0' : 'translate-x-full'"
                aria-label="Processing log"
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
                        @click="logOpen = false"
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
                        No log entries yet.
                    </div>
                </div>
            </aside>
        </div>
    </main>
</template>
