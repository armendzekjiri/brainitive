import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import { Bell, BellOff, CheckCheck, ExternalLink, ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import axios from 'axios';
import { useState } from 'react';

interface NotificationData {
    type: string;
    item_key: string;
    item_title: string;
    item_url: string;
    old_column?: string;
    new_column?: string;
    actor_name: string;
    actor_avatar?: string;
}

interface Notification {
    id: string;
    type: string;
    data: NotificationData;
    read_at: string | null;
    created_at: string;
}

interface PaginatedNotifications {
    data: Notification[];
    total: number;
    current_page: number;
    last_page: number;
    per_page: number;
}

interface Props extends PageProps {
    notifications: PaginatedNotifications;
}

function formatDate(dateStr: string): string {
    const date = new Date(dateStr);
    const now = new Date();
    const seconds = Math.floor((now.getTime() - date.getTime()) / 1000);

    if (seconds < 60) return 'just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} minute${minutes !== 1 ? 's' : ''} ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} hour${hours !== 1 ? 's' : ''} ago`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days} day${days !== 1 ? 's' : ''} ago`;

    return date.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function NotificationRow({ notification, onMarkRead }: { notification: Notification; onMarkRead: (id: string) => void }) {
    const d = notification.data;
    const isUnread = !notification.read_at;

    return (
        <div className={cn(
            'group flex gap-4 px-6 py-4 border-b last:border-b-0 transition-colors',
            isUnread ? 'bg-primary/5 hover:bg-primary/8' : 'hover:bg-muted/40'
        )}>
            {/* Avatar */}
            <div className="size-10 rounded-full bg-muted flex items-center justify-center shrink-0 text-sm font-semibold uppercase overflow-hidden border">
                {d.actor_avatar
                    ? <img src={d.actor_avatar} alt={d.actor_name} className="size-full object-cover" />
                    : (d.actor_name?.charAt(0) ?? '?')
                }
            </div>

            {/* Content */}
            <div className="flex-1 min-w-0">
                <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <p className="text-sm leading-relaxed">
                            <span className="font-semibold">{d.actor_name}</span>
                            {d.type === 'item_assigned' ? ' assigned you to ' : ' moved '}
                            <a
                                href={d.item_url}
                                className="font-semibold text-primary hover:underline inline-flex items-center gap-0.5"
                            >
                                {d.item_key}
                                <ExternalLink className="size-3 ml-0.5" />
                            </a>
                            {d.type === 'item_status_changed' && (
                                <>
                                    {' from '}
                                    <span className="text-muted-foreground">{d.old_column}</span>
                                    <span className="mx-1.5 text-muted-foreground">→</span>
                                    <span className="font-medium">{d.new_column}</span>
                                </>
                            )}
                        </p>
                        <p className="text-sm text-muted-foreground mt-0.5 truncate">{d.item_title}</p>
                    </div>

                    <div className="flex items-center gap-2 shrink-0">
                        <span className="text-xs text-muted-foreground whitespace-nowrap">{formatDate(notification.created_at)}</span>
                        {isUnread && (
                            <button
                                onClick={() => onMarkRead(notification.id)}
                                className="opacity-0 group-hover:opacity-100 transition-opacity p-1.5 rounded-md hover:bg-muted text-muted-foreground text-xs"
                                title="Mark as read"
                            >
                                <CheckCheck className="size-3.5" />
                            </button>
                        )}
                    </div>
                </div>
            </div>

            {/* Unread indicator */}
            {isUnread && (
                <div className="size-2 rounded-full bg-primary mt-1.5 shrink-0" />
            )}
        </div>
    );
}

export default function NotificationsIndex({ notifications }: Props) {
    const [items, setItems] = useState<Notification[]>(notifications.data);
    const unreadCount = items.filter(n => !n.read_at).length;

    const handleMarkRead = async (id: string) => {
        try {
            await axios.post(`/api/notifications/${id}/read`);
            setItems(prev => prev.map(n => n.id === id ? { ...n, read_at: new Date().toISOString() } : n));
        } catch { /* */ }
    };

    const handleMarkAllRead = async () => {
        try {
            await axios.post('/api/notifications/read-all');
            setItems(prev => prev.map(n => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })));
        } catch { /* */ }
    };

    return (
        <AppLayout>
            <Head title="Notifications" />

            <div className="p-6 md:p-8 max-w-3xl mx-auto">
                {/* Header */}
                <div className="mb-8">
                    <div className="flex items-center gap-3 mb-6">
                        <Button variant="ghost" size="sm" className="gap-1.5 text-muted-foreground" onClick={() => window.history.back()}>
                            <ArrowLeft className="size-4" />
                            Back
                        </Button>
                    </div>

                    <div className="flex items-start justify-between gap-4">
                        <div className="flex items-center gap-3">
                            <div className="size-12 rounded-xl bg-primary/10 flex items-center justify-center">
                                <Bell className="size-6 text-primary" />
                            </div>
                            <div>
                                <h1 className="text-2xl md:text-3xl font-bold tracking-tight">Notifications</h1>
                                <p className="text-muted-foreground text-sm mt-0.5">
                                    {notifications.total} total
                                    {unreadCount > 0 && (
                                        <> · <span className="text-primary font-medium">{unreadCount} unread</span></>
                                    )}
                                </p>
                            </div>
                        </div>

                        {unreadCount > 0 && (
                            <Button
                                variant="outline"
                                size="sm"
                                className="gap-2 shrink-0"
                                onClick={handleMarkAllRead}
                            >
                                <CheckCheck className="size-4" />
                                Mark all as read
                            </Button>
                        )}
                    </div>
                </div>

                {/* Notification list */}
                {items.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-24 gap-4 text-muted-foreground border-2 border-dashed rounded-xl">
                        <BellOff className="size-12 opacity-30" />
                        <div className="text-center">
                            <p className="font-medium">No notifications yet</p>
                            <p className="text-sm mt-1 opacity-70">You'll be notified when items you're watching change status.</p>
                        </div>
                    </div>
                ) : (
                    <div className="border rounded-xl overflow-hidden shadow-sm bg-card">
                        {items.map(notification => (
                            <NotificationRow
                                key={notification.id}
                                notification={notification}
                                onMarkRead={handleMarkRead}
                            />
                        ))}
                    </div>
                )}

                {/* Pagination */}
                {notifications.last_page > 1 && (
                    <div className="flex items-center justify-center gap-2 mt-6">
                        {notifications.current_page > 1 && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => router.get('/notifications', { page: notifications.current_page - 1 })}
                            >
                                Previous
                            </Button>
                        )}
                        <span className="text-sm text-muted-foreground">
                            Page {notifications.current_page} of {notifications.last_page}
                        </span>
                        {notifications.current_page < notifications.last_page && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => router.get('/notifications', { page: notifications.current_page + 1 })}
                            >
                                Next
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
