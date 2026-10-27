import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * Excalidraw loads its fonts from window.EXCALIDRAW_ASSET_PATH and otherwise from a public CDN — a
 * third-party request with the visitor's IP (BRAI-156). Ship the fonts with our own build:
 * public/build/excalidraw-assets/fonts/…, set as EXCALIDRAW_ASSET_PATH in app.blade.php and
 * excalidraw-editor.blade.php. (The fonts are OFL-licensed; licences ship inside the package.)
 */
function excalidrawFonts() {
    return {
        name: 'brainitive-excalidraw-fonts',
        apply: 'build',
        closeBundle() {
            const from = path.resolve(__dirname, 'node_modules/@excalidraw/excalidraw/dist/prod/fonts');
            const to = path.resolve(__dirname, 'public/build/excalidraw-assets/fonts');
            if (fs.existsSync(from)) {
                fs.cpSync(from, to, { recursive: true });
            }
        },
    };
}

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
        excalidrawFonts(),
    ],
});
