import path from 'path';
import { fileURLToPath } from 'url';
import { defineConfig } from 'vite';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * Builds the helpdesk widget to public/js/helpdesk-widget.js
 * Run: npm run build:widget  (or vite build --config vite.widget.config.js)
 */
export default defineConfig({
    build: {
        outDir: 'public/js',
        emptyOutDir: false,
        lib: {
            entry: path.resolve(__dirname, 'resources/js/widget/helpdesk-widget.ts'),
            name: 'HelpdeskWidget',
            fileName: () => 'helpdesk-widget.js',
            formats: ['iife'],
        },
        rollupOptions: {
            output: {
                inlineDynamicImports: true,
            },
        },
    },
});
