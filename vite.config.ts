import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    build: {
        rolldownOptions: {
            onwarn(warning: { code?: string; id?: string; message?: string }, defaultHandler: (warning: unknown) => void) {
                const warningText = `${warning.id || ''} ${warning.message || ''} ${JSON.stringify(warning)}`;

                if (
                    warning.code === 'INVALID_ANNOTATION' &&
                    warningText.includes('node_modules/reka-ui/node_modules/@vueuse/core')
                ) {
                    return;
                }

                defaultHandler(warning);
            },
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
});
