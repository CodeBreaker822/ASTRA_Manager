import { onUnmounted, ref, type Ref } from 'vue';

export const useTranscriptPolling = (
    hasWork: Ref<boolean>,
    refresh: () => Promise<void>,
) => {
    const lastUpdated = ref<string | null>(null);
    let pollTimer: number | null = null;

    const stopPolling = () => {
        if (pollTimer === null) {
            return;
        }

        window.clearInterval(pollTimer);
        pollTimer = null;
    };

    const tick = async () => {
        await refresh();
        lastUpdated.value = new Date().toISOString();

        if (!hasWork.value) {
            stopPolling();
            await refresh();
            lastUpdated.value = new Date().toISOString();
        }
    };

    const startPolling = () => {
        if (pollTimer !== null || !hasWork.value) {
            return;
        }

        pollTimer = window.setInterval(() => {
            void tick();
        }, 3000);
    };

    onUnmounted(stopPolling);

    return {
        lastUpdated,
        startPolling,
        stopPolling,
    };
};
