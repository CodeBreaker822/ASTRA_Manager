import { computed, ref } from 'vue';

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

type UploadClip = {
    index: number;
    startMs: number;
    endMs: number;
    durationMs: number;
    rangeLabel: string;
    status:
        | 'Waiting'
        | 'Queued'
        | 'Sending'
        | 'Processing'
        | 'Complete'
        | 'Failed'
        | 'Cancelled';
    meta: string;
};

type UploadResponse = {
    message?: string;
    transcript?: Transcript;
    upgrade?: boolean;
};

const MAX_BATCH_CLIPS = 20;

export const useAudioUpload = (options: {
    csrfToken: () => string;
    projectId: () => number | null;
    onTranscript: (transcript: Transcript) => void;
    onQueued: () => void;
    onUpgrade: (message: string) => void;
    onSuccess: (message: string) => void;
    onError: (message: string) => void;
}) => {
    const fileName = ref('Select an audio file');
    const metaLine = ref('');
    const durationLabel = ref('--:--');
    const status = ref<
        | 'Ready'
        | 'Preparing source'
        | 'Uploading source'
        | 'Queued'
        | 'Processing'
        | 'Pausing'
        | 'Paused'
        | 'Cancelling'
        | 'Cancelled'
        | 'Complete'
        | 'Failed'
        | 'Ready to continue'
    >('Ready');
    const uploadPercent = ref(0);
    const clips = ref<UploadClip[]>([]);
    const selectedFile = ref<File | null>(null);
    const selectedDurationMs = ref(0);
    const currentXhr = ref<XMLHttpRequest | null>(null);
    const isPreparing = ref(false);
    const inFlight = ref(false);
    const pauseRequested = ref(false);
    const hasSession = ref(false);
    const retryable = ref(false);
    const completionNotified = ref(false);
    const queuedTranscriptIds = ref<number[]>([]);

    const hasProgress = computed(
        () =>
            hasSession.value ||
            clips.value.some((clip) => clip.status !== 'Waiting'),
    );
    const completedCount = computed(
        () => clips.value.filter((clip) => clip.status === 'Complete').length,
    );
    const progressPercent = computed(() => {
        if (clips.value.length === 0) {
            return 0;
        }

        if (status.value === 'Uploading source') {
            return uploadPercent.value;
        }

        if (status.value === 'Queued') {
            return 0;
        }

        if (status.value === 'Processing') {
            return Math.round(
                (completedCount.value / clips.value.length) * 100,
            );
        }

        return Math.round((completedCount.value / clips.value.length) * 100);
    });
    const statusLine = computed(() =>
        status.value === 'Uploading source'
            ? `Uploading source ${uploadPercent.value}%`
            : status.value,
    );
    const canStart = computed(
        () =>
            Boolean(selectedFile.value) &&
            clips.value.length > 0 &&
            !inFlight.value &&
            !hasSession.value,
    );
    const canPause = computed(() => inFlight.value && !pauseRequested.value);
    const canContinue = computed(
        () =>
            hasSession.value &&
            !inFlight.value &&
            status.value === 'Paused' &&
            unfinishedClips().length > 0 &&
            !retryable.value,
    );
    const canRetry = computed(
        () => hasSession.value && !inFlight.value && retryable.value,
    );
    const canCancel = computed(() => inFlight.value || hasProgress.value);
    const isActive = computed(
        () => isPreparing.value || inFlight.value || hasProgress.value,
    );
    const hasFile = computed(() => Boolean(selectedFile.value));

    const selectFile = async (file: File) => {
        resetSession();
        selectedFile.value = file;
        fileName.value = file.name;
        metaLine.value = `${formatBytes(file.size)} selected`;
        status.value = 'Ready';

        prepareClips();
    };

    const start = async () => {
        if (!selectedFile.value || clips.value.length === 0) {
            return;
        }

        retryable.value = false;
        hasSession.value = true;
        await sendUnfinished();
    };

    const pause = () => {
        if (!inFlight.value) {
            return;
        }

        pauseRequested.value = true;
        status.value = 'Pausing';
        currentXhr.value?.abort();
    };

    const resume = async () => {
        pauseRequested.value = false;
        status.value = 'Ready to continue';
        retryable.value = false;
        clips.value.forEach((clip) => {
            if (clip.status === 'Cancelled') {
                clip.status = 'Waiting';
                clip.meta = 'Ready to continue';
            }
        });
        await sendUnfinished();
    };

    const retry = async () => {
        retryable.value = false;
        clips.value.forEach((clip) => {
            if (['Failed', 'Cancelled'].includes(clip.status)) {
                clip.status = 'Waiting';
                clip.meta = 'Ready to retry';
            }
        });
        await sendUnfinished();
    };

    const cancel = () => {
        status.value = 'Cancelling';
        pauseRequested.value = false;
        currentXhr.value?.abort();
        void cancelQueuedTranscripts();
        clips.value.forEach((clip) => {
            if (clip.status !== 'Complete') {
                clip.status = 'Cancelled';
                clip.meta = 'Ready to continue';
            }
        });
        window.setTimeout(() => {
            inFlight.value = false;
            retryable.value = true;
            status.value = 'Cancelled';
        }, 350);
    };

    const sendUnfinished = async () => {
        const projectId = options.projectId();

        if (!projectId) {
            return;
        }

        inFlight.value = true;

        for (
            let index = 0;
            index < clips.value.length;
            index += MAX_BATCH_CLIPS
        ) {
            if (pauseRequested.value) {
                status.value = 'Paused';
                inFlight.value = false;

                return;
            }

            const batch = clips.value
                .slice(index, index + MAX_BATCH_CLIPS)
                .filter((clip) => clip.status !== 'Complete');

            if (batch.length === 0) {
                continue;
            }

            batch.forEach((clip) => {
                clip.status = 'Sending';
                clip.meta = `${formatBytes(selectedFile.value?.size ?? 0)} source`;
            });

            try {
                const payload = await postBatch(projectId);

                if (payload.upgrade) {
                    options.onUpgrade(
                        payload.message ??
                            'Audio upload could not be processed.',
                    );
                    markFailed(batch);

                    return;
                }

                batch.forEach((clip) => {
                    clip.status = 'Queued';
                    clip.meta = 'Queued for server processing';
                });
                status.value = 'Queued';
                metaLine.value = 'Queued for server processing';

                if (payload.transcript) {
                    queuedTranscriptIds.value.push(payload.transcript.id);
                    options.onTranscript(payload.transcript);
                }

                options.onQueued();
            } catch (error) {
                if (pauseRequested.value) {
                    batch.forEach((clip) => {
                        if (clip.status !== 'Complete') {
                            clip.status = 'Cancelled';
                            clip.meta = 'Ready to continue';
                        }
                    });
                    status.value = 'Paused';
                    inFlight.value = false;
                    retryable.value = false;

                    return;
                }

                markFailed(batch);
                options.onError(
                    error instanceof Error
                        ? error.message
                        : 'Audio upload could not be processed.',
                );

                return;
            }
        }

        inFlight.value = false;
        status.value = 'Queued';
    };

    const finish = () => {
        if (!hasProgress.value || inFlight.value || clips.value.length === 0) {
            return;
        }

        if (completionNotified.value) {
            return;
        }

        clips.value.forEach((clip) => {
            if (!['Failed', 'Cancelled'].includes(clip.status)) {
                clip.status = 'Complete';
            }
        });
        status.value = 'Complete';
        uploadPercent.value = 100;
        completionNotified.value = true;
        options.onSuccess('Audio transcription completed.');
    };

    const syncTranscripts = (transcripts: Transcript[]) => {
        if (queuedTranscriptIds.value.length === 0) {
            return;
        }

        const tracked = transcripts.filter((transcript) =>
            queuedTranscriptIds.value.includes(transcript.id),
        );

        if (tracked.length === 0) {
            return;
        }

        if (tracked.some((transcript) => transcript.status === 'failed')) {
            markFailed(clips.value);

            return;
        }

        if (tracked.every((transcript) => transcript.status === 'completed')) {
            finish();

            return;
        }

        if (tracked.every((transcript) => transcript.status === 'queued')) {
            status.value = 'Queued';
            metaLine.value = `Queued ${clips.value.length} ${clips.value.length === 1 ? 'clip' : 'clips'}`;
            clips.value.forEach((clip) => {
                if (
                    !['Failed', 'Cancelled', 'Complete'].includes(clip.status)
                ) {
                    clip.status = 'Queued';
                    clip.meta = 'Queued for server processing';
                }
            });

            return;
        }

        if (tracked.some((transcript) => transcript.status === 'processing')) {
            status.value = 'Processing';
            metaLine.value = `Processing ${clips.value.length} ${clips.value.length === 1 ? 'clip' : 'clips'}`;
            clips.value.forEach((clip) => {
                if (
                    !['Failed', 'Cancelled', 'Complete'].includes(clip.status)
                ) {
                    clip.status = 'Processing';
                    clip.meta = 'Server processing';
                }
            });
        }
    };

    const postBatch = (projectId: number) =>
        new Promise<UploadResponse>((resolve, reject) => {
            const form = new FormData();
            const file = selectedFile.value;

            if (!file) {
                reject(new Error('Select an audio file first.'));

                return;
            }

            form.append('audio', file);
            form.append('server_chunk', '1');

            if (selectedDurationMs.value > 0) {
                form.append(
                    'duration_seconds',
                    String(Math.ceil(selectedDurationMs.value / 1000)),
                );
            }

            const xhr = new XMLHttpRequest();
            currentXhr.value = xhr;
            xhr.open('POST', `/workspace/${projectId}/upload`);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', options.csrfToken());

            xhr.upload.onprogress = (event) => {
                if (!event.lengthComputable) {
                    return;
                }

                uploadPercent.value = Math.min(
                    99,
                    Math.round((event.loaded / event.total) * 100),
                );
                status.value = 'Uploading source';
            };
            xhr.onload = () => {
                currentXhr.value = null;
                const payload = parseJson(xhr.responseText);

                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(payload);

                    return;
                }

                if (payload.upgrade) {
                    resolve(payload);

                    return;
                }

                reject(new Error('Audio upload could not be processed.'));
            };
            xhr.onerror = () =>
                reject(new Error('Audio upload could not be processed.'));
            xhr.onabort = () =>
                reject(new Error('Audio upload could not be processed.'));
            xhr.send(form);
        });

    const cancelQueuedTranscripts = async () => {
        const projectId = options.projectId();

        if (!projectId || queuedTranscriptIds.value.length === 0) {
            return;
        }

        await Promise.allSettled(
            queuedTranscriptIds.value.map((transcriptId) =>
                fetch(
                    `/workspace/${projectId}/transcripts/${transcriptId}/cancel`,
                    {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': options.csrfToken(),
                        },
                    },
                ),
            ),
        );
        options.onQueued();
    };

    const prepareClips = () => {
        isPreparing.value = true;
        status.value = 'Preparing source';

        try {
            selectedDurationMs.value = 0;
            clips.value = [
                {
                    index: 0,
                    startMs: 0,
                    endMs: 0,
                    durationMs: 0,
                    rangeLabel: 'Server chunking',
                    status: 'Waiting',
                    meta: 'Waiting for source upload',
                },
            ];
            durationLabel.value = 'Server measured';
            status.value = 'Ready';
        } finally {
            isPreparing.value = false;
        }
    };

    const markFailed = (batch: UploadClip[]) => {
        batch.forEach((clip) => {
            if (clip.status !== 'Complete') {
                clip.status = 'Failed';
                clip.meta = 'Ready to retry';
            }
        });
        status.value = 'Failed';
        metaLine.value = 'Audio upload could not be processed.';
        inFlight.value = false;
        retryable.value = true;
    };

    const resetSession = () => {
        currentXhr.value?.abort();
        fileName.value = 'Select an audio file';
        metaLine.value = '';
        durationLabel.value = '--:--';
        status.value = 'Ready';
        uploadPercent.value = 0;
        clips.value = [];
        selectedFile.value = null;
        selectedDurationMs.value = 0;
        currentXhr.value = null;
        isPreparing.value = false;
        inFlight.value = false;
        pauseRequested.value = false;
        hasSession.value = false;
        retryable.value = false;
        completionNotified.value = false;
        queuedTranscriptIds.value = [];
    };

    const unfinishedClips = () =>
        clips.value.filter((clip) => clip.status !== 'Complete');

    return {
        fileName,
        metaLine,
        durationLabel,
        statusLine,
        progressPercent,
        clips,
        isPreparing,
        inFlight,
        isActive,
        hasFile,
        canStart,
        canPause,
        canContinue,
        canRetry,
        canCancel,
        selectFile,
        start,
        pause,
        resume,
        retry,
        cancel,
        finish,
        syncTranscripts,
    };
};

const parseJson = (value: string): UploadResponse => {
    try {
        const parsed = JSON.parse(value) as UploadResponse;

        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
        return {};
    }
};

const formatBytes = (bytes: number) => {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};
