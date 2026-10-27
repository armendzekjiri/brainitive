import { usePage } from '@inertiajs/react';
import { Bell, Bookmark, CalendarClock, CalendarDays, ChartColumn, Clock, Code2, Home, Lightbulb, ListTodo, ReceiptText, type LucideIcon } from 'lucide-react';
import type { PageProps, SidebarNavKey } from '@/types';

/**
 * The sidebar's top-level navigation as data (BRAI-106). The sidebar renders the entries the user
 * has not hidden; the command palette's "Go to" commands come from the same list, so hidden
 * entries stay reachable. Home and Inbox can't be hidden.
 */
export interface SidebarNavItem {
    key: 'home' | 'inbox' | SidebarNavKey;
    label: string;
    icon: LucideIcon;
    href: string;
    active: boolean;
    /** False for Home and Inbox (always shown). */
    hideable: boolean;
    /** Shown in the customize panel under the label. */
    hint: string;
    badge?: { count: number; label: string };
    palette: { id: string; label: string; description: string; keywords?: string; color?: string };
}

export function useSidebarNavItems(): SidebarNavItem[] {
    const { currentWorkspace, canManageWorkspace = false, captureInboxCount = 0 } = usePage<PageProps>().props;
    const current = route().current() ?? '';
    const ws = currentWorkspace?.slug;
    const items: (SidebarNavItem | false)[] = [
        {
            key: 'home',
            label: 'Home',
            icon: Home,
            href: ws ? route('workspaces.show', ws) : route('workspaces.index'),
            active: current === 'workspaces.show' || current === 'workspaces.index',
            hideable: false,
            hint: 'Projects of this workspace',
            palette: { id: 'nav-workspace', label: currentWorkspace ? `Go to ${currentWorkspace.name}` : 'Go to Home', description: 'Projects of this workspace', keywords: 'home workspace projects' },
        },
        {
            key: 'my_work',
            label: 'My Work',
            icon: ListTodo,
            href: route('my-work.index'),
            active: current === 'my-work.index',
            hideable: true,
            hint: 'Issues assigned to you',
            palette: { id: 'nav-my-work', label: 'Go to My Work', description: 'Issues assigned to you', keywords: 'assigned tasks todo' },
        },
        {
            key: 'captures',
            label: 'Captures',
            icon: Lightbulb,
            href: route('captures.index'),
            active: current === 'captures.index',
            hideable: true,
            hint: 'Your private idea inbox',
            badge: captureInboxCount > 0 ? { count: captureInboxCount, label: `${captureInboxCount} in your capture inbox` } : undefined,
            palette: { id: 'nav-captures', label: 'Go to Captures', description: 'Your private idea inbox', keywords: 'ideas inbox capture', color: '#f59e0b' },
        },
        // Time tracking & billing (v1.38.0)
        !!ws && route().has('timesheet.index') && {
            key: 'timesheet',
            label: 'Timesheet',
            icon: Clock,
            href: route('timesheet.index', ws),
            active: current === 'timesheet.index',
            hideable: true,
            hint: 'Your logged time',
            palette: { id: 'nav-timesheet', label: 'Go to Timesheet', description: 'Your logged time', keywords: 'time hours log tracking' },
        },
        !!ws && canManageWorkspace && {
            key: 'billing',
            label: 'Billing',
            icon: ReceiptText,
            href: route('billing.index', ws),
            active: current.startsWith('billing.'),
            hideable: true,
            hint: 'Client billing and reports (admins)',
            palette: { id: 'nav-billing', label: 'Go to Billing', description: 'Client billing and reports', keywords: 'invoice clients reports hours' },
        },
        // Admin insights (BRAI-105), shown once its routes exist
        !!ws && canManageWorkspace && route().has('insights.index') && {
            key: 'insights',
            label: 'Insights',
            icon: ChartColumn,
            href: route('insights.index', ws),
            active: current.startsWith('insights.'),
            hideable: true,
            hint: 'Workspace analytics (admins)',
            palette: { id: 'nav-insights', label: 'Go to Insights', description: 'Workspace analytics', keywords: 'analytics stats reports admin' },
        },
        {
            key: 'calendar',
            label: 'Calendar',
            icon: CalendarDays,
            href: route('calendar.index'),
            active: current === 'calendar.index',
            hideable: true,
            hint: 'Due dates and connected calendars',
            palette: { id: 'nav-calendar', label: 'Go to Calendar', description: 'Due dates and connected calendars', keywords: 'schedule events google' },
        },
        {
            key: 'booking_pages',
            label: 'Booking pages',
            icon: CalendarClock,
            href: route('booking-pages.index'),
            active: current.startsWith('booking-pages.'),
            hideable: true,
            hint: 'Let people book a time with you',
            palette: { id: 'nav-booking-pages', label: 'Go to Booking pages', description: 'Let people book a time with you', keywords: 'book appointment schedule meeting calendly slots availability' },
        },
        {
            key: 'inbox',
            label: 'Inbox',
            icon: Bell,
            href: route('notifications.page'),
            active: current === 'notifications.page',
            hideable: false,
            hint: 'All notifications',
            palette: { id: 'nav-inbox', label: 'Go to Inbox', description: 'All notifications', keywords: 'notifications mentions' },
        },
        !!ws && {
            key: 'bookmarks',
            label: 'Bookmarks',
            icon: Bookmark,
            href: route('bookmarks.index', ws),
            active: current === 'bookmarks.index',
            hideable: true,
            hint: 'Bookmark collections',
            palette: { id: 'nav-bookmarks', label: 'Go to Bookmarks', description: 'Open your bookmark collections', color: '#0969da' },
        },
        !!ws && {
            key: 'snippets',
            label: 'Snippets',
            icon: Code2,
            href: route('snippets.index', ws),
            active: current === 'snippets.index',
            hideable: true,
            hint: 'Code snippet collections',
            palette: { id: 'nav-snippets', label: 'Go to Snippets', description: 'Open your code snippet collections', color: '#1a7f37' },
        },
    ];

    return items.filter((item): item is SidebarNavItem => !!item);
}
