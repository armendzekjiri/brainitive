import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
    AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { useFlashToast } from '@/components/automations/useFlashToast';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { GithubAvailableRepository, GithubConnectionSettings, GithubSettings, Project, Workspace } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { Check, Copy, ExternalLink, Eye, EyeOff, GitMerge, Github, Lock, RefreshCw, TriangleAlert, Unplug } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

export type { GithubSettings } from '@/types';

const EVENTS = ['Pushes', 'Branch or tag creation', 'Branch or tag deletion', 'Pull requests', 'Deployment statuses'];

const visit = { preserveScroll: true, preserveState: true } as const;

function ago(iso: string | null | undefined) {
    return iso ? formatDistanceToNow(new Date(iso), { addSuffix: true }) : null;
}

function CopyField({ id, label, value, secret = false }: { id: string; label: string; value: string; secret?: boolean }) {
    const [shown, setShown] = useState(!secret);
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch {
            toast.error('Copy failed — select the text and copy it manually.');
        }
    };

    return (
        <div className="space-y-1.5">
            <Label htmlFor={id}>{label}</Label>
            <div className="flex gap-2">
                <Input id={id} readOnly value={shown ? value : '•'.repeat(24)} className="font-mono text-xs" onFocus={(e) => shown && e.currentTarget.select()} />
                {secret && (
                    <Button type="button" variant="outline" size="icon" onClick={() => setShown((s) => !s)} aria-label={shown ? 'Hide secret' : 'Show secret'}>
                        {shown ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                    </Button>
                )}
                <Button type="button" variant="outline" size="icon" onClick={copy} aria-label={`Copy ${label.toLowerCase()}`}>
                    {copied ? <Check className="size-4 text-green-600" /> : <Copy className="size-4" />}
                </Button>
            </div>
        </div>
    );
}

function ErrorNote({ children }: { children: React.ReactNode }) {
    return (
        <div className="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive" role="alert">
            <TriangleAlert className="mt-0.5 size-4 shrink-0" /> <span className="min-w-0">{children}</span>
        </div>
    );
}

function LastEvent({ connection }: { connection: GithubConnectionSettings }) {
    return connection.last_delivery_at
        ? <Badge variant="secondary">Last event {ago(connection.last_delivery_at)}{connection.last_event ? ` · ${connection.last_event}` : ''}</Badge>
        : <Badge variant="outline">Waiting for the first event</Badge>;
}

function DisconnectButton({ params, app }: { params: string[]; app: boolean }) {
    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>
                <Button variant="outline" className="text-destructive hover:text-destructive"><Unplug className="size-4" /> Disconnect</Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Disconnect GitHub?</AlertDialogTitle>
                    <AlertDialogDescription>
                        All linked branches, commits, pull requests and deployments are removed from this project’s issues.
                        {app ? ' The GitHub App stays installed on GitHub; uninstall it there if no other project uses it.' : ' Delete the webhook in GitHub too.'}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction className="bg-destructive text-destructive-foreground hover:bg-destructive/90" onClick={() => router.delete(route('projects.github.destroy', params), visit)}>Disconnect</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/** Repositories of the installation this project follows (each repository feeds one project). */
function RepositoryPicker({ params, repositories, manageUrl }: { params: string[]; repositories: GithubAvailableRepository[]; manageUrl: string | null }) {
    const initial = useMemo(() => repositories.filter((r) => r.followed).map((r) => r.id), [repositories]);
    const [selected, setSelected] = useState<number[]>(initial);
    const [saving, setSaving] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;
    useEffect(() => setSelected(initial), [initial]);

    const dirty = selected.length !== initial.length || selected.some((id) => !initial.includes(id));
    const toggle = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
    const save = () => router.put(route('projects.github.repositories', params), { repository_ids: selected }, {
        ...visit, onStart: () => setSaving(true), onFinish: () => setSaving(false),
    });

    return (
        <fieldset className="space-y-2">
            <legend className="text-sm font-medium">Repositories this project follows</legend>
            {repositories.length === 0 ? (
                <p className="text-sm text-muted-foreground">The installation shares no repositories yet.</p>
            ) : (
                <ul className="divide-y rounded-md border">
                    {repositories.map((repo) => {
                        const id = `gh-repo-${repo.id}`;
                        return (
                            <li key={repo.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                                <input
                                    id={id}
                                    type="checkbox"
                                    className="size-4 shrink-0 accent-primary"
                                    checked={selected.includes(repo.id)}
                                    disabled={repo.taken}
                                    onChange={() => toggle(repo.id)}
                                />
                                <label htmlFor={id} className={`flex min-w-0 flex-1 items-center gap-1.5 font-mono text-sm ${repo.taken ? 'text-muted-foreground' : ''}`}>
                                    {repo.private && <Lock className="size-3.5 shrink-0 text-muted-foreground" aria-label="Private" />}
                                    <span className="truncate">{repo.full_name}</span>
                                </label>
                                {repo.taken && <Badge variant="outline" className="text-xs">Used by another project</Badge>}
                                {repo.removed && <Badge variant="destructive" className="text-xs">No longer shared</Badge>}
                            </li>
                        );
                    })}
                </ul>
            )}
            {errors.repository_ids && <p className="text-sm text-destructive">{errors.repository_ids}</p>}
            <div className="flex flex-wrap items-center gap-2">
                <Button type="button" size="sm" onClick={save} disabled={!dirty || saving}>Save repositories</Button>
                {manageUrl && (
                    <Button type="button" size="sm" variant="ghost" asChild>
                        <a href={manageUrl} target="_blank" rel="noopener noreferrer"><ExternalLink className="size-4" /> Share more repositories on GitHub</a>
                    </Button>
                )}
            </div>
        </fieldset>
    );
}

/** Per board: "primary PR merged → move to column". Off by default. */
function MergeTransitions({ params, boards }: { params: string[]; boards: GithubSettings['boards'] }) {
    const initial = useMemo(() => Object.fromEntries(boards.map((b) => [b.id, b.merge_column_id ? String(b.merge_column_id) : 'off'])) as Record<number, string>, [boards]);
    const [values, setValues] = useState<Record<number, string>>(initial);
    useEffect(() => setValues(initial), [initial]);
    const dirty = boards.some((b) => values[b.id] !== initial[b.id]);

    if (boards.length === 0) return null;

    const save = () => router.put(route('projects.github.transitions', params), {
        transitions: boards.map((b) => ({ board_id: b.id, column_id: values[b.id] === 'off' ? null : Number(values[b.id]) })),
    }, visit);

    return (
        <section className="space-y-3 border-t pt-4" aria-labelledby="gh-transitions">
            <div>
                <h3 id="gh-transitions" className="flex items-center gap-2 text-sm font-medium"><GitMerge className="size-4" /> Merge transitions</h3>
                <p className="text-sm text-muted-foreground">
                    When an issue’s primary pull request is merged, move the card. The primary PR is the first one linked, unless someone picks another on the issue.
                    Closed-without-merge PRs and finished issues are never moved.
                </p>
            </div>
            <div className="space-y-2">
                {boards.map((board) => (
                    <div key={board.id} className="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                        <Label htmlFor={`gh-transition-${board.id}`} className="truncate">{board.name}</Label>
                        <Select value={values[board.id] ?? 'off'} onValueChange={(v) => setValues((s) => ({ ...s, [board.id]: v }))}>
                            <SelectTrigger id={`gh-transition-${board.id}`} className="w-full sm:w-64" aria-label={`When merged on ${board.name}`}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="off">Don’t move</SelectItem>
                                {board.columns.map((c) => <SelectItem key={c.id} value={String(c.id)}>Move to {c.name}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>
                ))}
            </div>
            <Button type="button" size="sm" variant="outline" onClick={save} disabled={!dirty}>Save merge transitions</Button>
        </section>
    );
}

function LegacyWebhookSetup({ params, connection, canSwitch }: { params: string[]; connection: GithubConnectionSettings; canSwitch: boolean }) {
    return (
        <>
            {canSwitch && (
                <div className="flex flex-col gap-3 rounded-md border border-primary/30 bg-primary/5 p-3 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm">Connect through the GitHub App instead: no webhook to paste per repository, and access is checked by GitHub. Linked issues are kept.</p>
                    <Button size="sm" className="shrink-0" onClick={() => router.post(route('projects.github.app.connect', params), {}, visit)}>
                        <Github className="size-4" /> Switch to the GitHub App
                    </Button>
                </div>
            )}
            <ol className="space-y-5 text-sm">
                <li className="space-y-3">
                    <p><span className="font-medium">1.</span> In GitHub open the repository → <span className="font-medium">Settings → Webhooks → Add webhook</span> and fill in:</p>
                    <CopyField id="gh-url" label="Payload URL" value={connection.webhook_url ?? ''} />
                    <p className="text-muted-foreground">Content type: <code className="rounded bg-muted px-1">application/json</code></p>
                    <CopyField id="gh-secret" label="Secret" value={connection.secret ?? ''} secret />
                </li>
                <li className="space-y-2">
                    <p><span className="font-medium">2.</span> Choose <span className="font-medium">Let me select individual events</span> and tick:</p>
                    <div className="flex flex-wrap gap-1.5">{EVENTS.map((e) => <Badge key={e} variant="outline">{e}</Badge>)}</div>
                    <p className="text-muted-foreground">The same URL and secret work for several repositories.</p>
                </li>
                <li className="space-y-2">
                    <p><span className="font-medium">3.</span> Optional — show deployments: give the deploy job in your GitHub Actions workflow an environment.</p>
                    <pre className="overflow-x-auto rounded-md bg-muted p-3 text-xs">{`jobs:\n  deploy:\n    environment:\n      name: production\n      url: https://your-app.example`}</pre>
                </li>
            </ol>

            {(connection.repositories?.length ?? 0) > 0 && (
                <div className="space-y-1.5">
                    <p className="text-sm font-medium">Repositories sending events</p>
                    <div className="flex flex-wrap gap-1.5">{connection.repositories!.map((r) => <Badge key={r} variant="secondary" className="max-w-full truncate font-mono">{r}</Badge>)}</div>
                </div>
            )}
        </>
    );
}

export default function GithubIntegrationCard({ workspace, project, github }: { workspace: Workspace; project: Project; github: GithubSettings | null }) {
    useFlashToast();
    const params = [workspace.slug, project.slug];
    const key = project.key || 'KEY';
    const app = github?.app ?? null;
    const connection = github?.connection ?? null;
    const boards = github?.boards ?? [];
    const connectApp = () => router.post(route('projects.github.app.connect', params), {}, visit);
    const connectManually = () => router.post(route('projects.github.store', params), {}, visit);

    if (!connection) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2"><Github className="size-5" /> GitHub</CardTitle>
                    <CardDescription>
                        Connect repositories so branches, commits, pull requests and deployments show up on the issues they mention —
                        e.g. <code className="rounded bg-muted px-1">fix({key}-123): …</code> or a branch <code className="rounded bg-muted px-1">feat/{key.toLowerCase()}-123-login</code>.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    {app ? (
                        <>
                            <div className="flex flex-wrap items-center gap-2">
                                <Button onClick={connectApp} disabled={!app.can_connect}><Github className="size-4" /> Connect GitHub</Button>
                                <Button variant="ghost" size="sm" onClick={connectManually}>Use manual webhooks instead</Button>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                GitHub asks you to pick the account and the repositories for the <a href={app.url} target="_blank" rel="noopener noreferrer" className="underline underline-offset-2">{app.name}</a> app; then you choose which of them this project follows.
                            </p>
                        </>
                    ) : (
                        <>
                            <Button onClick={connectManually}><Github className="size-4" /> Connect GitHub</Button>
                            <p className="text-sm text-muted-foreground">You’ll add a webhook to each repository. For one-click connect, an instance admin can set up the GitHub App (Admin → GitHub App).</p>
                        </>
                    )}
                </CardContent>
            </Card>
        );
    }

    const isApp = connection.mode === 'app';
    const installation = connection.installation;

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2"><Github className="size-5" /> GitHub</CardTitle>
                    <div className="flex flex-wrap items-center gap-1.5">
                        {isApp
                            ? <Badge variant="outline">GitHub App{installation?.account ? ` · ${installation.account}` : ''}</Badge>
                            : <Badge variant="outline">Manual webhooks (legacy)</Badge>}
                        <LastEvent connection={connection} />
                    </div>
                </div>
                <CardDescription>
                    Issue keys in commit messages, branch names and pull request titles link them to the issue. {connection.linked_issues > 0 && `${connection.linked_issues} issue${connection.linked_issues === 1 ? '' : 's'} linked so far.`}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
                {connection.last_error && <ErrorNote>{connection.last_error}</ErrorNote>}

                {isApp ? (
                    <>
                        {installation?.removed && (
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <p className="text-sm text-muted-foreground">The app is no longer installed on {installation.account ?? 'the GitHub account'}.</p>
                                {app && <Button size="sm" onClick={connectApp}><Github className="size-4" /> Connect again</Button>}
                            </div>
                        )}
                        <RepositoryPicker params={params} repositories={connection.available_repositories ?? []} manageUrl={installation?.manage_url ?? null} />
                        <p className="text-xs text-muted-foreground">
                            {connection.last_refreshed_at ? `Checked with GitHub ${ago(connection.last_refreshed_at)}.` : 'Not refreshed yet.'}
                            {connection.rate_limited_until && ` GitHub’s rate limit is reached until ${new Date(connection.rate_limited_until).toLocaleTimeString()}.`}
                        </p>
                    </>
                ) : (
                    <LegacyWebhookSetup params={params} connection={connection} canSwitch={!!app?.can_connect} />
                )}

                <MergeTransitions params={params} boards={boards} />

                <div className="flex flex-wrap gap-2 border-t pt-4">
                    {isApp ? (
                        <Button variant="outline" disabled={!!connection.rate_limited_until} onClick={() => router.post(route('projects.github.refresh', params), {}, visit)}>
                            <RefreshCw className="size-4" /> Refresh from GitHub
                        </Button>
                    ) : (
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <Button variant="outline"><RefreshCw className="size-4" /> New secret</Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>Create a new secret?</AlertDialogTitle>
                                    <AlertDialogDescription>Events signed with the old secret are rejected until you paste the new one into every connected repository’s webhook.</AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                    <AlertDialogAction onClick={() => router.post(route('projects.github.rotate', params), {}, visit)}>Create new secret</AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    )}
                    <DisconnectButton params={params} app={isApp} />
                </div>
            </CardContent>
        </Card>
    );
}
