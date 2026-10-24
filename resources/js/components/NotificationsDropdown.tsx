import { NotificationList } from '@/components/notifications/NotificationList';
import { NotificationRow } from '@/components/notifications/NotificationRow';
import { emptyStateFor, FILTER_TABS } from '@/components/notifications/filters';
import { useNotificationFeed, useUnreadCount, type NotificationAction } from '@/components/notifications/useNotifications';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import type { AppNotification, NotificationFilter } from '@/types';
import { Link } from '@inertiajs/react';
import { ArrowRight, Bell, CheckCheck, Settings } from 'lucide-react';
import { useCallback, useState, type ReactNode } from 'react';
import { toast } from 'sonner';

/**
 * The bell (BRAI-102): a ~420px popover on desktop, a full-screen sheet on phones. Tabs filter on
 * the server (All · Unread · Mentions · Assigned); rows open in this tab and are marked read.
 */
export function NotificationsDropdown() {
    const { count } = useUnreadCount({ manageTitle: true });
    const [open, setOpen] = useState(false);
    const isMobile = useIsMobile();
    const close = useCallback(() => setOpen(false), []);

    const trigger = (
        <Button
            variant="ghost"
            size="icon"
            className="relative size-8 text-muted-foreground hover:text-foreground"
            aria-label={count > 0 ? `Notifications (${count} unread)` : 'Notifications'}
        >
            <Bell className="size-4" />
            {count > 0 && (
                <span
                    aria-hidden
                    className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold leading-none text-white ring-2 ring-background"
                >
                    {count > 99 ? '99+' : count}
                </span>
            )}
        </Button>
    );

    if (isMobile) {
        return (
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetTrigger asChild>{trigger}</SheetTrigger>
                <SheetContent side="right" className="flex w-full flex-col gap-0 p-0 sm:max-w-full" aria-describedby={undefined} onOpenAutoFocus={focusActiveTab}>
                    {open && (
                        <NotificationPanel
                            unreadCount={count}
                            onClose={close}
                            title={<SheetTitle className="text-base font-semibold">Notifications</SheetTitle>}
                            className="pr-12"
                        />
                    )}
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>{trigger}</PopoverTrigger>
            <PopoverContent
                align="end"
                sideOffset={8}
                aria-labelledby="notifications-panel-title"
                onOpenAutoFocus={focusActiveTab}
                className="flex max-h-[min(640px,calc(100vh-5rem))] w-[420px] max-w-[calc(100vw-1rem)] flex-col overflow-hidden p-0 shadow-xl"
            >
                <NotificationPanel
                    unreadCount={count}
                    onClose={close}
                    title={
                        <h2 id="notifications-panel-title" className="text-base font-semibold">
                            Notifications
                        </h2>
                    }
                />
            </PopoverContent>
        </Popover>
    );
}

/** Opening the panel puts focus on the active tab (arrow keys switch tabs, Tab reaches the rows). */
function focusActiveTab(e: Event) {
    const panel = e.currentTarget as HTMLElement | null;
    const tab = panel?.querySelector<HTMLElement>('[role="tab"][data-state="active"]');
    if (!tab) return;
    e.preventDefault();
    tab.focus({ preventScroll: true });
}

function NotificationPanel({ unreadCount, onClose, title, className }: { unreadCount: number; onClose: () => void; title: ReactNode; className?: string }) {
    const [filter, setFilter] = useState<NotificationFilter>('all');
    const feed = useNotificationFeed({ filter, archived: false });

    const act = useCallback(
        (n: AppNotification, action: NotificationAction) => {
            feed.act([n.id], action).catch(() => toast.error('Could not update the notification.'));
        },
        [feed],
    );

    const openRow = useCallback(
        (n: AppNotification) => {
            if (!n.read_at) feed.act([n.id], 'read').catch(() => {});
            onClose();
        },
        [feed, onClose],
    );

    const markAllRead = () => feed.markAllRead().catch(() => toast.error('Could not mark everything as read.'));

    return (
        <Tabs value={filter} onValueChange={(v) => setFilter(v as NotificationFilter)} className="flex min-h-0 flex-1 flex-col">
            <div className={cn('flex items-center justify-between gap-2 px-4 pb-1 pt-3', className)}>
                {title}
                <div className="flex items-center gap-0.5">
                    <Button variant="ghost" size="sm" className="h-8 gap-1.5 px-2 text-xs text-muted-foreground" onClick={markAllRead} disabled={unreadCount === 0}>
                        <CheckCheck className="size-3.5" />
                        Mark all read
                    </Button>
                    <Button asChild variant="ghost" size="icon" className="size-8 text-muted-foreground">
                        <Link href={`${route('profile.edit')}#notifications`} onClick={onClose} aria-label="Notification settings" title="Notification settings">
                            <Settings className="size-4" />
                        </Link>
                    </Button>
                </div>
            </div>

            <TabsList className="h-auto w-full justify-start gap-4 rounded-none border-b bg-transparent px-4 py-0">
                {FILTER_TABS.map((tab) => (
                    <TabsTrigger
                        key={tab.value}
                        value={tab.value}
                        className="-mb-px gap-1.5 rounded-none border-b-2 border-transparent px-0 pb-2 pt-1.5 text-[13px] data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:shadow-none"
                    >
                        {tab.label}
                        {tab.value === 'unread' && unreadCount > 0 && (
                            <span className="rounded-full bg-primary/10 px-1.5 text-[10px] font-semibold leading-4 text-primary">{unreadCount > 99 ? '99+' : unreadCount}</span>
                        )}
                    </TabsTrigger>
                ))}
            </TabsList>

            <TabsContent value={filter} className="mt-0 min-h-0 flex-1 overflow-y-auto overscroll-contain focus-visible:ring-0 focus-visible:ring-offset-0">
                <NotificationList
                    items={feed.items}
                    loading={feed.loading}
                    error={feed.error}
                    hasMore={feed.hasMore}
                    loadingMore={feed.loadingMore}
                    onLoadMore={feed.loadMore}
                    onRetry={feed.reload}
                    empty={emptyStateFor(filter)}
                    renderRow={(n) => <NotificationRow key={n.id} notification={n} onOpen={openRow} onAction={act} />}
                />
            </TabsContent>

            <div className="border-t p-1.5">
                <Button asChild variant="ghost" size="sm" className="w-full justify-center gap-1.5 text-xs text-muted-foreground">
                    <Link href={route('notifications.page')} onClick={onClose}>
                        Open inbox
                        <ArrowRight className="size-3.5" />
                    </Link>
                </Button>
            </div>
        </Tabs>
    );
}
