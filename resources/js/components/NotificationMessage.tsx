import { Bell, ExternalLink, LifeBuoy } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * The database payload of every notification type the app sends (see app/Notifications).
 * Rendered by the bell dropdown and the notifications page — add new types here only.
 */
export interface NotificationData {
    type:
        | 'mentioned'
        | 'page_updated'
        | 'item_assigned'
        | 'item_status_changed'
        | 'item_reminder'
        | 'ticket_created'
        | 'ticket_reply'
        | 'ticket_assigned'
        | 'lead_created'
        | string;
    item_key?: string;
    item_title?: string;
    item_url?: string;
    old_column?: string;
    new_column?: string;
    when_label?: string;
    // task_digest
    due_today?: number;
    overdue?: number;
    titles?: string[];
    url?: string;
    context_type?: string;
    context_title?: string;
    context_url?: string;
    page_name?: string;
    page_url?: string;
    ticket_key?: string;
    ticket_subject?: string;
    ticket_url?: string;
    reply_preview?: string;
    contact_name?: string;
    contact_email?: string;
    actor_name?: string;
    actor_avatar?: string;
    // lead_created (BRAI-93)
    form_name?: string;
    lead_number?: number;
    lead_name?: string;
    lead_url?: string;
}

/** Who did it: a user, or the customer for helpdesk tickets. */
export function notificationActor(d: NotificationData): string {
    return d.actor_name || d.contact_name || d.contact_email || '';
}

/** Only links into this app: a relative path or an absolute URL on this origin. */
export function safeAppHref(url: string | undefined): string | undefined {
    if (!url) return undefined;
    try {
        // always parse (browsers strip tabs / newlines, so "/\t/evil.com" means //evil.com)
        const parsed = new URL(url, window.location.origin);
        if (parsed.origin !== window.location.origin || !/^https?:$/.test(parsed.protocol)) return undefined;
        return parsed.pathname + parsed.search + parsed.hash;
    } catch {
        return undefined;
    }
}

export function notificationUrl(d: NotificationData): string | undefined {
    return safeAppHref(d.ticket_url ?? d.lead_url ?? d.item_url ?? d.context_url ?? d.page_url);
}

/** Second line under the sentence. */
export function notificationDetail(d: NotificationData): string | undefined {
    if (d.type === 'task_digest') return d.titles?.join(', ');
    if (d.type === 'ticket_reply') return d.reply_preview || d.ticket_subject;
    if (d.type === 'lead_created') return d.form_name;
    return d.ticket_subject ?? d.item_title ?? d.page_name;
}

export function NotificationAvatar({ data: d }: { data: NotificationData }) {
    if (d.type === 'item_reminder' || d.type === 'task_digest') return <Bell className="size-4 text-muted-foreground" />;
    if (d.actor_avatar) return <img src={d.actor_avatar} alt={d.actor_name} className="size-full object-cover" />;
    const actor = notificationActor(d);
    if (actor) return <>{actor.charAt(0)}</>;
    return d.type.startsWith('ticket_') ? <LifeBuoy className="size-4 text-muted-foreground" /> : <>?</>;
}

/** "Alice assigned you to WEB-12", "customer@x.com opened ticket SUP-3", … */
export function NotificationMessage({
    data: d,
    onOpen,
    linkClassName = 'font-medium text-primary hover:underline inline-flex items-center gap-0.5',
    iconClassName = 'size-2.5',
    newTab = false,
}: {
    data: NotificationData;
    onOpen?: () => void;
    linkClassName?: string;
    iconClassName?: string;
    newTab?: boolean;
}) {
    const link = (href: string | undefined, label: ReactNode) => (
        <a href={safeAppHref(href)} className={linkClassName} onClick={onOpen} {...(newTab ? { target: '_blank', rel: 'noreferrer' } : {})}>
            {label}
            <ExternalLink className={iconClassName} />
        </a>
    );
    const actor = <span className="font-medium">{notificationActor(d) || 'Someone'}</span>;

    switch (d.type) {
        case 'task_digest':
            return (
                <>
                    Your tasks:{' '}
                    {link(d.url, [d.due_today ? `${d.due_today} due today` : null, d.overdue ? `${d.overdue} overdue` : null].filter(Boolean).join(' · '))}
                </>
            );
        case 'item_reminder':
            return (
                <>
                    {link(d.item_url, d.item_key)} {d.when_label}
                </>
            );
        case 'mentioned':
            return (
                <>
                    {actor} mentioned you {d.context_type === 'page' ? 'on page ' : 'in '}
                    {link(d.context_url, d.context_title)}
                </>
            );
        case 'page_updated':
            return (
                <>
                    {actor} updated page {link(d.page_url, d.page_name)}
                </>
            );
        case 'item_assigned':
            return (
                <>
                    {actor} assigned you to {link(d.item_url, d.item_key)}
                </>
            );
        case 'item_status_changed':
            return (
                <>
                    {actor} moved {link(d.item_url, d.item_key)} from <span className="text-muted-foreground">{d.old_column}</span>
                    <span className="mx-1 text-muted-foreground">→</span>
                    <span className="font-medium">{d.new_column}</span>
                </>
            );
        case 'ticket_created':
            return (
                <>
                    {actor} opened ticket {link(d.ticket_url, d.ticket_key)}
                </>
            );
        case 'lead_created':
            return (
                <>
                    New lead {link(d.lead_url, `#${d.lead_number ?? ''}`)} <span className="font-medium">{d.lead_name}</span>
                </>
            );
        case 'ticket_reply':
            return (
                <>
                    {actor} replied to ticket {link(d.ticket_url, d.ticket_key)}
                </>
            );
        case 'ticket_assigned':
            return (
                <>
                    {actor} assigned you to ticket {link(d.ticket_url, d.ticket_key)}
                </>
            );
        default:
            return (
                <>
                    {actor} updated {link(notificationUrl(d), d.ticket_key ?? d.item_key ?? d.page_name ?? 'this')}
                </>
            );
    }
}
