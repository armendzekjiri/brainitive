/**
 * Typing indicator (BRAI-151): "Erin is typing…" under a comment box. The signal carries no content —
 * `{}` while typing (at most every 2 s), `{stop: true}` once the comment was posted or dropped — and
 * the sender is set by the realtime server. Entries expire after a few seconds; you never see yourself.
 */
import type { LiveSender, PageProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useLiveSignal } from '@/lib/live';

const SEND_EVERY_MS = 2_000;
const EXPIRE_MS = 4_500;

interface TypingData {
    stop?: boolean;
}

export function useTyping(channel: string | null) {
    const me = usePage<PageProps>().props.auth?.user?.id;
    const [typists, setTypists] = useState<Map<number, { name: string; until: number }>>(new Map());
    const lastSent = useRef(0);

    const send = useLiveSignal<TypingData>(channel, 'typing', (data, from: LiveSender) => {
        if (from.id === me) return; // my other tabs
        setTypists((previous) => {
            const next = new Map(previous);
            if (data && typeof data === 'object' && data.stop === true) next.delete(from.id);
            else next.set(from.id, { name: String(from.name ?? '').slice(0, 80), until: Date.now() + EXPIRE_MS });
            return next;
        });
    });

    // expire people who stopped without saying so (closed the tab, went quiet)
    useEffect(() => {
        if (typists.size === 0) return;
        const timer = window.setInterval(() => {
            const now = Date.now();
            setTypists((previous) => {
                const next = new Map([...previous].filter(([, entry]) => entry.until > now));
                return next.size === previous.size ? previous : next;
            });
        }, 1_000);
        return () => window.clearInterval(timer);
    }, [typists.size]);

    useEffect(() => setTypists(new Map()), [channel]);

    /** Call on every keystroke in the comment box; throttled. */
    const typing = useCallback(() => {
        const now = Date.now();
        if (now - lastSent.current < SEND_EVERY_MS) return;
        if (send({})) lastSent.current = now;
    }, [send]);

    /** The comment was posted or discarded. */
    const stopped = useCallback(() => {
        if (lastSent.current === 0) return;
        lastSent.current = 0;
        send({ stop: true });
    }, [send]);

    return { names: [...typists.values()].map((entry) => entry.name), typing, stopped };
}

export function typingText(names: string[]): string | null {
    if (names.length === 0) return null;
    if (names.length === 1) return `${names[0]} is typing…`;
    if (names.length === 2) return `${names[0]} and ${names[1]} are typing…`;
    return `${names[0]} and ${names.length - 1} others are typing…`;
}

export function TypingIndicator({ names, className }: { names: string[]; className?: string }) {
    const text = typingText(names);
    return (
        <p className={className ?? 'min-h-[1.25rem] px-1 pt-1 text-xs text-muted-foreground'} aria-live="polite" data-testid="typing-indicator">
            {text && (
                <span className="inline-flex items-center gap-1.5">
                    <span className="inline-flex gap-0.5" aria-hidden>
                        <span className="size-1 animate-bounce rounded-full bg-muted-foreground/70 [animation-delay:-0.3s]" />
                        <span className="size-1 animate-bounce rounded-full bg-muted-foreground/70 [animation-delay:-0.15s]" />
                        <span className="size-1 animate-bounce rounded-full bg-muted-foreground/70" />
                    </span>
                    {text}
                </span>
            )}
        </p>
    );
}
