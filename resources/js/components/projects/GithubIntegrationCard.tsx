import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
    AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Project, Workspace } from '@/types';
import { router } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { Check, Copy, Eye, EyeOff, Github, RefreshCw, TriangleAlert, Unplug } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

export interface GithubSettings {
    webhook_url: string;
    secret: string;
    repositories: string[];
    last_delivery_at: string | null;
    last_event: string | null;
    last_error: string | null;
    linked_issues: number;
}

const EVENTS = ['Pushes', 'Branch or tag creation', 'Branch or tag deletion', 'Pull requests', 'Deployment statuses'];

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

export default function GithubIntegrationCard({ workspace, project, github }: { workspace: Workspace; project: Project; github: GithubSettings | null }) {
    const params = [workspace.slug, project.slug];
    const key = project.key || 'KEY';

    if (!github) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2"><Github className="size-5" /> GitHub</CardTitle>
                    <CardDescription>
                        Connect repositories so branches, commits, pull requests and deployments show up on the issues they mention —
                        e.g. <code className="rounded bg-muted px-1">fix({key}-123): …</code> or a branch <code className="rounded bg-muted px-1">feat/{key.toLowerCase()}-123-login</code>.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Button onClick={() => router.post(route('projects.github.store', params), {}, { preserveScroll: true, preserveState: true })}>
                        <Github className="size-4" /> Connect GitHub
                    </Button>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2"><Github className="size-5" /> GitHub</CardTitle>
                    {github.last_delivery_at
                        ? <Badge variant="secondary">Last event {formatDistanceToNow(new Date(github.last_delivery_at), { addSuffix: true })}{github.last_event ? ` · ${github.last_event}` : ''}</Badge>
                        : <Badge variant="outline">Waiting for the first event</Badge>}
                </div>
                <CardDescription>
                    Issue keys in commit messages, branch names and pull request titles link them to the issue. {github.linked_issues > 0 && `${github.linked_issues} issue${github.linked_issues === 1 ? '' : 's'} linked so far.`}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
                {github.last_error && (
                    <div className="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive" role="alert">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" /> {github.last_error}
                    </div>
                )}

                <ol className="space-y-5 text-sm">
                    <li className="space-y-3">
                        <p><span className="font-medium">1.</span> In GitHub open the repository → <span className="font-medium">Settings → Webhooks → Add webhook</span> and fill in:</p>
                        <CopyField id="gh-url" label="Payload URL" value={github.webhook_url} />
                        <p className="text-muted-foreground">Content type: <code className="rounded bg-muted px-1">application/json</code></p>
                        <CopyField id="gh-secret" label="Secret" value={github.secret} secret />
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

                {github.repositories.length > 0 && (
                    <div className="space-y-1.5">
                        <p className="text-sm font-medium">Repositories sending events</p>
                        <div className="flex flex-wrap gap-1.5">{github.repositories.map((r) => <Badge key={r} variant="secondary" className="font-mono">{r}</Badge>)}</div>
                    </div>
                )}

                <div className="flex flex-wrap gap-2 border-t pt-4">
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
                                <AlertDialogAction onClick={() => router.post(route('projects.github.rotate', params), {}, { preserveScroll: true, preserveState: true })}>Create new secret</AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                    <AlertDialog>
                        <AlertDialogTrigger asChild>
                            <Button variant="outline" className="text-destructive hover:text-destructive"><Unplug className="size-4" /> Disconnect</Button>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>Disconnect GitHub?</AlertDialogTitle>
                                <AlertDialogDescription>All linked branches, commits, pull requests and deployments are removed from this project’s issues. Delete the webhook in GitHub too.</AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                <AlertDialogAction className="bg-destructive text-destructive-foreground hover:bg-destructive/90" onClick={() => router.delete(route('projects.github.destroy', params), { preserveScroll: true, preserveState: true })}>Disconnect</AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                </div>
            </CardContent>
        </Card>
    );
}
