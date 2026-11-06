import { useCallback, useEffect, useRef, useState } from 'react';
import type React from 'react';

/**
 * Drag-to-resize columns (board Timeline + List). Widths are a personal convenience, so they
 * live in localStorage under `storageKey` (written on release / key / reset, never per move).
 * The Gantt plan's splitter (PlanChart) is the document-backed variant: its width is shared plan
 * state and goes through the plan editor instead.
 */
export interface ColumnSpec {
    min: number;
    /** Upper bound; a function is read when it's needed (e.g. a share of the window). */
    max?: number | (() => number);
    /** `null` = auto: the column takes the spare space until it's resized. */
    default: number | null;
}

const KEY_STEP = 16;
const KEY_STEP_LARGE = 64;

function readStored(storageKey: string | null): Record<string, number> {
    if (!storageKey) return {};
    try {
        const parsed = JSON.parse(window.localStorage.getItem(storageKey) ?? '{}');
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return {};
        return Object.fromEntries(Object.entries(parsed).filter(([, v]) => typeof v === 'number' && Number.isFinite(v))) as Record<string, number>;
    } catch {
        return {};
    }
}

function writeStored(storageKey: string | null, widths: Record<string, number>) {
    if (!storageKey) return;
    try { window.localStorage.setItem(storageKey, JSON.stringify(widths)); } catch { /* private mode */ }
}

const maxOf = (spec: ColumnSpec | undefined) => {
    const max = typeof spec?.max === 'function' ? spec.max() : spec?.max;
    return Math.max(spec?.min ?? 0, max ?? Infinity);
};

export function clampWidth(spec: ColumnSpec | undefined, width: number): number {
    return Math.round(Math.min(maxOf(spec), Math.max(spec?.min ?? 0, width)));
}

export function useResizableColumns(storageKey: string | null, specs: Record<string, ColumnSpec>) {
    // Only what the person resized is stored; everything else follows the spec's default
    const [custom, setCustom] = useState<Record<string, number>>(() => readStored(storageKey));
    const [measured, setMeasured] = useState<Record<string, number>>({});
    const specsRef = useRef(specs);
    specsRef.current = specs;
    const customRef = useRef(custom);
    customRef.current = custom;

    // Another board (same component instance) → its own widths
    const loadedKey = useRef(storageKey);
    useEffect(() => {
        if (loadedKey.current === storageKey) return;
        loadedKey.current = storageKey;
        setCustom(readStored(storageKey));
    }, [storageKey]);

    /** The width in px, or null for an auto column nobody resized. */
    const widthOf = useCallback((key: string): number | null => {
        const spec = specs[key];
        const value = custom[key] ?? spec?.default ?? null;
        return value === null ? null : clampWidth(spec, value);
    }, [specs, custom]);

    const commit = useCallback((next: Record<string, number>) => {
        setCustom(next);
        writeStored(storageKey, next);
    }, [storageKey]);

    const setWidth = useCallback((key: string, width: number, persist = true) => {
        const next = { ...customRef.current, [key]: clampWidth(specsRef.current[key], width) };
        customRef.current = next;
        if (persist) commit(next); else setCustom(next);
    }, [commit]);

    const reset = useCallback((key: string) => {
        const next = { ...customRef.current };
        delete next[key];
        customRef.current = next;
        commit(next);
    }, [commit]);

    // The handle sits inside the column's header cell: an auto column starts from what it renders at
    const currentWidth = (key: string, handle: HTMLElement): number =>
        customRef.current[key] ?? specsRef.current[key]?.default ?? handle.parentElement?.getBoundingClientRect().width ?? specsRef.current[key]?.min ?? 0;

    const drag = useRef<{ key: string; startX: number; startWidth: number; frame: number; lastX: number } | null>(null);

    useEffect(() => () => { if (drag.current?.frame) cancelAnimationFrame(drag.current.frame); }, []);

    const handleProps = (key: string, label: string) => {
        const spec = specs[key];
        const now = widthOf(key) ?? measured[key];
        const max = maxOf(spec);
        return {
            role: 'separator',
            'aria-orientation': 'vertical' as const,
            'aria-label': `Resize ${label} column`,
            'aria-valuenow': now === undefined ? undefined : Math.round(now),
            'aria-valuemin': spec?.min,
            'aria-valuemax': Number.isFinite(max) ? Math.round(max) : undefined,
            tabIndex: 0,
            title: 'Drag to resize · double-click to reset',
            'data-resize-handle': key,
            draggable: false,
            onFocus: (e: React.FocusEvent<HTMLElement>) => {
                if (widthOf(key) === null) {
                    const width = Math.round(currentWidth(key, e.currentTarget));
                    setMeasured((m) => (m[key] === width ? m : { ...m, [key]: width }));
                }
            },
            onClick: (e: React.MouseEvent) => e.stopPropagation(),
            onDoubleClick: (e: React.MouseEvent) => { e.preventDefault(); e.stopPropagation(); reset(key); },
            onDragStart: (e: React.DragEvent) => { e.preventDefault(); e.stopPropagation(); },
            onKeyDown: (e: React.KeyboardEvent<HTMLElement>) => {
                if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
                e.preventDefault();
                e.stopPropagation();
                const step = (e.shiftKey ? KEY_STEP_LARGE : KEY_STEP) * (e.key === 'ArrowLeft' ? -1 : 1);
                setWidth(key, currentWidth(key, e.currentTarget) + step);
            },
            onPointerDown: (e: React.PointerEvent<HTMLElement>) => {
                if (e.button !== 0) return;
                e.preventDefault();
                e.stopPropagation();
                const handle = e.currentTarget;
                const startWidth = currentWidth(key, handle);
                drag.current = { key, startX: e.clientX, startWidth, frame: 0, lastX: e.clientX };
                try { handle.setPointerCapture(e.pointerId); } catch { /* synthetic events */ }
                const previousCursor = document.body.style.cursor;
                document.body.style.cursor = 'col-resize';
                document.body.style.userSelect = 'none';

                const onMove = (event: PointerEvent) => {
                    const d = drag.current;
                    if (!d) return;
                    d.lastX = event.clientX;
                    if (d.frame) return;
                    d.frame = requestAnimationFrame(() => {
                        d.frame = 0;
                        setWidth(d.key, d.startWidth + d.lastX - d.startX, false);
                    });
                };
                const onUp = (event: PointerEvent) => {
                    const d = drag.current;
                    window.removeEventListener('pointermove', onMove);
                    window.removeEventListener('pointerup', onUp);
                    window.removeEventListener('pointercancel', onUp);
                    document.body.style.cursor = previousCursor;
                    document.body.style.userSelect = '';
                    drag.current = null;
                    if (!d) return;
                    if (d.frame) cancelAnimationFrame(d.frame);
                    if (event.type === 'pointerup') d.lastX = event.clientX;
                    // A plain click (no movement) doesn't turn an auto column into a fixed one
                    if (d.lastX !== d.startX) setWidth(d.key, d.startWidth + d.lastX - d.startX);
                };
                window.addEventListener('pointermove', onMove);
                window.addEventListener('pointerup', onUp);
                window.addEventListener('pointercancel', onUp);
            },
        };
    };

    return { widthOf, setWidth, reset, handleProps };
}
