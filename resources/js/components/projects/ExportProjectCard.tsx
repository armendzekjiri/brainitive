import { useCallback, useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { toast } from 'sonner';
import type { Project, ProjectExportData, Workspace } from '@/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Download, FileArchive, Loader2 } from 'lucide-react';

const STATUS: Record<ProjectExportData['status'], { label: string; tone: string }> = {
    queued: { label: 'Preparing…', tone: 'border-primary/40 text-primary' },
    running: { label: 'Preparing…', tone: 'border-primary/40 text-primary' },
    ready: { label: 'Ready', tone: 'border-green-500/40 text-green-700 dark:text-green-400' },
    failed: { label: 'Failed', tone: 'border-destructive/40 text-destructive' },
    expired: { label: 'Expired', tone: 'text-muted-foreground' },
};

function size(bytes: number): string {
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/**
 * Project settings → "Export project" (project admins, BRAI-81): queue a ZIP of the project and
 * download it here or from the notification. Links work 24 h and only for the person who exported.
 */
export default function ExportProjectCard({ workspace, project }: { workspace: Workspace; project: Project }) {
    const [exports, setExports] = useState<ProjectExportData[]>([]);
    const [starting, setStarting] = useState(false);
    const pending = exports.some((e) => e.status === 'queued' || e.status === 'running');

    const load = useCallback(async () => {
        try {
            const { data } = await window.axios.get<{ exports: ProjectExportData[] }>(route('projects.export.index', [workspace.slug, project.slug]));
            setExports(data.exports);
        } catch {
            // the list is a convenience; the notification still arrives
        }
    }, [workspace.slug, project.slug]);

    useEffect(() => { void load(); }, [load]);
    useEffect(() => {
        if (!pending) return;
        const timer = window.setInterval(() => void load(), 2000);
        return () => window.clearInterval(timer);
    }, [pending, load]);

    const start = async () => {
        setStarting(true);
        try {
            const { data } = await window.axios.post<{ exports: ProjectExportData[] }>(route('projects.export.store', [workspace.slug, project.slug]), {}, { headers: { Accept: 'application/json' } });
            setExports(data.exports);
            toast.success('The export is being prepared. You’ll get a notification when it’s ready.');
        } catch (error) {
            const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response;
            toast.error(response?.data?.errors?.export?.[0] ?? response?.data?.message ?? 'The export could not be started.');
        } finally {
            setStarting(false);
        }
    };

    return (
        <Card id="export">
            <CardHeader>
                <CardTitle className="flex items-center gap-2"><FileArchive className="h-5 w-5" /> Export project</CardTitle>
                <CardDescription>
                    A ZIP with boards, issues, comments, attachments, docs, whiteboards, task lists, plans and the helpdesk structure — to keep as a
                    backup or to restore as a new project on this or another Brainitive (<Link href={route('imports.index', workspace.slug)} className="underline underline-offset-2">Import data</Link>).
                    Members, tickets, tokens and integrations are never included. The download link works for 24 hours, only for you.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <Button type="button" onClick={start} disabled={starting || pending}>
                    {starting || pending ? <Loader2 className="h-4 w-4 animate-spin" /> : <FileArchive className="h-4 w-4" />}
                    {pending ? 'Preparing export…' : 'Export project'}
                </Button>

                {exports.length > 0 && (
                    <ul aria-label="Your exports" className="divide-y rounded-md border text-sm">
                        {exports.map((e) => (
                            <li key={e.uuid} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                <Badge variant="outline" className={STATUS[e.status].tone}>{STATUS[e.status].label}</Badge>
                                {e.created_at && <span className="text-muted-foreground">{formatDistanceToNow(new Date(e.created_at), { addSuffix: true })}</span>}
                                {e.status === 'ready' && <span className="text-muted-foreground">· {size(e.file_size)}</span>}
                                {e.error && <span className="text-destructive">{e.error}</span>}
                                {e.status === 'ready' && e.download_url && (
                                    <Button asChild size="sm" variant="outline" className="ml-auto">
                                        <a href={e.download_url} download><Download className="h-4 w-4" /> Download</a>
                                    </Button>
                                )}
                                {e.status === 'ready' && e.expires_at && (
                                    <span className="w-full text-xs text-muted-foreground">Expires {formatDistanceToNow(new Date(e.expires_at), { addSuffix: true })}</span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
