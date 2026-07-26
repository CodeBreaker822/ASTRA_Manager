import { router } from '@inertiajs/vue3';
import { notifyFlash } from '@/lib/notify';

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;

        notifyFlash(flash);
    });

    router.on('success', (event) => {
        const flash = (event as CustomEvent).detail?.page?.props?.flash;

        notifyFlash(flash);
    });

    router.on('error', (event) => {
        const errors = (event as CustomEvent).detail?.errors;
        const firstError = Object.values(
            errors && typeof errors === 'object' ? errors : {},
        )
            .flat()
            .at(0);

        if (typeof firstError === 'string') {
            notifyFlash({ error: firstError });
        }
    });
}
