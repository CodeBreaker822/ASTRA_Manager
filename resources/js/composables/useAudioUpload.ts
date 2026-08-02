import { computed, ref } from 'vue';
import type { Transcript } from '@/types/workspace';

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

type UploadRequestError = Error & {
    status?: number;
    serverMessage?: boolean;
};

type UploadSession = {
    id: string;
    totalChunks: number;
    nextChunkIndex: number;
    completeAttempts: number;
    consumed: boolean;
};

const MAX_UPLOAD_BYTES = 500 * 1024 * 1024;
const UPLOAD_CHUNK_BYTES = 20 * 1024 * 1024;
const SERVER_AUDIO_CHUNK_MS = 60 * 1000;

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
    const uploadSession = ref<UploadSession | null>(null);
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

        if (file.size > MAX_UPLOAD_BYTES) {
            options.onError('Audio upload must not exceed 500 MB.');

            return;
        }

        selectedFile.value = file;
        fileName.value = file.name;
        metaLine.value = `${formatBytes(file.size)} selected`;
        status.value = 'Ready';

        prepareClips();
    };

    const start = async () => {
        if (
            !selectedFile.value ||
            clips.value.length === 0 ||
            inFlight.value ||
            hasSession.value
        ) {
            return;
        }

        retryable.value = false;
        hasSession.value = true;
        await sendSource();
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
        await sendSource();
    };

    const retry = async () => {
        if (inFlight.value) {
            return;
        }

        retryable.value = false;
        clips.value.forEach((clip) => {
            if (['Failed', 'Cancelled'].includes(clip.status)) {
                clip.status = 'Waiting';
                clip.meta = 'Ready to retry';
            }
        });
        await sendSource();
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

    const sendSource = async () => {
        const projectId = options.projectId();

        if (!projectId || inFlight.value || !selectedFile.value) {
            return;
        }

        inFlight.value = true;

        if (pauseRequested.value) {
            status.value = 'Paused';
            inFlight.value = false;

            return;
        }

        const uploadDisplayClips = unfinishedClips();

        if (uploadDisplayClips.length === 0) {
            inFlight.value = false;

            return;
        }

        uploadDisplayClips.forEach((clip) => {
            clip.status = 'Sending';
            clip.meta = `${formatBytes(selectedFile.value?.size ?? 0)} source`;
        });

        try {
            const payload = await uploadSource(projectId);

            if (payload.upgrade) {
                options.onUpgrade(
                    payload.message ?? 'Audio upload could not be processed.',
                );
                markFailed(uploadDisplayClips);

                return;
            }

            uploadDisplayClips.forEach((clip) => {
                clip.status = 'Queued';
                clip.meta = 'Queued for server processing';
            });
            status.value = 'Queued';
            metaLine.value = 'Queued for server processing';

            if (payload.transcript) {
                if (
                    !queuedTranscriptIds.value.includes(payload.transcript.id)
                ) {
                    queuedTranscriptIds.value.push(payload.transcript.id);
                }

                syncTranscripts([payload.transcript]);
                options.onTranscript(payload.transcript);
            }

            options.onQueued();
        } catch (error) {
            if (pauseRequested.value) {
                uploadDisplayClips.forEach((clip) => {
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

            markFailed(uploadDisplayClips);
            options.onError(
                error instanceof Error
                    ? error.message
                    : 'Audio upload could not be processed.',
            );

            return;
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

        const failedIds = tracked
            .filter((transcript) => transcript.status === 'failed')
            .map((transcript) => transcript.id);

        if (failedIds.length > 0) {
            // Failed transcripts must leave the tracked list, otherwise a
            // stale failure keeps overriding the progress of a later retry.
            queuedTranscriptIds.value = queuedTranscriptIds.value.filter(
                (id) => !failedIds.includes(id),
            );
        }

        const live = tracked.filter(
            (transcript) => transcript.status !== 'failed',
        );

        if (live.length === 0) {
            markFailed(clips.value);

            return;
        }

        const totalClips = live.reduce(
            (total, transcript) =>
                total + transcript.transcription_progress.total_clips,
            0,
        );
        const processedClips = live.reduce(
            (total, transcript) =>
                total + transcript.transcription_progress.processed_clips,
            0,
        );
        const durationSeconds = live.reduce(
            (total, transcript) => total + transcript.duration_seconds,
            0,
        );

        syncServerClips(totalClips, processedClips, durationSeconds);

        if (live.every((transcript) => transcript.status === 'completed')) {
            finish();

            return;
        }

        if (live.every((transcript) => transcript.status === 'queued')) {
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

        if (live.some((transcript) => transcript.status === 'processing')) {
            status.value = 'Processing';
            metaLine.value = `Processing ${processedClips} of ${totalClips} ${totalClips === 1 ? 'clip' : 'clips'}`;
            clips.value.forEach((clip, index) => {
                if (
                    !['Failed', 'Cancelled', 'Complete'].includes(clip.status)
                ) {
                    clip.status =
                        index === processedClips ? 'Processing' : 'Queued';
                    clip.meta =
                        index === processedClips
                            ? 'Server processing'
                            : 'Queued for server processing';
                }
            });
        }
    };

    const syncServerClips = (
        totalClips: number,
        processedClips: number,
        durationSeconds: number,
    ) => {
        if (totalClips <= 0) {
            return;
        }

        const durationMs = Math.max(0, durationSeconds * 1000);

        if (clips.value.length !== totalClips) {
            clips.value = Array.from(
                { length: totalClips },
                (_, index): UploadClip => {
                    const startMs = index * SERVER_AUDIO_CHUNK_MS;
                    const endMs = Math.min(
                        durationMs || startMs + SERVER_AUDIO_CHUNK_MS,
                        startMs + SERVER_AUDIO_CHUNK_MS,
                    );

                    return {
                        index,
                        startMs,
                        endMs,
                        durationMs: Math.max(0, endMs - startMs),
                        rangeLabel: `${formatDuration(startMs)}-${formatDuration(endMs)}`,
                        status: index < processedClips ? 'Complete' : 'Queued',
                        meta:
                            index < processedClips
                                ? 'Transcription complete'
                                : 'Queued for server processing',
                    };
                },
            );
        } else {
            clips.value.forEach((clip, index) => {
                if (index < processedClips) {
                    clip.status = 'Complete';
                    clip.meta = 'Transcription complete';
                }
            });
        }

        if (durationSeconds > 0) {
            durationLabel.value = formatDuration(durationMs);
        }
    };

    const uploadSource = async (
        projectId: number,
    ): Promise<UploadResponse> => {
        const file = selectedFile.value;

        if (!file) {
            throw new Error('Select an audio file first.');
        }

        const totalChunks = Math.max(
            1,
            Math.ceil(file.size / UPLOAD_CHUNK_BYTES),
        );

        // Reuse the pending session so pause/failure recovery resumes the
        // same upload id instead of re-submitting the source from scratch.
        // A consumed session means the server already claimed its chunks;
        // only then may a brand-new upload id be issued.
        if (
            !uploadSession.value ||
            uploadSession.value.consumed ||
            uploadSession.value.totalChunks !== totalChunks
        ) {
            uploadSession.value = {
                id: makeUploadId(),
                totalChunks,
                nextChunkIndex: 0,
                completeAttempts: 0,
                consumed: false,
            };
        }

        const session = uploadSession.value;

        for (
            let chunkIndex = session.nextChunkIndex;
            chunkIndex < totalChunks;
            chunkIndex += 1
        ) {
            if (pauseRequested.value) {
                throw new Error('Audio upload could not be processed.');
            }

            const start = chunkIndex * UPLOAD_CHUNK_BYTES;
            const end = Math.min(file.size, start + UPLOAD_CHUNK_BYTES);
            const chunk = file.slice(end > start ? start : 0, end || file.size);
            const uploadedBytes = Math.min(start, file.size);
            const form = new FormData();

            form.append('upload_id', session.id);
            form.append('chunk_index', String(chunkIndex));
            form.append('total_chunks', String(totalChunks));
            form.append('total_size', String(file.size));
            form.append('filename', file.name);
            form.append('mime_type', file.type || 'application/octet-stream');
            form.append('chunk_hash', await sha256Hex(chunk));
            form.append('chunk', chunk, `${file.name}.part${chunkIndex}`);

            await xhrJson<UploadResponse>(
                `/workspace/${projectId}/upload/chunk`,
                form,
                (loaded) => {
                    uploadPercent.value = Math.min(
                        99,
                        Math.round(
                            ((uploadedBytes + loaded) / file.size) * 100,
                        ),
                    );
                    status.value = 'Uploading source';
                },
            );
            session.nextChunkIndex = chunkIndex + 1;
        }

        const completeForm = new FormData();
        completeForm.append('upload_id', session.id);

        if (selectedDurationMs.value > 0) {
            completeForm.append(
                'duration_seconds',
                String(Math.ceil(selectedDurationMs.value / 1000)),
            );
        }

        uploadPercent.value = 99;
        status.value = 'Uploading source';
        session.completeAttempts += 1;
        const firstCompleteAttempt = session.completeAttempts === 1;

        let payload: UploadResponse;

        try {
            payload = await xhrJson<UploadResponse>(
                `/workspace/${projectId}/upload/complete`,
                completeForm,
            );
        } catch (error) {
            // Only a first-attempt definitive rejection proves the server
            // discarded this session without creating a transcript. Any
            // other failure is indeterminate (timeout, network, retry), so
            // the session is kept: retrying it can never duplicate the
            // source, while a fresh upload id could.
            if (firstCompleteAttempt && isDefinitiveRejection(error)) {
                uploadSession.value = null;
            }

            throw error;
        }

        session.consumed = true;

        return payload;
    };

    const xhrJson = <T>(
        url: string,
        form: FormData,
        onProgress?: (loaded: number, total: number) => void,
    ) =>
        new Promise<T>((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            currentXhr.value = xhr;
            xhr.open('POST', url);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', options.csrfToken());

            xhr.upload.onprogress = (event) => {
                if (event.lengthComputable) {
                    onProgress?.(event.loaded, event.total);
                }
            };
            xhr.onload = () => {
                currentXhr.value = null;
                const payload = parseJson(xhr.responseText);

                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(payload as T);

                    return;
                }

                if (payload.upgrade) {
                    resolve(payload as T);

                    return;
                }

                const error: UploadRequestError = new Error(
                    payload.message ??
                        'Audio upload could not be processed.',
                );
                error.status = xhr.status;
                error.serverMessage = typeof payload.message === 'string';
                reject(error);
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
        uploadSession.value = null;
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

export type AudioUploadController = ReturnType<typeof useAudioUpload>;

const parseJson = (value: string): UploadResponse => {
    try {
        const parsed = JSON.parse(value) as UploadResponse;

        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
        return {};
    }
};

const isDefinitiveRejection = (error: unknown): boolean =>
    error instanceof Error &&
    (error as UploadRequestError).status === 422 &&
    (error as UploadRequestError).serverMessage === true;

const makeUploadId = () => {
    if (crypto.randomUUID) {
        return crypto.randomUUID();
    }

    return `upload-${Date.now()}-${Math.random().toString(36).slice(2)}`;
};

const sha256Hex = async (blob: Blob) => {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        await blob.arrayBuffer(),
    );

    return [...new Uint8Array(digest)]
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
};

const formatBytes = (bytes: number) => {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

const formatDuration = (ms: number) => {
    const totalSeconds = Math.max(0, Math.round(ms / 1000));
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = String(totalSeconds % 60).padStart(2, '0');

    return hours > 0
        ? `${hours}:${String(minutes).padStart(2, '0')}:${seconds}`
        : `${minutes}:${seconds}`;
};
