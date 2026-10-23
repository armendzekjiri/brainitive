import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { PageProps, Workspace, Project, Board, TaskList, Page as PageType, Helpdesk, PlanSummary } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import {
    LayoutGrid, Plus, Settings, ArrowRight, Kanban, FileText, Folder,
    ChevronRight, PencilRuler, Headphones, Calendar, Key, Users, List, GanttChart, Magnet,
} from 'lucide-react';
import LeadFormTemplatePicker from '@/components/leadForms/LeadFormTemplatePicker';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { ProjectIcon } from '@/components/ProjectIcon';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import { safeHtmlProp } from '@/lib/sanitize';
import ProjectAiAssistant from '@/components/projects/ProjectAiAssistant';

type PageWithChildren = PageType & { children?: PageWithChildren[] };

interface Props extends PageProps {
    workspace: Workspace;
    project: Project & {
        boards: Board[];
        task_lists?: TaskList[];
        plans?: PlanSummary[];
        pages?: PageWithChildren[];
        helpdesks?: Helpdesk[];
        creator?: { id: number; name: string; avatar?: string | null; avatar_display?: string | null };
        users_count?: number;
    };
    canUpdate?: boolean;
    canCreatePages?: boolean;
    aiConfigured?: boolean;
}

export default function ProjectsShow({ workspace, project, canUpdate = false, canCreatePages = false, aiConfigured = false }: Props) {
    const [createBoardDialogOpen, setCreateBoardDialogOpen] = useState(false);
    const [createHelpdeskDialogOpen, setCreateHelpdeskDialogOpen] = useState(false);
    const [createLeadFormOpen, setCreateLeadFormOpen] = useState(false);
    const [createListDialogOpen, setCreateListDialogOpen] = useState(false);
    const [createPlanDialogOpen, setCreatePlanDialogOpen] = useState(false);
    const { planTemplates = [] } = usePage<PageProps>().props;

    const boardForm = useForm({ name: '', description: '' });
    const helpdeskForm = useForm({ name: '', description: '' });
    const listForm = useForm({ name: '', description: '' });
    const planForm = useForm({ name: '', template: 'blank' });

    const createBoard = (e: React.FormEvent) => {
        e.preventDefault();
        boardForm.post(route('boards.store', [workspace.slug, project.slug]), { onSuccess: () => { setCreateBoardDialogOpen(false); boardForm.reset(); } });
    };
    const createHelpdesk = (e: React.FormEvent) => {
        e.preventDefault();
        helpdeskForm.post(route('helpdesks.store', [workspace.slug, project.slug]), { onSuccess: () => { setCreateHelpdeskDialogOpen(false); helpdeskForm.reset(); } });
    };
    const createPlan = (e: React.FormEvent) => {
        e.preventDefault();
        planForm.post(route('plans.store', [workspace.slug, project.slug]), { onSuccess: () => { setCreatePlanDialogOpen(false); planForm.reset(); } });
    };
    const createList = (e: React.FormEvent) => {
        e.preventDefault();
        listForm.post(route('task-lists.store', [workspace.slug, project.slug]), { onSuccess: () => { setCreateListDialogOpen(false); listForm.reset(); } });
    };

    const pages = project.pages ?? [];
    const documentPages = (function flatten(ps: PageWithChildren[]): PageWithChildren[] {
        const out: PageWithChildren[] = [];
        for (const p of ps) {
            if (p.type === 'page' && !p.is_whiteboard) out.push(p);
            if (p.type === 'folder') out.push(p);
            if (p.children?.length) out.push(...flatten(p.children));
        }
        return out;
    })(pages);
    const whiteboardPages = pages.filter((p) => p.type === 'page' && p.is_whiteboard);
    const accent = project.color || '#0E83DC';

    function renderPageItem(page: PageWithChildren, depth = 0) {
        if (page.type === 'page' && page.is_whiteboard) return null;
        const href = route('pages.show', [workspace.slug, project.slug, page.uuid]);
        const isFolder = page.type === 'folder';
        const children = page.children ?? [];
        return (
            <div key={page.id} className="min-w-0" style={{ marginLeft: depth > 0 ? depth * 20 : 0 }}>
                <Link href={href} className="flex items-center gap-3 py-2 px-3 -mx-3 rounded-lg hover:bg-muted/80 transition-colors group min-w-0">
                    {isFolder ? <Folder className="size-4 shrink-0 text-primary" /> : page.icon ? <span className="text-base shrink-0 w-5 text-center">{page.icon}</span> : <FileText className="size-4 shrink-0 text-muted-foreground" />}
                    <span className="truncate text-sm font-medium group-hover:text-primary transition-colors">{page.name}</span>
                    <ChevronRight className="size-3.5 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity ml-auto" />
                </Link>
                {children.map((child) => renderPageItem(child, depth + 1))}
            </div>
        );
    }

    return (
        <AppLayout>
            <Head title={project.name} />

            <div className="min-h-screen bg-muted/30">
                {/* ── Banner ── */}
                <div className="relative overflow-hidden border-b border-border">
                    <div className="absolute inset-0" style={{ background: `linear-gradient(135deg, ${accent}, color-mix(in srgb, ${accent} 55%, #0b1620))` }} />
                    <div className="absolute inset-0 bg-black/10" />

                    <div className="relative px-6 md:px-8 py-7 flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-4 min-w-0">
                            <ProjectIcon project={project} size="xl" className="shadow-lg shrink-0 ring-2 ring-white/25" />
                            <div className="min-w-0">
                                <div className="text-[11px] font-semibold uppercase tracking-wider text-white/70">Project</div>
                                <h1 className="text-2xl md:text-[28px] font-bold text-white leading-tight truncate">{project.name}</h1>
                                <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[12px] text-white/85">
                                    {project.creator && (
                                        <span className="inline-flex items-center gap-1.5">
                                            <Avatar className="size-4">
                                                {project.creator.avatar_display && <AvatarImage src={project.creator.avatar_display} alt={project.creator.name} />}
                                                <AvatarFallback className="text-[8px] bg-white/25 text-white">{project.creator.name.charAt(0)}</AvatarFallback>
                                            </Avatar>
                                            {project.creator.name}
                                        </span>
                                    )}
                                    {project.key && <span className="inline-flex items-center gap-1.5"><Key className="size-3" /><span className="font-mono">{project.key}</span></span>}
                                    <span className="inline-flex items-center gap-1.5"><Calendar className="size-3" />{new Date(project.created_at).toLocaleDateString('en', { month: 'short', day: 'numeric', year: 'numeric' })}</span>
                                    <span className="inline-flex items-center gap-1.5"><LayoutGrid className="size-3" />{project.boards?.length ?? 0} board{(project.boards?.length ?? 0) !== 1 ? 's' : ''}</span>
                                    {project.users_count != null && <span className="inline-flex items-center gap-1.5"><Users className="size-3" />{project.users_count} member{project.users_count !== 1 ? 's' : ''}</span>}
                                </div>
                            </div>
                        </div>
                        <div className="flex items-center gap-2 shrink-0">
                            <Dialog open={createBoardDialogOpen} onOpenChange={setCreateBoardDialogOpen}>
                                <DialogTrigger asChild>
                                    <Button size="sm" className="h-8 gap-1.5 bg-white text-primary hover:bg-white/90"><Plus className="size-3.5" />New board</Button>
                                </DialogTrigger>
                                <DialogContent className="sm:max-w-md">
                                    <DialogHeader><DialogTitle>Create Board</DialogTitle><DialogDescription>Create a new board to track your work.</DialogDescription></DialogHeader>
                                    <form onSubmit={createBoard} className="space-y-4 mt-4">
                                        <div className="space-y-2"><Label>Board Name</Label><Input value={boardForm.data.name} onChange={(e) => boardForm.setData('name', e.target.value)} placeholder="e.g., Sprint Board" required />{boardForm.errors.name && <p className="text-sm text-destructive">{boardForm.errors.name}</p>}</div>
                                        <div className="space-y-2"><Label>Description (optional)</Label><Textarea value={boardForm.data.description} onChange={(e) => boardForm.setData('description', e.target.value)} placeholder="What is this board for?" /></div>
                                        <div className="flex justify-end gap-2"><Button type="button" variant="outline" onClick={() => setCreateBoardDialogOpen(false)}>Cancel</Button><Button type="submit" disabled={boardForm.processing}>{boardForm.processing ? 'Creating…' : 'Create'}</Button></div>
                                    </form>
                                </DialogContent>
                            </Dialog>
                            <Link href={route('projects.edit', [workspace.slug, project.slug])}>
                                <Button size="icon" className="size-8 bg-white/15 text-white hover:bg-white/25 border border-white/20"><Settings className="size-3.5" /></Button>
                            </Link>
                        </div>
                    </div>
                </div>

                {/* ── Content ── */}
                <div className="mx-auto max-w-5xl px-6 py-6 md:px-8 space-y-8">
                    {/* Description */}
                    {project.description && project.description.trim() !== '' && (
                        <Card>
                            <CardContent className="p-5">
                                <div className="prose prose-sm dark:prose-invert max-w-none" dangerouslySetInnerHTML={safeHtmlProp(project.description.trim().startsWith('<') ? project.description : `<p>${project.description.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</p>`)} />
                            </CardContent>
                        </Card>
                    )}

                    <ProjectAiAssistant workspace={workspace} project={project} aiConfigured={aiConfigured} canUpdate={canUpdate} canCreatePages={canCreatePages} />

                    {/* Boards */}
                    <section>
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-sm font-semibold text-muted-foreground uppercase tracking-wide"><Kanban className="size-4" />Boards</h2>
                            <Button size="sm" variant="ghost" className="h-7 gap-1 text-xs text-muted-foreground" onClick={() => setCreateBoardDialogOpen(true)}><Plus className="size-3.5" />Add</Button>
                        </div>
                        {project.boards.length === 0 ? (
                            <Card className="border-dashed bg-card/50"><CardContent className="flex flex-col items-center justify-center py-10"><div className="size-14 rounded-xl bg-primary/10 flex items-center justify-center mb-4"><Kanban className="size-7 text-primary" /></div><p className="text-sm font-medium mb-1">No boards yet</p><p className="text-xs text-muted-foreground mb-4">Create a board to organize tasks with Kanban.</p><Button size="sm" className="gap-2" onClick={() => setCreateBoardDialogOpen(true)}><Plus className="size-4" />Create board</Button></CardContent></Card>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {project.boards.map((board) => (
                                    <Link key={board.id} href={route('boards.show', [workspace.slug, project.slug, board.slug])}>
                                        <Card className="h-full transition-all hover:shadow-md hover:border-primary/30 group">
                                            <CardContent className="p-4">
                                                <div className="flex items-start gap-3">
                                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl" style={{ background: `${accent}1A` }}><Kanban className="size-5" style={{ color: accent }} /></div>
                                                    <div className="min-w-0 flex-1">
                                                        <h3 className="font-semibold truncate group-hover:text-primary transition-colors">{board.name}</h3>
                                                        <p className="mt-0.5 text-xs text-muted-foreground truncate">{board.description || 'No description'}</p>
                                                    </div>
                                                    <ArrowRight className="size-4 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100 transition-all mt-0.5" />
                                                </div>
                                            </CardContent>
                                        </Card>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </section>

                    {/* Lists */}
                    <section>
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-sm font-semibold text-muted-foreground uppercase tracking-wide"><List className="size-4" />Lists</h2>
                            <Button size="sm" variant="ghost" className="h-7 gap-1 text-xs text-muted-foreground" onClick={() => setCreateListDialogOpen(true)}><Plus className="size-3.5" />Add</Button>
                        </div>
                        {(!project.task_lists || project.task_lists.length === 0) ? (
                            <Card className="border-dashed bg-card/50"><CardContent className="flex flex-col items-center justify-center py-10"><div className="size-14 rounded-xl bg-primary/10 flex items-center justify-center mb-4"><List className="size-7 text-primary" /></div><p className="text-sm font-medium mb-1">No lists yet</p><p className="text-xs text-muted-foreground mb-4">Create a list to track tasks.</p><Button size="sm" className="gap-2" onClick={() => setCreateListDialogOpen(true)}><Plus className="size-4" />Create list</Button></CardContent></Card>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {project.task_lists.map((tl) => (
                                    <Link key={tl.id} href={route('task-lists.show', [workspace.slug, project.slug, tl.slug])}>
                                        <Card className="h-full transition-all hover:shadow-md hover:border-primary/30 group">
                                            <CardContent className="p-4">
                                                <div className="flex items-start gap-3">
                                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10"><List className="size-5 text-primary" /></div>
                                                    <div className="min-w-0 flex-1">
                                                        <h3 className="font-semibold truncate group-hover:text-primary transition-colors">{tl.name}</h3>
                                                        {tl.description && <p className="mt-0.5 text-xs text-muted-foreground truncate">{tl.description}</p>}
                                                    </div>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </section>

                    {/* Plans */}
                    <section>
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-sm font-semibold text-muted-foreground uppercase tracking-wide"><GanttChart className="size-4" />Plans</h2>
                            <Button size="sm" variant="ghost" className="h-7 gap-1 text-xs text-muted-foreground" onClick={() => setCreatePlanDialogOpen(true)}><Plus className="size-3.5" />Add</Button>
                        </div>
                        {(!project.plans || project.plans.length === 0) ? (
                            <Card className="border-dashed bg-card/50"><CardContent className="flex flex-col items-center justify-center py-10"><div className="size-14 rounded-xl bg-primary/10 flex items-center justify-center mb-4"><GanttChart className="size-7 text-primary" /></div><p className="text-sm font-medium mb-1">No plans yet</p><p className="text-xs text-muted-foreground mb-4">Plan phases, milestones and who does what on a timeline.</p><Button size="sm" className="gap-2" onClick={() => setCreatePlanDialogOpen(true)}><Plus className="size-4" />Create plan</Button></CardContent></Card>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {project.plans.map((plan) => (
                                    <Link key={plan.id} href={route('plans.show', [workspace.slug, project.slug, plan.slug])}>
                                        <Card className="h-full transition-all hover:shadow-md hover:border-primary/30 group">
                                            <CardContent className="p-4">
                                                <div className="flex items-start gap-3">
                                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl" style={{ background: `${accent}1A` }}><GanttChart className="size-5" style={{ color: accent }} /></div>
                                                    <div className="min-w-0 flex-1">
                                                        <h3 className="font-semibold truncate group-hover:text-primary transition-colors">{plan.name}</h3>
                                                        <p className="mt-0.5 text-xs text-muted-foreground truncate">{plan.description || (plan.updated_at ? `Updated ${new Date(plan.updated_at).toLocaleDateString('en', { month: 'short', day: 'numeric' })}` : 'Plan')}</p>
                                                    </div>
                                                    <ArrowRight className="size-4 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100 transition-all mt-0.5" />
                                                </div>
                                            </CardContent>
                                        </Card>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </section>

                    {/* Helpdesks */}
                    <section>
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-sm font-semibold text-muted-foreground uppercase tracking-wide"><Headphones className="size-4" />Helpdesks</h2>
                            <Button size="sm" variant="ghost" className="h-7 gap-1 text-xs text-muted-foreground" onClick={() => setCreateHelpdeskDialogOpen(true)}><Plus className="size-3.5" />Add</Button>
                        </div>
                        {(!project.helpdesks || project.helpdesks.length === 0) ? (
                            <Card className="border-dashed bg-card/50"><CardContent className="flex flex-col items-center justify-center py-10"><div className="size-14 rounded-xl bg-primary/10 flex items-center justify-center mb-4"><Headphones className="size-7 text-primary" /></div><p className="text-sm font-medium mb-1">No helpdesks yet</p><p className="text-xs text-muted-foreground mb-4">Create a helpdesk for support tickets.</p><Button size="sm" className="gap-2" onClick={() => setCreateHelpdeskDialogOpen(true)}><Plus className="size-4" />Create helpdesk</Button></CardContent></Card>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {project.helpdesks.map((hd) => (
                                    <Link key={hd.id} href={route('helpdesks.show', [workspace.slug, project.slug, hd.slug])}>
                                        <Card className="h-full transition-all hover:shadow-md hover:border-primary/30 group">
                                            <CardContent className="p-4">
                                                <div className="flex items-start gap-3">
                                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10"><Headphones className="size-5 text-primary" /></div>
                                                    <div className="min-w-0 flex-1">
                                                        <h3 className="font-semibold truncate group-hover:text-primary transition-colors">{hd.name}</h3>
                                                        <p className="mt-0.5 text-xs text-muted-foreground truncate">{hd.description || 'No description'}</p>
                                                    </div>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </section>

                    {/* Lead forms (BRAI-93) */}
                    <section>
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-sm font-semibold text-muted-foreground uppercase tracking-wide"><Magnet className="size-4" />Lead forms</h2>
                            {canUpdate && <Button size="sm" variant="ghost" className="h-7 gap-1 text-xs text-muted-foreground" onClick={() => setCreateLeadFormOpen(true)}><Plus className="size-3.5" />Add</Button>}
                        </div>
                        {(project.lead_forms?.length ?? 0) === 0 ? (
                            <Card className="border-dashed bg-card/50"><CardContent className="flex flex-col items-center justify-center py-10"><div className="size-14 rounded-xl bg-primary/10 flex items-center justify-center mb-4"><Magnet className="size-7 text-primary" /></div><p className="text-sm font-medium mb-1">No lead forms yet</p><p className="text-xs text-muted-foreground mb-4">Collect leads with a multi-step form — every submission lands on its own board.</p>{canUpdate && <Button size="sm" className="gap-2" onClick={() => setCreateLeadFormOpen(true)}><Plus className="size-4" />Create lead form</Button>}</CardContent></Card>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {project.lead_forms!.map((lf) => (
                                    <Link key={lf.id} href={route('lead-forms.show', [workspace.slug, project.slug, lf.slug])}>
                                        <Card className="h-full transition-all hover:shadow-md hover:border-primary/30 group">
                                            <CardContent className="p-4">
                                                <div className="flex items-start gap-3">
                                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10"><Magnet className="size-5 text-primary" /></div>
                                                    <div className="min-w-0 flex-1">
                                                        <h3 className="font-semibold truncate group-hover:text-primary transition-colors">{lf.name}</h3>
                                                        <Badge variant="secondary" className={cn('mt-1 text-xs', lf.status === 'published' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300')}>{lf.status === 'published' ? 'Live' : 'Draft'}</Badge>
                                                    </div>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </section>

                    {/* Documents */}
                    <section>
                        <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold text-muted-foreground uppercase tracking-wide"><FileText className="size-4" />Documents</h2>
                        {documentPages.length === 0 ? (
                            <Card className="border-dashed bg-card/50"><CardContent className="flex flex-col items-center justify-center py-10"><div className="size-14 rounded-xl bg-primary/10 flex items-center justify-center mb-4"><FileText className="size-7 text-primary" /></div><p className="text-sm text-muted-foreground">No documents yet. Create pages from the sidebar.</p></CardContent></Card>
                        ) : (
                            <Card><CardContent className="p-4">{documentPages.map((p) => renderPageItem(p))}</CardContent></Card>
                        )}
                    </section>

                    {/* Whiteboards */}
                    {whiteboardPages.length > 0 && (
                        <section>
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold text-muted-foreground uppercase tracking-wide"><PencilRuler className="size-4" />Whiteboards</h2>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {whiteboardPages.map((page) => (
                                    <Link key={page.id} href={route('pages.show', [workspace.slug, project.slug, page.uuid])}>
                                        <Card className="h-full transition-all hover:shadow-md hover:border-primary/30 group">
                                            <CardContent className="p-4">
                                                <div className="flex items-start gap-3">
                                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10"><PencilRuler className="size-5 text-primary" /></div>
                                                    <div className="min-w-0 flex-1"><h3 className="font-semibold truncate group-hover:text-primary transition-colors">{page.name}</h3><Badge variant="secondary" className="text-xs mt-1">Whiteboard</Badge></div>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    </Link>
                                ))}
                            </div>
                        </section>
                    )}
                </div>

                {/* Create dialogs */}
                <Dialog open={createLeadFormOpen} onOpenChange={setCreateLeadFormOpen}>
                    <DialogContent className="sm:max-w-4xl">
                        <DialogHeader><DialogTitle>Create Lead Form</DialogTitle><DialogDescription>Start from a template — multi-step questions, icon cards and its own leads board. You can change everything later.</DialogDescription></DialogHeader>
                        {createLeadFormOpen && <LeadFormTemplatePicker workspaceSlug={workspace.slug} projectSlug={project.slug} onCreated={() => setCreateLeadFormOpen(false)} />}
                    </DialogContent>
                </Dialog>
                <Dialog open={createHelpdeskDialogOpen} onOpenChange={setCreateHelpdeskDialogOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader><DialogTitle>Create Helpdesk</DialogTitle><DialogDescription>Create a helpdesk to manage support tickets.</DialogDescription></DialogHeader>
                        <form onSubmit={createHelpdesk} className="space-y-4 mt-4">
                            <div className="space-y-2"><Label>Helpdesk Name</Label><Input value={helpdeskForm.data.name} onChange={(e) => helpdeskForm.setData('name', e.target.value)} placeholder="e.g., Customer Support" required />{helpdeskForm.errors.name && <p className="text-sm text-destructive">{helpdeskForm.errors.name}</p>}</div>
                            <div className="space-y-2"><Label>Description (optional)</Label><Textarea value={helpdeskForm.data.description} onChange={(e) => helpdeskForm.setData('description', e.target.value)} placeholder="Brief description" /></div>
                            <div className="flex justify-end gap-2"><Button type="button" variant="outline" onClick={() => setCreateHelpdeskDialogOpen(false)}>Cancel</Button><Button type="submit" disabled={helpdeskForm.processing}>{helpdeskForm.processing ? 'Creating…' : 'Create'}</Button></div>
                        </form>
                    </DialogContent>
                </Dialog>
                <Dialog open={createPlanDialogOpen} onOpenChange={setCreatePlanDialogOpen}>
                    <DialogContent className="sm:max-w-lg">
                        <DialogHeader><DialogTitle>Create Plan</DialogTitle><DialogDescription>A Gantt plan for the big picture: phases, bars, milestones and who does what.</DialogDescription></DialogHeader>
                        <form onSubmit={createPlan} className="space-y-4 mt-4">
                            <div className="space-y-2"><Label>Plan Name</Label><Input value={planForm.data.name} onChange={(e) => planForm.setData('name', e.target.value)} placeholder="e.g., Roadmap 2027" required autoFocus />{planForm.errors.name && <p className="text-sm text-destructive">{planForm.errors.name}</p>}</div>
                            <div className="space-y-2">
                                <Label>Start from</Label>
                                <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Template">
                                    {planTemplates.map((template) => (
                                        <button key={template.key} type="button" role="radio" aria-checked={planForm.data.template === template.key} onClick={() => planForm.setData('template', template.key)} className={cn('rounded-lg border px-3 py-2 text-left transition-colors hover:border-primary/60', planForm.data.template === template.key && 'border-primary bg-primary/5 ring-1 ring-primary')}>
                                            <span className="block text-sm font-medium">{template.name}</span>
                                            <span className="block text-xs text-muted-foreground">{template.description}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <div className="flex justify-end gap-2"><Button type="button" variant="outline" onClick={() => setCreatePlanDialogOpen(false)}>Cancel</Button><Button type="submit" disabled={planForm.processing}>{planForm.processing ? 'Creating…' : 'Create'}</Button></div>
                        </form>
                    </DialogContent>
                </Dialog>
                <Dialog open={createListDialogOpen} onOpenChange={setCreateListDialogOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader><DialogTitle>Create List</DialogTitle><DialogDescription>Create a task list with assignees and due dates.</DialogDescription></DialogHeader>
                        <form onSubmit={createList} className="space-y-4 mt-4">
                            <div className="space-y-2"><Label>List Name</Label><Input value={listForm.data.name} onChange={(e) => listForm.setData('name', e.target.value)} placeholder="e.g., Sprint Backlog" required autoFocus />{listForm.errors.name && <p className="text-sm text-destructive">{listForm.errors.name}</p>}</div>
                            <div className="space-y-2"><Label>Description (optional)</Label><Textarea value={listForm.data.description} onChange={(e) => listForm.setData('description', e.target.value)} placeholder="What is this list for?" /></div>
                            <div className="flex justify-end gap-2"><Button type="button" variant="outline" onClick={() => setCreateListDialogOpen(false)}>Cancel</Button><Button type="submit" disabled={listForm.processing}>{listForm.processing ? 'Creating…' : 'Create'}</Button></div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
