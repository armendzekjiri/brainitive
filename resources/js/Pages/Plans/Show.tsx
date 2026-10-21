import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle, Check, ChevronDown, CloudOff, Copy, Download, FileSpreadsheet, GanttChart, Globe, History, Loader2,
    MoreHorizontal, Printer, Save, Trash2, Upload,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter,
    AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { PlanChart } from '@/components/plan/PlanChart';
import { PlanHistoryDialog } from '@/components/plan/PlanHistoryDialog';
import { ImportDialog } from '@/components/plan/ImportDialog';
import { ShareDialog } from '@/components/plan/ShareDialog';
import { downloadFile, toCsv } from '@/components/plan/csv';
import { formatDate, todayDay } from '@/components/plan/dates';
import { insertRows } from '@/components/plan/ops';
import { PlanPayload, PlanRow } from '@/components/plan/types';
import { usePlanEditor } from '@/components/plan/usePlanEditor';
import { PageProps, Project, Workspace } from '@/types';
import { cn } from '@/lib/utils';

interface Props extends PageProps {
    workspace: Workspace;
    project: Project;
    plan: PlanPayload;
    members: { id: number; name: string; avatar?: string | null }[];
    can: { update: boolean; delete: boolean };
}

function relativeTime(date: Date | null): string {
    if (!date) return '';
    const seconds = Math.round((Date.now() - date.getTime()) / 1000);
    if (seconds < 45) return 'just now';
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `${minutes} min ago`;
    return date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

export default function PlanShow({ workspace, project, plan, members, can }: Props) {
    const params = useMemo(() => [workspace.slug, project.slug, plan.slug] as [string, string, string], [workspace.slug, project.slug, plan.slug]);
    const printMode = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('print') === '1';

    const editor = usePlanEditor({
        initial: { data: plan.data, settings: plan.settings },
        revision: plan.edit_revision,
        saveUrl: route('plans.save', params),
        documentUrl: route('plans.document', params),
        canEdit: can.update && !printMode,
    });

    const [historyOpen, setHistoryOpen] = useState(false);
    const [shareOpen, setShareOpen] = useState(false);
    const [importOpen, setImportOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [shareUrl, setShareUrl] = useState(plan.share_url);
    const [renaming, setRenaming] = useState(false);
    const [name, setName] = useState(plan.name);
    const [, setTick] = useState(0);

    // Keep "saved 3 min ago" fresh
    useEffect(() => {
        const id = setInterval(() => setTick((t) => t + 1), 30_000);
        return () => clearInterval(id);
    }, []);

    useEffect(() => setName(plan.name), [plan.name]);

    useEffect(() => {
        if (!printMode) return;
        const id = setTimeout(() => window.print(), 700);
        return () => clearTimeout(id);
    }, [printMode]);

    const rename = (event?: FormEvent) => {
        event?.preventDefault();
        setRenaming(false);
        const trimmed = name.trim();
        if (!trimmed || trimmed === plan.name) {
            setName(plan.name);
            return;
        }
        router.patch(route('plans.update', params), { name: trimmed }, { preserveScroll: true, preserveState: true });
    };

    const exportCsv = () => downloadFile(`${plan.slug}.csv`, toCsv(editor.doc.data), 'text/csv;charset=utf-8');
    const openPrint = () => window.open(`${route('plans.show', params)}?print=1`, '_blank', 'noopener');

    const importRows = (rows: PlanRow[], mode: 'append' | 'replace') => {
        editor.apply((doc) => ({
            ...doc,
            data: mode === 'replace' ? { rows, dependencies: [] } : insertRows(doc.data, doc.data.rows.length, rows),
        }));
    };

    const restore = async (version: number) => {
        const { data } = await window.axios.post(route('plans.versions.restore', [...params, version]));
        editor.replace({ data: data.data, settings: data.settings }, data.edit_revision);
    };

    if (printMode) {
        return (
            <div className="min-h-screen bg-white p-6 text-foreground print:p-0">
                <Head title={`${plan.name} – print`} />
                <style>{'@media print { @page { size: landscape; margin: 10mm; } body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }'}</style>
                <div className="mb-4 flex items-end justify-between gap-4">
                    <div>
                        <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{project.name}</div>
                        <h1 className="text-2xl font-semibold">{plan.name}</h1>
                        {plan.description && <p className="text-sm text-muted-foreground">{plan.description}</p>}
                    </div>
                    <div className="text-xs text-muted-foreground">Printed {formatDate(todayDay())}</div>
                </div>
                <div className="w-max rounded-md border">
                    <PlanChart doc={editor.doc} printMode showToolbar={false} />
                </div>
            </div>
        );
    }

    const saveStatus = (() => {
        switch (editor.saveState) {
            case 'saving': return { icon: <Loader2 className="size-3.5 animate-spin" />, text: 'Saving…', tone: 'text-muted-foreground' };
            case 'dirty': return { icon: <span className="size-1.5 rounded-full bg-amber-500" />, text: 'Unsaved changes', tone: 'text-muted-foreground' };
            case 'error': return { icon: <CloudOff className="size-3.5" />, text: 'Offline — retrying', tone: 'text-destructive' };
            case 'conflict': return { icon: <AlertTriangle className="size-3.5" />, text: 'Not saved', tone: 'text-destructive' };
            default: return { icon: <Check className="size-3.5" />, text: editor.lastSavedAt ? `Saved ${relativeTime(editor.lastSavedAt)}` : 'All changes saved', tone: 'text-muted-foreground' };
        }
    })();

    return (
        <AppLayout>
            <Head title={plan.name} />
            <div className="flex h-full flex-col overflow-hidden bg-background">
                {/* Header */}
                <div className="shrink-0 px-4 pt-4 sm:px-6">
                    <nav className="flex items-center gap-1.5 text-[13px] text-muted-foreground" aria-label="Breadcrumb">
                        <Link href={route('projects.show', [workspace.slug, project.slug])} className="hover:text-foreground hover:underline">Projects</Link>
                        <span aria-hidden>/</span>
                        <Link href={route('projects.show', [workspace.slug, project.slug])} className="inline-flex items-center gap-1.5 hover:text-foreground hover:underline">
                            <span className="grid size-4 place-items-center rounded-[3px]" style={{ background: project.color || '#0E83DC' }}>
                                <GanttChart className="size-2.5 text-white" />
                            </span>
                            {project.name}
                        </Link>
                        <span aria-hidden>/</span>
                        <span>Plans</span>
                    </nav>
                    <div className="mt-2 flex flex-wrap items-center justify-between gap-3 pb-3">
                        <div className="flex min-w-0 items-center gap-3">
                            {renaming ? (
                                <form onSubmit={rename} className="min-w-0">
                                    <input
                                        autoFocus
                                        value={name}
                                        maxLength={255}
                                        onChange={(e) => setName(e.target.value)}
                                        onBlur={() => rename()}
                                        onKeyDown={(e) => { if (e.key === 'Escape') { setName(plan.name); setRenaming(false); } }}
                                        className="w-[min(32rem,70vw)] rounded-md border bg-background px-2 py-0.5 text-2xl font-semibold tracking-[-0.01em] outline-none focus:ring-2 focus:ring-ring/30"
                                        aria-label="Plan name"
                                    />
                                </form>
                            ) : (
                                <h1
                                    className={cn('m-0 truncate text-2xl font-semibold tracking-[-0.01em]', can.update && 'cursor-text rounded-md px-1 -mx-1 hover:bg-accent')}
                                    onClick={() => can.update && setRenaming(true)}
                                    title={can.update ? 'Click to rename' : undefined}
                                >
                                    {plan.name}
                                </h1>
                            )}
                            {can.update ? (
                                <span className={cn('hidden items-center gap-1.5 whitespace-nowrap text-xs sm:inline-flex', saveStatus.tone)} aria-live="polite">
                                    {saveStatus.icon}{saveStatus.text}
                                </span>
                            ) : (
                                <span className="rounded bg-muted px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">View only</span>
                            )}
                        </div>
                        <div className="flex items-center gap-1.5">
                            {can.update && (
                                <Button variant="ghost" size="sm" className="h-8 gap-1.5 text-muted-foreground" onClick={() => editor.saveNow()} title="Save now and keep this state in the history">
                                    <Save className="size-3.5" /><span className="hidden md:inline">Save version</span>
                                </Button>
                            )}
                            <Button variant="ghost" size="sm" className="h-8 gap-1.5 text-muted-foreground" onClick={() => setHistoryOpen(true)}>
                                <History className="size-3.5" /><span className="hidden md:inline">History</span>
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" size="sm" className="h-8 gap-1 text-muted-foreground">
                                        <Download className="size-3.5" /><span className="hidden md:inline">Export</span><ChevronDown className="size-3" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem onClick={exportCsv}><FileSpreadsheet className="mr-2 size-4" />Excel / CSV</DropdownMenuItem>
                                    <DropdownMenuItem onClick={openPrint}><Printer className="mr-2 size-4" />Print / PDF</DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <Button size="sm" variant="outline" className="h-8 gap-1.5" onClick={() => setShareOpen(true)}>
                                <Globe className="size-3.5" />{shareUrl ? 'Shared' : 'Share'}
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" size="icon" className="size-8 text-muted-foreground" aria-label="More"><MoreHorizontal className="size-4" /></Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-48">
                                    {can.update && <DropdownMenuItem onClick={() => setImportOpen(true)}><Upload className="mr-2 size-4" />Import rows…</DropdownMenuItem>}
                                    {can.update && <DropdownMenuItem onClick={() => setRenaming(true)}><GanttChart className="mr-2 size-4" />Rename</DropdownMenuItem>}
                                    <DropdownMenuItem onClick={() => router.post(route('plans.duplicate', params))}><Copy className="mr-2 size-4" />Duplicate</DropdownMenuItem>
                                    {can.delete && (
                                        <>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem className="text-destructive focus:text-destructive" onClick={() => setDeleteOpen(true)}><Trash2 className="mr-2 size-4" />Delete plan</DropdownMenuItem>
                                        </>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                </div>

                {editor.saveState === 'conflict' && (
                    <div className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-y border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200 sm:px-6" role="alert">
                        <span className="flex items-center gap-2">
                            <AlertTriangle className="size-4 shrink-0" />
                            {editor.conflict?.updated_by?.name ?? 'Someone else'} changed this plan while you were editing. Your latest changes are not saved.
                        </span>
                        <span className="flex gap-2">
                            <Button size="sm" variant="outline" className="h-7 bg-background" onClick={() => editor.loadLatest()}>Load latest</Button>
                            <Button size="sm" variant="outline" className="h-7 bg-background" onClick={() => editor.overwrite()}>Overwrite with mine</Button>
                        </span>
                    </div>
                )}

                <PlanChart
                    doc={editor.doc}
                    editor={can.update ? editor : null}
                    members={members}
                    className="min-h-0 flex-1 border-t"
                />
            </div>

            <PlanHistoryDialog
                open={historyOpen}
                onOpenChange={setHistoryOpen}
                versionsUrl={route('plans.versions.index', params)}
                versionUrl={(version) => route('plans.versions.show', [...params, version])}
                canRestore={can.update}
                onRestore={restore}
            />
            <ShareDialog
                open={shareOpen}
                onOpenChange={setShareOpen}
                shareUrl={shareUrl}
                canManage={can.update}
                shareRoute={route('plans.share', params)}
                onChange={setShareUrl}
            />
            <ImportDialog open={importOpen} onOpenChange={setImportOpen} hasRows={editor.doc.data.rows.length > 0} onImport={importRows} />
            <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete “{plan.name}”?</AlertDialogTitle>
                        <AlertDialogDescription>The plan and its share link are removed for everyone in the project.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction className="bg-destructive text-destructive-foreground hover:bg-destructive/90" onClick={() => router.delete(route('plans.destroy', params))}>Delete</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
