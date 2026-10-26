import AdminShell from '@/components/admin/AdminShell';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
    AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AdminGithubApp } from '@/types';
import { router } from '@inertiajs/react';
import { ExternalLink, Github, Loader2, Trash2, TriangleAlert } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { toast } from 'sonner';

interface Props {
    app: AdminGithubApp | null;
    fromEnv: boolean;
    stats: { installations: number; app_connections: number; legacy_connections: number };
    defaults: {
        name: string;
        webhook_url: string;
        setup_url: string;
        callback_url: string;
        permissions: Record<string, string>;
        events: string[];
    };
}

function ReadOnly({ id, label, value }: { id: string; label: string; value: string }) {
    return (
        <div className="space-y-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Input id={id} readOnly value={value} className="font-mono text-xs" onFocus={(e) => e.currentTarget.select()} />
        </div>
    );
}

/** Posts the manifest to github.com in this tab: GitHub needs the admin signed in there. */
function postToGithub(url: string, manifest: string) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'manifest';
    input.value = manifest;
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}

function CreateApp({ defaults }: { defaults: Props['defaults'] }) {
    const [name, setName] = useState(defaults.name);
    const [organization, setOrganization] = useState('');
    const [isPublic, setIsPublic] = useState(true);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        window.axios.post(route('admin.github-app.manifest'), { name, organization: organization || null, public: isPublic })
            .then(({ data }: { data: { url: string; manifest: string } }) => postToGithub(data.url, data.manifest))
            .catch((error) => {
                setBusy(false);
                const fieldErrors = error?.response?.data?.errors as Record<string, string[]> | undefined;
                if (fieldErrors) setErrors(Object.fromEntries(Object.entries(fieldErrors).map(([k, v]) => [k, v[0]])));
                else toast.error(error?.response?.data?.message ?? 'Could not start creating the app.');
            });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Create the GitHub App</CardTitle>
                <CardDescription>
                    One click: GitHub creates an app with read-only access and the right events, then sends you back here. Its private key and
                    secrets are stored encrypted. After that, project admins connect repositories with “Connect GitHub” — no webhooks to paste.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="gh-app-name">App name</Label>
                            <Input id="gh-app-name" value={name} maxLength={34} onChange={(e) => setName(e.target.value)} required />
                            {errors.name ? <p className="text-sm text-destructive">{errors.name}</p> : <p className="text-xs text-muted-foreground">Must be unique on GitHub; people see it when they install.</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="gh-app-org">GitHub organization (optional)</Label>
                            <Input id="gh-app-org" value={organization} onChange={(e) => setOrganization(e.target.value)} placeholder="your-org" />
                            {errors.organization ? <p className="text-sm text-destructive">{errors.organization}</p> : <p className="text-xs text-muted-foreground">Empty = your personal GitHub account owns the app.</p>}
                        </div>
                    </div>
                    <label className="flex items-start gap-3 text-sm">
                        <input type="checkbox" className="mt-0.5 size-4 accent-primary" checked={isPublic} onChange={(e) => setIsPublic(e.target.checked)} />
                        <span>
                            <span className="font-medium">Any GitHub account can install it</span>
                            <span className="block text-muted-foreground">Needed when your users connect repositories of their own accounts or organizations. Off = only the owner account can install it.</span>
                        </span>
                    </label>
                    <Button type="submit" disabled={busy || name.trim() === ''}>
                        {busy ? <Loader2 className="size-4 animate-spin" /> : <Github className="size-4" />} Create GitHub App on GitHub
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function AppStatus({ app, stats, fromEnv }: { app: AdminGithubApp; stats: Props['stats']; fromEnv: boolean }) {
    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2"><Github className="size-5" /> {app.name || app.slug}</CardTitle>
                    <Badge variant={app.source === 'env' ? 'secondary' : 'outline'}>
                        {app.source === 'env' ? 'Set by environment variables' : app.source === 'fake' ? 'Test app (e2e)' : 'Created here'}
                    </Badge>
                </div>
                <CardDescription>Project admins can connect repositories through this app from their project’s settings → Integrations.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
                {!app.can_verify_installations && (
                    <div className="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive" role="alert">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                        The app has no client id / secret, so installations can’t be verified and “Connect GitHub” stays off. Set GITHUB_APP_CLIENT_ID and GITHUB_APP_CLIENT_SECRET.
                    </div>
                )}
                <dl className="grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt className="text-muted-foreground">App</dt><dd className="font-mono break-all">{app.slug} · #{app.app_id}</dd></div>
                    <div><dt className="text-muted-foreground">Owner</dt><dd>{app.owner ?? '—'}</dd></div>
                    <div><dt className="text-muted-foreground">Installations</dt><dd>{stats.installations}</dd></div>
                    <div><dt className="text-muted-foreground">Projects on the app</dt><dd>{stats.app_connections}</dd></div>
                    <div><dt className="text-muted-foreground">Projects on manual webhooks</dt><dd>{stats.legacy_connections}</dd></div>
                </dl>
                <div className="flex flex-wrap gap-2 border-t pt-4">
                    <Button variant="outline" asChild>
                        <a href={app.url} target="_blank" rel="noopener noreferrer"><ExternalLink className="size-4" /> Open on GitHub</a>
                    </Button>
                    {app.source === 'stored' && !fromEnv && (
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <Button variant="outline" className="text-destructive hover:text-destructive"><Trash2 className="size-4" /> Remove from this instance</Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>Remove the GitHub App?</AlertDialogTitle>
                                    <AlertDialogDescription>
                                        Projects connected through it stop receiving events until a new app is set up and they connect again. The app itself stays on GitHub — delete it there too.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                    <AlertDialogAction className="bg-destructive text-destructive-foreground hover:bg-destructive/90" onClick={() => router.delete(route('admin.github-app.destroy'), { preserveScroll: true })}>Remove</AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

export default function GithubApp({ app, fromEnv, stats, defaults }: Props) {
    return (
        <AdminShell title="GitHub App">
            <div className="space-y-6">
                {app ? <AppStatus app={app} stats={stats} fromEnv={fromEnv} /> : fromEnv ? (
                    <div className="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive" role="alert">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                        GITHUB_APP_ID is set, but the app is incomplete: GITHUB_APP_SLUG, GITHUB_APP_PRIVATE_KEY and GITHUB_APP_WEBHOOK_SECRET are needed too.
                    </div>
                ) : <CreateApp defaults={defaults} />}

                <Card>
                    <CardHeader>
                        <CardTitle>What the app gets</CardTitle>
                        <CardDescription>For reference, or to create the app by hand and set the GITHUB_APP_* environment variables.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4 text-sm">
                        <div className="space-y-1.5">
                            <p className="font-medium">Repository permissions (read-only)</p>
                            <div className="flex flex-wrap gap-1.5">
                                {Object.entries(defaults.permissions).map(([name, level]) => <Badge key={name} variant="outline">{name.replace('_', ' ')}: {level}</Badge>)}
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <p className="font-medium">Events</p>
                            <div className="flex flex-wrap gap-1.5">{defaults.events.map((e) => <Badge key={e} variant="outline">{e}</Badge>)}</div>
                            <p className="text-muted-foreground">Installation and repository changes arrive on their own.</p>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <ReadOnly id="gh-webhook-url" label="Webhook URL" value={defaults.webhook_url} />
                            <ReadOnly id="gh-setup-url" label="Setup URL + callback URL" value={defaults.setup_url} />
                        </div>
                        <p className="text-muted-foreground">Turn on “Request user authorization (OAuth) during installation” — it proves an installation belongs to the person connecting it.</p>
                    </CardContent>
                </Card>
            </div>
        </AdminShell>
    );
}
