/**
 * GlobalStickyNotes — system-level sticky notes, stored per user + URL.
 *
 * • Renders into document.body via createPortal (position: fixed)
 * • Fetches notes from /api/page-sticky-notes?url=...
 * • Re-fetches when Inertia navigates to a new page
 * • Listens for the 'page-sticky-note:create' custom event (dispatched by
 *   CommandPalette) to create a new note on the current page
 */

import axios from 'axios';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
} from 'react';
import { createPortal } from 'react-dom';
import { router } from '@inertiajs/react';

// ── Types ─────────────────────────────────────────────────────────────────────
type NoteColor = 'yellow' | 'pink';

export interface PageStickyNote {
    id: number;
    url: string;
    color: NoteColor;
    x: number;
    y: number;
    width: number;
    font_size: number;
    content: string | null;
}

// ── Constants ─────────────────────────────────────────────────────────────────
const MIN_WIDTH = 150;
const MAX_WIDTH = 600;
const MAX_FONT = 40;
const MIN_FONT = 10;
const SAVE_DELAY_MS = 800;

// ── Individual sticky note card ───────────────────────────────────────────────
function StickyCard({
    note,
    onUpdate,
    onDelete,
}: {
    note: PageStickyNote;
    onUpdate: (id: number, patch: Partial<Omit<PageStickyNote, 'id' | 'url'>>) => void;
    onDelete: (id: number) => void;
}) {
    const containerRef = useRef<HTMLDivElement>(null);
    const textRef = useRef<HTMLDivElement>(null);
    const posRef = useRef({ x: note.x, y: note.y, isDragging: false, offsetX: 0, offsetY: 0 });
    const resizeRef = useRef({ isResizing: false, startX: 0, startWidth: note.width });
    const saveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const [color, setColor] = useState<NoteColor>(note.color);
    const [fontSize, setFontSize] = useState(note.font_size);
    const [width, setWidth] = useState(note.width);
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    // Set initial text content once (don't re-run on re-renders to avoid caret reset)
    useEffect(() => {
        if (textRef.current) {
            // innerText correctly converts \n → visible line breaks
            textRef.current.innerText = note.content ?? '';
        }
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [note.id]);

    // Sync visual position reference
    useEffect(() => {
        posRef.current.x = note.x;
        posRef.current.y = note.y;
        if (containerRef.current) {
            containerRef.current.style.left = note.x + 'px';
            containerRef.current.style.top = note.y + 'px';
        }
    }, [note.x, note.y]);

    // ── Helpers ───────────────────────────────────────────────────────────────
    const scheduleSave = useCallback((patch: Partial<Omit<PageStickyNote, 'id' | 'url'>>) => {
        if (saveTimerRef.current) clearTimeout(saveTimerRef.current);
        saveTimerRef.current = setTimeout(() => onUpdate(note.id, patch), SAVE_DELAY_MS);
    }, [note.id, onUpdate]);

    const saveNow = useCallback((patch: Partial<Omit<PageStickyNote, 'id' | 'url'>>) => {
        if (saveTimerRef.current) clearTimeout(saveTimerRef.current);
        onUpdate(note.id, patch);
    }, [note.id, onUpdate]);

    // ── Drag ──────────────────────────────────────────────────────────────────
    const handleDragMove = useCallback((e: PointerEvent) => {
        const pos = posRef.current;
        if (!pos.isDragging) return;
        pos.x = e.clientX - pos.offsetX;
        pos.y = e.clientY - pos.offsetY;
        if (containerRef.current) {
            containerRef.current.style.left = pos.x + 'px';
            containerRef.current.style.top = pos.y + 'px';
        }
    }, []);

    const handleDragUp = useCallback((_e: PointerEvent) => {
        const pos = posRef.current;
        pos.isDragging = false;
        containerRef.current?.classList.remove('dragging');
        document.removeEventListener('pointermove', handleDragMove);
        document.removeEventListener('pointerup', handleDragUp);
        saveNow({ x: Math.round(pos.x), y: Math.round(pos.y) });
    }, [handleDragMove, saveNow]);

    const startDrag = useCallback((e: React.PointerEvent) => {
        if (e.button === 2) return; // ignore right-click
        const el = containerRef.current;
        if (!el) return;
        const rect = el.getBoundingClientRect();
        const pos = posRef.current;
        pos.offsetX = e.clientX - rect.left;
        pos.offsetY = e.clientY - rect.top;
        pos.isDragging = true;
        el.classList.add('dragging');
        document.addEventListener('pointermove', handleDragMove);
        document.addEventListener('pointerup', handleDragUp);
        e.preventDefault();
    }, [handleDragMove, handleDragUp]);

    // ── Resize ────────────────────────────────────────────────────────────────
    const handleResizeMove = useCallback((e: PointerEvent) => {
        const r = resizeRef.current;
        if (!r.isResizing) return;
        const newW = Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, r.startWidth + (e.clientX - r.startX)));
        setWidth(newW);
        if (containerRef.current) {
            (containerRef.current.querySelector('.gsn-note') as HTMLElement | null)
                ?.style.setProperty('width', newW + 'px');
        }
    }, []);

    const handleResizeUp = useCallback((e: PointerEvent) => {
        const r = resizeRef.current;
        r.isResizing = false;
        document.removeEventListener('pointermove', handleResizeMove);
        document.removeEventListener('pointerup', handleResizeUp);
        const newW = Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, r.startWidth + (e.clientX - r.startX)));
        const rounded = Math.round(newW);
        setWidth(rounded);
        saveNow({ width: rounded });
    }, [handleResizeMove, saveNow]);

    const startResize = useCallback((e: React.PointerEvent) => {
        e.stopPropagation();
        e.preventDefault();
        const noteEl = containerRef.current?.querySelector('.gsn-note') as HTMLElement | null;
        resizeRef.current = {
            isResizing: true,
            startX: e.clientX,
            startWidth: noteEl ? noteEl.offsetWidth : width,
        };
        document.addEventListener('pointermove', handleResizeMove);
        document.addEventListener('pointerup', handleResizeUp);
    }, [width, handleResizeMove, handleResizeUp]);

    // ── Font size ─────────────────────────────────────────────────────────────
    const changeFontSize = (delta: number) => {
        const next = Math.min(MAX_FONT, Math.max(MIN_FONT, fontSize + delta));
        setFontSize(next);
        saveNow({ font_size: next });
    };

    // ── Color ─────────────────────────────────────────────────────────────────
    const toggleColor = () => {
        const next: NoteColor = color === 'yellow' ? 'pink' : 'yellow';
        setColor(next);
        saveNow({ color: next });
    };

    // ── Content save ──────────────────────────────────────────────────────────
    const handleContentBlur = () => {
        const content = textRef.current?.innerText ?? '';
        saveNow({ content });
    };

    const handleContentInput = () => {
        const content = textRef.current?.innerText ?? '';
        scheduleSave({ content });
    };

    // Intercept Enter to insert <br> instead of letting the browser wrap in <div>
    const handleContentKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const sel = window.getSelection();
            if (!sel || !sel.rangeCount) return;
            const range = sel.getRangeAt(0);
            range.deleteContents();
            const br = document.createElement('br');
            range.insertNode(br);
            // Move caret after the <br>
            range.setStartAfter(br);
            range.collapse(true);
            sel.removeAllRanges();
            sel.addRange(range);
            handleContentInput();
        }
    };

    return (
        <div
            ref={containerRef}
            className="sticky-note-container"
            style={{ position: 'fixed', left: note.x, top: note.y, zIndex: 9999 }}
        >
            <div
                className={`sticky-note gsn-note ${color}`}
                style={{
                    width,
                    ['--sticky-font-size' as string]: `${fontSize}px`,
                }}
                onPointerDown={(e) => {
                    // Only drag when clicking directly on the note background (not toolbar or text)
                    const target = e.target as HTMLElement;
                    if (
                        target.closest('.sticky-toolbar') ||
                        target.closest('.gsn-text') ||
                        target.closest('.sticky-resize-handle')
                    ) return;
                    startDrag(e);
                }}
            >
                {/* Toolbar */}
                <div className="sticky-toolbar">
                    {confirmingDelete ? (
                        <>
                            <span style={{ fontSize: 11, fontFamily: 'Inter,sans-serif', opacity: 0.75, flexShrink: 0 }}>
                                Delete forever?
                            </span>
                            <button
                                onPointerDown={(e) => e.stopPropagation()}
                                onClick={() => onDelete(note.id)}
                                className="sticky-font-btn"
                                style={{ color: '#c0392b', borderColor: '#c0392b', marginLeft: 'auto' }}
                            >
                                Yes, delete
                            </button>
                            <button
                                onPointerDown={(e) => e.stopPropagation()}
                                onClick={() => setConfirmingDelete(false)}
                                className="sticky-font-btn"
                            >
                                Cancel
                            </button>
                        </>
                    ) : (
                        <>
                            <button
                                onPointerDown={(e) => e.stopPropagation()}
                                onClick={() => changeFontSize(-2)}
                                className="sticky-font-btn"
                                title="Decrease font size"
                            >
                                A−
                            </button>
                            <span className="sticky-font-size">{fontSize}</span>
                            <button
                                onPointerDown={(e) => e.stopPropagation()}
                                onClick={() => changeFontSize(2)}
                                className="sticky-font-btn"
                                title="Increase font size"
                            >
                                A+
                            </button>
                            <button
                                onPointerDown={(e) => e.stopPropagation()}
                                onClick={toggleColor}
                                className="sticky-color-btn"
                                title="Toggle color"
                            >
                                🎨
                            </button>
                            <button
                                onPointerDown={(e) => e.stopPropagation()}
                                onClick={() => setConfirmingDelete(true)}
                                className="sticky-delete-btn"
                                title="Delete"
                            >
                                ✕
                            </button>
                        </>
                    )}
                </div>

                {/* Text area */}
                <div style={{ position: 'relative' }}>
                    <div
                        ref={textRef}
                        className="gsn-text StickyNode__contentEditable"
                        contentEditable
                        suppressContentEditableWarning
                        onInput={handleContentInput}
                        onBlur={handleContentBlur}
                        onKeyDown={handleContentKeyDown}
                        onPointerDown={(e) => e.stopPropagation()}
                        data-placeholder="What's up?"
                    />
                </div>

                {/* Resize handle */}
                <div
                    className="sticky-resize-handle"
                    title="Drag to resize"
                    onPointerDown={startResize}
                />
            </div>
        </div>
    );
}

// ── GlobalStickyNotes container ───────────────────────────────────────────────
export function GlobalStickyNotes() {
    const [notes, setNotes] = useState<PageStickyNote[]>([]);
    const currentUrlRef = useRef('');

    const currentUrl = () =>
        window.location.pathname + (window.location.search ? window.location.search : '');

    // Fetch all notes for the current URL
    const fetchNotes = useCallback(async (url: string) => {
        try {
            const res = await axios.get<PageStickyNote[]>('/api/page-sticky-notes', {
                params: { url },
            });
            setNotes(res.data);
        } catch {
            // silently fail (e.g. unauthenticated)
        }
    }, []);

    // Initial fetch + re-fetch on Inertia navigation
    useEffect(() => {
        const url = currentUrl();
        currentUrlRef.current = url;
        fetchNotes(url);

        const unsubscribe = router.on('navigate', () => {
            const nextUrl = currentUrl();
            currentUrlRef.current = nextUrl;
            fetchNotes(nextUrl);
        });

        return () => unsubscribe();
    }, [fetchNotes]);

    // Listen for the create command from CommandPalette
    useEffect(() => {
        const handleCreate = async () => {
            const url = currentUrlRef.current;
            // Place new note near top-center-ish, offset so multiple notes don't stack
            const x = Math.max(60, Math.round(window.innerWidth / 2 - 110 + notes.length * 20));
            const y = Math.max(80, 80 + notes.length * 20);
            try {
                const res = await axios.post<PageStickyNote>('/api/page-sticky-notes', {
                    url,
                    x,
                    y,
                    width: 220,
                    font_size: 20,
                    color: 'yellow',
                    content: '',
                });
                setNotes((prev) => [...prev, res.data]);
            } catch {
                // silently fail
            }
        };

        window.addEventListener('page-sticky-note:create', handleCreate);
        return () => window.removeEventListener('page-sticky-note:create', handleCreate);
    }, [notes.length]);

    // Update a note (optimistic + API call with debounce handled upstream)
    const handleUpdate = useCallback(
        async (id: number, patch: Partial<Omit<PageStickyNote, 'id' | 'url'>>) => {
            // Optimistic update
            setNotes((prev) =>
                prev.map((n) => (n.id === id ? { ...n, ...patch } : n)),
            );
            try {
                await axios.patch(`/api/page-sticky-notes/${id}`, patch);
            } catch {
                // If save fails, re-fetch to get correct state
                fetchNotes(currentUrlRef.current);
            }
        },
        [fetchNotes],
    );

    // Delete a note
    const handleDelete = useCallback(
        async (id: number) => {
            setNotes((prev) => prev.filter((n) => n.id !== id));
            try {
                await axios.delete(`/api/page-sticky-notes/${id}`);
            } catch {
                fetchNotes(currentUrlRef.current);
            }
        },
        [fetchNotes],
    );

    if (notes.length === 0) return null;

    return createPortal(
        <>
            {notes.map((note) => (
                <StickyCard
                    key={note.id}
                    note={note}
                    onUpdate={handleUpdate}
                    onDelete={handleDelete}
                />
            ))}
        </>,
        document.body,
    );
}
