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
    file: File;
    startMs: number;
    endMs: number;
    durationMs: number;
    rangeLabel: string;
    status:
        | 'Waiting'
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
const MAX_BATCH_DURATION_MS = 1_200_000;
const SECTION_MS = 60_000;

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
    const progressPercent = computed(() =>
        clips.value.length === 0
            ? 0
            : Math.round((completedCount.value / clips.value.length) * 100),
    );
    const statusLine = computed(() =>
        status.value === 'Uploading source'
            ? `Uploading source ${uploadPercent.value}%`
            : status.value,
    );
    const canStart = computed(
        () =>
            Boolean(selectedFile.value) && !inFlight.value && !hasSession.value,
    );
    const canPause = computed(() => inFlight.value && !pauseRequested.value);
    const canContinue = computed(
        () =>
            hasSession.value &&
            !inFlight.value &&
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

        try {
            await prepareClips(file);
        } catch {
            status.value = 'Failed';
            retryable.value = true;
            options.onError('Audio upload could not be processed.');
        }
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
                clip.meta = `${formatBytes(clip.file.size)} sent`;
            });

            try {
                const payload = await postBatch(projectId, batch);

                if (payload.upgrade) {
                    options.onUpgrade(
                        payload.message ??
                            'Audio upload could not be processed.',
                    );
                    markFailed(batch);
                    return;
                }

                batch.forEach((clip) => {
                    clip.status = 'Processing';
                    clip.meta = `${formatBytes(clip.file.size)} sent`;
                });
                status.value = 'Processing';
                metaLine.value = `Processing ${batch[0].index + 1} of ${clips.value.length}`;

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
        status.value = 'Processing';
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

    const postBatch = (projectId: number, batch: UploadClip[]) =>
        new Promise<UploadResponse>((resolve, reject) => {
            const form = new FormData();

            batch.forEach((clip) => {
                form.append('audio[]', clip.file);
                form.append('clip_index[]', String(clip.index));
                form.append('clip_start_ms[]', String(clip.startMs));
                form.append('clip_end_ms[]', String(clip.endMs));
            });
            form.append(
                'duration_seconds',
                String(Math.ceil(totalDurationMs(batch) / 1000)),
            );

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

                reject(
                    new Error(
                        payload.message ??
                            'Audio upload could not be processed.',
                    ),
                );
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

    const prepareClips = async (file: File) => {
        isPreparing.value = true;
        status.value = 'Preparing source';

        const buffer = await file.arrayBuffer();
        const audioContext = new AudioContext();
        const audio = await audioContext.decodeAudioData(buffer.slice(0));
        const prepared: UploadClip[] = [];
        const totalMs = Math.round(audio.duration * 1000);

        for (let startMs = 0; startMs < totalMs; startMs += SECTION_MS) {
            const endMs = Math.min(totalMs, startMs + SECTION_MS);
            prepared.push({
                index: prepared.length,
                file: audioBufferToWavFile(
                    sliceAudioBuffer(audioContext, audio, startMs, endMs),
                    `${file.name}-clip-${prepared.length + 1}.wav`,
                ),
                startMs,
                endMs,
                durationMs: endMs - startMs,
                rangeLabel: `${formatTime(startMs)}-${formatTime(endMs)}`,
                status: 'Waiting',
                meta: 'Waiting for source upload',
            });
        }

        if (
            prepared.length > MAX_BATCH_CLIPS ||
            totalDurationMs(prepared) > MAX_BATCH_DURATION_MS
        ) {
            await audioContext.close();
            resetSession();
            options.onError('Audio is too big.');
            return;
        }

        clips.value = prepared;
        durationLabel.value = formatTime(totalMs);
        status.value = 'Ready';
        isPreparing.value = false;
        await audioContext.close();
    };

    const markFailed = (batch: UploadClip[]) => {
        batch.forEach((clip) => {
            if (clip.status !== 'Complete') {
                clip.status = 'Failed';
                clip.meta = 'Ready to retry';
            }
        });
        status.value = 'Failed';
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

const totalDurationMs = (clips: UploadClip[]) =>
    clips.reduce((total, clip) => total + clip.durationMs, 0);

const formatBytes = (bytes: number) => {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

const formatTime = (ms: number) => {
    const seconds = Math.max(0, Math.round(ms / 1000));
    const minutes = Math.floor(seconds / 60);
    const rest = String(seconds % 60).padStart(2, '0');

    return `${minutes}:${rest}`;
};

const sliceAudioBuffer = (
    context: AudioContext,
    source: AudioBuffer,
    startMs: number,
    endMs: number,
) => {
    const sampleRate = source.sampleRate;
    const start = Math.floor((startMs / 1000) * sampleRate);
    const end = Math.min(source.length, Math.ceil((endMs / 1000) * sampleRate));
    const length = Math.max(1, end - start);
    const target = context.createBuffer(
        source.numberOfChannels,
        length,
        sampleRate,
    );

    for (let channel = 0; channel < source.numberOfChannels; channel++) {
        target.copyToChannel(
            source.getChannelData(channel).slice(start, end),
            channel,
        );
    }

    return target;
};

const audioBufferToWavFile = (buffer: AudioBuffer, name: string) =>
    new File([encodeWav(buffer)], name, { type: 'audio/wav' });

const encodeWav = (buffer: AudioBuffer) => {
    const channelCount = buffer.numberOfChannels;
    const sampleRate = buffer.sampleRate;
    const bytesPerSample = 2;
    const blockAlign = channelCount * bytesPerSample;
    const samples = buffer.length;
    const dataSize = samples * blockAlign;
    const output = new ArrayBuffer(44 + dataSize);
    const view = new DataView(output);
    let offset = 0;

    writeString(view, offset, 'RIFF');
    offset += 4;
    view.setUint32(offset, 36 + dataSize, true);
    offset += 4;
    writeString(view, offset, 'WAVE');
    offset += 4;
    writeString(view, offset, 'fmt ');
    offset += 4;
    view.setUint32(offset, 16, true);
    offset += 4;
    view.setUint16(offset, 1, true);
    offset += 2;
    view.setUint16(offset, channelCount, true);
    offset += 2;
    view.setUint32(offset, sampleRate, true);
    offset += 4;
    view.setUint32(offset, sampleRate * blockAlign, true);
    offset += 4;
    view.setUint16(offset, blockAlign, true);
    offset += 2;
    view.setUint16(offset, bytesPerSample * 8, true);
    offset += 2;
    writeString(view, offset, 'data');
    offset += 4;
    view.setUint32(offset, dataSize, true);
    offset += 4;

    for (let sample = 0; sample < samples; sample++) {
        for (let channel = 0; channel < channelCount; channel++) {
            const value = Math.max(
                -1,
                Math.min(1, buffer.getChannelData(channel)[sample] ?? 0),
            );
            view.setInt16(
                offset,
                value < 0 ? value * 0x8000 : value * 0x7fff,
                true,
            );
            offset += 2;
        }
    }

    return output;
};

const writeString = (view: DataView, offset: number, value: string) => {
    for (let index = 0; index < value.length; index++) {
        view.setUint8(offset + index, value.charCodeAt(index));
    }
};
