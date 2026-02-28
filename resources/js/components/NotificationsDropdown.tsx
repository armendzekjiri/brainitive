import React, { useState, useEffect, useCallback } from 'react';
import { usePage, Link } from '@inertiajs/react';
import axios from 'axios';
import { Bell, CheckCheck, ExternalLink, X, BellOff } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { ScrollArea } from '@/components/ui/scroll-area';
import { PageProps } from '@/types';

interface NotificationData {
    type: string;
    item_key?: string;
    item_title?: string;
    item_url?: string;
    old_column?: string;
    new_column?: string;
    context_type?: string;
    context_title?: string;
    context_url?: string;
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

function timeAgo(dateStr: string): string {
    const date = new Date(dateStr);
    const seconds = Math.floor((Date.now() - date.getTime()) / 1000);
    if (seconds < 60) return 'just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    return `${days}d ago`;
}

export function NotificationsDropdown() {
    const { unreadNotificationsCount: initialCount = 0 } = usePage<PageProps>().props;

    const [open, setOpen] = useState(false);
    const [unreadCount, setUnreadCount] = useState(initialCount);
    const [notifications, setNotifications] = useState<Notification[]>([]);
    const [loading, setLoading] = useState(false);

    // Poll unread count every 30 seconds
    useEffect(() => {
        const poll = async () => {
            try {
                const res = await axios.get<{ count: number }>('/api/notifications/unread-count');
                setUnreadCount(res.data.count);
            } catch {
                // silently ignore
            }
        };

        const interval = setInterval(poll, 30_000);
        return () => clearInterval(interval);
    }, []);

    // Fetch notifications when dropdown opens
    const fetchNotifications = useCallback(async () => {
        setLoading(true);
        try {
            const res = await axios.get<{ data: Notification[] }>('/api/notifications');
            setNotifications(res.data.data);
        } catch {
            //
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (open) {
            fetchNotifications();
        }
    }, [open, fetchNotifications]);

    const markRead = async (id: string) => {
        try {
            await axios.post(`/api/notifications/${id}/read`);
            setNotifications(prev =>
                prev.map(n => n.id === id ? { ...n, read_at: new Date().toISOString() } : n)
            );
            setUnreadCount(prev => Math.max(0, prev - 1));
        } catch {
            //
        }
    };

    const markAllRead = async () => {
        try {
            await axios.post('/api/notifications/read-all');
            setNotifications(prev => prev.map(n => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })));
            setUnreadCount(0);
        } catch {
            //
        }
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative size-8 text-muted-foreground hover:text-foreground"
                    aria-label="Notifications"
                >
                    <Bell className="size-4" />
                    {unreadCount > 0 && (
                        <span className="absolute -top-0.5 -right-0.5 flex size-4 items-center justify-center rounded-full bg-red-500 text-[10px] font-bold text-white leading-none">
                            {unreadCount > 9 ? '9+' : unreadCount}
                        </span>
                    )}
                </Button>
            </PopoverTrigger>

            <PopoverContent
                align="end"
                sideOffset={8}
                className="w-[380px] p-0 shadow-xl"
            >
                {/* Header */}
                <div className="flex items-center justify-between border-b px-4 py-3">
                    <div className="flex items-center gap-2">
                        <Bell className="size-4 text-muted-foreground" />
                        <span className="font-semibold text-sm">Notifications</span>
                        {unreadCount > 0 && (
                            <span className="rounded-full bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 leading-none">
                                {unreadCount}
                            </span>
                        )}
                    </div>
                    {unreadCount > 0 && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 text-xs text-muted-foreground gap-1.5"
                            onClick={markAllRead}
                        >
                            <CheckCheck className="size-3.5" />
                            Mark all read
                        </Button>
                    )}
                </div>

                {/* Notification list */}
                <ScrollArea className="max-h-[420px]">
                    {loading ? (
                        <div className="flex flex-col gap-3 p-4">
                            {[1, 2, 3].map(i => (
                                <div key={i} className="flex gap-3 animate-pulse">
                                    <div className="size-8 rounded-full bg-muted shrink-0" />
                                    <div className="flex-1 space-y-1.5">
                                        <div className="h-3 bg-muted rounded w-3/4" />
                                        <div className="h-2.5 bg-muted rounded w-1/2" />
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : notifications.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-12 gap-3 text-muted-foreground">
                            <BellOff className="size-8 opacity-40" />
                            <p className="text-sm">No notifications yet</p>
                        </div>
                    ) : (
                        <div className="divide-y">
                            {notifications.map(n => {
                                const isUnread = !n.read_at;
                                const d = n.data;

                                return (
                                    <div
                                        key={n.id}
                                        className={cn(
                                            'flex items-start gap-3 px-4 py-3 transition-colors group',
                                            isUnread
                                                ? 'bg-primary/5 hover:bg-primary/10'
                                                : 'hover:bg-muted/50'
                                        )}
                                    >
                                        {/* Avatar / Icon */}
                                        <div className="size-8 rounded-full bg-muted flex items-center justify-center shrink-0 text-xs font-medium uppercase overflow-hidden border">
                                            {d.actor_avatar
                                                ? <img src={d.actor_avatar} alt={d.actor_name} className="size-full object-cover" />
                                                : d.actor_name?.charAt(0) ?? '?'
                                            }
                                        </div>

                                        {/* Content */}
                                        <div className="flex-1 min-w-0">
                                            <p className="text-sm leading-snug">
                                                <span className="font-medium">{d.actor_name}</span>
                                                {d.type === 'mentioned' && (
                                                    <>
                                                        {' mentioned you '}
                                                        {d.context_type === 'page' ? 'on page ' : 'in '}
                                                        <a
                                                            href={d.context_url!}
                                                            className="font-medium text-primary hover:underline inline-flex items-center gap-0.5"
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            onClick={(e) => {
                                                                if (isUnread) markRead(n.id);
                                                                // Allow click to navigate natively or in new tab
                                                            }}
                                                        >
                                                            {d.context_title}
                                                            <ExternalLink className="size-2.5" />
                                                        </a>
                                                    </>
                                                )}
                                                {d.type !== 'mentioned' && (
                                                    <>
                                                        {d.type === 'item_assigned' ? ' assigned you to ' : ' moved '}
                                                        <a
                                                            href={d.item_url}
                                                            className="font-medium text-primary hover:underline inline-flex items-center gap-0.5"
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            onClick={(e) => {
                                                                if (isUnread) markRead(n.id);
                                                            }}
                                                        >
                                                            {d.item_key}
                                                            <ExternalLink className="size-2.5" />
                                                        </a>
                                                    </>
                                                )}
                                                {d.type === 'item_status_changed' && (
                                                    <>
                                                        {' from '}
                                                        <span className="text-muted-foreground">{d.old_column}</span>
                                                        {' → '}
                                                        <span className="font-medium">{d.new_column}</span>
                                                    </>
                                                )}
                                            </p>
                                            <p className="text-xs text-muted-foreground mt-0.5 truncate">{d.item_title}</p>
                                            <p className="text-[11px] text-muted-foreground/70 mt-1">{timeAgo(n.created_at)}</p>
                                        </div>

                                        {/* Mark read button */}
                                        {isUnread && (
                                            <button
                                                onClick={() => markRead(n.id)}
                                                className="opacity-0 group-hover:opacity-100 transition-opacity shrink-0 mt-0.5 p-1 rounded hover:bg-muted text-muted-foreground"
                                                title="Mark as read"
                                            >
                                                <X className="size-3" />
                                            </button>
                                        )}

                                        {/* Unread dot */}
                                        {isUnread && (
                                            <div className="size-2 rounded-full bg-primary mt-1.5 shrink-0" />
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </ScrollArea>

                {/* Footer - link to full page */}
                <div className="border-t px-4 py-2.5">
                    <Link
                        href="/notifications"
                        className="block text-center text-xs text-muted-foreground hover:text-foreground transition-colors py-1"
                        onClick={() => setOpen(false)}
                    >
                        View all notifications
                    </Link>
                </div>
            </PopoverContent>
        </Popover>
    );
}
