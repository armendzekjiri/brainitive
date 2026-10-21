import { useEffect, useRef } from 'react';

type BrainitiveExcalidrawWindow = { BrainitiveExcalidraw?: { init: (id: string, opts: object) => void } };

/**
 * Read-only Excalidraw canvas (view mode = pan and zoom, no editing). Loads the separate
 * Excalidraw bundle on first use. `id` must be unique per scene — change it (or the React
 * key) to show different content.
 */
export function ExcalidrawViewMode({ id, content, height = 500 }: { id: string; content: unknown; height?: number | string }) {
    const initializedRef = useRef(false);

    useEffect(() => {
        if (initializedRef.current) return;
        const c = (content ?? {}) as Record<string, unknown>;
        const elements = (c.elements ?? []) as object[];
        const appState = (c.appState ?? {}) as Record<string, unknown>;
        const files = (c.files ?? {}) as Record<string, unknown>;
        const w = window as unknown as BrainitiveExcalidrawWindow;

        const init = () => {
            if (!w.BrainitiveExcalidraw || initializedRef.current || !document.getElementById(id)) return;
            initializedRef.current = true;
            w.BrainitiveExcalidraw.init(id, {
                viewModeEnabled: true,
                zenModeEnabled: true,
                initialData: { elements, appState: { ...appState, viewModeEnabled: true }, files },
            });
        };

        if (w.BrainitiveExcalidraw) {
            init();
            return;
        }
        if (!document.getElementById('excalidraw-preview-css')) {
            const link = document.createElement('link');
            link.id = 'excalidraw-preview-css';
            link.rel = 'stylesheet';
            link.href = '/js/excalidraw-dist/excalidraw-bundle.css';
            document.head.appendChild(link);
        }
        if (!document.getElementById('excalidraw-preview-js')) {
            const script = document.createElement('script');
            script.id = 'excalidraw-preview-js';
            script.src = '/js/excalidraw-dist/excalidraw-bundle.iife.js';
            script.onload = init;
            document.head.appendChild(script);
            return;
        }
        // Script tag exists but may still be loading — poll briefly
        const poll = setInterval(() => {
            if (w.BrainitiveExcalidraw) { clearInterval(poll); init(); }
        }, 100);
        const stop = setTimeout(() => clearInterval(poll), 10000);
        return () => { clearInterval(poll); clearTimeout(stop); };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id]);

    return <div id={id} style={{ width: '100%', height }} />;
}
