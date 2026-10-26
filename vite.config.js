import path from 'path';
import { fileURLToPath } from 'url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
            '@/components': path.resolve(__dirname, 'resources/js/components'),
        },
    },
    plugins: [
        laravel({
            // whiteboard-live: the whiteboard editor's live drawing (BRAI-153, excalidraw-editor.blade.php)
            input: ['resources/js/app.tsx', 'resources/js/whiteboard-live.ts'],
            refresh: true,
        }),
        react(),
    ],
});
