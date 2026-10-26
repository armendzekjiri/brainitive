import { CommentList, DecisionList, DeliverableContent, DeliverableStatusBadge, FlashNotice, formatDay, statusLabel } from '@/components/client-portal/ClientPortalBits';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import type {
    ClientCommentEntry,
    ClientDecisionEntry,
    ClientDeliverableTeamSummary,
    ClientDeliverableTeamVersion,
    ClientPlanSnapshot,
    PageProps,
    Project,
    Workspace,
} from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, Lock, Reply, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

interface Props {
    workspace: Workspace;
    project: Project;
    portal: { title: string; slug: string; enabled: boolean; url: string };
    deliverable: ClientDeliverableTeamSummary & {
        message: string | null;
        html: string | null;
        plan: ClientPlanSnapshot | null;
        client_url: string | null;
        decisions: ClientDecisionEntry[];
        comments: ClientCommentEntry[];
    };
    versions: ClientDeliverableTeamVersion[];
    canPublish: boolean;
    canManage: boolean;
}

/**
 * Team view of one deliverable (ClientPortalTeamController::showDeliverable, v1.42.0 BRAI-73): the frozen
 * content, the decision history, and the conversation — replies reach the client only via "Reply to client".
 */
export default function ProjectClientPortalDeliverable({ workspace, project, portal, deliverable, versions, canPublish, canManage }: Props) {
    const flash = (usePage().props as unknown as PageProps).flash?.success;
    const base = [workspace.slug, project.slug];
    const comment = useForm({ body: '', visibility: 'client' as 'client' | 'internal' });
    const withdrawn = deliverable.status === 'withdrawn';

    const send = (visibility: 'client' | 'internal') => {
        comment.transform((data) => ({ ...data, visibility }));
        comment.post(route('client-portal.manage.deliverables.comments.store', [...base, deliverable.id]), { preserveScroll: true, onSuccess: () => comment.reset() });
    };

    const withdraw = () => {
        if (window.confirm('Remove this deliverable from the client portal? Clients will no longer see it; its history stays here.')) {
            router.delete(route('client-portal.manage.deliverables.destroy', [...base, deliverable.id]), {
                onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Could not remove it from the portal'),
            });
        }
    };

    return (
        <AppLayout>
            <Head title={`${deliverable.title} · Client portal · ${project.name}`} />
            <div className="mx-auto w-full max-w-5xl space-y-6 px-4 py-6 sm:px-6">
                <Link href={route('client-portal.manage.index', base)} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" aria-hidden="true" /> Client portal
                </Link>

                <FlashNotice message={flash} />

                <header className="flex flex-wrap items-start gap-3">
                    <div className="w-full min-w-0 space-y-1 sm:w-auto sm:flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="min-w-0 break-words text-xl font-semibold">{deliverable.title}</h1>
                            <DeliverableStatusBadge status={deliverable.status} />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {deliverable.kind === 'plan' ? 'Plan snapshot' : 'Doc'} · version {deliverable.version} · published {formatDay(deliverable.published_at)}
                            {deliverable.published_by ? ` by ${deliverable.published_by}` : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {deliverable.client_url && portal.enabled && (
                            <Button variant="outline" size="sm" asChild>
                                <a href={deliverable.client_url} target="_blank" rel="noopener noreferrer"><ExternalLink className="size-4" aria-hidden="true" /> Client view</a>
                            </Button>
                        )}
                        {canPublish && !withdrawn && (
                            <Button variant="outline" size="sm" onClick={withdraw}><Trash2 className="size-4" aria-hidden="true" /> Remove from portal</Button>
                        )}
                    </div>
                </header>

                {deliverable.message && <p className="whitespace-pre-line rounded-xl bg-muted/60 px-4 py-3 text-sm"><span className="font-medium">Note to the client: </span>{deliverable.message}</p>}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <section className="min-w-0 rounded-xl border bg-card p-4 sm:p-6" aria-label="What the client sees">
                        <DeliverableContent html={deliverable.html} plan={deliverable.plan} />
                    </section>

                    <aside className="space-y-6">
                        <section className="space-y-3" aria-labelledby="team-decisions">
                            <h2 id="team-decisions" className="font-semibold">Decisions</h2>
                            <DecisionList decisions={deliverable.decisions} showEmail={canManage} />
                        </section>
                        {versions.length > 1 && (
                            <section className="space-y-2" aria-labelledby="team-versions">
                                <h2 id="team-versions" className="font-semibold">Versions</h2>
                                <ul className="space-y-1 text-sm">
                                    {versions.map((v) => (
                                        <li key={v.id} className="flex flex-wrap items-center gap-2">
                                            {v.id === deliverable.id ? (
                                                <span className="font-medium">v{v.version} (this one)</span>
                                            ) : (
                                                <Link href={route('client-portal.manage.deliverables.show', [...base, v.id])} className="text-primary hover:underline">v{v.version}</Link>
                                            )}
                                            <span className="text-xs text-muted-foreground">
                                                {formatDay(v.published_at)} · {v.status === 'superseded' && v.decision !== 'awaiting' ? `${statusLabel(v.decision)}, superseded` : statusLabel(v.status)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                    </aside>
                </div>

                <section className="space-y-3" aria-labelledby="team-comments">
                    <h2 id="team-comments" className="font-semibold">Conversation</h2>
                    <CommentList comments={deliverable.comments} empty="No comments yet." />
                    {canPublish && (
                        <form onSubmit={(e) => { e.preventDefault(); send('internal'); }} className="space-y-2">
                            <Label htmlFor="team-comment">Your message</Label>
                            <Textarea id="team-comment" rows={3} maxLength={5000} value={comment.data.body} onChange={(e) => comment.setData('body', e.target.value)} />
                            {comment.errors.body && <p className="text-sm text-destructive">{comment.errors.body}</p>}
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" onClick={() => send('client')} disabled={comment.processing || withdrawn || comment.data.body.trim() === ''}>
                                    <Reply className="size-4" aria-hidden="true" /> Reply to client
                                </Button>
                                <Button type="submit" variant="outline" disabled={comment.processing || comment.data.body.trim() === ''}>
                                    <Lock className="size-4" aria-hidden="true" /> Add internal note
                                </Button>
                            </div>
                            <p className="text-xs text-muted-foreground">Only “Reply to client” is visible to the client (and emailed to them). Internal notes stay with the team.</p>
                        </form>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
