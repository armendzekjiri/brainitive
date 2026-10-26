import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import type { ImportRunData, ImportSource } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { FileUp, Loader2, PackageOpen } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { STATUS_LABELS, statusTone } from '@/lib/imports';

interface Props {
    workspace: { id: number; name: string; slug: string };
    sources: ImportSource[];
    projects: { id: number; name: string; slug: string; key: string }[];
    preselectedProject: number | null;
    canCreateProjects: boolean;
    runs: ImportRunData[];
}

/** Bring your data (v1.41.0): pick a source, a target project and the exported file. */
export default function ImportsIndex({ workspace, sources, projects, preselectedProject, runs }: Props) {
    const [source, setSource] = useState<string>(sources[0]?.key ?? '');
    const [projectId, setProjectId] = useState<string>(preselectedProject && projects.some((p) => p.id === preselectedProject) ? String(preselectedProject) : '');
    const [file, setFile] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const current = sources.find((s) => s.key === source) ?? null;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!current || !file) return;
        router.post(route('imports.store', workspace.slug), { source, file, ...(current.needs_project ? { project_id: projectId } : {}) }, {
            forceFormData: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onError: (errs) => setErrors(errs),
        });
    };

    return (
        <AppLayout>
            <Head title="Import" />
            <div className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-8">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight"><PackageOpen className="size-6" /> Bring your data</h1>
                    <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                        Move your work from another tool into {workspace.name}. You always see a preview first — nothing is created until you confirm.
                    </p>
                </div>

                {sources.length === 0 ? (
                    <Card><CardContent className="py-8 text-center text-sm text-muted-foreground">No importers are available yet.</CardContent></Card>
                ) : (
                    <form onSubmit={submit} className="space-y-6">
                        <div role="radiogroup" aria-label="Import from" className="grid gap-3 sm:grid-cols-2">
                            {sources.map((s) => (
                                <button
                                    key={s.key}
                                    type="button"
                                    role="radio"
                                    aria-checked={source === s.key}
                                    onClick={() => { setSource(s.key); setErrors({}); }}
                                    className={cn('rounded-lg border bg-card p-4 text-left transition-colors hover:bg-muted/50', source === s.key && 'border-primary ring-2 ring-primary/20')}
                                >
                                    <span className="block font-semibold">{s.label}</span>
                                    <span className="mt-1 block text-sm text-muted-foreground">{s.description}</span>
                                </button>
                            ))}
                        </div>

                        {current && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Import from {current.label}</CardTitle>
                                    <CardDescription>{current.needs_project ? 'Pick the project it goes into, then the exported file.' : 'This creates a new project from the file.'}</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {current.needs_project && (
                                        <div className="space-y-1.5">
                                            <Label htmlFor="import-project">Project</Label>
                                            <Select value={projectId} onValueChange={setProjectId}>
                                                <SelectTrigger id="import-project" aria-label="Project" className="max-w-sm"><SelectValue placeholder="Choose a project" /></SelectTrigger>
                                                <SelectContent>
                                                    {projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                                                </SelectContent>
                                            </Select>
                                            {errors.project_id && <p className="text-sm text-destructive">{errors.project_id}</p>}
                                        </div>
                                    )}
                                    <div className="space-y-1.5">
                                        <Label htmlFor="import-file">Exported file</Label>
                                        <input
                                            id="import-file"
                                            type="file"
                                            accept={current.accept}
                                            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                                            className="block w-full max-w-md text-sm file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-1.5 file:text-sm file:font-medium"
                                        />
                                        {(errors.file || errors.source) && <p className="text-sm text-destructive">{errors.file ?? errors.source}</p>}
                                    </div>
                                    <Button type="submit" disabled={busy || !file || (current.needs_project && !projectId)}>
                                        {busy ? <Loader2 className="size-4 animate-spin" /> : <FileUp className="size-4" />}
                                        Upload and preview
                                    </Button>
                                </CardContent>
                            </Card>
                        )}
                    </form>
                )}

                {runs.length > 0 && (
                    <section aria-labelledby="recent-imports" className="space-y-2">
                        <h2 id="recent-imports" className="text-sm font-semibold">Recent imports</h2>
                        <ul className="divide-y rounded-lg border bg-card">
                            {runs.map((r) => (
                                <li key={r.uuid}>
                                    <Link href={route('imports.show', [workspace.slug, r.uuid])} className="flex flex-wrap items-center gap-2 px-4 py-3 text-sm hover:bg-muted/40">
                                        <span className="font-medium">{sources.find((s) => s.key === r.source)?.label ?? r.source}</span>
                                        <span className="truncate text-muted-foreground">{r.file_name}</span>
                                        {r.project && <span className="text-muted-foreground">→ {r.project.name}</span>}
                                        <Badge variant="outline" className={cn('ml-auto', statusTone(r.status))}>{STATUS_LABELS[r.status]}</Badge>
                                        {r.created_at && <span className="text-xs text-muted-foreground">{formatDistanceToNow(new Date(r.created_at), { addSuffix: true })}</span>}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
