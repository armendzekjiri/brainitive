import { SOURCE_PREVIEWS } from '@/components/imports/sources';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { STATUS_LABELS, statusTone } from '@/lib/imports';
import { cn } from '@/lib/utils';
import type { ImportRunData, ImportSource } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, ArrowLeft, CheckCircle2, Loader2, RotateCcw, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    workspace: { id: number; name: string; slug: string };
    run: ImportRunData;
    source: ImportSource | null;
    importedBefore: { uuid: string; finished_at: string | null } | null;
}

/** One import (v1.41.0): preview + options → progress → report. */
export default function ImportsShow({ workspace, run: initial, source, importedBefore }: Props) {
    const [run, setRun] = useState(initial);
    const [busy, setBusy] = useState(false);
    useEffect(() => setRun(initial), [initial]);

    // poll while the job works
    useEffect(() => {
        if (run.status !== 'queued' && run.status !== 'running') return;
        const timer = window.setInterval(async () => {
            try {
                const { data } = await axios.get<ImportRunData>(route('imports.status', [workspace.slug, run.uuid]));
                setRun(data);
            } catch {
                // keep polling; a hiccup is not a failure
            }
        }, 2000);
        return () => window.clearInterval(timer);
    }, [run.status, run.uuid, workspace.slug]);

    const start = (options: Record<string, unknown>) =>
        router.post(route('imports.start', [workspace.slug, run.uuid]), { options } as never, { onStart: () => setBusy(true), onFinish: () => setBusy(false), preserveScroll: true });
    const remove = () => router.delete(route('imports.destroy', [workspace.slug, run.uuid]));

    const Preview = SOURCE_PREVIEWS[run.source];
    const percent = run.progress.total > 0 ? Math.min(100, Math.round((run.progress.done / run.progress.total) * 100)) : null;
    const report = run.report ?? {};
    const resultUrl = typeof run.result?.url === 'string' ? run.result.url : null;

    return (
        <AppLayout>
            <Head title={`Import from ${source?.label ?? run.source}`} />
            <div className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-8">
                <Link href={route('imports.index', workspace.slug)} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" /> All imports
                </Link>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Import from {source?.label ?? run.source}</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {run.file_name}{run.project ? <> → <span className="font-medium text-foreground">{run.project.name}</span></> : null}
                        </p>
                    </div>
                    <Badge variant="outline" className={statusTone(run.status)} data-testid="import-status">{STATUS_LABELS[run.status]}</Badge>
                </div>

                {importedBefore && run.status === 'previewed' && (
                    <p role="status" className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        This exact file was imported before. Importing it again creates a second copy.
                    </p>
                )}

                {run.error && (
                    <p role="alert" className="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" /> {run.error}
                    </p>
                )}

                {run.status === 'previewed' && (Preview
                    ? <Preview run={run} workspaceSlug={workspace.slug} busy={busy} onStart={start} />
                    : (
                        <Card>
                            <CardHeader><CardTitle className="text-base">Preview</CardTitle><CardDescription>Check what will be imported, then start.</CardDescription></CardHeader>
                            <CardContent className="space-y-3">
                                <pre className="max-h-80 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(run.preview, null, 2)}</pre>
                                <Button onClick={() => start({})} disabled={busy}>{busy && <Loader2 className="size-4 animate-spin" />} Start import</Button>
                            </CardContent>
                        </Card>
                    ))}

                {(run.status === 'queued' || run.status === 'running') && (
                    <Card>
                        <CardContent className="space-y-3 py-6">
                            <p className="flex items-center gap-2 text-sm font-medium"><Loader2 className="size-4 animate-spin" /> {run.status === 'queued' ? 'Waiting to start…' : 'Importing…'}</p>
                            <div className="h-2 overflow-hidden rounded-full bg-muted" role="progressbar" aria-label="Import progress" aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent ?? undefined}>
                                <div className={cn('h-full bg-primary transition-all', percent === null && 'w-1/3 animate-pulse')} style={percent === null ? undefined : { width: `${percent}%` }} />
                            </div>
                            {run.progress.total > 0 && <p className="text-xs text-muted-foreground">{run.progress.done} of {run.progress.total}</p>}
                            <p className="text-xs text-muted-foreground">You can leave this page; the import keeps going.</p>
                        </CardContent>
                    </Card>
                )}

                {(run.status === 'completed' || run.status === 'failed') && (report.created || report.warnings?.length || report.unsupported) && (
                    <Card data-testid="import-report">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                {run.status === 'completed' ? <CheckCircle2 className="size-5 text-green-600" /> : <AlertTriangle className="size-5 text-destructive" />}
                                {run.status === 'completed' ? 'Imported' : 'Imported so far'}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 text-sm">
                            {report.created && Object.keys(report.created).length > 0 && (
                                <ul className="flex flex-wrap gap-2">
                                    {Object.entries(report.created).map(([what, count]) => <li key={what}><Badge variant="secondary">{count} {what}</Badge></li>)}
                                </ul>
                            )}
                            {report.unsupported && Object.keys(report.unsupported).length > 0 && (
                                <div>
                                    <p className="font-medium">Not imported</p>
                                    <ul className="mt-1 list-disc pl-5 text-muted-foreground">
                                        {Object.entries(report.unsupported).map(([what, count]) => <li key={what}>{what}: {count}</li>)}
                                    </ul>
                                </div>
                            )}
                            {report.warnings && report.warnings.length > 0 && (
                                <div>
                                    <p className="font-medium">Notes</p>
                                    <ul className="mt-1 list-disc pl-5 text-muted-foreground">{report.warnings.map((w) => <li key={w}>{w}</li>)}</ul>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                <div className="flex flex-wrap gap-2">
                    {run.status === 'completed' && resultUrl && <Button asChild><a href={resultUrl}>Open what was imported</a></Button>}
                    {run.status === 'failed' && run.preview && (
                        <Button onClick={() => start(run.options ?? {})} disabled={busy}><RotateCcw className="size-4" /> Retry — continue where it stopped</Button>
                    )}
                    {(run.status === 'previewed' || run.status === 'failed' || run.status === 'uploaded') && (
                        <Button variant="ghost" onClick={remove}><Trash2 className="size-4" /> Cancel this import</Button>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
