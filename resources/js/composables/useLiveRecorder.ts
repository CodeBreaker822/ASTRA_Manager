import { computed, onUnmounted, ref } from 'vue';
import type { Transcript } from '@/types/workspace';

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
    captureScreenAudio: () => boolean;
    onTranscript: (transcript: Transcript) => void;
    onQueued: () => void;
    onUpgrade: (message: string) => void;
    onToastError: (message: string) => void;
}) => {
    const recorder = ref<MediaRecorder | null>(null);
    const startedAt = ref<number | null>(null);
    const segmentStartedAt = ref<number | null>(null);
    const elapsedMs = ref(0);
    const supportLine = ref('Ready');
    const requestState = ref<
        'idle' | 'requesting' | 'recording' | 'blocked' | 'unsupported'
    >('idle');
    const clips = ref<LiveClip[]>([]);
    const timer = ref<number | null>(null);
    const pendingSends = ref(0);
    const captureSourceLabel = ref('Microphone');

    let microphoneStream: MediaStream | null = null;
    let displayStream: MediaStream | null = null;
    let recordingStream: MediaStream | null = null;
    let audioContext: AudioContext | null = null;
    let audioNodes: AudioNode[] = [];
    let monitoredDisplayTracks: MediaStreamTrack[] = [];
    let captureAttempt = 0;

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

        return isRecording.value
            ? `Recording - ${captureSourceLabel.value}`
            : 'Ready';
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
            return supportLine.value === 'Requesting screen audio'
                ? 'Choose audio to share'
                : 'Requesting microphone';
        }

        return isRecording.value ? 'Stop recording' : 'Ready to capture';
    });

    const toggle = async () => {
        if (isRecording.value) {
            stop();

            return;
        }

        if (isRequesting.value) {
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

        const includeScreenAudio = options.captureScreenAudio();
        const mediaDevices = navigator.mediaDevices;

        if (
            !mediaDevices?.getUserMedia ||
            typeof MediaRecorder === 'undefined'
        ) {
            requestState.value = 'unsupported';
            supportLine.value = 'Start failed';
            options.onToastError(
                'Live recording could not start. Please try again.',
            );

            return;
        }

        if (
            includeScreenAudio &&
            typeof mediaDevices.getDisplayMedia !== 'function'
        ) {
            requestState.value = 'idle';
            supportLine.value = 'Screen audio unsupported';
            options.onToastError(
                'Screen audio capture is not supported by this browser. Turn it off in Recording settings to use microphone-only capture.',
            );

            return;
        }

        releaseCaptureResources();
        const attempt = ++captureAttempt;
        let stage: 'display' | 'microphone' | 'mixing' | 'recorder' =
            includeScreenAudio ? 'display' : 'microphone';

        requestState.value = 'requesting';
        supportLine.value = includeScreenAudio
            ? 'Requesting screen audio'
            : 'Requesting microphone';

        try {
            if (includeScreenAudio) {
                const displayOptions: DisplayMediaStreamOptions & {
                    systemAudio: 'include';
                } = {
                    audio: true,
                    systemAudio: 'include',
                    video: true,
                };
                const selectedDisplayStream =
                    await mediaDevices.getDisplayMedia(displayOptions);

                if (attempt !== captureAttempt) {
                    stopMediaStream(selectedDisplayStream);

                    return;
                }

                displayStream = selectedDisplayStream;

                if (!hasLiveAudioTrack(selectedDisplayStream)) {
                    captureAttempt += 1;
                    releaseCaptureResources();
                    requestState.value = 'idle';
                    supportLine.value = 'No screen audio shared';
                    options.onToastError(
                        'No screen audio was shared. Choose a browser tab, window, or screen with audio and enable Share audio in the picker.',
                    );

                    return;
                }

                monitorDisplayTracks(selectedDisplayStream);
                stage = 'microphone';
                supportLine.value = 'Requesting microphone';
            }

            const selectedMicrophoneStream = await mediaDevices.getUserMedia({
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
                ? await createMixedAudioStream(
                      selectedMicrophoneStream,
                      displayStream as MediaStream,
                  )
                : selectedMicrophoneStream;

            if (attempt !== captureAttempt) {
                releaseCaptureResources();

                return;
            }

            if (!hasLiveAudioTrack(recordingStream)) {
                throw new Error(
                    'The recording stream has no live audio track.',
                );
            }

            stage = 'recorder';
            const mediaRecorder = new MediaRecorder(recordingStream, {
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

            const finishRecording = () => {
                if (recorder.value !== mediaRecorder) {
                    return;
                }

                captureAttempt += 1;
                stopTimer();
                releaseCaptureResources();
                recorder.value = null;
                startedAt.value = null;
                segmentStartedAt.value = null;
                elapsedMs.value = 0;
                requestState.value =
                    requestState.value === 'blocked' ? 'blocked' : 'idle';
                supportLine.value =
                    pendingSends.value > 0 ? 'Processing' : 'Ready';
            };

            mediaRecorder.onstop = finishRecording;
            mediaRecorder.onerror = () => {
                supportLine.value = 'Recording failed';
                options.onToastError(
                    'Live recording stopped because the browser could not continue capturing audio.',
                );

                if (mediaRecorder.state !== 'inactive') {
                    mediaRecorder.stop();

                    return;
                }

                finishRecording();
            };

            recorder.value = mediaRecorder;
            startedAt.value = Date.now();
            segmentStartedAt.value = startedAt.value;
            elapsedMs.value = 0;
            requestState.value = 'recording';
            captureSourceLabel.value = includeScreenAudio
                ? 'Mic + screen audio'
                : 'Microphone';
            supportLine.value = captureSourceLabel.value;
            mediaRecorder.start(SEGMENT_MS);
            startTimer();
        } catch (error) {
            if (attempt !== captureAttempt) {
                return;
            }

            captureAttempt += 1;
            releaseCaptureResources();
            recorder.value = null;

            if (stage === 'display') {
                requestState.value = 'idle';
                supportLine.value = 'Screen share cancelled';
                options.onToastError(
                    isScreenSelectionCancelled(error)
                        ? 'Screen sharing was cancelled. Start live recording again when you are ready to choose an audio source.'
                        : 'Screen audio could not be captured. Please try again or turn it off in Recording settings.',
                );

                return;
            }

            if (stage === 'microphone') {
                const blocked = isPermissionError(error);
                requestState.value = blocked ? 'blocked' : 'idle';
                supportLine.value = blocked
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
                requestState.value = 'idle';
                supportLine.value = 'Screen audio mix failed';
                options.onToastError(
                    'Screen and microphone audio could not be mixed. Please try again or turn off screen audio in Recording settings.',
                );

                return;
            }

            requestState.value = 'unsupported';
            supportLine.value = 'Start failed';
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

                throw new Error('Audio upload could not be processed.');
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

    const createMixedAudioStream = async (
        microphone: MediaStream,
        display: MediaStream,
    ): Promise<MediaStream> => {
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

        const connectInput = (input: MediaStream) => {
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
    };

    const monitorDisplayTracks = (capturedDisplay: MediaStream) => {
        stopMonitoringDisplayTracks();
        monitoredDisplayTracks = capturedDisplay.getTracks();
        monitoredDisplayTracks.forEach((track) => {
            track.addEventListener('ended', handleDisplayTrackEnded);
        });
    };

    const stopMonitoringDisplayTracks = () => {
        monitoredDisplayTracks.forEach((track) => {
            track.removeEventListener('ended', handleDisplayTrackEnded);
        });
        monitoredDisplayTracks = [];
    };

    const handleDisplayTrackEnded = () => {
        stopMonitoringDisplayTracks();

        if (isRecording.value) {
            supportLine.value = 'Screen sharing stopped';
            options.onToastError(
                'Screen sharing stopped, so the live recording was stopped.',
            );
            stop();

            return;
        }

        if (isRequesting.value) {
            captureAttempt += 1;
            releaseCaptureResources();
            requestState.value = 'idle';
            supportLine.value = 'Screen sharing stopped';
            options.onToastError(
                'Screen sharing stopped before live recording could start. Please try again.',
            );
        }
    };

    const releaseCaptureResources = () => {
        stopMonitoringDisplayTracks();

        const tracks = new Set<MediaStreamTrack>([
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
    };

    onUnmounted(() => {
        captureAttempt += 1;
        stop();
        stopTimer();
        releaseCaptureResources();
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

export type LiveRecorderController = ReturnType<typeof useLiveRecorder>;

const hasLiveAudioTrack = (stream: MediaStream | null): stream is MediaStream =>
    Boolean(
        stream?.getAudioTracks().some((track) => track.readyState === 'live'),
    );

const stopMediaStream = (stream: MediaStream) => {
    stream.getTracks().forEach((track) => track.stop());
};

const isPermissionError = (error: unknown) =>
    error instanceof DOMException &&
    ['NotAllowedError', 'PermissionDeniedError', 'SecurityError'].includes(
        error.name,
    );

const isScreenSelectionCancelled = (error: unknown) =>
    isPermissionError(error) ||
    (error instanceof DOMException && error.name === 'AbortError');

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
