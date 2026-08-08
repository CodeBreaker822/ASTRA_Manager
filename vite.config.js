import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/blade.js',
                'resources/js/dashboard-api.js',
                'resources/js/workspace/index.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
