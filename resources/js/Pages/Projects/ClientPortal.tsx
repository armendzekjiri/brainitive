import { DeliverableStatusBadge, FlashNotice, formatDay, formatWhen } from '@/components/client-portal/ClientPortalBits';
import { ProjectIcon } from '@/components/ProjectIcon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import type { ClientDeliverableTeamSummary, ClientPortalMemberRow, PageProps, Project, Workspace } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Copy, ExternalLink, FileText, GanttChart, Handshake, Send, UserPlus } from 'lucide-react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';

interface Props {
    workspace: Workspace;
    project: Project;
    portal: { title: string; slug: string; welcome: string | null; enabled: boolean; url: string } | null;
    suggested: { title: string; slug: string };
    members: ClientPortalMemberRow[];
    deliverables: ClientDeliverableTeamSummary[];
    candidates: { pages: { id: string; name: string; version: number }[]; plans: { id: string; name: string }[] };
    billingClient: { name: string; email: string | null } | null;
    canManage: boolean;
    canPublish: boolean;
}

const selectClass = 'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';

/** Inertia onError for router.* calls whose errors no form on the page shows. */
const errorToast = (fallback: string) => (errors: Record<string, string>) => toast.error(Object.values(errors)[0] ?? fallback);

const MEMBER_STATE: Record<ClientPortalMemberRow['state'], string> = {
    active: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300',
    expired: 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300',
    revoked: 'bg-muted text-muted-foreground',
};

/**
 * Team page of a project's client portal (ClientPortalTeamController::index, v1.42.0 BRAI-73):
 * settings, invited clients, and the deliverables shared with them.
 */
export default function ProjectClientPortal({ workspace, project, portal, suggested, members, deliverables, candidates, billingClient, canManage, canPublish }: Props) {
    const flash = (usePage().props as unknown as PageProps).flash?.success;
    const base = [workspace.slug, project.slug];

    const settings = useForm({
        enabled: portal?.enabled ?? true,
        title: portal?.title ?? suggested.title,
        slug: portal?.slug ?? suggested.slug,
        welcome: portal?.welcome ?? '',
    });
    const invite = useForm({ email: '', name: '', expires_at: '' });
    const publish = useForm({ kind: 'page' as 'page' | 'plan', id: '', message: '' });

    const saveSettings = (e: FormEvent) => {
        e.preventDefault();
        settings.put(route('client-portal.manage.update', base), { preserveScroll: true });
    };
    const sendInvite = (e: FormEvent) => {
        e.preventDefault();
        invite.post(route('client-portal.manage.members.store', base), { preserveScroll: true, onSuccess: () => invite.reset() });
    };
    const reinvite = (m: ClientPortalMemberRow) => {
        if (!m.email) return;
        router.post(route('client-portal.manage.members.store', base), { email: m.email, name: m.name ?? '' }, { preserveScroll: true, onError: errorToast('Could not re-invite this client') });
    };
    const revoke = (m: ClientPortalMemberRow) => {
        if (!window.confirm(`Remove ${m.email ?? 'this client'}'s access to the portal? You can invite them again later.`)) return;
        router.delete(route('client-portal.manage.members.destroy', [...base, m.id]), { preserveScroll: true, onError: errorToast('Could not revoke access') });
    };
    const submitPublish = (e: FormEvent) => {
        e.preventDefault();
        publish.post(route('client-portal.manage.deliverables.store', base));
    };
    const copyLink = async () => {
        if (!portal) return;
        try {
            await navigator.clipboard.writeText(portal.url);
            toast.success('Link copied');
        } catch {
            toast.error('Could not copy the link');
        }
    };

    const options = publish.data.kind === 'page' ? candidates.pages : candidates.plans;
    const current = deliverables.filter((d) => d.status !== 'superseded' && d.status !== 'withdrawn');
    const earlier = deliverables.filter((d) => d.status === 'superseded' || d.status === 'withdrawn');

    return (
        <AppLayout>
            <Head title={`Client portal · ${project.name}`} />
            <div className="mx-auto w-full max-w-5xl space-y-6 px-4 py-6 sm:px-6">
                <div className="flex flex-wrap items-center gap-3">
                    <ProjectIcon project={project} size="md" />
                    <div className="min-w-0 flex-1 basis-56">
                        <h1 className="flex items-center gap-2 text-xl font-semibold"><Handshake className="size-5" aria-hidden="true" /> Client portal</h1>
                        <p className="text-sm text-muted-foreground">Share selected docs and plans with your client, and collect approvals on exact versions.</p>
                    </div>
                    {portal?.enabled && (
                        <div className="flex w-full flex-wrap gap-2 sm:w-auto">
                            <Button variant="outline" size="sm" onClick={copyLink}><Copy className="size-4" aria-hidden="true" /> Copy link</Button>
                            <Button variant="outline" size="sm" asChild>
                                <a href={portal.url} target="_blank" rel="noopener noreferrer"><ExternalLink className="size-4" aria-hidden="true" /> Open portal</a>
                            </Button>
                        </div>
                    )}
                </div>

                <FlashNotice message={flash} />

                {portal?.enabled && (
                    <p className="break-all rounded-lg border bg-muted/40 px-3 py-2 font-mono text-xs" data-testid="client-portal-url">{portal.url}</p>
                )}

                {/* ── Settings ── */}
                <section className="rounded-xl border bg-card p-4 sm:p-6" aria-labelledby="cp-settings">
                    <h2 id="cp-settings" className="mb-1 font-semibold">Settings</h2>
                    {!portal && <p className="mb-4 text-sm text-muted-foreground">The client portal is off. Turn it on to invite clients; they only see what you publish here.</p>}
                    {canManage ? (
                        <form onSubmit={saveSettings} className="grid gap-4 sm:grid-cols-2">
                            <label className="flex items-center gap-2 text-sm font-medium sm:col-span-2">
                                <input type="checkbox" className="size-4 accent-primary" checked={settings.data.enabled} onChange={(e) => settings.setData('enabled', e.target.checked)} />
                                Portal is on (clients with an invitation can sign in)
                            </label>
                            <div className="space-y-1.5">
                                <Label htmlFor="cp-title">Title</Label>
                                <Input id="cp-title" maxLength={120} value={settings.data.title} onChange={(e) => settings.setData('title', e.target.value)} />
                                {settings.errors.title && <p className="text-sm text-destructive">{settings.errors.title}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="cp-slug">Address</Label>
                                <div className="flex items-center gap-1 text-sm">
                                    <span className="shrink-0 text-muted-foreground">/client/</span>
                                    <Input id="cp-slug" maxLength={60} value={settings.data.slug} onChange={(e) => settings.setData('slug', e.target.value.toLowerCase())} />
                                </div>
                                {settings.errors.slug && <p className="text-sm text-destructive">{settings.errors.slug}</p>}
                            </div>
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label htmlFor="cp-welcome">Welcome text <span className="font-normal text-muted-foreground">(optional)</span></Label>
                                <Textarea id="cp-welcome" rows={3} maxLength={2000} value={settings.data.welcome} onChange={(e) => settings.setData('welcome', e.target.value)} />
                                {settings.errors.welcome && <p className="text-sm text-destructive">{settings.errors.welcome}</p>}
                            </div>
                            <div className="sm:col-span-2">
                                <Button type="submit" disabled={settings.processing}>{portal ? 'Save settings' : 'Turn on the client portal'}</Button>
                            </div>
                        </form>
                    ) : (
                        <p className="text-sm text-muted-foreground">{portal ? `${portal.title} · ${portal.enabled ? 'on' : 'off'}` : 'Project admins can turn the client portal on.'}</p>
                    )}
                </section>

                {portal && (
                    <>
                        {/* ── Clients ── */}
                        <section className="rounded-xl border bg-card p-4 sm:p-6" aria-labelledby="cp-clients">
                            <h2 id="cp-clients" className="mb-3 font-semibold">Clients</h2>
                            {canManage && (
                                <form onSubmit={sendInvite} className="mb-4 grid gap-3 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,0.9fr)_auto] sm:items-end">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="cp-invite-email">Client email</Label>
                                        <Input id="cp-invite-email" type="email" required value={invite.data.email} onChange={(e) => invite.setData('email', e.target.value)} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="cp-invite-name">Name <span className="font-normal text-muted-foreground">(optional)</span></Label>
                                        <Input id="cp-invite-name" maxLength={120} value={invite.data.name} onChange={(e) => invite.setData('name', e.target.value)} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="cp-invite-expires">Access until <span className="font-normal text-muted-foreground">(optional)</span></Label>
                                        <Input id="cp-invite-expires" type="date" value={invite.data.expires_at} onChange={(e) => invite.setData('expires_at', e.target.value)} />
                                    </div>
                                    <Button type="submit" disabled={invite.processing || !portal.enabled}><UserPlus className="size-4" aria-hidden="true" /> Invite</Button>
                                    {(invite.errors.email || invite.errors.expires_at || invite.errors.name) && (
                                        <p className="text-sm text-destructive sm:col-span-4">{invite.errors.email ?? invite.errors.expires_at ?? invite.errors.name}</p>
                                    )}
                                    {billingClient?.email && invite.data.email === '' && (
                                        <p className="text-sm text-muted-foreground sm:col-span-4">
                                            Billing contact: {billingClient.name}{' '}
                                            <button type="button" className="text-primary underline-offset-2 hover:underline" onClick={() => invite.setData({ ...invite.data, email: billingClient.email ?? '', name: invite.data.name || billingClient.name })}>
                                                Use {billingClient.email}
                                            </button>
                                        </p>
                                    )}
                                    {!portal.enabled && <p className="text-sm text-muted-foreground sm:col-span-4">Turn the portal on to invite clients.</p>}
                                </form>
                            )}
                            {members.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No clients invited yet. Invited people sign in with a link we email them; they never join the workspace.</p>
                            ) : (
                                <ul className="divide-y rounded-lg border" aria-label="Invited clients">
                                    {members.map((m) => (
                                        <li key={m.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5" data-testid="client-member">
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">{m.name}{m.email && <span className="font-normal text-muted-foreground"> {m.email}</span>}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    Invited {formatDay(m.invited_at)}{m.invited_by ? ` by ${m.invited_by}` : ''}
                                                    {m.expires_at && ` · access until ${formatDay(m.expires_at)}`}
                                                    {m.last_seen_at && ` · last seen ${formatWhen(m.last_seen_at)}`}
                                                </p>
                                            </div>
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium capitalize ${MEMBER_STATE[m.state]}`}>{m.state}</span>
                                            {canManage && (m.state === 'active' ? (
                                                <Button variant="ghost" size="sm" onClick={() => revoke(m)}>
                                                    Revoke
                                                </Button>
                                            ) : (
                                                <Button variant="ghost" size="sm" disabled={!portal.enabled} onClick={() => reinvite(m)}>Re-invite</Button>
                                            ))}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        {/* ── Deliverables ── */}
                        <section className="rounded-xl border bg-card p-4 sm:p-6" aria-labelledby="cp-deliverables">
                            <h2 id="cp-deliverables" className="mb-3 font-semibold">Deliverables</h2>
                            {canPublish && (
                                <form onSubmit={submitPublish} className="mb-4 grid gap-3 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-end">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="cp-kind">Type</Label>
                                        <select id="cp-kind" className={selectClass} value={publish.data.kind} onChange={(e) => publish.setData({ ...publish.data, kind: e.target.value as 'page' | 'plan', id: '' })}>
                                            <option value="page">Doc</option>
                                            <option value="plan">Plan</option>
                                        </select>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="cp-item">{publish.data.kind === 'page' ? 'Published doc (its latest version)' : 'Plan (a snapshot of it now)'}</Label>
                                        <select id="cp-item" className={selectClass} value={publish.data.id} onChange={(e) => publish.setData('id', e.target.value)} required>
                                            <option value="">{options.length === 0 ? (publish.data.kind === 'page' ? 'No published docs' : 'No plans') : 'Choose…'}</option>
                                            {options.map((o) => (
                                                <option key={o.id} value={o.id}>{o.name}{'version' in o ? ` (v${o.version})` : ''}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="space-y-1.5 sm:col-span-2">
                                        <Label htmlFor="cp-message">Note to the client <span className="font-normal text-muted-foreground">(optional)</span></Label>
                                        <Textarea id="cp-message" rows={2} maxLength={2000} value={publish.data.message} onChange={(e) => publish.setData('message', e.target.value)} />
                                    </div>
                                    {(publish.errors.id || publish.errors.kind) && <p className="text-sm text-destructive sm:col-span-2">{publish.errors.id ?? publish.errors.kind}</p>}
                                    <div className="sm:col-span-2">
                                        <Button type="submit" disabled={publish.processing || publish.data.id === ''}><Send className="size-4" aria-hidden="true" /> Publish to the portal</Button>
                                        <p className="mt-1 text-xs text-muted-foreground">Publishing a newer version replaces the previous one for the client; its decision stays in the history and the new version needs a fresh approval.</p>
                                    </div>
                                </form>
                            )}
                            <DeliverableRows rows={current} base={base} empty="Nothing shared with clients yet." />
                            {earlier.length > 0 && (
                                <details className="mt-4">
                                    <summary className="cursor-pointer text-sm text-muted-foreground">Earlier versions and removed ({earlier.length})</summary>
                                    <div className="mt-2"><DeliverableRows rows={earlier} base={base} empty="" /></div>
                                </details>
                            )}
                        </section>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

function DeliverableRows({ rows, base, empty }: { rows: ClientDeliverableTeamSummary[]; base: string[]; empty: string }) {
    if (rows.length === 0) return <p className="text-sm text-muted-foreground">{empty}</p>;
    return (
        <ul className="divide-y rounded-lg border" aria-label="Deliverables">
            {rows.map((d) => (
                <li key={d.id}>
                    <Link href={route('client-portal.manage.deliverables.show', [...base, d.id])} className="flex flex-wrap items-center gap-3 px-3 py-2.5 transition-colors hover:bg-muted/50" data-testid="team-deliverable">
                        {d.kind === 'plan' ? <GanttChart className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" /> : <FileText className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />}
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-medium">{d.title} <span className="font-normal text-muted-foreground">v{d.version}</span></span>
                            <span className="block text-xs text-muted-foreground">
                                {formatDay(d.published_at)}{d.published_by ? ` by ${d.published_by}` : ''}
                                {` · ${d.decisions_count ?? 0} decision${d.decisions_count === 1 ? '' : 's'} · ${d.comments_count ?? 0} comment${d.comments_count === 1 ? '' : 's'}`}
                            </span>
                        </span>
                        <DeliverableStatusBadge status={d.status} />
                    </Link>
                </li>
            ))}
        </ul>
    );
}
