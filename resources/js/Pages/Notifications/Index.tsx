import { NotificationList } from '@/components/notifications/NotificationList';
import { NotificationRow } from '@/components/notifications/NotificationRow';
import { emptyStateFor, FILTER_TABS } from '@/components/notifications/filters';
import { useNotificationFeed, useUnreadCount, type NotificationAction } from '@/components/notifications/useNotifications';
import { describeNotification } from '@/components/NotificationMessage';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import type { AppNotification, NotificationFeed, NotificationFilter, PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Archive, ArchiveRestore, Check, CheckCheck, CircleDot, Inbox, Settings, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

interface Props extends PageProps {
    feed: NotificationFeed;
    filter: NotificationFilter;
    archived: boolean;
}

function isTyping(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) return false;
    if (target.isContentEditable) return true;
    if (target instanceof HTMLInputElement) return target.type !== 'checkbox';
    return target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement;
}

function Kbd({ children }: { children: string }) {
    return <kbd className="rounded border bg-muted px-1 font-mono text-[10px] font-medium text-foreground/80">{children}</kbd>;
}

/**
 * The inbox (BRAI-102): the bell's rows with filters, an archive view, multi-select bulk actions
 * and keyboard navigation (j / k move, Enter opens, e archives, x selects, u toggles read).
 * Opening the page doesn't mark anything read.
 */
export default function NotificationsIndex({ feed: initialFeed, filter: initialFilter, archived: initialArchived }: Props) {
    const [filter, setFilter] = useState<NotificationFilter>(initialFilter);
    const [archived, setArchived] = useState(initialArchived);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [active, setActive] = useState(-1);
    const listRef = useRef<HTMLDivElement>(null);
    const { count } = useUnreadCount({ poll: false });
    const feed = useNotificationFeed({ filter, archived, initial: initialFeed });
    const { items } = feed;

    // the URL follows the view (reload / share keeps it) without a server round trip
    const changeView = (next: { filter?: NotificationFilter; archived?: boolean }) => {
        const f = next.filter ?? filter;
        const a = next.archived ?? archived;
        setFilter(f);
        setArchived(a);
        setSelected(new Set());
        setActive(-1);
        router.replace({
            url: route('notifications.page', { ...(f !== 'all' ? { filter: f } : {}), ...(a ? { archived: 1 } : {}) }),
            props: (props) => ({ ...props, filter: f, archived: a }),
            preserveScroll: true,
            preserveState: true,
        });
    };

    const run = useCallback(
        (ids: string[], action: NotificationAction) => feed.act(ids, action).catch(() => toast.error('Could not update the notifications.')),
        [feed],
    );

    const onRowAction = useCallback((n: AppNotification, action: NotificationAction) => run([n.id], action), [run]);

    const onOpen = useCallback(
        (n: AppNotification) => {
            if (!n.read_at) feed.act([n.id], 'read').catch(() => {});
        },
        [feed],
    );

    const toggleSelected = useCallback((n: AppNotification, on: boolean) => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (on) next.add(n.id);
            else next.delete(n.id);
            return next;
        });
    }, []);

    // drop selections that left the list (archived, filtered away)
    useEffect(() => {
        setSelected((prev) => {
            const ids = new Set(items.map((n) => n.id));
            const kept = [...prev].filter((id) => ids.has(id));
            return kept.length === prev.size ? prev : new Set(kept);
        });
        setActive((i) => Math.min(i, items.length - 1));
    }, [items]);

    const bulk = (action: NotificationAction) => {
        const ids = [...selected];
        run(ids, action).then(() => setSelected(new Set()));
    };

    const allSelected = items.length > 0 && items.every((n) => selected.has(n.id));
    const someSelected = selected.size > 0 && !allSelected;
    const selectAll = useRef<HTMLInputElement>(null);
    useEffect(() => {
        if (selectAll.current) selectAll.current.indeterminate = someSelected;
    }, [someSelected]);

    const focusRow = (index: number) => {
        const n = items[index];
        if (!n) return;
        const row = listRef.current?.querySelector<HTMLElement>(`[data-notification-id="${n.id}"]`);
        const target = row?.querySelector<HTMLElement>('a[href]') ?? row;
        target?.focus({ preventScroll: true });
        row?.scrollIntoView({ block: 'nearest' });
    };

    // keyboard: j / k / Enter / e / x / u
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.altKey || isTyping(e.target)) return;
            if (document.querySelector('[role="dialog"], [role="menu"]')) return;
            const current = items[active];
            switch (e.key) {
                case 'j':
                case 'k': {
                    if (items.length === 0) return;
                    e.preventDefault();
                    const next = e.key === 'j' ? Math.min(active + 1, items.length - 1) : Math.max(active - 1, 0);
                    setActive(next);
                    focusRow(next);
                    return;
                }
                case 'Enter': {
                    // a focused link / button handles Enter itself
                    if (!current || (e.target instanceof HTMLElement && e.target.closest('a, button'))) return;
                    const url = describeNotification(current.data ?? {}).url;
                    if (!url) return;
                    e.preventDefault();
                    onOpen(current);
                    router.visit(url);
                    return;
                }
                case 'e':
                    if (!current) return;
                    e.preventDefault();
                    run([current.id], current.archived_at ? 'unarchive' : 'archive');
                    return;
                case 'u':
                    if (!current) return;
                    e.preventDefault();
                    run([current.id], current.read_at ? 'unread' : 'read');
                    return;
                case 'x':
                    if (!current) return;
                    e.preventDefault();
                    toggleSelected(current, !selected.has(current.id));
                    return;
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    // after an archive the next row slides into place: keep the cursor's focus on it
    useEffect(() => {
        if (active >= 0 && listRef.current?.contains(document.activeElement) === false && document.activeElement === document.body) focusRow(active);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [items.length]);

    const markAllRead = () => feed.markAllRead().catch(() => toast.error('Could not mark everything as read.'));

    return (
        <AppLayout>
            <Head title="Notifications" />

            <div className="mx-auto w-full max-w-3xl px-4 py-6 md:px-8 md:py-8">
                <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <span className="flex size-10 items-center justify-center rounded-xl bg-primary/10">
                            <Inbox className="size-5 text-primary" aria-hidden />
                        </span>
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight">Notifications</h1>
                            <p className="text-sm text-muted-foreground" aria-live="polite">
                                {count > 0 ? `${count} unread` : 'You’re all caught up'}
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-1.5">
                        <Button variant="outline" size="sm" className="gap-1.5" onClick={markAllRead} disabled={count === 0}>
                            <CheckCheck className="size-4" />
                            Mark all read
                        </Button>
                        <Button asChild variant="ghost" size="icon" className="size-9">
                            <Link href={`${route('profile.edit')}#notifications`} aria-label="Notification settings" title="Notification settings">
                                <Settings className="size-4" />
                            </Link>
                        </Button>
                    </div>
                </div>

                <Tabs value={filter} onValueChange={(v) => changeView({ filter: v as NotificationFilter })}>
                    <div className="overflow-hidden rounded-xl border bg-card shadow-sm">
                        <div className="flex items-end justify-between gap-2 border-b px-4">
                            <TabsList className="h-auto min-w-0 justify-start gap-3 overflow-x-auto rounded-none bg-transparent p-0 sm:gap-4">
                                {FILTER_TABS.map((tab) => (
                                    <TabsTrigger
                                        key={tab.value}
                                        value={tab.value}
                                        className="-mb-px rounded-none border-b-2 border-transparent px-0 pb-2.5 pt-3 data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:shadow-none"
                                    >
                                        {tab.label}
                                    </TabsTrigger>
                                ))}
                            </TabsList>
                            <Button
                                variant={archived ? 'secondary' : 'ghost'}
                                size="sm"
                                className="mb-1.5 h-8 shrink-0 gap-1.5 px-2 text-xs sm:px-3"
                                aria-pressed={archived}
                                title="Show archived"
                                onClick={() => changeView({ archived: !archived })}
                            >
                                <Archive className="size-3.5" aria-hidden />
                                <span className="sr-only sm:not-sr-only">Show archived</span>
                            </Button>
                        </div>

                        {items.length > 0 && (
                            <div className="flex min-h-11 flex-wrap items-center gap-2 border-b bg-muted/30 px-4 py-1.5 text-sm">
                                <input
                                    ref={selectAll}
                                    type="checkbox"
                                    checked={allSelected}
                                    onChange={(e) => setSelected(e.target.checked ? new Set(items.map((n) => n.id)) : new Set())}
                                    aria-label="Select all loaded notifications"
                                    className="size-4 cursor-pointer rounded border-input bg-background text-primary shadow-sm focus:ring-primary"
                                />
                                {selected.size > 0 ? (
                                    <div className="flex flex-wrap items-center gap-1" role="toolbar" aria-label="Bulk actions">
                                        <span className="mr-1 font-medium">{selected.size} selected</span>
                                        <Button variant="ghost" size="sm" className="h-7 gap-1.5 text-xs" onClick={() => bulk('read')}>
                                            <Check className="size-3.5" /> Mark read
                                        </Button>
                                        <Button variant="ghost" size="sm" className="h-7 gap-1.5 text-xs" onClick={() => bulk('unread')}>
                                            <CircleDot className="size-3.5" /> Mark unread
                                        </Button>
                                        {archived ? (
                                            <Button variant="ghost" size="sm" className="h-7 gap-1.5 text-xs" onClick={() => bulk('unarchive')}>
                                                <ArchiveRestore className="size-3.5" /> Unarchive
                                            </Button>
                                        ) : (
                                            <Button variant="ghost" size="sm" className="h-7 gap-1.5 text-xs" onClick={() => bulk('archive')}>
                                                <Archive className="size-3.5" /> Archive
                                            </Button>
                                        )}
                                        <Button variant="ghost" size="icon" className="size-7" onClick={() => setSelected(new Set())} aria-label="Clear selection">
                                            <X className="size-3.5" />
                                        </Button>
                                    </div>
                                ) : (
                                    <span className="text-xs text-muted-foreground">
                                        <span className="hidden md:inline">
                                            <Kbd>j</Kbd> <Kbd>k</Kbd> move · <Kbd>Enter</Kbd> open · <Kbd>e</Kbd> {archived ? 'unarchive' : 'archive'} · <Kbd>x</Kbd> select · <Kbd>u</Kbd> read / unread
                                        </span>
                                        <span className="md:hidden">Select to act on several at once</span>
                                    </span>
                                )}
                            </div>
                        )}

                        <TabsContent value={filter} className="mt-0 focus-visible:ring-0 focus-visible:ring-offset-0" ref={listRef}>
                            <NotificationList
                                items={items}
                                loading={feed.loading}
                                error={feed.error}
                                hasMore={feed.hasMore}
                                loadingMore={feed.loadingMore}
                                onLoadMore={feed.loadMore}
                                onRetry={() => feed.reload()}
                                empty={emptyStateFor(filter, archived)}
                                headerClassName="bg-card/95"
                                className={cn(feed.loading && items.length > 0 && 'opacity-60 transition-opacity')}
                                renderRow={(n, index) => (
                                    <NotificationRow
                                        key={n.id}
                                        notification={n}
                                        onOpen={onOpen}
                                        onAction={onRowAction}
                                        selectable
                                        selected={selected.has(n.id)}
                                        onSelectedChange={toggleSelected}
                                        active={index === active}
                                        comfortable
                                        fresh={feed.freshIds.has(n.id)}
                                    />
                                )}
                            />
                        </TabsContent>
                    </div>
                </Tabs>
            </div>
        </AppLayout>
    );
}
