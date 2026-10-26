import { FeedbackStatusBadge, formatDate } from '@/components/feedback/FeedbackBits';
import { ProjectIcon } from '@/components/ProjectIcon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import type { FeedbackEventData, FeedbackModerationPost, FeedbackStatus, Project, Workspace } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, Copy, ExternalLink, GitMerge, History, Link2, MessageSquareHeart, Plus, Unlink, X } from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';
import { toast } from 'sonner';

type Tab = 'pending' | 'published' | 'rejected' | 'merged';

interface Props {
    workspace: Workspace;
    project: Project;
    portal: { enabled: boolean; slug: string; title: string; intro: string | null; public_url: string } | null;
    suggestedSlug: string | null;
    tab: Tab;
    counts: Record<Tab, number>;
    posts: FeedbackModerationPost[];
    targets: { id: number; number: number; title: string }[];
    boards: { id: number; name: string }[];
    events: FeedbackEventData[];
    statuses: Record<FeedbackStatus, string>;
}

const TAB_LABELS: Record<Tab, string> = { pending: 'Waiting for review', published: 'Published', rejected: 'Rejected', merged: 'Merged' };

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-background px-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';

/** Project → Feedback (FeedbackModerationController): settings, moderation queue, audit log. */
export default function ProjectFeedback({ workspace, project, portal, suggestedSlug, tab, counts, posts, targets, boards, events, statuses }: Props) {
    const params = [workspace.slug, project.slug];

    return (
        <AppLayout>
            <Head title={`Feedback · ${project.name}`} />
            <div className="mx-auto w-full max-w-5xl space-y-8 px-4 py-6 sm:px-6">
                <div className="flex flex-wrap items-center gap-3">
                    <ProjectIcon project={project} size="md" />
                    <div className="min-w-0 flex-1">
                        <Link href={route('projects.show', params)} className="text-xs text-muted-foreground hover:underline">
                            {project.name}
                        </Link>
                        <h1 className="flex items-center gap-2 text-2xl font-bold">
                            <MessageSquareHeart className="size-6 text-primary" />
                            Feedback portal
                        </h1>
                    </div>
                    {portal && <PublicLink portal={portal} />}
                </div>

                <Settings params={params} portal={portal} suggestedSlug={suggestedSlug} />

                {portal && (
                    <section className="space-y-4" aria-label="Moderation">
                        <nav className="flex flex-wrap gap-1 border-b" aria-label="Ideas by state">
                            {(Object.keys(TAB_LABELS) as Tab[]).map((t) => (
                                <Link
                                    key={t}
                                    href={route('projects.feedback.index', [...params, { tab: t }])}
                                    preserveScroll
                                    aria-current={tab === t ? 'page' : undefined}
                                    className={cn(
                                        '-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium',
                                        tab === t ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {TAB_LABELS[t]}
                                    <span className="rounded-full bg-muted px-1.5 text-xs tabular-nums">{counts[t]}</span>
                                </Link>
                            ))}
                        </nav>

                        {posts.length === 0 ? (
                            <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                                {tab === 'pending' ? 'No new ideas to review.' : 'Nothing here.'}
                            </p>
                        ) : (
                            <ul className="space-y-3">
                                {posts.map((post) => (
                                    <PostCard key={post.id} params={params} post={post} targets={targets} boards={boards} statuses={statuses} />
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                {events.length > 0 && (
                    <section aria-label="Moderation log">
                        <h2 className="mb-2 flex items-center gap-1.5 text-sm font-semibold">
                            <History className="size-4" />
                            Moderation log
                        </h2>
                        <ul className="divide-y rounded-xl border bg-card text-sm" data-testid="feedback-audit">
                            {events.map((event) => (
                                <li key={event.id} className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 px-3 py-2">
                                    <span className="min-w-0 [overflow-wrap:anywhere]">
                                        <strong>{event.actor_name ?? 'Someone'}</strong> {event.label}
                                        {event.post_number !== null && <> #{event.post_number}</>}
                                        {describeChanges(event, statuses)}
                                    </span>
                                    <time className="text-xs text-muted-foreground">{event.created_at ? new Date(event.created_at).toLocaleString() : ''}</time>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}

function describeChanges(event: FeedbackEventData, statuses: Record<FeedbackStatus, string>): string {
    const c = (event.changes ?? {}) as Record<string, unknown>;
    if (event.action === 'status_changed' && typeof c.to === 'string') return ` → ${statuses[c.to as FeedbackStatus] ?? c.to}`;
    if (event.action === 'merged' && c.into_number) return ` into #${String(c.into_number)}`;
    if ((event.action === 'card_created' || event.action === 'card_linked' || event.action === 'card_unlinked') && c.card) return ` (${String(c.card)})`;
    return '';
}

function PublicLink({ portal }: { portal: NonNullable<Props['portal']> }) {
    const copy = () => {
        if (!navigator.clipboard) {
            toast.error('Could not copy the link');
            return;
        }
        navigator.clipboard
            .writeText(portal.public_url)
            .then(() => toast.success('Link copied'))
            .catch(() => toast.error('Could not copy the link'));
    };

    return (
        <div className="flex w-full min-w-0 items-center gap-2 sm:w-auto">
            <span
                className={cn(
                    'shrink-0 rounded-full px-2 py-0.5 text-xs font-medium',
                    portal.enabled ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-muted text-muted-foreground',
                )}
                data-testid="feedback-portal-state"
            >
                {portal.enabled ? 'Public' : 'Off'}
            </span>
            <code className="min-w-0 truncate rounded bg-muted px-2 py-1 text-xs" data-testid="feedback-public-url">
                {portal.public_url}
            </code>
            <Button variant="outline" size="icon" className="size-8 shrink-0" onClick={copy} aria-label="Copy public link">
                <Copy className="size-4" />
            </Button>
            {portal.enabled && (
                <Button variant="outline" size="icon" className="size-8 shrink-0" asChild>
                    <a href={portal.public_url} target="_blank" rel="noopener noreferrer" aria-label="Open the public portal">
                        <ExternalLink className="size-4" />
                    </a>
                </Button>
            )}
        </div>
    );
}

function Settings({ params, portal, suggestedSlug }: { params: string[]; portal: Props['portal']; suggestedSlug: string | null }) {
    const form = useForm({
        enabled: portal?.enabled ?? false,
        slug: portal?.slug ?? suggestedSlug ?? '',
        title: portal?.title ?? 'Feedback',
        intro: portal?.intro ?? '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('projects.feedback.settings', params), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-4 rounded-2xl border bg-card p-4 sm:p-5" aria-label="Portal settings">
            <div>
                <h2 className="font-semibold">Settings</h2>
                <p className="text-sm text-muted-foreground">
                    A public page where anyone can read ideas; people sign in with their email to submit, vote and follow. New ideas wait for
                    your review. Card details, comments and assignees are never shown there.
                </p>
            </div>
            <label className="flex items-center gap-2 text-sm font-medium">
                <input
                    type="checkbox"
                    className="size-4 rounded border-input accent-primary"
                    checked={form.data.enabled}
                    onChange={(e) => form.setData('enabled', e.target.checked)}
                />
                Portal is public
            </label>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="feedback-title">Title</Label>
                    <Input id="feedback-title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} maxLength={120} required />
                    {form.errors.title && <p className="text-sm text-destructive">{form.errors.title}</p>}
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="feedback-slug">Public address</Label>
                    <div className="flex min-w-0 items-center rounded-md border border-input bg-background shadow-sm focus-within:ring-1 focus-within:ring-ring">
                        <span className="shrink-0 pl-3 text-sm text-muted-foreground">/feedback/</span>
                        <input
                            id="feedback-slug"
                            className="h-9 min-w-0 flex-1 bg-transparent pr-3 text-sm focus:outline-none"
                            value={form.data.slug}
                            onChange={(e) => form.setData('slug', e.target.value.toLowerCase())}
                            maxLength={60}
                            required
                        />
                    </div>
                    {form.errors.slug && <p className="text-sm text-destructive">{form.errors.slug}</p>}
                </div>
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="feedback-intro">
                    Introduction <span className="font-normal text-muted-foreground">(optional, plain text)</span>
                </Label>
                <Textarea id="feedback-intro" value={form.data.intro} onChange={(e) => form.setData('intro', e.target.value)} rows={3} maxLength={2000} />
                {form.errors.intro && <p className="text-sm text-destructive">{form.errors.intro}</p>}
            </div>
            <div className="flex justify-end">
                <Button type="submit" disabled={form.processing}>
                    Save settings
                </Button>
            </div>
        </form>
    );
}

function PostCard({
    params,
    post,
    targets,
    boards,
    statuses,
}: {
    params: string[];
    post: FeedbackModerationPost;
    targets: Props['targets'];
    boards: Props['boards'];
    statuses: Record<FeedbackStatus, string>;
}) {
    const p = [...params, post.id];
    const act = (method: 'post' | 'put' | 'delete', name: string, data: Record<string, string | number | null> = {}) =>
        router[method](route(name, p), data, { preserveScroll: true });

    return (
        <li className="rounded-2xl border bg-card p-4" data-testid="feedback-moderation-post">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-mono text-xs text-muted-foreground">#{post.number}</span>
                        <h3 className="font-semibold [overflow-wrap:anywhere]">{post.title}</h3>
                        {post.moderation === 'approved' && !post.merged_into && <FeedbackStatusBadge status={post.status} label={statuses[post.status]} />}
                    </div>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {post.author ? `${post.author.name} <${post.author.email}>` : 'Unknown author'} · {formatDate(post.created_at)} ·{' '}
                        <span className="tabular-nums">{post.votes_count} vote{post.votes_count === 1 ? '' : 's'}</span>
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {post.moderation !== 'approved' && !post.merged_into && (
                        <Button size="sm" onClick={() => act('post', 'projects.feedback.moderate', { decision: 'approve' })}>
                            <Check className="size-4" />
                            Approve
                        </Button>
                    )}
                    {post.moderation === 'pending' && !post.merged_into && (
                        <Button size="sm" variant="outline" onClick={() => act('post', 'projects.feedback.moderate', { decision: 'reject' })}>
                            <X className="size-4" />
                            Reject
                        </Button>
                    )}
                    {post.moderation === 'approved' && !post.merged_into && (
                        <Button size="sm" variant="ghost" asChild>
                            <a href={post.public_url} target="_blank" rel="noopener noreferrer">
                                <ExternalLink className="size-4" />
                                Public page
                            </a>
                        </Button>
                    )}
                </div>
            </div>

            {post.body && <p className="mt-3 line-clamp-6 whitespace-pre-line text-sm [overflow-wrap:anywhere]">{post.body}</p>}

            {post.merged_into ? (
                <p className="mt-3 text-sm text-muted-foreground">
                    Merged into #{post.merged_into.number} “{post.merged_into.title}”. Its old link redirects there.
                </p>
            ) : (
                <div className="mt-4 grid gap-4 border-t pt-4 lg:grid-cols-3">
                    {post.moderation === 'approved' && <StatusForm params={p} post={post} statuses={statuses} />}
                    <Panel title="Duplicate?" icon={<GitMerge className="size-4" />}>
                        <MergeForm params={p} post={post} targets={targets} />
                    </Panel>
                    <Panel title="Card" icon={<Link2 className="size-4" />}>
                        {post.item ? (
                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                <Link href={post.item.url} className="font-mono text-primary hover:underline" data-testid="feedback-card">
                                    {post.item.key}
                                </Link>
                                <span className="min-w-0 truncate text-muted-foreground">{post.item.title}</span>
                                <Button size="sm" variant="ghost" onClick={() => act('delete', 'projects.feedback.card.unlink')} aria-label="Unlink card">
                                    <Unlink className="size-4" />
                                </Button>
                            </div>
                        ) : (
                            <CardForms params={p} boards={boards} />
                        )}
                    </Panel>
                </div>
            )}
        </li>
    );
}

function Panel({ title, icon, children }: { title: string; icon: ReactNode; children: ReactNode }) {
    return (
        <div className="min-w-0 space-y-2">
            <h4 className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {icon}
                {title}
            </h4>
            {children}
        </div>
    );
}

function StatusForm({ params, post, statuses }: { params: (string | number)[]; post: FeedbackModerationPost; statuses: Record<FeedbackStatus, string> }) {
    const form = useForm({ status: post.status, note: post.status_note ?? '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('projects.feedback.status', params), { preserveScroll: true });
    };

    return (
        <Panel title="Public status" icon={<MessageSquareHeart className="size-4" />}>
            <form onSubmit={submit} className="space-y-2">
                <select
                    className={selectClass}
                    value={form.data.status}
                    onChange={(e) => form.setData('status', e.target.value as FeedbackStatus)}
                    aria-label={`Status of #${post.number}`}
                >
                    {(Object.keys(statuses) as FeedbackStatus[]).map((s) => (
                        <option key={s} value={s}>
                            {statuses[s]}
                        </option>
                    ))}
                </select>
                <Textarea
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                    rows={2}
                    maxLength={1000}
                    placeholder="Public note (optional)"
                    aria-label={`Public note for #${post.number}`}
                />
                {form.errors.status && <p className="text-sm text-destructive">{form.errors.status}</p>}
                <Button type="submit" size="sm" variant="outline" disabled={form.processing || (form.data.status === post.status && form.data.note === (post.status_note ?? ''))}>
                    Update status
                </Button>
                <p className="text-xs text-muted-foreground">Followers get one email per status.</p>
            </form>
        </Panel>
    );
}

function MergeForm({ params, post, targets }: { params: (string | number)[]; post: FeedbackModerationPost; targets: Props['targets'] }) {
    const options = targets.filter((t) => t.number !== post.number);
    const form = useForm<{ target_id: string }>({ target_id: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.target_id) return;
        if (!window.confirm(`Merge #${post.number} into the selected idea? Votes and followers move over; the old link redirects.`)) return;
        form.post(route('projects.feedback.merge', params), { preserveScroll: true });
    };

    if (options.length === 0) return <p className="text-sm text-muted-foreground">No other published idea to merge into.</p>;

    return (
        <form onSubmit={submit} className="space-y-2">
            <select className={selectClass} value={form.data.target_id} onChange={(e) => form.setData('target_id', e.target.value)} aria-label={`Merge #${post.number} into`}>
                <option value="">Merge into…</option>
                {options.map((t) => (
                    <option key={t.id} value={t.id}>
                        #{t.number} {t.title}
                    </option>
                ))}
            </select>
            {form.errors.target_id && <p className="text-sm text-destructive">{form.errors.target_id}</p>}
            <Button type="submit" size="sm" variant="outline" disabled={!form.data.target_id || form.processing}>
                <GitMerge className="size-4" />
                Merge
            </Button>
        </form>
    );
}

function CardForms({ params, boards }: { params: (string | number)[]; boards: Props['boards'] }) {
    const create = useForm<{ board_id: string }>({ board_id: boards[0] ? String(boards[0].id) : '' });
    const link = useForm({ key: '' });

    return (
        <div className="space-y-3">
            {boards.length > 0 && (
                <form
                    className="flex gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        create.post(route('projects.feedback.card.create', params), { preserveScroll: true });
                    }}
                >
                    <select className={selectClass} value={create.data.board_id} onChange={(e) => create.setData('board_id', e.target.value)} aria-label="Board for the new card">
                        {boards.map((b) => (
                            <option key={b.id} value={b.id}>
                                {b.name}
                            </option>
                        ))}
                    </select>
                    <Button type="submit" size="sm" disabled={create.processing} className="shrink-0">
                        <Plus className="size-4" />
                        Create card
                    </Button>
                </form>
            )}
            {create.errors.board_id && <p className="text-sm text-destructive">{create.errors.board_id}</p>}
            <form
                className="flex gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    link.put(route('projects.feedback.card.link', params), { preserveScroll: true });
                }}
            >
                <Input value={link.data.key} onChange={(e) => link.setData('key', e.target.value)} placeholder="Issue key, e.g. WEB-12" aria-label="Issue key to link" className="h-9" />
                <Button type="submit" size="sm" variant="outline" disabled={!link.data.key.trim() || link.processing} className="shrink-0">
                    Link
                </Button>
            </form>
            {link.errors.key && <p className="text-sm text-destructive">{link.errors.key}</p>}
        </div>
    );
}
