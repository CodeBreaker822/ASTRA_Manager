/**
 * Live microphone (optionally mixed with screen audio) recorder.
 *
 * Returns a plain object of state and methods, with getters for derived state.
 * The workspace reads these fields to paint the blade-rendered dock. Capture
 * handles (streams, nodes, timers) stay in the closure.
 */
const SEGMENT_MS = 15_000;

export const formatDuration = (ms, forceHours = false) => {
    const totalSeconds = Math.max(0, Math.floor(ms / 1000));
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const pad = (value) => String(value).padStart(2, '0');

    return hours > 0 || forceHours
        ? `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`
        : `${pad(minutes)}:${pad(seconds)}`;
};

const hasLiveAudioTrack = (stream) =>
    Boolean(
        stream?.getAudioTracks().some((track) => track.readyState === 'live'),
    );

const stopMediaStream = (stream) =>
    stream.getTracks().forEach((track) => track.stop());

const isPermissionError = (error) =>
    error instanceof DOMException &&
    ['NotAllowedError', 'PermissionDeniedError', 'SecurityError'].includes(
        error.name,
    );

const isScreenSelectionCancelled = (error) =>
    isPermissionError(error) ||
    (error instanceof DOMException && error.name === 'AbortError');

export function createRecorder(options) {
    let mediaRecorder = null;
    let microphoneStream = null;
    let displayStream = null;
    let recordingStream = null;
    let audioContext = null;
    let audioNodes = [];
    let monitoredDisplayTracks = [];
    let captureAttempt = 0;
    let timer = null;

    const state = {
        liveStartedAt: null,
        liveSegmentStartedAt: null,
        liveElapsedMs: 0,
        liveSupportLine: 'Ready',
        liveRequestState: 'idle',
        liveClips: [],
        livePendingSends: 0,
        liveCaptureSourceLabel: 'Microphone',

        get isRecording() {
            return this.liveRequestState === 'recording';
        },

        get isRequesting() {
            return this.liveRequestState === 'requesting';
        },

        get isUnavailable() {
            return ['blocked', 'unsupported'].includes(this.liveRequestState);
        },

        get hasUnsavedChunks() {
            return (
                this.livePendingSends > 0 ||
                this.liveClips.some((clip) => clip.status === 'Sending')
            );
        },

        get isLivePanelVisible() {
            return this.isRecording || this.hasUnsavedChunks;
        },

        get liveActiveName() {
            if (this.hasUnsavedChunks && !this.isRecording) {
                return 'Processing';
            }

            return this.isRecording
                ? `Recording - ${this.liveCaptureSourceLabel}`
                : 'Ready';
        },

        get liveElapsedLabel() {
            return formatDuration(this.liveElapsedMs, true);
        },

        get liveCurrentRangeLabel() {
            const startMs = Math.max(
                0,
                this.liveElapsedMs - (this.liveElapsedMs % SEGMENT_MS),
            );
            const endMs = Math.min(this.liveElapsedMs, startMs + SEGMENT_MS);

            return `${formatDuration(startMs)}-${formatDuration(endMs)}`;
        },

        get liveSegmentProgress() {
            return Math.min(
                100,
                Math.round(
                    ((this.liveElapsedMs % SEGMENT_MS) / SEGMENT_MS) * 100,
                ),
            );
        },

        get liveButtonTop() {
            if (this.isUnavailable) {
                return 'Unavailable';
            }

            return this.isRecording ? 'Recording' : 'Listening';
        },

        get liveButtonBottom() {
            if (this.isUnavailable) {
                return 'Click for details';
            }

            if (this.isRequesting) {
                return this.liveSupportLine === 'Requesting screen audio'
                    ? 'Choose audio to share'
                    : 'Requesting microphone';
            }

            return this.isRecording ? 'Stop recording' : 'Ready to capture';
        },

        async toggleLive() {
            if (this.isRecording) {
                this.stopLive();

                return;
            }

            if (this.isRequesting) {
                return;
            }

            if (this.isUnavailable) {
                options.onToastError(
                    this.liveRequestState === 'blocked'
                        ? 'Microphone access is blocked. Please allow it to record audio.'
                        : 'Live recording could not start. Please try again.',
                );

                return;
            }

            await this.startLive();
        },

        async startLive() {
            if (!options.projectId() || !options.canUseLive()) {
                return;
            }

            const includeScreenAudio = options.captureScreenAudio();
            const mediaDevices = navigator.mediaDevices;

            if (
                !mediaDevices?.getUserMedia ||
                typeof MediaRecorder === 'undefined'
            ) {
                this.liveRequestState = 'unsupported';
                this.liveSupportLine = 'Start failed';
                options.onToastError(
                    'Live recording could not start. Please try again.',
                );

                return;
            }

            if (
                includeScreenAudio &&
                typeof mediaDevices.getDisplayMedia !== 'function'
            ) {
                this.liveRequestState = 'idle';
                this.liveSupportLine = 'Screen audio unsupported';
                options.onToastError(
                    'Screen audio capture is not supported by this browser. Turn it off in Recording settings to use microphone-only capture.',
                );

                return;
            }

            this.releaseCapture();
            const attempt = ++captureAttempt;
            let stage = includeScreenAudio ? 'display' : 'microphone';

            this.liveRequestState = 'requesting';
            this.liveSupportLine = includeScreenAudio
                ? 'Requesting screen audio'
                : 'Requesting microphone';

            try {
                if (includeScreenAudio) {
                    const selectedDisplayStream =
                        await mediaDevices.getDisplayMedia({
                            audio: true,
                            systemAudio: 'include',
                            video: true,
                        });

                    if (attempt !== captureAttempt) {
                        stopMediaStream(selectedDisplayStream);

                        return;
                    }

                    displayStream = selectedDisplayStream;

                    if (!hasLiveAudioTrack(selectedDisplayStream)) {
                        captureAttempt += 1;
                        this.releaseCapture();
                        this.liveRequestState = 'idle';
                        this.liveSupportLine = 'No screen audio shared';
                        options.onToastError(
                            'No screen audio was shared. Choose a browser tab, window, or screen with audio and enable Share audio in the picker.',
                        );

                        return;
                    }

                    this.monitorDisplayTracks(selectedDisplayStream);
                    stage = 'microphone';
                    this.liveSupportLine = 'Requesting microphone';
                }

                const selectedMicrophoneStream =
                    await mediaDevices.getUserMedia({
                        audio: {
                            autoGainControl: true,
                            echoCancellation: true,
                            noiseSuppression: true,
                        },
                    });

                if (attempt !== captureAttempt) {
                    stopMediaStream(selectedMicrophoneStream);

                    return;
                }

                microphoneStream = selectedMicrophoneStream;
                stage = includeScreenAudio ? 'mixing' : 'recorder';
                recordingStream = includeScreenAudio
                    ? await this.createMixedAudioStream(
                          selectedMicrophoneStream,
                          displayStream,
                      )
                    : selectedMicrophoneStream;

                if (attempt !== captureAttempt) {
                    this.releaseCapture();

                    return;
                }

                if (!hasLiveAudioTrack(recordingStream)) {
                    throw new Error(
                        'The recording stream has no live audio track.',
                    );
                }

                stage = 'recorder';
                const recorder = new MediaRecorder(recordingStream, {
                    mimeType: MediaRecorder.isTypeSupported(
                        'audio/webm;codecs=opus',
                    )
                        ? 'audio/webm;codecs=opus'
                        : 'audio/webm',
                });

                recorder.ondataavailable = (event) => {
                    if (event.data.size === 0 || !this.liveSegmentStartedAt) {
                        return;
                    }

                    const started = this.liveSegmentStartedAt;
                    const ended = Date.now();
                    this.liveSegmentStartedAt = ended;
                    void this.sendChunk(event.data, started, ended);
                };

                const finishRecording = () => {
                    if (mediaRecorder !== recorder) {
                        return;
                    }

                    captureAttempt += 1;
                    this.stopTimer();
                    this.releaseCapture();
                    mediaRecorder = null;
                    this.liveStartedAt = null;
                    this.liveSegmentStartedAt = null;
                    this.liveElapsedMs = 0;
                    this.liveRequestState =
                        this.liveRequestState === 'blocked'
                            ? 'blocked'
                            : 'idle';
                    this.liveSupportLine =
                        this.livePendingSends > 0 ? 'Processing' : 'Ready';
                };

                recorder.onstop = finishRecording;
                recorder.onerror = () => {
                    this.liveSupportLine = 'Recording failed';
                    options.onToastError(
                        'Live recording stopped because the browser could not continue capturing audio.',
                    );

                    if (recorder.state !== 'inactive') {
                        recorder.stop();

                        return;
                    }

                    finishRecording();
                };

                mediaRecorder = recorder;
                this.liveStartedAt = Date.now();
                this.liveSegmentStartedAt = this.liveStartedAt;
                this.liveElapsedMs = 0;
                this.liveRequestState = 'recording';
                this.liveCaptureSourceLabel = includeScreenAudio
                    ? 'Mic + screen audio'
                    : 'Microphone';
                this.liveSupportLine = this.liveCaptureSourceLabel;
                recorder.start(SEGMENT_MS);
                this.startTimer();
            } catch (error) {
                if (attempt !== captureAttempt) {
                    return;
                }

                captureAttempt += 1;
                this.releaseCapture();
                mediaRecorder = null;

                if (stage === 'display') {
                    this.liveRequestState = 'idle';
                    this.liveSupportLine = 'Screen share cancelled';
                    options.onToastError(
                        isScreenSelectionCancelled(error)
                            ? 'Screen sharing was cancelled. Start live recording again when you are ready to choose an audio source.'
                            : 'Screen audio could not be captured. Please try again or turn it off in Recording settings.',
                    );

                    return;
                }

                if (stage === 'microphone') {
                    const blocked = isPermissionError(error);
                    this.liveRequestState = blocked ? 'blocked' : 'idle';
                    this.liveSupportLine = blocked
                        ? 'Microphone blocked'
                        : 'Microphone unavailable';
                    options.onToastError(
                        blocked
                            ? 'Microphone access is blocked. Please allow it to record audio.'
                            : 'The microphone could not be captured. Please try again.',
                    );

                    return;
                }

                if (stage === 'mixing') {
                    this.liveRequestState = 'idle';
                    this.liveSupportLine = 'Screen audio mix failed';
                    options.onToastError(
                        'Screen and microphone audio could not be mixed. Please try again or turn off screen audio in Recording settings.',
                    );

                    return;
                }

                this.liveRequestState = 'unsupported';
                this.liveSupportLine = 'Start failed';
                options.onToastError(
                    'Live recording could not start. Please try again.',
                );
            }
        },

        stopLive() {
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                mediaRecorder.stop();
            }
        },

        async sendChunk(blob, startTime, endTime) {
            const projectId = options.projectId();

            if (!projectId || !this.liveStartedAt) {
                return;
            }

            const index = this.liveClips.length;
            const startMs = Math.max(0, startTime - this.liveStartedAt);
            const endMs = Math.max(startMs + 1, endTime - this.liveStartedAt);

            this.liveClips.push({
                index,
                rangeLabel: `${formatDuration(startMs)}-${formatDuration(endMs)}`,
                status: 'Sending',
                progress: 66,
            });

            const clip = this.liveClips[index];
            this.livePendingSends += 1;
            this.liveSupportLine = 'Sending';

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

                const payload = await response.json();

                if (!response.ok) {
                    if (payload.upgrade) {
                        options.onUpgrade(
                            payload.message ??
                                'Audio upload could not be processed.',
                        );
                    }

                    throw new Error('Audio upload could not be processed.');
                }

                clip.status = 'Saved';
                clip.progress = 100;
                this.liveSupportLine = 'Saved';

                if (payload.transcript) {
                    options.onTranscript(payload.transcript);
                }

                options.onQueued();
            } catch {
                clip.status = 'Error';
                clip.progress = 100;
                this.liveSupportLine = 'Save failed';
                this.stopLive();
                options.onToastError(
                    `Clip ${index + 1} could not be saved. Please try again.`,
                );
            } finally {
                this.livePendingSends = Math.max(0, this.livePendingSends - 1);

                if (this.livePendingSends > 0) {
                    this.liveSupportLine = 'Processing';
                }
            }
        },

        startTimer() {
            this.stopTimer();
            timer = window.setInterval(() => {
                this.liveElapsedMs = this.liveStartedAt
                    ? Date.now() - this.liveStartedAt
                    : 0;
            }, 100);
        },

        stopTimer() {
            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }
        },

        async createMixedAudioStream(microphone, display) {
            if (typeof window.AudioContext === 'undefined') {
                throw new Error('Web Audio is not supported.');
            }

            const context = new window.AudioContext();
            const destination = context.createMediaStreamDestination();
            const compressor = context.createDynamicsCompressor();

            compressor.threshold.value = -12;
            compressor.knee.value = 12;
            compressor.ratio.value = 4;
            compressor.attack.value = 0.003;
            compressor.release.value = 0.25;

            const connectInput = (input) => {
                const source = context.createMediaStreamSource(input);
                const gain = context.createGain();

                gain.gain.value = 0.8;
                source.connect(gain);
                gain.connect(compressor);
                audioNodes.push(source, gain);
            };

            audioContext = context;
            connectInput(microphone);
            connectInput(display);
            compressor.connect(destination);
            audioNodes.push(compressor, destination);
            recordingStream = destination.stream;

            if (context.state === 'suspended') {
                await context.resume();
            }

            return destination.stream;
        },

        monitorDisplayTracks(capturedDisplay) {
            this.stopMonitoringDisplayTracks();
            monitoredDisplayTracks = capturedDisplay.getTracks();
            monitoredDisplayTracks.forEach((track) => {
                track.addEventListener('ended', this.handleDisplayTrackEnded);
            });
        },

        stopMonitoringDisplayTracks() {
            monitoredDisplayTracks.forEach((track) => {
                track.removeEventListener(
                    'ended',
                    this.handleDisplayTrackEnded,
                );
            });
            monitoredDisplayTracks = [];
        },

        releaseCapture() {
            this.stopMonitoringDisplayTracks();

            const tracks = new Set([
                ...(recordingStream?.getTracks() ?? []),
                ...(microphoneStream?.getTracks() ?? []),
                ...(displayStream?.getTracks() ?? []),
            ]);
            tracks.forEach((track) => track.stop());

            recordingStream = null;
            microphoneStream = null;
            displayStream = null;

            audioNodes.forEach((node) => node.disconnect());
            audioNodes = [];

            const context = audioContext;
            audioContext = null;

            if (context && context.state !== 'closed') {
                void context.close().catch(() => undefined);
            }
        },

        destroyRecorder() {
            captureAttempt += 1;
            this.stopLive();
            this.stopTimer();
            this.releaseCapture();
        },
    };

    // Bound once so add/removeEventListener see the same reference.
    state.handleDisplayTrackEnded = () => {
        state.stopMonitoringDisplayTracks();

        if (state.isRecording) {
            state.liveSupportLine = 'Screen sharing stopped';
            options.onToastError(
                'Screen sharing stopped, so the live recording was stopped.',
            );
            state.stopLive();

            return;
        }

        if (state.isRequesting) {
            captureAttempt += 1;
            state.releaseCapture();
            state.liveRequestState = 'idle';
            state.liveSupportLine = 'Screen sharing stopped';
            options.onToastError(
                'Screen sharing stopped before live recording could start. Please try again.',
            );
        }
    };

    return state;
}
