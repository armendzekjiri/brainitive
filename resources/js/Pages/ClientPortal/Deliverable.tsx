import {
    CommentList,
    DecisionList,
    DeliverableContent,
    DeliverableStatusBadge,
    FlashNotice,
    formatDay,
    statusLabel,
} from '@/components/client-portal/ClientPortalBits';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import PortalLayout from '@/Layouts/PortalLayout';
import type {
    ClientCommentEntry,
    ClientDecisionEntry,
    ClientDecisionValue,
    ClientDeliverableSummary,
    ClientDeliverableVersion,
    ClientPlanSnapshot,
    ClientPortalPublic,
    PageProps,
    PortalIdentityData,
} from '@/types';
import { Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Download, History, RotateCcw } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface Props {
    portal: ClientPortalPublic;
    identity: PortalIdentityData | null;
    deliverable: ClientDeliverableSummary & {
        message: string | null;
        html: string | null;
        plan: ClientPlanSnapshot | null;
        can_decide: boolean;
        latest: { uuid: string; version: number } | null;
        decisions: ClientDecisionEntry[];
        comments: ClientCommentEntry[];
    };
    versions: ClientDeliverableVersion[];
}

/** One deliverable for a client (ClientPortalController::deliverable, v1.42.0 BRAI-73). */
export default function ClientPortalDeliverable({ portal, identity, deliverable, versions }: Props) {
    const flash = (usePage().props as unknown as PageProps).flash?.success;
    const [changesOpen, setChangesOpen] = useState(false);
    const decision = useForm<{ decision: ClientDecisionValue; note: string }>({ decision: 'approved', note: '' });
    const comment = useForm({ body: '' });
    const params = [portal.slug, deliverable.uuid];

    const decide = (value: ClientDecisionValue) => {
        // an approval never carries the text typed into the change-request box
        decision.transform((data) => ({ decision: value, note: value === 'changes_requested' ? data.note : '' }));
        decision.post(route('client-portal.decide', params), {
            preserveScroll: true,
            onSuccess: () => {
                decision.reset();
                setChangesOpen(false);
            },
        });
    };

    const submitChanges = (e: FormEvent) => {
        e.preventDefault();
        decide('changes_requested');
    };

    const submitComment = (e: FormEvent) => {
        e.preventDefault();
        comment.post(route('client-portal.comments.store', params), { preserveScroll: true, onSuccess: () => comment.reset() });
    };

    return (
        <PortalLayout title={`${deliverable.title} · ${portal.title}`} brand={portal.title} subtitle="Client portal" identity={identity} returnTo={route('client-portal.show', portal.slug, false)}>
            <div className="space-y-6">
                <Link href={route('client-portal.show', portal.slug)} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" aria-hidden="true" /> All deliverables
                </Link>

                <FlashNotice message={flash} />

                <header className="space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="min-w-0 break-words text-2xl font-semibold tracking-tight">{deliverable.title}</h1>
                        <DeliverableStatusBadge status={deliverable.status} />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Version {deliverable.version} · shared {formatDay(deliverable.published_at)}
                    </p>
                    {deliverable.message && <p className="whitespace-pre-line rounded-xl bg-muted/60 px-4 py-3 text-sm">{deliverable.message}</p>}
                </header>

                {deliverable.latest && (
                    <div role="status" className="flex flex-wrap items-center gap-2 rounded-xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                        <History className="size-4 shrink-0" aria-hidden="true" />
                        <span className="flex-1">This is an earlier version. Decisions are made on the latest version.</span>
                        <Link href={route('client-portal.deliverable', [portal.slug, deliverable.latest.uuid])} className="font-medium underline underline-offset-2">
                            Open version {deliverable.latest.version}
                        </Link>
                    </div>
                )}

                <section className="rounded-xl border bg-card p-4 sm:p-6" aria-label="Content">
                    <DeliverableContent html={deliverable.html} plan={deliverable.plan} />
                    {deliverable.kind === 'page' && (
                        <div className="mt-4 border-t pt-3">
                            <a href={route('client-portal.download', params)} className="inline-flex items-center gap-1.5 text-sm text-primary hover:underline">
                                <Download className="size-4" aria-hidden="true" /> Download this version
                            </a>
                        </div>
                    )}
                </section>

                {deliverable.can_decide && (
                    <section className="space-y-3 rounded-xl border bg-card p-4 sm:p-6" aria-labelledby="decision-heading">
                        <h2 id="decision-heading" className="font-semibold">Your decision on version {deliverable.version}</h2>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                onClick={() => decide('approved')}
                                disabled={decision.processing || deliverable.status === 'approved'}
                                className="bg-emerald-600 text-white hover:bg-emerald-700"
                            >
                                <Check className="size-4" aria-hidden="true" />
                                {deliverable.status === 'approved' ? 'Approved' : 'Approve'}
                            </Button>
                            <Button variant="outline" onClick={() => setChangesOpen((o) => !o)} aria-expanded={changesOpen} disabled={decision.processing}>
                                <RotateCcw className="size-4" aria-hidden="true" /> Request changes
                            </Button>
                        </div>
                        {changesOpen && (
                            <form onSubmit={submitChanges} className="space-y-2">
                                <Label htmlFor="changes-note">What should change? <span className="font-normal text-muted-foreground">(optional)</span></Label>
                                <Textarea id="changes-note" rows={4} maxLength={2000} value={decision.data.note} onChange={(e) => decision.setData('note', e.target.value)} />
                                <Button type="submit" disabled={decision.processing}>Send change request</Button>
                            </form>
                        )}
                        {decision.errors.decision && <p className="text-sm text-destructive">{decision.errors.decision}</p>}
                        {decision.errors.note && <p className="text-sm text-destructive">{decision.errors.note}</p>}
                    </section>
                )}

                <div className="grid gap-6 md:grid-cols-2">
                    <section className="space-y-3" aria-labelledby="history-heading">
                        <h2 id="history-heading" className="font-semibold">Decisions</h2>
                        <DecisionList decisions={deliverable.decisions} />
                        {versions.length > 1 && (
                            <div className="space-y-2 pt-2">
                                <h3 className="text-sm font-medium">All versions</h3>
                                <ul className="space-y-1 text-sm">
                                    {versions.map((v) => (
                                        <li key={v.uuid} className="flex flex-wrap items-center gap-2">
                                            {v.uuid === deliverable.uuid ? (
                                                <span className="font-medium">Version {v.version} (this one)</span>
                                            ) : (
                                                <Link href={route('client-portal.deliverable', [portal.slug, v.uuid])} className="text-primary hover:underline">Version {v.version}</Link>
                                            )}
                                            <span className="text-xs text-muted-foreground">
                                                {formatDay(v.published_at)} · {v.status === 'superseded' && v.decision !== 'awaiting' ? `${statusLabel(v.decision)}, superseded` : statusLabel(v.status)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </section>

                    <section className="space-y-3" aria-labelledby="comments-heading">
                        <h2 id="comments-heading" className="font-semibold">Comments</h2>
                        <CommentList comments={deliverable.comments} empty="No comments yet. Ask a question or leave feedback for the team." />
                        <form onSubmit={submitComment} className="space-y-2">
                            <Label htmlFor="client-comment" className="sr-only">Comment</Label>
                            <Textarea
                                id="client-comment"
                                rows={3}
                                maxLength={5000}
                                placeholder="Write a comment for the team…"
                                value={comment.data.body}
                                onChange={(e) => comment.setData('body', e.target.value)}
                            />
                            {comment.errors.body && <p className="text-sm text-destructive">{comment.errors.body}</p>}
                            <Button type="submit" variant="outline" disabled={comment.processing || comment.data.body.trim() === ''}>Send comment</Button>
                        </form>
                    </section>
                </div>
            </div>
        </PortalLayout>
    );
}
