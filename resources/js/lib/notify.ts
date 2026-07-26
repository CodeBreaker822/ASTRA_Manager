export type NotificationType = 'success' | 'error' | 'info' | 'warning';

type FlashToast = {
    type?: NotificationType;
    message?: string;
};

type FlashPayload = {
    toast?: FlashToast;
    success?: string;
    error?: string;
    info?: string;
    warning?: string;
};

declare global {
    interface Window {
        showNotification?: (message: string, type?: NotificationType) => void;
    }
}

export function notify(message: string, type: NotificationType = 'success') {
    if (!message) {
        return;
    }

    if (typeof window.showNotification === 'function') {
        window.showNotification(message, type);

        return;
    }

    console[type === 'error' ? 'error' : 'log'](message);
}

export function notifyError(message: string) {
    notify(message, 'error');
}

export function notifyFlash(flash: unknown) {
    if (!flash || typeof flash !== 'object') {
        return;
    }

    const data = flash as FlashPayload;

    if (data.toast?.message) {
        notify(data.toast.message, data.toast.type ?? 'success');

        return;
    }

    (['success', 'error', 'warning', 'info'] as const).some((type) => {
        const message = data[type];

        if (message) {
            notify(message, type);

            return true;
        }

        return false;
    });
}
