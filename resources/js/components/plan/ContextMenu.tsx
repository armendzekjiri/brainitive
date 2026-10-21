import { ReactNode, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export interface ContextMenuItem {
    label: string;
    icon?: ReactNode;
    shortcut?: string;
    onSelect: () => void;
    destructive?: boolean;
    disabled?: boolean;
}

export type ContextMenuEntry = ContextMenuItem | 'separator' | { custom: ReactNode };

/**
 * Right-click menu at the pointer (stays inside the viewport). Closes on outside click,
 * Escape, scroll or after an item is chosen.
 */
export function ContextMenu({ x, y, entries, onClose }: { x: number; y: number; entries: ContextMenuEntry[]; onClose: () => void }) {
    const ref = useRef<HTMLDivElement>(null);
    const [position, setPosition] = useState({ left: x, top: y });

    useLayoutEffect(() => {
        const el = ref.current;
        if (!el) return;
        const { width, height } = el.getBoundingClientRect();
        setPosition({
            left: Math.min(x, window.innerWidth - width - 8),
            top: Math.min(y, window.innerHeight - height - 8),
        });
    }, [x, y]);

    useEffect(() => {
        const onPointer = (event: PointerEvent) => {
            if (!ref.current?.contains(event.target as Node)) onClose();
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
        };
        window.addEventListener('pointerdown', onPointer, true);
        window.addEventListener('keydown', onKey, true);
        window.addEventListener('scroll', onClose, true);
        window.addEventListener('resize', onClose);
        return () => {
            window.removeEventListener('pointerdown', onPointer, true);
            window.removeEventListener('keydown', onKey, true);
            window.removeEventListener('scroll', onClose, true);
            window.removeEventListener('resize', onClose);
        };
    }, [onClose]);

    return (
        <div
            ref={ref}
            role="menu"
            className="fixed z-[60] min-w-[210px] rounded-md border bg-popover p-1 text-popover-foreground shadow-lg animate-in fade-in-0 zoom-in-95"
            style={position}
            onContextMenu={(e) => e.preventDefault()}
        >
            {entries.map((entry, index) => {
                if (entry === 'separator') return <div key={index} className="-mx-1 my-1 h-px bg-border" />;
                if ('custom' in entry) return <div key={index}>{entry.custom}</div>;
                return (
                    <button
                        key={index}
                        type="button"
                        role="menuitem"
                        disabled={entry.disabled}
                        onClick={() => { entry.onSelect(); onClose(); }}
                        className={cn(
                            'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm outline-none transition-colors hover:bg-accent focus-visible:bg-accent disabled:pointer-events-none disabled:opacity-50',
                            entry.destructive && 'text-destructive hover:bg-destructive/10',
                        )}
                    >
                        <span className="flex size-4 items-center justify-center text-muted-foreground [&_svg]:size-4">{entry.icon}</span>
                        <span className="flex-1">{entry.label}</span>
                        {entry.shortcut && <span className="text-xs tracking-wide text-muted-foreground">{entry.shortcut}</span>}
                    </button>
                );
            })}
        </div>
    );
}
