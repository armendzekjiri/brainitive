import { describeNotification, type NotificationView } from '@/components/NotificationMessage';
import { cn } from '@/lib/utils';
import type { AppNotification } from '@/types';
import { Link } from '@inertiajs/react';
import { Archive, ArchiveRestore, Check, CircleDot } from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';
import { categoryStyle } from './categories';
import type { NotificationAction } from './useNotifications';

/** "now", "5m", "3h", "2d", then "Oct 3" / "Oct 3, 2025". */
export function shortTime(iso: string, now = new Date()): string {
    const date = new Date(iso);
    const seconds = Math.max(0, Math.floor((now.getTime() - date.getTime()) / 1000));
    if (seconds < 60) return 'now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}d`;
    return date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        ...(date.getFullYear() !== now.getFullYear() ? { year: 'numeric' } : {}),
    });
}

function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase() || '?';
}

/** The actor's avatar with the category badge; without an actor, the category icon itself. */
export function NotificationAvatar({ view, category, size = 'md' }: { view: NotificationView; category: string | null; size?: 'md' | 'lg' }) {
    const style = categoryStyle(category);
    const Icon = style.icon;
    const box = size === 'lg' ? 'size-10' : 'size-9';

    if (!view.actorName && !view.actorAvatar) {
        return (
            <span className={cn('flex shrink-0 items-center justify-center rounded-full', box, style.tint)} title={style.label}>
                <Icon className="size-4" aria-hidden />
            </span>
        );
    }

    return (
        <span className={cn('relative shrink-0', box)}>
            {view.actorAvatar ? (
                <img src={view.actorAvatar} alt="" className={cn('rounded-full object-cover', box)} loading="lazy" />
            ) : (
                <span className={cn('flex items-center justify-center rounded-full bg-muted text-xs font-semibold text-foreground/70', box)}>
                    {initials(view.actorName ?? '')}
                </span>
            )}
            <span
                className={cn('absolute -bottom-0.5 -right-1 flex size-[18px] items-center justify-center rounded-full ring-2 ring-background', style.tint)}
                title={style.label}
            >
                <Icon className="size-2.5" aria-hidden />
            </span>
        </span>
    );
}

function ActionButton({ label, onClick, children }: { label: string; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={(e) => {
                e.stopPropagation();
                onClick();
            }}
            className="flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            aria-label={label}
            title={label}
        >
            {children}
        </button>
    );
}

interface RowProps {
    notification: AppNotification;
    /** Called when the row is opened (before the visit): mark read, close the panel … */
    onOpen: (notification: AppNotification) => void;
    onAction: (notification: AppNotification, action: NotificationAction) => void;
    /** Inbox page: a checkbox for bulk actions. */
    selectable?: boolean;
    selected?: boolean;
    onSelectedChange?: (notification: AppNotification, selected: boolean) => void;
    /** Inbox page: the keyboard cursor (j / k) is on this row. */
    active?: boolean;
    comfortable?: boolean;
}

/**
 * One notification. The headline is a stretched link (the whole row opens it, in this tab via
 * Inertia; ⌘/Ctrl-click still opens a new tab). Actions sit above the link and show on hover or
 * keyboard focus (always on touch screens).
 */
export function NotificationRow({ notification: n, onOpen, onAction, selectable, selected, onSelectedChange, active, comfortable }: RowProps) {
    const view = describeNotification(n.data ?? {});
    const unread = !n.read_at;
    const archived = !!n.archived_at;
    const created = new Date(n.created_at);

    const headline = (
        <>
            {unread && <span className="sr-only">Unread: </span>}
            {view.headline}
        </>
    );

    const open = (e: MouseEvent) => {
        // let the browser handle new-tab clicks; still count them as read
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) {
            if (unread) onAction(n, 'read');
            return;
        }
        onOpen(n);
    };

    return (
        <li
            data-notification-id={n.id}
            data-unread={unread || undefined}
            tabIndex={-1}
            className={cn(
                'group relative flex gap-3 border-l-2 border-transparent outline-none transition-colors',
                comfortable ? 'px-4 py-3.5 sm:px-5' : 'px-4 py-3',
                unread ? 'bg-primary/[0.035] dark:bg-primary/[0.06]' : '',
                'hover:bg-accent/60 focus-within:bg-accent/60',
                active && 'border-l-primary bg-accent/60',
            )}
        >
            {selectable && (
                <input
                    type="checkbox"
                    checked={!!selected}
                    onChange={(e) => onSelectedChange?.(n, e.target.checked)}
                    aria-label={`Select: ${view.text}`}
                    className="relative z-10 mt-2.5 size-4 shrink-0 cursor-pointer rounded border-input bg-background text-primary shadow-sm focus:ring-primary"
                />
            )}

            <span className="relative mt-0.5">
                <NotificationAvatar view={view} category={n.category} size={comfortable ? 'lg' : 'md'} />
            </span>

            <div className="min-w-0 flex-1">
                <p className={cn('text-sm leading-snug', unread ? 'text-foreground' : 'text-muted-foreground')}>
                    {view.url ? (
                        <Link
                            href={view.url}
                            onClick={open}
                            className="rounded-sm after:absolute after:inset-0 after:content-[''] focus-visible:outline-none focus-visible:after:rounded-md focus-visible:after:ring-2 focus-visible:after:ring-inset focus-visible:after:ring-ring"
                        >
                            {headline}
                        </Link>
                    ) : (
                        headline
                    )}
                </p>
                {view.body && <p className="mt-0.5 line-clamp-2 break-words text-[13px] leading-snug text-muted-foreground">{view.body}</p>}
                <div className="mt-1.5 flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-muted-foreground">
                    {view.subjectKey && (
                        <span className="rounded border bg-background px-1.5 py-px font-mono text-[11px] font-medium text-foreground/80">{view.subjectKey}</span>
                    )}
                    {view.context && <span className="min-w-0 truncate">{view.context}</span>}
                    {(view.subjectKey || view.context) && <span aria-hidden>·</span>}
                    <time dateTime={n.created_at} title={created.toLocaleString()} className="whitespace-nowrap">
                        {shortTime(n.created_at)}
                    </time>
                    {archived && <span className="rounded-full bg-muted px-1.5 py-px text-[10px] font-medium uppercase tracking-wide">Archived</span>}
                </div>
            </div>

            <div className="relative z-10 flex shrink-0 flex-col items-end gap-1">
                <div className="flex items-center gap-0.5 transition-opacity md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100">
                    {unread ? (
                        <ActionButton label="Mark as read" onClick={() => onAction(n, 'read')}>
                            <Check className="size-4" />
                        </ActionButton>
                    ) : (
                        <ActionButton label="Mark as unread" onClick={() => onAction(n, 'unread')}>
                            <CircleDot className="size-4" />
                        </ActionButton>
                    )}
                    {archived ? (
                        <ActionButton label="Unarchive" onClick={() => onAction(n, 'unarchive')}>
                            <ArchiveRestore className="size-4" />
                        </ActionButton>
                    ) : (
                        <ActionButton label="Archive" onClick={() => onAction(n, 'archive')}>
                            <Archive className="size-4" />
                        </ActionButton>
                    )}
                </div>
            </div>

            {unread && <span aria-hidden className="pointer-events-none absolute left-1.5 top-[1.35rem] size-1.5 rounded-full bg-primary" />}
        </li>
    );
}
