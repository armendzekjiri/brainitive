import type { NotificationData } from '@/types';
import type { ReactNode } from 'react';

export type { NotificationData };

/**
 * Turns any notification payload into what a row shows. The ONE place that knows payload keys
 * (bell, inbox page). Older types keep their own keys and wording; every other type — including
 * ones added later — renders from the payload contract: title, body, url, actor_name,
 * actor_avatar, project_name, subject_key (docs/ai/tasks/release-v1.39.0.md).
 */
export interface NotificationView {
    /** The worded line, with the actor and subject emphasised. */
    headline: ReactNode;
    /** The same line as plain text (labels, tooltips). */
    text: string;
    /** Optional preview / detail line. */
    body?: string;
    /** Issue / ticket key, shown as a chip. */
    subjectKey?: string;
    /** Context line: project, board, form … */
    context?: string;
    /** Safe in-app path to open, if any. */
    url?: string;
    actorName?: string;
    actorAvatar?: string;
}

/** Only links into this app: a relative path or an absolute URL on this origin. */
export function safeAppHref(url: unknown): string | undefined {
    if (typeof url !== 'string' || !url) return undefined;
    try {
        // always parse (browsers strip tabs / newlines, so "/\t/evil.com" means //evil.com)
        const parsed = new URL(url, window.location.origin);
        if (parsed.origin !== window.location.origin || !/^https?:$/.test(parsed.protocol)) return undefined;
        return parsed.pathname + parsed.search + parsed.hash;
    } catch {
        return undefined;
    }
}

const str = (v: unknown): string | undefined => (typeof v === 'string' && v.trim() !== '' ? v : undefined);

/** Who did it: a user, or the customer for helpdesk tickets. */
export function notificationActor(d: NotificationData): string | undefined {
    return str(d.actor_name) ?? str(d.contact_name) ?? str(d.contact_email);
}

type Part = string | { b: string } | undefined | null | false;

/** Builds a headline from parts: strings stay plain, {b} parts are emphasised. */
function line(...parts: Part[]): { headline: ReactNode; text: string } {
    const kept = parts.filter((p): p is string | { b: string } => !!p && (typeof p === 'string' || !!p.b));
    return {
        headline: (
            <>
                {kept.map((p, i) =>
                    typeof p === 'string' ? (
                        <span key={i}>{p}</span>
                    ) : (
                        <strong key={i} className="font-semibold text-foreground">
                            {p.b}
                        </strong>
                    ),
                )}
            </>
        ),
        text: kept.map((p) => (typeof p === 'string' ? p : p.b)).join(''),
    };
}

export function describeNotification(d: NotificationData): NotificationView {
    const actor = notificationActor(d);
    const who = { b: actor ?? 'Someone' };
    const base = { actorName: actor, actorAvatar: str(d.actor_avatar) };

    switch (d.type) {
        case 'task_digest': {
            const summary = [d.due_today ? `${d.due_today} due today` : null, d.overdue ? `${d.overdue} overdue` : null].filter(Boolean).join(' · ');
            return { ...base, ...line('Your tasks: ', { b: summary || 'nothing due' }), body: d.titles?.join(', '), url: safeAppHref(d.url) };
        }
        case 'item_reminder':
            return {
                ...base,
                ...line({ b: d.item_key ?? 'An item' }, d.when_label ? ` ${d.when_label}` : ''),
                body: str(d.item_title),
                subjectKey: str(d.item_key),
                url: safeAppHref(d.item_url),
            };
        case 'mentioned':
            return {
                ...base,
                ...line(who, d.context_type === 'page' ? ' mentioned you on page ' : ' mentioned you in ', { b: d.context_title ?? '' }),
                url: safeAppHref(d.context_url),
            };
        case 'page_updated':
            return { ...base, ...line(who, ' updated page ', { b: d.page_name ?? '' }), url: safeAppHref(d.page_url) };
        case 'item_assigned':
            return {
                ...base,
                ...line(who, ' assigned you to ', { b: d.item_key ?? '' }),
                body: str(d.item_title),
                subjectKey: str(d.item_key),
                url: safeAppHref(d.item_url),
            };
        case 'item_status_changed':
            return {
                ...base,
                ...line(who, ' moved ', { b: d.item_key ?? '' }, ` from ${d.old_column ?? '?'} → `, { b: d.new_column ?? '?' }),
                body: str(d.item_title),
                subjectKey: str(d.item_key),
                url: safeAppHref(d.item_url),
            };
        case 'ticket_created':
            return {
                ...base,
                ...line(who, ' opened ticket ', { b: d.ticket_key ?? '' }),
                body: str(d.ticket_subject),
                subjectKey: str(d.ticket_key),
                url: safeAppHref(d.ticket_url),
            };
        case 'ticket_reply':
            return {
                ...base,
                ...line(who, ' replied to ticket ', { b: d.ticket_key ?? '' }),
                body: str(d.reply_preview) ?? str(d.ticket_subject),
                subjectKey: str(d.ticket_key),
                url: safeAppHref(d.ticket_url),
            };
        case 'ticket_assigned':
            return {
                ...base,
                ...line(who, ' assigned you to ticket ', { b: d.ticket_key ?? '' }),
                body: str(d.ticket_subject),
                subjectKey: str(d.ticket_key),
                url: safeAppHref(d.ticket_url),
            };
        case 'lead_created':
            return {
                ...base,
                ...line('New lead ', { b: `#${d.lead_number ?? ''}` }, d.lead_name ? ' from ' : '', d.lead_name ? { b: d.lead_name } : null),
                context: str(d.form_name),
                url: safeAppHref(d.lead_url),
            };
        default:
            return {
                ...base,
                ...genericLine(d, actor),
                body: str(d.body),
                subjectKey: str(d.subject_key),
                context: str(d.project_name),
                url: safeAppHref(d.url),
            };
    }
}

/** Contract types: the server words the title; the actor's name at its start is emphasised. */
function genericLine(d: NotificationData, actor: string | undefined) {
    const title = str(d.title);
    if (!title) {
        const subject = str(d.subject_key) ?? str(d.item_key) ?? str(d.ticket_key) ?? str(d.page_name);
        return line({ b: actor ?? 'Someone' }, subject ? ' updated ' : ' sent you a notification', subject ? { b: subject } : null);
    }
    if (actor && title.startsWith(actor)) return line({ b: actor }, title.slice(actor.length));
    return line(title);
}

/** The headline alone (for callers that only need the sentence). */
export function NotificationMessage({ data }: { data: NotificationData }) {
    return <>{describeNotification(data).headline}</>;
}
