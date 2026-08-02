import { onMounted, ref } from 'vue';

const CAPTURE_SCREEN_AUDIO_KEY = 'jerva.capture-screen-audio';

const captureScreenAudio = ref(false);
let hasLoadedPreference = false;

const loadPreference = () => {
    if (hasLoadedPreference || typeof window === 'undefined') {
        return;
    }

    hasLoadedPreference = true;

    try {
        captureScreenAudio.value =
            window.localStorage.getItem(CAPTURE_SCREEN_AUDIO_KEY) === 'true';
    } catch {
        captureScreenAudio.value = false;
    }
};

export function useRecordingSettings() {
    onMounted(loadPreference);

    const updateCaptureScreenAudio = (enabled: boolean) => {
        captureScreenAudio.value = enabled;

        if (typeof window === 'undefined') {
            return;
        }

        try {
            window.localStorage.setItem(
                CAPTURE_SCREEN_AUDIO_KEY,
                String(enabled),
            );
        } catch {
            // Keep the preference for this page when browser storage is blocked.
        }
    };

    return {
        captureScreenAudio,
        updateCaptureScreenAudio,
    };
}
