import { notify } from '@/lib/notify';

export type WorkspaceToastType = 'success' | 'error';

export const useWorkspaceToast = () => ({
    success: (message: string) => notify(message, 'success'),
    error: (message: string) => notify(message, 'error'),
});
