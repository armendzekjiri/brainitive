import { Pin, PinOff } from 'lucide-react';
import { cn } from '@/lib/utils';
import { isPinned, togglePin, useSidebarPreferences } from '@/lib/sidebarPreferences';
import type { SidebarPinType } from '@/types';

/**
 * Pin / unpin an item to its project's "Pinned" sidebar group (BRAI-106). Accessible name
 * "Pin <name>" / "Unpin <name>". The parent row should carry `group/pin` so the button shows on
 * hover; it is always visible while pinned, on keyboard focus and on touch screens.
 */
export function PinButton({
    projectId,
    type,
    id,
    name,
    className,
    size = 'sm',
    hoverOnly = false,
}: {
    projectId: number;
    type: SidebarPinType;
    id: number;
    name: string;
    className?: string;
    size?: 'sm' | 'md';
    /** Hide the button until hover/focus even when pinned (rows of the Pinned group). */
    hoverOnly?: boolean;
}) {
    const prefs = useSidebarPreferences();
    const pinned = isPinned(prefs, projectId, type, id);
    const Icon = pinned ? PinOff : Pin;

    return (
        <button
            type="button"
            aria-label={`${pinned ? 'Unpin' : 'Pin'} ${name}`}
            title={pinned ? 'Unpin from sidebar' : 'Pin to sidebar'}
            data-pinned={pinned || undefined}
            onClick={(e) => {
                e.preventDefault();
                e.stopPropagation();
                togglePin(projectId, { type, id });
            }}
            className={cn(
                'inline-flex shrink-0 items-center justify-center rounded-md text-muted-foreground transition hover:bg-accent hover:text-foreground focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                size === 'sm' ? 'size-6' : 'size-8',
                pinned && 'text-primary',
                pinned && !hoverOnly
                    ? 'opacity-100'
                    : 'opacity-0 group-hover/pin:opacity-100 group-focus-within/pin:opacity-100 [@media(hover:none)]:opacity-60',
                className,
            )}
        >
            <Icon className={size === 'sm' ? 'size-3.5' : 'size-4'} />
        </button>
    );
}
