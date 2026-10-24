import type { NotificationFilter } from '@/types';
import { Archive, AtSign, BellOff, CheckCheck, UserPlus } from 'lucide-react';
import type { EmptyState } from './NotificationList';

export const FILTER_TABS: { value: NotificationFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'unread', label: 'Unread' },
    { value: 'mentions', label: 'Mentions' },
    { value: 'assigned', label: 'Assigned' },
];

export function isFilter(value: unknown): value is NotificationFilter {
    return FILTER_TABS.some((t) => t.value === value);
}

export function emptyStateFor(filter: NotificationFilter, archived = false): EmptyState {
    if (archived) {
        return {
            icon: Archive,
            title: 'Nothing archived',
            description: 'Archive notifications to clear your inbox. Archived ones are kept for 90 days.',
        };
    }
    switch (filter) {
        case 'unread':
            return { icon: CheckCheck, title: 'You’re all caught up', description: 'No unread notifications. New ones show up here.' };
        case 'mentions':
            return { icon: AtSign, title: 'No mentions', description: 'When someone @mentions you in an issue, comment or page, it lands here.' };
        case 'assigned':
            return { icon: UserPlus, title: 'Nothing assigned', description: 'Issues, tickets and leads assigned to you show up here.' };
        default:
            return { icon: BellOff, title: 'No notifications yet', description: 'Mentions, assignments and updates on things you watch show up here.' };
    }
}
