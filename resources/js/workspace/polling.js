/**
 * Polls the workspace status endpoint while a transcript is still being worked
 * on. Stops and reports through onError after repeated failures rather than
 * retrying silently forever.
 */
const POLL_MS = 3000;
const MAX_CONSECUTIVE_FAILURES = 5;

export function createPolling({ hasWork, refresh, onError }) {
    let timer = null;
    let inFlight = false;
    let failures = 0;

    const stop = () => {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    };

    const tick = async () => {
        if (inFlight) {
            return;
        }

        inFlight = true;

        try {
            await refresh();
            failures = 0;
        } catch {
            failures += 1;

            if (failures >= MAX_CONSECUTIVE_FAILURES) {
                stop();
                onError?.(
                    'Lost contact with the server while checking progress. Refresh to try again.',
                );
            }

            return;
        } finally {
            inFlight = false;
        }

        if (!hasWork()) {
            stop();

            // One last refresh so the finished transcript is on screen.
            try {
                await refresh();
            } catch {
                // The work already finished; a failed final read is not fatal.
            }
        }
    };

    return {
        start() {
            if (timer !== null || !hasWork()) {
                return;
            }

            failures = 0;
            timer = window.setInterval(() => void tick(), POLL_MS);
        },
        stop,
    };
}
