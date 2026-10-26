import { cn } from '@/lib/utils';
import type { FeedbackPortalData, FeedbackPostData, FeedbackStatus, PortalIdentityData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { CheckCircle2, ChevronUp, Lightbulb, Map as MapIcon } from 'lucide-react';
import { useState } from 'react';

/** Public feedback portal building blocks (v1.42.0, BRAI-60). */

export const STATUS_CLASSES: Record<FeedbackStatus, string> = {
    open: 'bg-muted text-muted-foreground border-border',
    planned: 'bg-sky-100 text-sky-800 border-sky-200 dark:bg-sky-950 dark:text-sky-200 dark:border-sky-900',
    building: 'bg-amber-100 text-amber-900 border-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:border-amber-900',
    released: 'bg-emerald-100 text-emerald-800 border-emerald-200 dark:bg-emerald-950 dark:text-emerald-200 dark:border-emerald-900',
    declined: 'bg-rose-100 text-rose-800 border-rose-200 dark:bg-rose-950 dark:text-rose-200 dark:border-rose-900',
};

export function FeedbackStatusBadge({ status, label, className }: { status: FeedbackStatus; label: string; className?: string }) {
    return (
        <span
            className={cn('inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium', STATUS_CLASSES[status], className)}
            data-testid="feedback-status"
        >
            {label}
        </span>
    );
}

/** Up-vote toggle. Signed out → the guest sign-in, coming back to this page. */
export function VoteButton({ portal, post, identity, size = 'md' }: { portal: FeedbackPortalData; post: FeedbackPostData; identity: PortalIdentityData | null; size?: 'md' | 'lg' }) {
    const [busy, setBusy] = useState(false);
    const base = cn(
        'flex shrink-0 flex-col items-center justify-center rounded-xl border font-semibold tabular-nums transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        size === 'lg' ? 'h-16 w-16 text-lg' : 'h-14 w-12 text-sm',
    );
    const label = `${post.has_voted ? 'Remove your vote for' : 'Vote for'} “${post.title}” (${post.votes_count} vote${post.votes_count === 1 ? '' : 's'})`;

    if (!identity) {
        const back = typeof window !== 'undefined' ? window.location.pathname + window.location.search : post.url;
        return (
            <Link href={route('guest.login', { return_to: back })} className={cn(base, 'bg-card hover:bg-muted')} aria-label={label} data-testid="feedback-vote">
                <ChevronUp className="size-4" />
                {post.votes_count}
            </Link>
        );
    }

    const toggle = () => {
        const options = { preserveScroll: true, preserveState: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };
        const url = route(post.has_voted ? 'feedback.unvote' : 'feedback.vote', [portal.slug, post.number]);
        if (post.has_voted) router.delete(url, options);
        else router.post(url, {}, options);
    };

    return (
        <button
            type="button"
            onClick={toggle}
            disabled={busy}
            aria-pressed={post.has_voted}
            aria-label={label}
            data-testid="feedback-vote"
            className={cn(
                base,
                post.has_voted ? 'border-primary bg-primary text-primary-foreground hover:bg-primary/90' : 'bg-card hover:bg-muted',
                busy && 'opacity-70',
            )}
        >
            <ChevronUp className="size-4" />
            {post.votes_count}
        </button>
    );
}

/** Ideas / Roadmap switch under the portal header. */
export function PortalNav({ portal, active }: { portal: FeedbackPortalData; active: 'ideas' | 'roadmap' }) {
    const item = (key: 'ideas' | 'roadmap', href: string, Icon: typeof Lightbulb, text: string) => (
        <Link
            href={href}
            aria-current={active === key ? 'page' : undefined}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                active === key ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
            )}
        >
            <Icon className="size-4" />
            {text}
        </Link>
    );

    return (
        <nav className="flex gap-1" aria-label="Feedback portal">
            {item('ideas', portal.url, Lightbulb, 'Ideas')}
            {item('roadmap', portal.roadmap_url, MapIcon, 'Roadmap')}
        </nav>
    );
}

/** The portal header block: title, intro and the Ideas / Roadmap switch. */
export function PortalHeading({ portal, active }: { portal: FeedbackPortalData; active: 'ideas' | 'roadmap' }) {
    return (
        <div className="mb-6 space-y-3">
            <div>
                <h1 className="text-2xl font-bold tracking-tight sm:text-3xl" data-testid="feedback-portal-title">{portal.title}</h1>
                {portal.intro && <p className="mt-2 whitespace-pre-line text-muted-foreground">{portal.intro}</p>}
            </div>
            <PortalNav portal={portal} active={active} />
        </div>
    );
}

/** Flash message from the server (portal pages have no toaster). */
export function FlashNotice() {
    const flash = (usePage().props as { flash?: { success?: string | null; error?: string | null } }).flash;
    if (!flash?.success && !flash?.error) return null;

    return (
        <div
            role="status"
            className={cn(
                'mb-4 flex items-start gap-2 rounded-xl border px-4 py-3 text-sm',
                flash.error
                    ? 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100'
                    : 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100',
            )}
        >
            <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
            <span>{flash.error ?? flash.success}</span>
        </div>
    );
}

export function formatDate(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}
