import type { NotificationCategoryKey } from '@/types';
import {
    AlarmClock,
    ArrowRightLeft,
    AtSign,
    BadgeCheck,
    Bell,
    FileText,
    LifeBuoy,
    Lightbulb,
    Magnet,
    MessageSquare,
    UserPlus,
    Users,
    Zap,
    type LucideIcon,
} from 'lucide-react';

/**
 * Icon + tint per notification CATEGORY (not per type), so types added later get the right
 * icon from the category the server sends. Unknown / null → the neutral bell.
 * (A .tsx file on purpose: Tailwind only scans .tsx files under resources/js for class names.)
 */
export interface CategoryStyle {
    label: string;
    icon: LucideIcon;
    /** badge background + icon colour, light and dark */
    tint: string;
}

const STYLES: Record<NotificationCategoryKey, CategoryStyle> = {
    mentions: { label: 'Mention', icon: AtSign, tint: 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' },
    assignments: { label: 'Assignment', icon: UserPlus, tint: 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300' },
    comments: { label: 'Comment', icon: MessageSquare, tint: 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300' },
    status: { label: 'Status change', icon: ArrowRightLeft, tint: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300' },
    reminders: { label: 'Reminder', icon: AlarmClock, tint: 'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300' },
    pages: { label: 'Page', icon: FileText, tint: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' },
    sprints: { label: 'Sprint', icon: Zap, tint: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300' },
    helpdesk: { label: 'Helpdesk', icon: LifeBuoy, tint: 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300' },
    leads: { label: 'Lead', icon: Magnet, tint: 'bg-teal-100 text-teal-700 dark:bg-teal-500/20 dark:text-teal-300' },
    workspace: { label: 'Workspace', icon: Users, tint: 'bg-slate-200 text-slate-700 dark:bg-slate-500/25 dark:text-slate-200' },
    reviews: { label: 'Review', icon: BadgeCheck, tint: 'bg-lime-100 text-lime-700 dark:bg-lime-500/20 dark:text-lime-300' },
    feedback: { label: 'Feedback', icon: Lightbulb, tint: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-500/20 dark:text-yellow-300' },
};

const FALLBACK: CategoryStyle = { label: 'Notification', icon: Bell, tint: 'bg-muted text-muted-foreground' };

export function categoryStyle(category: string | null | undefined): CategoryStyle {
    return (category && STYLES[category as NotificationCategoryKey]) || FALLBACK;
}
