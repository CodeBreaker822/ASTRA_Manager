import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

const inertiaSsrEnabled = process.env.INERTIA_SSR_ENABLED === 'true';

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
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertiaSsrEnabled ? inertia() : inertia({ ssr: false }),
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
