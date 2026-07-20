import { ref } from 'vue';

export type WorkspaceToastType = 'success' | 'error';

export type WorkspaceToast = {
    id: number;
    type: WorkspaceToastType;
    message: string;
    leaving: boolean;
};

const toasts = ref<WorkspaceToast[]>([]);
let nextId = 1;

const dismiss = (id: number) => {
    const toast = toasts.value.find((item) => item.id === id);

    if (!toast) {
        return;
    }

    toast.leaving = true;
    window.setTimeout(() => {
        toasts.value = toasts.value.filter((item) => item.id !== id);
    }, 300);
};

const show = (type: WorkspaceToastType, message: string) => {
    const id = nextId++;
    toasts.value.push({ id, type, message, leaving: false });
    window.setTimeout(() => dismiss(id), 5000);
};

export const useWorkspaceToast = () => ({
    toasts,
    dismiss,
    success: (message: string) => show('success', message),
    error: (message: string) => show('error', message),
});
