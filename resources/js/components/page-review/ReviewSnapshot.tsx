import { Button } from '@/components/ui/button';
import { safeHtml } from '@/lib/sanitize';
import type { PageReviewThread } from '@/types';
import { MessageSquarePlus } from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { highlight, selectionAnchor, type QuoteSelection } from './anchors';

// Tailwind only scans .tsx files, so the mark classes live here (not in anchors.ts)
const MARK_CLASS = 'rounded-sm bg-amber-200/70 text-inherit cursor-pointer transition-colors hover:bg-amber-300/80 dark:bg-amber-400/25 dark:hover:bg-amber-400/40';
const ACTIVE_MARK_CLASSES = ['ring-2', 'ring-amber-500/70', 'bg-amber-300/90', 'dark:bg-amber-400/50'];

interface Props {
    html: string;
    /** open, anchored threads to highlight */
    threads: PageReviewThread[];
    activeThread: string | null;
    /** scroll the active thread's text into view (false when the highlight itself was clicked) */
    scrollToActive?: boolean;
    onSelectThread: (uuid: string) => void;
    /** null when the viewer can't comment */
    onComment: ((selection: QuoteSelection) => void) | null;
    /** threads whose quote this rendering couldn't place */
    onUnplaced?: (uuids: Set<string>) => void;
}

/**
 * The review's snapshot, read-only (BRAI-80). Selecting text offers "Comment"; anchored threads are
 * highlighted by text quote. The HTML is sanitized and rendered into its own element — the stored
 * page content is never changed.
 */
export default function ReviewSnapshot({ html, threads, activeThread, scrollToActive = true, onSelectThread, onComment, onUnplaced }: Props) {
    const wrapperRef = useRef<HTMLDivElement>(null);
    const contentRef = useRef<HTMLDivElement>(null);
    const [pending, setPending] = useState<{ selection: QuoteSelection; top: number; left: number } | null>(null);

    const anchorKey = threads.map((t) => `${t.uuid}:${t.quote}`).join('|');

    // Render the snapshot and its highlights (re-run when the threads change)
    useLayoutEffect(() => {
        const root = contentRef.current;
        if (!root) return;
        root.innerHTML = safeHtml(html);
        const anchors = threads
            .filter((t) => t.quote && !t.outdated)
            .map((t) => ({ uuid: t.uuid, quote: t.quote!, prefix: t.prefix, suffix: t.suffix }));
        const missing = highlight(root, anchors, MARK_CLASS);
        onUnplaced?.(missing);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [html, anchorKey]);

    // Emphasize the active thread's text
    useEffect(() => {
        const root = contentRef.current;
        if (!root) return;
        root.querySelectorAll<HTMLElement>('mark[data-thread]').forEach((mark) => {
            const active = mark.dataset.thread === activeThread;
            ACTIVE_MARK_CLASSES.forEach((c) => mark.classList.toggle(c, active));
        });
        if (activeThread && scrollToActive) {
            root.querySelector<HTMLElement>(`mark[data-thread="${CSS.escape(activeThread)}"]`)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }, [activeThread, anchorKey]);

    // Offer "Comment" for a selection inside the snapshot (mouse and touch: selectionchange)
    const readSelection = useCallback(() => {
        const root = contentRef.current;
        const wrapper = wrapperRef.current;
        if (!root || !wrapper || !onComment) return;
        const selection = window.getSelection();
        const anchor = selectionAnchor(root, selection);
        if (!anchor || !selection) {
            setPending(null);
            return;
        }
        const rect = selection.getRangeAt(0).getBoundingClientRect();
        const box = wrapper.getBoundingClientRect();
        setPending({
            selection: anchor,
            top: rect.bottom - box.top + 6,
            left: Math.min(Math.max(rect.left - box.left + rect.width / 2, 60), box.width - 60),
        });
    }, [onComment]);

    useEffect(() => {
        if (!onComment) return;
        let timer: ReturnType<typeof setTimeout> | undefined;
        const onChange = () => {
            clearTimeout(timer);
            timer = setTimeout(readSelection, 120);
        };
        document.addEventListener('selectionchange', onChange);
        return () => {
            clearTimeout(timer);
            document.removeEventListener('selectionchange', onChange);
        };
    }, [onComment, readSelection]);

    return (
        <div ref={wrapperRef} className="relative">
            <div
                ref={contentRef}
                data-testid="review-snapshot"
                className="lexical-editor-content lexical-doc rich-html break-words"
                onClick={(e) => {
                    const mark = (e.target as HTMLElement).closest<HTMLElement>('mark[data-thread]');
                    if (mark?.dataset.thread) onSelectThread(mark.dataset.thread);
                }}
            />
            {pending && onComment && (
                <div className="absolute z-20 -translate-x-1/2" style={{ top: pending.top, left: pending.left }}>
                    <Button
                        size="sm"
                        className="h-8 shadow-lg"
                        onMouseDown={(e) => e.preventDefault() /* keep the selection */}
                        onClick={() => {
                            onComment(pending.selection);
                            setPending(null);
                            window.getSelection()?.removeAllRanges();
                        }}
                    >
                        <MessageSquarePlus className="size-4 mr-1.5" />
                        Comment
                    </Button>
                </div>
            )}
        </div>
    );
}
