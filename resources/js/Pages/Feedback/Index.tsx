import { FeedbackStatusBadge, FlashNotice, PortalHeading, VoteButton } from '@/components/feedback/FeedbackBits';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import PortalLayout from '@/Layouts/PortalLayout';
import { cn } from '@/lib/utils';
import type { FeedbackPortalData, FeedbackPostData, FeedbackStatus, PortalIdentityData } from '@/types';
import { Link, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { ChevronLeft, ChevronRight, Clock, LogIn, Plus, Search, X } from 'lucide-react';
import { type FormEvent, type ReactNode, useEffect, useState } from 'react';

interface Props {
    portal: FeedbackPortalData;
    posts: FeedbackPostData[];
    pagination: { current_page: number; last_page: number; total: number; prev_url: string | null; next_url: string | null };
    pending: FeedbackPostData[];
    filters: { sort: 'top' | 'new'; status: FeedbackStatus | null; q: string };
    counts: Partial<Record<FeedbackStatus, number>>;
    statuses: Record<FeedbackStatus, string>;
    identity: PortalIdentityData | null;
}

interface Similar {
    number: number;
    title: string;
    status_label: string;
    votes_count: number;
    url: string;
}

/** Public feedback portal: ideas list, filters, and "share an idea" (FeedbackPortalController::index). */
export default function FeedbackIndex({ portal, posts, pagination, pending, filters, counts, statuses, identity }: Props) {
    const [search, setSearch] = useState(filters.q);
    const [composing, setComposing] = useState(false);

    const visit = (next: Partial<Props['filters']>) => {
        const merged = { ...filters, q: search, ...next };
        router.get(
            portal.url,
            { sort: merged.sort === 'top' ? undefined : merged.sort, status: merged.status ?? undefined, q: merged.q || undefined },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const total = Object.values(counts).reduce((a, b) => a + (b ?? 0), 0);

    return (
        <PortalLayout title={portal.title} brand={portal.title} subtitle="Feedback" identity={identity}>
            <FlashNotice />
            <PortalHeading portal={portal} active="ideas" />

            <section className="mb-6 rounded-2xl border bg-card p-4 sm:p-5">
                {identity ? (
                    composing ? (
                        <IdeaForm portal={portal} onCancel={() => setComposing(false)} />
                    ) : (
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-sm text-muted-foreground">Have an idea or a wish? Tell us — others can vote for it.</p>
                            <Button onClick={() => setComposing(true)}>
                                <Plus className="size-4" />
                                Share an idea
                            </Button>
                        </div>
                    )
                ) : (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-muted-foreground">Sign in with your email to share ideas, vote and follow updates.</p>
                        <Button asChild>
                            <Link href={route('guest.login', { return_to: portal.url.replace(/^https?:\/\/[^/]+/, '') })}>
                                <LogIn className="size-4" />
                                Sign in to share an idea
                            </Link>
                        </Button>
                    </div>
                )}
            </section>

            {pending.length > 0 && (
                <section className="mb-6 rounded-2xl border border-dashed p-4" aria-label="Your ideas waiting for review">
                    <h2 className="mb-2 flex items-center gap-1.5 text-sm font-semibold">
                        <Clock className="size-4" />
                        Waiting for review
                    </h2>
                    <ul className="space-y-1 text-sm">
                        {pending.map((p) => (
                            <li key={p.number}>
                                <Link href={p.url} className="underline-offset-2 hover:underline">
                                    {p.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <div className="inline-flex shrink-0 rounded-lg border p-0.5" role="group" aria-label="Sort">
                    {(['top', 'new'] as const).map((s) => (
                        <button
                            key={s}
                            type="button"
                            aria-pressed={filters.sort === s}
                            onClick={() => visit({ sort: s })}
                            className={cn(
                                'rounded-md px-3 py-1 text-sm font-medium',
                                filters.sort === s ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {s === 'top' ? 'Top' : 'New'}
                        </button>
                    ))}
                </div>
                <form
                    className="relative flex-1"
                    role="search"
                    onSubmit={(e) => {
                        e.preventDefault();
                        visit({ q: search });
                    }}
                >
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search ideas"
                        aria-label="Search ideas"
                        className="pl-9"
                        maxLength={100}
                    />
                </form>
            </div>

            <div className="mb-4 flex flex-wrap gap-1.5" role="group" aria-label="Filter by status">
                <FilterChip active={!filters.status} onClick={() => visit({ status: null })}>
                    All <span className="tabular-nums opacity-70">{total}</span>
                </FilterChip>
                {(Object.keys(statuses) as FeedbackStatus[]).map((s) => (
                    <FilterChip key={s} active={filters.status === s} onClick={() => visit({ status: s })}>
                        {statuses[s]} <span className="tabular-nums opacity-70">{counts[s] ?? 0}</span>
                    </FilterChip>
                ))}
            </div>

            {posts.length === 0 ? (
                <div className="rounded-2xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                    {filters.q || filters.status ? (
                        <>
                            No ideas match.{' '}
                            <button type="button" className="underline underline-offset-2" onClick={() => { setSearch(''); visit({ q: '', status: null }); }}>
                                Clear filters
                            </button>
                        </>
                    ) : (
                        'No ideas yet. Be the first to share one!'
                    )}
                </div>
            ) : (
                <ul className="space-y-3" aria-label="Ideas">
                    {posts.map((post) => (
                        <li key={post.number} className="flex gap-3 rounded-2xl border bg-card p-3 sm:gap-4 sm:p-4" data-testid="feedback-post">
                            <VoteButton portal={portal} post={post} identity={identity} />
                            <div className="min-w-0 flex-1">
                                <Link href={post.url} className="font-semibold leading-snug underline-offset-2 hover:underline [overflow-wrap:anywhere]">
                                    {post.title}
                                </Link>
                                {post.excerpt && <p className="mt-1 line-clamp-2 text-sm text-muted-foreground [overflow-wrap:anywhere]">{post.excerpt}</p>}
                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    <FeedbackStatusBadge status={post.status} label={post.status_label} />
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {pagination.last_page > 1 && (
                <nav className="mt-6 flex items-center justify-between gap-2" aria-label="Pages">
                    <Button variant="outline" size="sm" asChild={!!pagination.prev_url} disabled={!pagination.prev_url}>
                        {pagination.prev_url ? (
                            <Link href={pagination.prev_url} preserveScroll={false}>
                                <ChevronLeft className="size-4" /> Previous
                            </Link>
                        ) : (
                            <span><ChevronLeft className="size-4" /> Previous</span>
                        )}
                    </Button>
                    <span className="text-sm text-muted-foreground">
                        Page {pagination.current_page} of {pagination.last_page}
                    </span>
                    <Button variant="outline" size="sm" asChild={!!pagination.next_url} disabled={!pagination.next_url}>
                        {pagination.next_url ? (
                            <Link href={pagination.next_url}>
                                Next <ChevronRight className="size-4" />
                            </Link>
                        ) : (
                            <span>Next <ChevronRight className="size-4" /></span>
                        )}
                    </Button>
                </nav>
            )}
        </PortalLayout>
    );
}

function FilterChip({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                active ? 'border-primary bg-primary/10 text-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
            )}
        >
            {children}
        </button>
    );
}

/** Title + details, with similar ideas from the search while typing the title. */
function IdeaForm({ portal, onCancel }: { portal: FeedbackPortalData; onCancel: () => void }) {
    const form = useForm({ title: '', body: '' });
    const [similar, setSimilar] = useState<Similar[]>([]);

    useEffect(() => {
        const q = form.data.title.trim();
        if (q.length < 3) {
            setSimilar([]);
            return;
        }
        const timer = window.setTimeout(() => {
            axios
                .get<{ posts: Similar[] }>(route('feedback.similar', { portalSlug: portal.slug, q }))
                .then((r) => setSimilar(r.data.posts))
                .catch(() => setSimilar([]));
        }, 300);
        return () => window.clearTimeout(timer);
    }, [form.data.title, portal.slug]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('feedback.posts.store', portal.slug));
    };

    return (
        <form onSubmit={submit} className="space-y-4" aria-label="Share an idea">
            <div className="flex items-center justify-between gap-2">
                <h2 className="font-semibold">Share an idea</h2>
                <Button type="button" variant="ghost" size="icon" onClick={onCancel} aria-label="Cancel">
                    <X className="size-4" />
                </Button>
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="idea-title">Title</Label>
                <Input
                    id="idea-title"
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                    placeholder="One line: what would you like?"
                    maxLength={160}
                    required
                    autoFocus
                />
                {form.errors.title && <p className="text-sm text-destructive">{form.errors.title}</p>}
            </div>
            {similar.length > 0 && (
                <div className="rounded-xl border bg-muted/40 p-3" data-testid="feedback-similar">
                    <p className="mb-2 text-xs font-medium text-muted-foreground">Similar ideas — vote for one instead?</p>
                    <ul className="space-y-1">
                        {similar.map((s) => (
                            <li key={s.number} className="flex items-center justify-between gap-3 text-sm">
                                <a href={s.url} target="_blank" rel="noopener noreferrer" className="min-w-0 truncate underline-offset-2 hover:underline">
                                    {s.title}
                                </a>
                                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                    {s.votes_count} vote{s.votes_count === 1 ? '' : 's'} · {s.status_label}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            <div className="space-y-1.5">
                <Label htmlFor="idea-body">
                    Details <span className="font-normal text-muted-foreground">(optional)</span>
                </Label>
                <Textarea
                    id="idea-body"
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                    rows={4}
                    maxLength={5000}
                    placeholder="Why would it help you? Plain text."
                />
                {form.errors.body && <p className="text-sm text-destructive">{form.errors.body}</p>}
            </div>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs text-muted-foreground">New ideas are reviewed before they appear.</p>
                <Button type="submit" disabled={form.processing}>
                    Submit idea
                </Button>
            </div>
        </form>
    );
}
