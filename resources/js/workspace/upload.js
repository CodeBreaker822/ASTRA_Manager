/**
 * Resumable audio source upload.
 *
 * Returns a plain object of state and methods, with getters for derived state.
 * The workspace reads these fields to paint the blade-rendered dock. Timers and
 * the in-flight XHR stay in the closure.
 */
const MAX_UPLOAD_BYTES = 500 * 1024 * 1024;
const UPLOAD_CHUNK_BYTES = 20 * 1024 * 1024;
const SERVER_AUDIO_CHUNK_MS = 60 * 1000;

// The progress bar spans three real phases. Transport upload and transcription
// are measurable; server preparation (FFmpeg chunking inside upload/complete)
// reports nothing, so it creeps within its own slice instead of pinning the
// whole bar at 99%.
const UPLOAD_PHASE_WEIGHT = 30;
const PREPARE_PHASE_WEIGHT = 20;
const TRANSCRIBE_PHASE_WEIGHT = 50;

// upload/complete runs source chunking synchronously, so an edge proxy can cut
// the connection long before the server is done. These windows bound how long
// the client waits for the transcript that request is still producing.
const RECOVERY_POLL_MS = 4000;
const RECOVERY_WINDOW_MS = 20 * 60 * 1000;
const RECOVERY_RECHECK_MS = 60 * 1000;

const parseJson = (value) => {
    try {
        const parsed = JSON.parse(value);

        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
        return {};
    }
};

// A parsed 4xx body means the application answered deliberately and stopped
// before creating a transcript: validation, auth, an expired CSRF token, a
// rejected payload. A 5xx, an edge timeout, or a dropped connection proves
// nothing about what the server did with the source.
const isDefinitiveRejection = (error) => {
    const status = error?.status ?? 0;

    return (
        error instanceof Error &&
        status >= 400 &&
        status < 500 &&
        error.serverMessage === true
    );
};

const makeUploadId = () =>
    crypto.randomUUID?.() ??
    `upload-${Date.now()}-${Math.random().toString(36).slice(2)}`;

const sha256Hex = async (blob) => {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        await blob.arrayBuffer(),
    );

    return [...new Uint8Array(digest)]
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
};

const formatBytes = (bytes) =>
    bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;

const formatDuration = (ms) => {
    const totalSeconds = Math.max(0, Math.round(ms / 1000));
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = String(totalSeconds % 60).padStart(2, '0');

    return hours > 0
        ? `${hours}:${String(minutes).padStart(2, '0')}:${seconds}`
        : `${minutes}:${seconds}`;
};

export function createUpload(options) {
    let prepareTimer = null;
    let recoveryTimer = null;
    let recoveryDeadline = 0;
    let recoveryInFlight = false;
    let currentXhr = null;

    return {
        uploadFileName: 'Select an audio file',
        uploadMetaLine: '',
        uploadDurationLabel: '--:--',
        uploadStatus: 'Ready',
        uploadPercent: 0,
        preparePercent: 0,
        uploadClips: [],
        selectedFile: null,
        selectedDurationMs: 0,
        uploadSession: null,
        isPreparing: false,
        uploadInFlight: false,
        pauseRequested: false,
        hasSession: false,
        retryable: false,
        completionNotified: false,
        queuedTranscriptIds: [],
        recovering: false,
        transcriptIdsBeforeUpload: [],

        get hasProgress() {
            return (
                this.hasSession ||
                this.uploadClips.some((clip) => clip.status !== 'Waiting')
            );
        },

        get completedCount() {
            return this.uploadClips.filter((clip) => clip.status === 'Complete')
                .length;
        },

        get transcribePercent() {
            return this.uploadClips.length === 0
                ? 0
                : (this.completedCount / this.uploadClips.length) * 100;
        },

        get progressPercent() {
            return Math.round(
                (this.uploadPercent * UPLOAD_PHASE_WEIGHT +
                    this.preparePercent * PREPARE_PHASE_WEIGHT +
                    this.transcribePercent * TRANSCRIBE_PHASE_WEIGHT) /
                    100,
            );
        },

        get uploadStatusLine() {
            return this.uploadStatus === 'Uploading source'
                ? `Uploading source ${Math.round(this.uploadPercent)}%`
                : this.uploadStatus;
        },

        get canStart() {
            return (
                Boolean(this.selectedFile) &&
                this.uploadClips.length > 0 &&
                !this.uploadInFlight &&
                !this.hasSession
            );
        },

        get canPause() {
            return this.uploadInFlight && !this.pauseRequested;
        },

        get canContinue() {
            return (
                this.hasSession &&
                !this.uploadInFlight &&
                this.uploadStatus === 'Paused' &&
                this.unfinishedClips().length > 0 &&
                !this.retryable
            );
        },

        get canRetry() {
            return (
                this.hasSession &&
                !this.uploadInFlight &&
                !this.recovering &&
                this.retryable
            );
        },

        get canCancel() {
            return this.uploadInFlight || this.hasProgress;
        },

        get isUploadActive() {
            return this.isPreparing || this.uploadInFlight || this.hasProgress;
        },

        get hasFile() {
            return Boolean(this.selectedFile);
        },

        async selectFile(file) {
            this.resetSession();

            if (file.size > MAX_UPLOAD_BYTES) {
                options.onError('Audio upload must not exceed 500 MB.');

                return;
            }

            this.selectedFile = file;
            this.uploadFileName = file.name;
            this.uploadMetaLine = `${formatBytes(file.size)} selected`;
            this.uploadStatus = 'Ready';

            this.prepareClips();
        },

        async startUpload() {
            if (
                !this.selectedFile ||
                this.uploadClips.length === 0 ||
                this.uploadInFlight ||
                this.hasSession
            ) {
                return;
            }

            this.retryable = false;
            this.hasSession = true;
            this.transcriptIdsBeforeUpload = options.knownTranscriptIds();
            await this.sendSource();
        },

        pauseUpload() {
            if (!this.uploadInFlight) {
                return;
            }

            this.pauseRequested = true;
            this.uploadStatus = 'Pausing';
            currentXhr?.abort();
        },

        async resumeUpload() {
            this.pauseRequested = false;
            this.uploadStatus = 'Ready to continue';
            this.retryable = false;
            this.uploadClips.forEach((clip) => {
                if (clip.status === 'Cancelled') {
                    clip.status = 'Waiting';
                    clip.meta = 'Ready to continue';
                }
            });
            await this.sendSource();
        },

        async retryUpload() {
            if (this.uploadInFlight || this.recovering) {
                return;
            }

            // Once every chunk has reached the server the source is already in
            // its hands, so retrying must never re-upload it. Re-check for the
            // transcript that upload/complete is producing instead.
            if (this.sourceFullyDelivered()) {
                this.beginRecovery(RECOVERY_RECHECK_MS);

                return;
            }

            this.retryable = false;
            this.uploadClips.forEach((clip) => {
                if (['Failed', 'Cancelled'].includes(clip.status)) {
                    clip.status = 'Waiting';
                    clip.meta = 'Ready to retry';
                }
            });
            await this.sendSource();
        },

        cancelUpload() {
            this.uploadStatus = 'Cancelling';
            this.pauseRequested = false;
            this.stopRecovery();
            this.stopPrepareCreep();
            currentXhr?.abort();
            void this.cancelQueuedTranscripts();
            this.uploadClips.forEach((clip) => {
                if (clip.status !== 'Complete') {
                    clip.status = 'Cancelled';
                    clip.meta = 'Ready to continue';
                }
            });
            window.setTimeout(() => {
                this.uploadInFlight = false;
                this.retryable = true;
                this.uploadStatus = 'Cancelled';
            }, 350);
        },

        async sendSource() {
            const projectId = options.projectId();

            if (!projectId || this.uploadInFlight || !this.selectedFile) {
                return;
            }

            this.uploadInFlight = true;

            if (this.pauseRequested) {
                this.uploadStatus = 'Paused';
                this.uploadInFlight = false;

                return;
            }

            const batch = this.unfinishedClips();

            if (batch.length === 0) {
                this.uploadInFlight = false;

                return;
            }

            batch.forEach((clip) => {
                clip.status = 'Sending';
                clip.meta = `${formatBytes(this.selectedFile?.size ?? 0)} source`;
            });

            try {
                const payload = await this.uploadSource(projectId);

                if (payload.upgrade) {
                    options.onUpgrade(
                        payload.message ??
                            'Audio upload could not be processed.',
                    );
                    this.markFailed(batch);

                    return;
                }

                this.markServerPrepared();

                batch.forEach((clip) => {
                    clip.status = 'Queued';
                    clip.meta = 'Queued for server processing';
                });
                this.uploadStatus = 'Queued';
                this.uploadMetaLine = 'Queued for server processing';

                if (payload.transcript) {
                    if (
                        !this.queuedTranscriptIds.includes(
                            payload.transcript.id,
                        )
                    ) {
                        this.queuedTranscriptIds.push(payload.transcript.id);
                    }

                    this.syncTranscripts([payload.transcript]);
                    options.onTranscript(payload.transcript);
                }

                options.onQueued();
            } catch (error) {
                if (this.pauseRequested) {
                    batch.forEach((clip) => {
                        if (clip.status !== 'Complete') {
                            clip.status = 'Cancelled';
                            clip.meta = 'Ready to continue';
                        }
                    });
                    this.uploadStatus = 'Paused';
                    this.uploadInFlight = false;
                    this.retryable = false;

                    return;
                }

                // The source reached the server and upload/complete may still
                // be running there. Reporting failure now would be wrong, and
                // re-sending would duplicate the job.
                if (error?.recoverable === true) {
                    this.beginRecovery(RECOVERY_WINDOW_MS);

                    return;
                }

                this.markFailed(batch);
                options.onError(
                    error instanceof Error
                        ? error.message
                        : 'Audio upload could not be processed.',
                );

                return;
            }

            this.uploadInFlight = false;
            this.uploadStatus = 'Queued';
        },

        finishUpload() {
            if (
                !this.hasProgress ||
                this.uploadInFlight ||
                this.uploadClips.length === 0
            ) {
                return;
            }

            if (this.completionNotified) {
                return;
            }

            this.stopRecovery();
            this.markServerPrepared();
            this.uploadClips.forEach((clip) => {
                if (!['Failed', 'Cancelled'].includes(clip.status)) {
                    clip.status = 'Complete';
                }
            });
            this.uploadStatus = 'Complete';
            this.uploadPercent = 100;
            this.completionNotified = true;
            options.onSuccess('Audio transcription completed.');
        },

        syncTranscripts(transcripts) {
            if (this.recovering) {
                this.adoptRecoveredTranscript(transcripts);
            }

            if (this.queuedTranscriptIds.length === 0) {
                return;
            }

            const tracked = transcripts.filter((transcript) =>
                this.queuedTranscriptIds.includes(transcript.id),
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
                this.queuedTranscriptIds = this.queuedTranscriptIds.filter(
                    (id) => !failedIds.includes(id),
                );
            }

            const live = tracked.filter(
                (transcript) => transcript.status !== 'failed',
            );

            if (live.length === 0) {
                this.markFailed(this.uploadClips);

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

            this.syncServerClips(totalClips, processedClips, durationSeconds);

            if (live.every((transcript) => transcript.status === 'completed')) {
                this.finishUpload();

                return;
            }

            if (live.every((transcript) => transcript.status === 'queued')) {
                this.uploadStatus = 'Queued';
                this.uploadMetaLine = `Queued ${this.uploadClips.length} ${this.uploadClips.length === 1 ? 'clip' : 'clips'}`;
                this.uploadClips.forEach((clip) => {
                    if (
                        !['Failed', 'Cancelled', 'Complete'].includes(
                            clip.status,
                        )
                    ) {
                        clip.status = 'Queued';
                        clip.meta = 'Queued for server processing';
                    }
                });

                return;
            }

            if (live.some((transcript) => transcript.status === 'processing')) {
                this.uploadStatus = 'Processing';
                this.uploadMetaLine = `Processing ${processedClips} of ${totalClips} ${totalClips === 1 ? 'clip' : 'clips'}`;
                this.uploadClips.forEach((clip, index) => {
                    if (
                        !['Failed', 'Cancelled', 'Complete'].includes(
                            clip.status,
                        )
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
        },

        // The upload that produced this transcript never got its response back,
        // so it is identified as the newest upload transcript that did not
        // exist when this source was submitted.
        adoptRecoveredTranscript(transcripts) {
            const adopted = transcripts
                .filter(
                    (transcript) =>
                        transcript.source === 'upload' &&
                        !this.transcriptIdsBeforeUpload.includes(
                            transcript.id,
                        ) &&
                        !this.queuedTranscriptIds.includes(transcript.id),
                )
                .sort((first, second) => second.id - first.id)[0];

            if (!adopted) {
                return;
            }

            this.stopRecovery();
            this.markServerPrepared();
            this.queuedTranscriptIds.push(adopted.id);
            this.uploadMetaLine = 'Queued for server processing';
            options.onTranscript(adopted);
            options.onQueued();
        },

        syncServerClips(totalClips, processedClips, durationSeconds) {
            if (totalClips <= 0) {
                return;
            }

            const durationMs = Math.max(0, durationSeconds * 1000);

            if (this.uploadClips.length !== totalClips) {
                this.uploadClips = Array.from(
                    { length: totalClips },
                    (_, index) => {
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
                            status:
                                index < processedClips ? 'Complete' : 'Queued',
                            meta:
                                index < processedClips
                                    ? 'Transcription complete'
                                    : 'Queued for server processing',
                        };
                    },
                );
            } else {
                this.uploadClips.forEach((clip, index) => {
                    if (index < processedClips) {
                        clip.status = 'Complete';
                        clip.meta = 'Transcription complete';
                    }
                });
            }

            if (durationSeconds > 0) {
                this.uploadDurationLabel = formatDuration(durationMs);
            }
        },

        async uploadSource(projectId) {
            const file = this.selectedFile;

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
                !this.uploadSession ||
                this.uploadSession.consumed ||
                this.uploadSession.totalChunks !== totalChunks
            ) {
                this.uploadSession = {
                    id: makeUploadId(),
                    totalChunks,
                    nextChunkIndex: 0,
                    completeAttempts: 0,
                    consumed: false,
                };
            }

            const session = this.uploadSession;

            for (
                let chunkIndex = session.nextChunkIndex;
                chunkIndex < totalChunks;
                chunkIndex += 1
            ) {
                if (this.pauseRequested) {
                    throw new Error('Audio upload could not be processed.');
                }

                const start = chunkIndex * UPLOAD_CHUNK_BYTES;
                const end = Math.min(file.size, start + UPLOAD_CHUNK_BYTES);
                const chunk = file.slice(
                    end > start ? start : 0,
                    end || file.size,
                );
                const uploadedBytes = Math.min(start, file.size);
                const form = new FormData();

                form.append('upload_id', session.id);
                form.append('chunk_index', String(chunkIndex));
                form.append('total_chunks', String(totalChunks));
                form.append('total_size', String(file.size));
                form.append('filename', file.name);
                form.append(
                    'mime_type',
                    file.type || 'application/octet-stream',
                );
                form.append('chunk_hash', await sha256Hex(chunk));
                form.append('chunk', chunk, `${file.name}.part${chunkIndex}`);

                await this.xhrJson(
                    `/workspace/${projectId}/upload/chunk`,
                    form,
                    (loaded) => {
                        this.uploadPercent = Math.min(
                            100,
                            ((uploadedBytes + loaded) / file.size) * 100,
                        );
                        this.uploadStatus = 'Uploading source';
                    },
                );

                session.nextChunkIndex = chunkIndex + 1;
                this.uploadPercent = Math.min(
                    100,
                    (Math.min(end, file.size) / file.size) * 100,
                );
            }

            const completeForm = new FormData();
            completeForm.append('upload_id', session.id);

            if (this.selectedDurationMs > 0) {
                completeForm.append(
                    'duration_seconds',
                    String(Math.ceil(this.selectedDurationMs / 1000)),
                );
            }

            this.uploadPercent = 100;
            this.beginServerPrepare();
            session.completeAttempts += 1;
            const firstCompleteAttempt = session.completeAttempts === 1;

            let payload;

            try {
                payload = await this.xhrJson(
                    `/workspace/${projectId}/upload/complete`,
                    completeForm,
                );
            } catch (error) {
                // A first-attempt definitive rejection proves the server
                // discarded this session without creating a transcript, so it
                // is a real failure. Everything else - a timeout, a dropped
                // connection, or a later attempt whose session the server
                // already consumed - means work may still be in flight there.
                if (firstCompleteAttempt && isDefinitiveRejection(error)) {
                    this.uploadSession = null;
                    this.stopPrepareCreep();

                    throw error;
                }

                error.recoverable = true;

                throw error;
            }

            session.consumed = true;

            return payload;
        },

        xhrJson(url, form, onProgress) {
            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                currentXhr = xhr;
                xhr.open('POST', url);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-CSRF-TOKEN', options.csrfToken());

                xhr.upload.onprogress = (event) => {
                    if (event.lengthComputable) {
                        onProgress?.(event.loaded, event.total);
                    }
                };

                xhr.onload = () => {
                    currentXhr = null;
                    const payload = parseJson(xhr.responseText);

                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve(payload);

                        return;
                    }

                    if (payload.upgrade) {
                        resolve(payload);

                        return;
                    }

                    const error = new Error(
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
        },

        beginServerPrepare() {
            this.uploadStatus = 'Preparing on server';
            this.uploadMetaLine =
                'Preparing audio on the server. This can take a while.';
            this.startPrepareCreep();
        },

        markServerPrepared() {
            this.stopPrepareCreep();
            this.uploadPercent = 100;
            this.preparePercent = 100;
        },

        startPrepareCreep() {
            this.stopPrepareCreep();

            prepareTimer = window.setInterval(() => {
                this.preparePercent = Math.min(
                    95,
                    this.preparePercent +
                        Math.max(0.4, (95 - this.preparePercent) * 0.03),
                );
            }, 1000);
        },

        stopPrepareCreep() {
            if (prepareTimer === null) {
                return;
            }

            window.clearInterval(prepareTimer);
            prepareTimer = null;
        },

        beginRecovery(windowMs) {
            this.stopRecovery();
            this.recovering = true;
            recoveryDeadline = Date.now() + windowMs;
            this.uploadInFlight = false;
            this.retryable = false;
            this.pauseRequested = false;
            this.uploadStatus = 'Preparing on server';
            this.uploadMetaLine =
                'Upload finished. Waiting for the server to finish processing.';
            this.startPrepareCreep();
            recoveryTimer = window.setInterval(
                () => void this.recoveryTick(),
                RECOVERY_POLL_MS,
            );
            void this.recoveryTick();
        },

        stopRecovery() {
            this.recovering = false;

            if (recoveryTimer === null) {
                return;
            }

            window.clearInterval(recoveryTimer);
            recoveryTimer = null;
        },

        async recoveryTick() {
            if (recoveryInFlight) {
                return;
            }

            if (Date.now() > recoveryDeadline) {
                this.stopRecovery();
                this.stopPrepareCreep();
                this.markFailed(
                    this.uploadClips,
                    'The server did not report this upload. It may still be processing, so check your transcripts before uploading again.',
                );

                return;
            }

            recoveryInFlight = true;

            try {
                await options.refreshStatus();
            } catch {
                return;
            } finally {
                recoveryInFlight = false;
            }
        },

        sourceFullyDelivered() {
            return Boolean(
                this.uploadSession &&
                this.uploadSession.nextChunkIndex >=
                    this.uploadSession.totalChunks,
            );
        },

        async cancelQueuedTranscripts() {
            const projectId = options.projectId();

            if (!projectId || this.queuedTranscriptIds.length === 0) {
                return;
            }

            await Promise.allSettled(
                this.queuedTranscriptIds.map((transcriptId) =>
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
        },

        prepareClips() {
            this.isPreparing = true;
            this.uploadStatus = 'Preparing source';

            try {
                this.selectedDurationMs = 0;
                this.uploadClips = [
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
                this.uploadDurationLabel = 'Server measured';
                this.uploadStatus = 'Ready';
            } finally {
                this.isPreparing = false;
            }
        },

        markFailed(batch, message = 'Audio upload could not be processed.') {
            this.stopPrepareCreep();
            batch.forEach((clip) => {
                if (clip.status !== 'Complete') {
                    clip.status = 'Failed';
                    clip.meta = 'Ready to retry';
                }
            });
            this.uploadStatus = 'Failed';
            this.uploadMetaLine = message;
            this.uploadInFlight = false;
            this.retryable = true;
        },

        resetSession() {
            currentXhr?.abort();
            this.stopRecovery();
            this.stopPrepareCreep();
            this.uploadFileName = 'Select an audio file';
            this.uploadMetaLine = '';
            this.uploadDurationLabel = '--:--';
            this.uploadStatus = 'Ready';
            this.uploadPercent = 0;
            this.preparePercent = 0;
            this.uploadClips = [];
            this.selectedFile = null;
            this.selectedDurationMs = 0;
            currentXhr = null;
            this.uploadSession = null;
            this.isPreparing = false;
            this.uploadInFlight = false;
            this.pauseRequested = false;
            this.hasSession = false;
            this.retryable = false;
            this.completionNotified = false;
            this.queuedTranscriptIds = [];
            this.transcriptIdsBeforeUpload = [];
        },

        unfinishedClips() {
            return this.uploadClips.filter(
                (clip) => clip.status !== 'Complete',
            );
        },

        destroyUpload() {
            this.stopRecovery();
            this.stopPrepareCreep();
        },
    };
}
