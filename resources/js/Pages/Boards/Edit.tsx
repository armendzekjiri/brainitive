import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { Board, BoardColumn, PageProps, Project, Workspace, WorkflowRuleMember, WorkflowRuleRole, WorkflowRuleSettings } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ProjectIcon } from '@/components/ProjectIcon';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader,
    AlertDialogTitle, AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { ArrowLeft, Columns3, GitBranch, Rows3, Settings, Shield, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { toast } from 'sonner';
import SwimlaneSettings, { type LaneOptions } from '@/components/board/SwimlaneSettings';
import DoneRetentionSettings from '@/components/board/DoneRetentionSettings';
import ColumnsSettings from '@/components/board/settings/ColumnsSettings';
import WorkflowRulesSettings from '@/components/board/settings/WorkflowRulesSettings';

interface Props extends PageProps {
    laneOptions: LaneOptions;
    workspace: Workspace;
    project: Project;
    board: Board & { columns: BoardColumn[] };
    workflowRules: Record<number, WorkflowRuleSettings>;
    ruleMembers: WorkflowRuleMember[];
    ruleRoles: WorkflowRuleRole[];
}

const SECTIONS = [
    { value: 'general', label: 'General', icon: Settings },
    { value: 'columns', label: 'Columns & statuses', icon: Columns3 },
    { value: 'workflow', label: 'Workflow rules', icon: GitBranch },
    { value: 'swimlanes', label: 'Swimlanes', icon: Rows3 },
] as const;
type Section = (typeof SECTIONS)[number]['value'] | 'danger';

const TRIGGER = 'w-auto shrink-0 justify-start gap-2 px-3 h-9 lg:w-full data-[state=active]:bg-secondary data-[state=active]:text-foreground data-[state=active]:shadow-none';

/** `?tab=` of the page's URL (Inertia's, so it is right before the browser's URL updates). */
function readTab(pageUrl: string): Section {
    const tab = new URLSearchParams(pageUrl.split('?')[1]?.split('#')[0] ?? '').get('tab');
    return ([...SECTIONS.map((s) => s.value), 'danger'] as string[]).includes(tab ?? '') ? (tab as Section) : 'general';
}

function GeneralCard({ workspace, project, board }: { workspace: Workspace; project: Project; board: Board }) {
    const { data, setData, put, processing, errors, isDirty } = useForm({
        name: board.name,
        description: board.description || '',
        type: board.type === 'scrum' ? 'scrum' : 'kanban',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put(route('boards.update', [workspace.slug, project.slug, board.slug]), {
            preserveScroll: true,
            onSuccess: () => toast.success('Board saved.'),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Could not save the board.'),
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Board details</CardTitle>
                <CardDescription>The name and description people see, and how the board plans work.</CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-5">
                    <div className="space-y-2">
                        <Label htmlFor="name">Board Name</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required maxLength={255} />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="description">Description (optional)</Label>
                        <Textarea
                            id="description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="What is this board for?"
                            rows={3}
                        />
                        {errors.description && <p className="text-sm text-destructive">{errors.description}</p>}
                    </div>
                    <label className="flex cursor-pointer items-start gap-3 rounded-md border border-border p-3">
                        <input
                            type="checkbox"
                            className="mt-0.5 size-4 accent-[hsl(var(--primary))]"
                            checked={data.type === 'scrum'}
                            onChange={(e) => setData('type', e.target.checked ? 'scrum' : 'kanban')}
                        />
                        <span className="space-y-0.5">
                            <span className="block text-sm font-medium">Use sprints (Scrum)</span>
                            <span className="block text-xs text-muted-foreground">
                                Adds a Backlog and Reports. The board then shows only the active sprint; new cards join it.
                            </span>
                        </span>
                    </label>
                    {errors.type && <p className="text-sm text-destructive">{errors.type}</p>}
                    <div className="flex items-center justify-end gap-3">
                        {isDirty && <span className="text-xs text-amber-700 dark:text-amber-400">Unsaved changes</span>}
                        <Button type="submit" disabled={processing}>{processing ? 'Saving...' : 'Save Changes'}</Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function DangerZone({ workspace, project, board }: { workspace: Workspace; project: Project; board: Board }) {
    return (
        <Card className="border-destructive/50">
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-destructive"><Trash2 className="size-5" /> Danger zone</CardTitle>
                <CardDescription>Irreversible actions. Proceed with caution.</CardDescription>
            </CardHeader>
            <CardContent>
                <div className="flex flex-col gap-3 rounded-lg border border-destructive/30 bg-destructive/5 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h4 className="font-semibold">Delete this board</h4>
                        <p className="text-sm text-muted-foreground">All columns, cards and workflow rules of the board are deleted.</p>
                    </div>
                    <AlertDialog>
                        <AlertDialogTrigger asChild>
                            <Button variant="destructive" className="gap-2"><Trash2 className="size-4" />Delete Board</Button>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>Delete board?</AlertDialogTitle>
                                <AlertDialogDescription>
                                    Are you sure you want to delete “{board.name}”? This deletes all columns and cards. This action cannot be undone.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                <AlertDialogAction
                                    onClick={() => router.delete(route('boards.destroy', [workspace.slug, project.slug, board.slug]))}
                                    className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                >
                                    Delete Board
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * Board settings: a sectioned page like Project settings (left nav, scrolling tabs on phones, `?tab=` deep links) —
 * General, Columns & statuses, Workflow rules (v1.52.0), Swimlanes, Danger zone.
 */
export default function BoardsEdit({ workspace, project, board, laneOptions, workflowRules, ruleMembers, ruleRoles }: Props) {
    const pageUrl = usePage().url;
    const [tab, setTab] = useState<Section>(() => readTab(pageUrl));
    const rules = workflowRules ?? {};

    const switchTo = (next: string, anchor?: string) => {
        setTab(next as Section);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', next);
        url.hash = anchor ?? '';
        window.history.replaceState(window.history.state, '', url);
        if (anchor) requestAnimationFrame(() => document.getElementById(anchor)?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    };

    return (
        <AppLayout>
            <Head title={`Settings - ${board.name}`} />

            <div className="min-h-full bg-background">
                <div className="sticky top-0 z-10 border-b bg-background/95 backdrop-blur">
                    <div className="mx-auto flex max-w-5xl items-center gap-3 px-4 py-4 sm:px-6">
                        <Link href={route('boards.show', [workspace.slug, project.slug, board.slug])}>
                            <Button variant="ghost" size="icon" className="size-8" aria-label="Back to the board">
                                <ArrowLeft className="size-4" />
                            </Button>
                        </Link>
                        <div className="min-w-0">
                            <h1 className="text-xl font-semibold">Board settings</h1>
                            <p className="flex items-center gap-2 truncate text-sm text-muted-foreground">
                                <ProjectIcon project={project} size="xs" />
                                <span className="truncate">{project.name} · {board.name}</span>
                            </p>
                        </div>
                    </div>
                </div>

                <div className="mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-8">
                    <Tabs value={tab} onValueChange={(v) => switchTo(v)} orientation="vertical" className="space-y-0">
                        <div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:gap-8">
                            <aside className="w-full lg:sticky lg:top-24 lg:w-56 lg:shrink-0">
                                <div className="mb-2 hidden px-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground lg:block">Settings</div>
                                <TabsList className="flex h-auto w-full flex-row justify-start gap-1 overflow-x-auto bg-transparent p-0 lg:flex-col">
                                    {SECTIONS.map(({ value, label, icon: Icon }) => (
                                        <TabsTrigger key={value} value={value} className={TRIGGER}>
                                            <Icon className="size-4" />{label}
                                        </TabsTrigger>
                                    ))}
                                    <TabsTrigger
                                        value="danger"
                                        className={`${TRIGGER} text-destructive data-[state=active]:bg-destructive/10 data-[state=active]:text-destructive`}
                                    >
                                        <Shield className="size-4" />Danger zone
                                    </TabsTrigger>
                                </TabsList>
                            </aside>

                            <div className="min-w-0 flex-1">
                                <TabsContent value="general" className="mt-0 space-y-6">
                                    <GeneralCard workspace={workspace} project={project} board={board} />
                                    <DoneRetentionSettings workspace={workspace} project={project} board={board} />
                                </TabsContent>
                                <TabsContent value="columns" className="mt-0 space-y-6">
                                    <ColumnsSettings
                                        workspace={workspace}
                                        project={project}
                                        board={board}
                                        rules={rules}
                                        onShowRule={(columnId) => switchTo('workflow', `rule-${columnId}`)}
                                    />
                                </TabsContent>
                                <TabsContent value="workflow" className="mt-0 space-y-6">
                                    <WorkflowRulesSettings
                                        workspace={workspace}
                                        project={project}
                                        board={board}
                                        rules={rules}
                                        members={ruleMembers ?? []}
                                        roles={ruleRoles ?? []}
                                    />
                                </TabsContent>
                                <TabsContent value="swimlanes" className="mt-0 space-y-6">
                                    <SwimlaneSettings workspace={workspace} project={project} board={board} options={laneOptions} />
                                </TabsContent>
                                <TabsContent value="danger" className="mt-0 space-y-6">
                                    <DangerZone workspace={workspace} project={project} board={board} />
                                </TabsContent>
                            </div>
                        </div>
                    </Tabs>
                </div>
            </div>
        </AppLayout>
    );
}
