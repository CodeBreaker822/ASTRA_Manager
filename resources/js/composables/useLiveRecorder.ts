import { computed, onUnmounted, ref } from 'vue';

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
    sections: Array<{
        id: number;
        position: number;
        text: string;
        cleaned_text: string | null;
        started_at_ms: number | null;
        ended_at_ms: number | null;
    }>;
};

type LiveClip = {
    index: number;
    rangeLabel: string;
    status: 'Waiting' | 'Sending' | 'Saved' | 'Error';
    progress: number;
};

type ChunkResponse = {
    message?: string;
    transcript?: Transcript;
    upgrade?: boolean;
};

const SEGMENT_MS = 15_000;

export const useLiveRecorder = (options: {
    csrfToken: () => string;
    projectId: () => number | null;
    canUseLive: () => boolean;
    onTranscript: (transcript: Transcript) => void;
    onQueued: () => void;
    onUpgrade: (message: string) => void;
    onToastError: (message: string) => void;
}) => {
    const recorder = ref<MediaRecorder | null>(null);
    const stream = ref<MediaStream | null>(null);
    const startedAt = ref<number | null>(null);
    const segmentStartedAt = ref<number | null>(null);
    const elapsedMs = ref(0);
    const supportLine = ref<
        | 'Ready'
        | 'Live'
        | 'Sending'
        | 'Saved'
        | 'Save failed'
        | 'Requesting mic'
        | 'Microphone blocked'
        | 'Start failed'
        | 'Processing'
    >('Ready');
    const requestState = ref<
        'idle' | 'requesting' | 'recording' | 'blocked' | 'unsupported'
    >('idle');
    const clips = ref<LiveClip[]>([]);
    const timer = ref<number | null>(null);
    const pendingSends = ref(0);

    const isRecording = computed(() => requestState.value === 'recording');
    const isRequesting = computed(() => requestState.value === 'requesting');
    const isUnavailable = computed(
        () =>
            requestState.value === 'blocked' ||
            requestState.value === 'unsupported',
    );
    const hasUnsavedChunks = computed(
        () =>
            pendingSends.value > 0 ||
            clips.value.some((clip) => clip.status === 'Sending'),
    );
    const isPanelVisible = computed(
        () => isRecording.value || hasUnsavedChunks.value,
    );
    const activeName = computed(() => {
        if (hasUnsavedChunks.value && !isRecording.value) {
            return 'Processing';
        }

        return isRecording.value ? 'Recording' : 'Ready';
    });
    const elapsedLabel = computed(() => formatDuration(elapsedMs.value, true));
    const currentRangeLabel = computed(() => {
        const startMs = Math.max(
            0,
            elapsedMs.value - (elapsedMs.value % SEGMENT_MS),
        );
        const endMs = Math.min(elapsedMs.value, startMs + SEGMENT_MS);

        return `${formatDuration(startMs)}-${formatDuration(endMs)}`;
    });
    const segmentProgress = computed(() =>
        Math.min(
            100,
            Math.round(((elapsedMs.value % SEGMENT_MS) / SEGMENT_MS) * 100),
        ),
    );
    const buttonTop = computed(() => {
        if (isUnavailable.value) {
            return 'Unavailable';
        }

        return isRecording.value ? 'Recording' : 'Listening';
    });
    const buttonBottom = computed(() => {
        if (isUnavailable.value) {
            return 'Click for details';
        }

        if (isRequesting.value) {
            return 'Requesting microphone';
        }

        return isRecording.value ? 'Stop recording' : 'Ready to capture';
    });

    const toggle = async () => {
        if (isRecording.value) {
            stop();
            return;
        }

        if (isUnavailable.value) {
            options.onToastError(
                requestState.value === 'blocked'
                    ? 'Microphone access is blocked. Please allow it to record audio.'
                    : 'Live recording could not start. Please try again.',
            );
            return;
        }

        await start();
    };

    const start = async () => {
        if (!options.projectId() || !options.canUseLive()) {
            return;
        }

        if (
            !navigator.mediaDevices?.getUserMedia ||
            typeof MediaRecorder === 'undefined'
        ) {
            requestState.value = 'unsupported';
            supportLine.value = 'Start failed';
            options.onToastError(
                'Live recording could not start. Please try again.',
            );
            return;
        }

        requestState.value = 'requesting';
        supportLine.value = 'Requesting mic';

        try {
            stream.value = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });
        } catch {
            requestState.value = 'blocked';
            supportLine.value = 'Microphone blocked';
            options.onToastError(
                'Microphone access is blocked. Please allow it to record audio.',
            );
            return;
        }

        try {
            const mediaRecorder = new MediaRecorder(stream.value, {
                mimeType: MediaRecorder.isTypeSupported(
                    'audio/webm;codecs=opus',
                )
                    ? 'audio/webm;codecs=opus'
                    : 'audio/webm',
            });

            mediaRecorder.ondataavailable = (event) => {
                if (event.data.size === 0 || !segmentStartedAt.value) {
                    return;
                }

                const started = segmentStartedAt.value;
                const ended = Date.now();
                segmentStartedAt.value = ended;
                void sendChunk(event.data, started, ended);
            };
            mediaRecorder.onstop = () => {
                stopTimer();
                stopStream();
                recorder.value = null;
                startedAt.value = null;
                segmentStartedAt.value = null;
                elapsedMs.value = 0;
                requestState.value =
                    requestState.value === 'blocked' ? 'blocked' : 'idle';
                supportLine.value =
                    pendingSends.value > 0 ? 'Processing' : 'Ready';
            };

            recorder.value = mediaRecorder;
            startedAt.value = Date.now();
            segmentStartedAt.value = startedAt.value;
            elapsedMs.value = 0;
            requestState.value = 'recording';
            supportLine.value = 'Live';
            mediaRecorder.start(SEGMENT_MS);
            startTimer();
        } catch {
            requestState.value = 'unsupported';
            supportLine.value = 'Start failed';
            stopStream();
            options.onToastError(
                'Live recording could not start. Please try again.',
            );
        }
    };

    const stop = () => {
        if (recorder.value && recorder.value.state !== 'inactive') {
            recorder.value.stop();
        }
    };

    const sendChunk = async (
        blob: Blob,
        startTime: number,
        endTime: number,
    ) => {
        const projectId = options.projectId();

        if (!projectId || !startedAt.value) {
            return;
        }

        const index = clips.value.length;
        const startMs = Math.max(0, startTime - startedAt.value);
        const endMs = Math.max(startMs + 1, endTime - startedAt.value);
        const clip: LiveClip = {
            index,
            rangeLabel: `${formatDuration(startMs)}-${formatDuration(endMs)}`,
            status: 'Sending',
            progress: 66,
        };
        clips.value.push(clip);
        pendingSends.value += 1;
        supportLine.value = 'Sending';

        const form = new FormData();
        form.append(
            'audio',
            new File([blob], `live-${index + 1}.webm`, {
                type: blob.type || 'audio/webm',
            }),
        );
        form.append(
            'duration_seconds',
            String(Math.max(1, Math.ceil((endMs - startMs) / 1000))),
        );
        form.append('clip_index', String(index));
        form.append('clip_start_ms', String(Math.round(startMs)));
        form.append('clip_end_ms', String(Math.round(endMs)));

        try {
            const response = await fetch(`/workspace/${projectId}/chunk`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': options.csrfToken(),
                },
                body: form,
            });
            const payload = (await response.json()) as ChunkResponse;

            if (!response.ok) {
                if (payload.upgrade) {
                    options.onUpgrade(
                        payload.message ??
                            'Audio upload could not be processed.',
                    );
                }

                throw new Error(
                    payload.message ?? 'Audio upload could not be processed.',
                );
            }

            clip.status = 'Saved';
            clip.progress = 100;
            supportLine.value = 'Saved';

            if (payload.transcript) {
                options.onTranscript(payload.transcript);
            }

            options.onQueued();
        } catch {
            clip.status = 'Error';
            clip.progress = 100;
            supportLine.value = 'Save failed';
            stop();
            options.onToastError(
                `Clip ${index + 1} could not be saved. Please try again.`,
            );
        } finally {
            pendingSends.value = Math.max(0, pendingSends.value - 1);

            if (pendingSends.value > 0) {
                supportLine.value = 'Processing';
            }
        }
    };

    const startTimer = () => {
        stopTimer();
        timer.value = window.setInterval(() => {
            elapsedMs.value = startedAt.value
                ? Date.now() - startedAt.value
                : 0;
        }, 100);
    };

    const stopTimer = () => {
        if (timer.value !== null) {
            window.clearInterval(timer.value);
            timer.value = null;
        }
    };

    const stopStream = () => {
        stream.value?.getTracks().forEach((track) => track.stop());
        stream.value = null;
    };

    onUnmounted(() => {
        stop();
        stopTimer();
        stopStream();
    });

    return {
        activeName,
        buttonBottom,
        buttonTop,
        clips,
        currentRangeLabel,
        elapsedLabel,
        hasUnsavedChunks,
        isPanelVisible,
        isRecording,
        isRequesting,
        isUnavailable,
        segmentProgress,
        supportLine,
        toggle,
    };
};

const formatDuration = (ms: number, forceHours = false) => {
    const totalSeconds = Math.max(0, Math.floor(ms / 1000));
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    if (hours > 0 || forceHours) {
        return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    }

    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
};
