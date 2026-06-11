import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/sass/app.scss',
                'resources/js/app.js',
                // React micro-app for the conversation Flow Builder.
                // Mounts on /c/{slug}/flows/{id}/editor only; the rest
                // of the admin keeps using vanilla Blade + Alpine.
                'resources/js/flow-editor/index.jsx',
            ],
            refresh: true,
        }),
        react({
            include: ['resources/js/flow-editor/**/*.{js,jsx}'],
        }),
    ],
});
