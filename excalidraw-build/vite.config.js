import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: '../public/js/excalidraw-dist',
    emptyOutDir: true,
    lib: {
      entry: 'src/main.jsx',
      name: 'BrainitiveExcalidraw',
      fileName: 'excalidraw-bundle',
      formats: ['iife'],
    },
    rollupOptions: {
      output: {
        globals: {
          react: 'React',
          'react-dom': 'ReactDOM',
        },
      },
    },
  },
  define: {
    'process.env.NODE_ENV': '"production"',
    global: 'globalThis',
  },
});
