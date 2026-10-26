import AppLayout, { useGlobalCreateModal } from '@/Layouts/AppLayout';
import HelpdeskTemplatePicker from '@/components/helpdesk/HelpdeskTemplatePicker';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { PageProps, Workspace, Project, Board, TaskList, Page as PageType, Helpdesk, PlanSummary, RecentContentEntry, SidebarPinType } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import {
    Plus, Settings, Kanban, FileText, Folder, PencilRuler, Headphones, Key, List, GanttChart, Magnet, ListChecks,
    Pin, History, Briefcase, BookOpen, Users2, Wrench, HeartPulse, Gauge, Rocket, FolderPlus, Workflow,
} from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import LeadFormTemplatePicker from '@/components/leadForms/LeadFormTemplatePicker';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { ProjectIcon } from '@/components/ProjectIcon';
import { useState, type ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { safeHtmlProp } from '@/lib/sanitize';
import ProjectAiAssistant from '@/components/projects/ProjectAiAssistant';
import ExtractActionItemsDialog from '@/components/meetingNotes/ExtractActionItemsDialog';
import { PinButton } from '@/components/sidebar/PinButton';
import { PinIcon, resolvePins, type PinKind, type SidebarProject } from '@/components/sidebar/pins';
import { projectPreferences, useSidebarPreferences } from '@/lib/sidebarPreferences';
import PageIcon from '@/components/page/PageIcon';

type PageWithChildren = PageType & { children?: PageWithChildren[] };

type ShowProject = Project & {
    boards: Board[];
    task_lists?: TaskList[];
    plans?: PlanSummary[];
    pages?: PageWithChildren[];
    helpdesks?: Helpdesk[];
    creator?: { id: number; name: string; avatar?: string | null; avatar_display?: string | null };
    users_count?: number;
};

interface ProjectMember {
    id: number;
    name: string;
    avatar_display?: string | null;
}

interface Props extends PageProps {
    workspace: Workspace;
    project: ShowProject;
    members?: ProjectMember[];
    recentlyUpdated?: RecentContentEntry[];
    canUpdate?: boolean;
    canCreatePages?: boolean;
    aiConfigured?: boolean;
    canCreateIssues?: boolean;
}

const KIND_LABELS: Record<PinKind, string> = {
    board: 'Board', list: 'List', plan: 'Plan', helpdesk: 'Helpdesk', lead_form: 'Lead form', page: 'Page', whiteboard: 'Whiteboard', folder: 'Folder',
};

function kindOf(entry: RecentContentEntry): PinKind {
    return entry.type === 'page' ? (entry.is_whiteboard ? 'whiteboard' : 'page') : entry.type;
}

/** A top-level section of the project page (Pinned, Recently updated, Work, Docs, …). */
function Section({ title, icon, children, aside }: { title: string; icon: ReactNode; children: ReactNode; aside?: ReactNode }) {
    return (
        <section className="space-y-4">
            <div className="flex items-center gap-2 border-b border-border/60 pb-2">
                <span className="text-muted-foreground">{icon}</span>
                <h2 className="text-sm font-semibold uppercase tracking-wide text-foreground/80">{title}</h2>
                {aside && <div className="ml-auto">{aside}</div>}
            </div>
            {children}
        </section>
    );
}

/**
 * One kind of content inside a section. The header row holds the h3 and its "Add" button (e2e:
 * `heading('Boards').locator('..').getByRole('button', { name: 'Add' })`). Without items, the
 * header row is all there is: one compact row instead of a big empty card.
 */
function Subsection({
    title,
    icon,
    count,
    empty,
    onAdd,
    addLabel,
    extraAction,
    children,
}: {
    title: string;
    icon: ReactNode;
    count: number;
    empty: string;
    onAdd?: () => void;
    addLabel: string;
    extraAction?: ReactNode;
    children?: ReactNode;
}) {
    const addButton = onAdd && (
        <Button size="sm" variant="ghost" className="h-7 gap-1 px-2 text-xs text-muted-foreground" onClick={onAdd}>
            <Plus className="size-3.5" />Add<span className="sr-only"> {addLabel}</span>
        </Button>
    );

    if (count === 0) {
        return (
            <div className="flex min-w-0 items-center gap-2 rounded-xl border border-dashed border-border bg-card/40 px-3 py-2">
                <span className="text-muted-foreground">{icon}</span>
                <h3 className="text-sm font-medium">{title}</h3>
                <span className="hidden min-w-0 truncate text-xs text-muted-foreground sm:inline">· {empty}</span>
                <span className="ml-auto flex shrink-0 items-center gap-1">{extraAction}{addButton}</span>
            </div>
        );
    }

    return (
        <div className="space-y-2">
            <div className="flex min-w-0 items-center gap-2">
                <span className="text-muted-foreground">{icon}</span>
                <h3 className="text-sm font-semibold">{title}</h3>
                <span className="rounded-full bg-muted px-1.5 text-[11px] font-medium tabular-nums text-muted-foreground">{count}</span>
                <span className="ml-auto flex shrink-0 items-center gap-1">{extraAction}{addButton}</span>
            </div>
            {children}
        </div>
    );
}

/** A content card with a pin toggle; the card title stays an h3 (helpdesk.spec matches it). */
function ContentCard({
    href,
    projectId,
    pinType,
    id,
    name,
    icon,
    children,
}: {
    href: string;
    projectId: number;
    pinType: SidebarPinType;
    id: number;
    name: string;
    icon: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="group/pin relative h-full min-w-0">
            <Link href={href} className="block h-full rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                <Card className="h-full transition-all hover:border-primary/30 hover:shadow-md group">
                    <CardContent className="p-4 pr-11">
                        <div className="flex items-start gap-3">
                            {icon}
                            <div className="min-w-0 flex-1">
                                <h3 className="font-semibold truncate group-hover:text-primary transition-colors">{name}</h3>
                                {children}
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </Link>
            <PinButton projectId={projectId} type={pinType} id={id} name={name} size="md" className="absolute right-2 top-2" />
        </div>
    );
}

function CardIcon({ children, tint }: { children: ReactNode; tint?: string }) {
    return (
        <div className={cn('flex size-10 shrink-0 items-center justify-center rounded-xl', !tint && 'bg-primary/10 text-primary')} style={tint ? { background: `${tint}1A`, color: tint } : undefined}>
            {children}
        </div>
    );
}

const grid = 'grid gap-3 sm:grid-cols-2 lg:grid-cols-3';

function ProjectOverview({ workspace, project, members = [], recentlyUpdated = [], canUpdate = false, canCreatePages = false, aiConfigured = false, canCreateIssues = false }: Props) {
    const [meetingNotesOpen, setMeetingNotesOpen] = useState(false);
    const [createBoardDialogOpen, setCreateBoardDialogOpen] = useState(false);
    const [createHelpdeskDialogOpen, setCreateHelpdeskDialogOpen] = useState(false);
    const [createLeadFormOpen, setCreateLeadFormOpen] = useState(false);
    const [createListDialogOpen, setCreateListDialogOpen] = useState(false);
    const [createPlanDialogOpen, setCreatePlanDialogOpen] = useState(false);
    const { planTemplates = [], currentWorkspace } = usePage<PageProps>().props;
    const { openCreateTaskModal } = useGlobalCreateModal();

    const boardForm = useForm({ name: '', description: '' });
    const helpdeskForm = useForm({ name: '', description: '', template: '' });
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

    // The sidebar payload carries the full (visible) page tree; this page's own props only two levels
    const sidebarProject = currentWorkspace?.projects?.find((p) => p.id === project.id) as SidebarProject | undefined;
    const pages: PageWithChildren[] = (sidebarProject?.pages as PageWithChildren[] | undefined) ?? project.pages ?? [];
    const treePages = pages.filter((p) => !(p.type === 'page' && p.is_whiteboard));
    const countTree = (ps: PageWithChildren[]): number => ps.reduce((n, p) => n + (p.type === 'page' && p.is_whiteboard ? 0 : 1 + countTree(p.children ?? [])), 0);
    const whiteboardPages = pages.filter((p) => p.type === 'page' && p.is_whiteboard);
    const accent = project.color || '#0E83DC';
    const params = [workspace.slug, project.slug];

    const prefs = projectPreferences(useSidebarPreferences(), project.id);
    const pinned = resolvePins(workspace.slug, sidebarProject ?? (project as SidebarProject), prefs.pins);

    const description = project.description?.trim() ?? '';
    const memberCount = project.users_count ?? members.length;

    function renderPageItem(page: PageWithChildren, depth = 0): ReactNode {
        if (page.type === 'page' && page.is_whiteboard) return null;
        const isFolder = page.type === 'folder';
        const pageIcon = page.icon && !page.icon.startsWith('data:') && !page.icon.startsWith('http') && !page.icon.startsWith('/') ? page.icon : null;
        return (
            <div key={page.id} className="min-w-0">
                <div className="group/pin flex min-w-0 items-center gap-1 rounded-lg hover:bg-muted/70" style={{ paddingLeft: depth * 20 }}>
                    <Link href={route('pages.show', [...params, page.uuid])} className="flex min-w-0 flex-1 items-center gap-3 rounded-lg px-3 py-2 group">
                        {isFolder ? <Folder className="size-4 shrink-0 text-primary" /> : pageIcon ? <PageIcon icon={pageIcon} className="w-5 text-center text-base" imageClassName="size-5" /> : <FileText className="size-4 shrink-0 text-muted-foreground" />}
                        <span className="truncate text-sm font-medium transition-colors group-hover:text-primary">{page.name}</span>
                    </Link>
                    <PinButton projectId={project.id} type="page" id={page.id} name={page.name} className="mr-2" />
                </div>
                {(page.children ?? []).map((child) => renderPageItem(child, depth + 1))}
            </div>
        );
    }

    const quickLinks = [
        { label: 'Health', aria: 'Project health', href: route('projects.health', params), icon: HeartPulse },
        { label: 'Workload', aria: 'Project workload', href: route('workload.index', params), icon: Gauge },
        { label: 'Releases', aria: 'Project releases', href: route('releases.index', params), icon: Rocket },
        { label: 'Client portal', aria: 'Project client portal', href: route('client-portal.manage.index', params), icon: Briefcase },
        ...(canUpdate ? [{ label: 'Feedback', aria: 'Feedback portal', href: route('projects.feedback.index', params), icon: Users2 }] : []), // BRAI-60: project admins
    ];

    return (
        <div className="min-h-screen bg-muted/30">
            {/* ── Header: name, key, description, members, quick links ── */}
            <div className="relative overflow-hidden border-b border-border">
                <div className="absolute inset-0" style={{ background: `linear-gradient(135deg, ${accent}, color-mix(in srgb, ${accent} 55%, #0b1620))` }} />
                <div className="absolute inset-0 bg-black/10" />

                <div className="relative px-4 py-6 sm:px-6 md:px-8 md:py-7">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex min-w-0 items-start gap-4">
                            <ProjectIcon project={project} size="xl" className="shrink-0 shadow-lg ring-2 ring-white/25" />
                            <div className="min-w-0">
                                <div className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-white/70">
                                    Project
                                    {project.key && <span className="inline-flex items-center gap-1 rounded bg-white/15 px-1.5 py-0.5 font-mono normal-case tracking-normal text-white"><Key className="size-3" />{project.key}</span>}
                                </div>
                                <h1 className="truncate text-2xl font-bold leading-tight text-white md:text-[28px]">{project.name}</h1>
                                {description !== '' && (
                                    <div
                                        className="prose prose-sm prose-invert mt-1.5 max-w-2xl text-white/85 line-clamp-2 [&_*]:my-0"
                                        dangerouslySetInnerHTML={safeHtmlProp(description.startsWith('<') ? description : `<p>${description.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</p>`)}
                                    />
                                )}
                            </div>
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
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
                            {canUpdate && (
                                <Link href={route('projects.edit', params)} aria-label="Project settings">
                                    <Button size="icon" className="size-8 border border-white/20 bg-white/15 text-white hover:bg-white/25"><Settings className="size-3.5" /></Button>
                                </Link>
                            )}
                        </div>
                    </div>

                    <div className="mt-4 flex flex-wrap items-center gap-x-5 gap-y-3">
                        {members.length > 0 && (
                            <div className="flex items-center gap-2">
                                <div className="flex -space-x-2">
                                    {members.slice(0, 5).map((member) => (
                                        <Avatar key={member.id} className="size-7 ring-2 ring-white/40" title={member.name}>
                                            {member.avatar_display && <AvatarImage src={member.avatar_display} alt={member.name} />}
                                            <AvatarFallback className="bg-white/25 text-[10px] font-semibold text-white">{member.name.charAt(0).toUpperCase()}</AvatarFallback>
                                        </Avatar>
                                    ))}
                                </div>
                                <span className="text-xs text-white/85">{memberCount} member{memberCount !== 1 ? 's' : ''}</span>
                            </div>
                        )}
                        <nav aria-label="Project tools" className="flex flex-wrap items-center gap-1.5">
                            {quickLinks.map((link) => (
                                <Link
                                    key={link.label}
                                    href={link.href}
                                    aria-label={link.aria}
                                    className="inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-2.5 py-1 text-xs font-medium text-white transition-colors hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
                                >
                                    <link.icon className="size-3.5" />{link.label}
                                </Link>
                            ))}
                        </nav>
                    </div>
                </div>
            </div>

            {/* ── Content ── */}
            <div className="mx-auto max-w-5xl space-y-10 px-4 py-6 sm:px-6 md:px-8">
                {/* Pinned: the user's pins in this project (also first in the sidebar) */}
                <Section title="Pinned" icon={<Pin className="size-4" />}>
                    {pinned.length === 0 ? (
                        <p className="rounded-xl border border-dashed border-border bg-card/40 px-4 py-3 text-sm text-muted-foreground">
                            Pin the boards, lists and pages you use most: they show up here and at the top of your sidebar. Hover a card and click its pin <Pin className="inline size-3.5 align-[-2px]" aria-hidden="true" />.
                        </p>
                    ) : (
                        <ul className={grid} aria-label="Pinned items">
                            {pinned.map((entry) => (
                                <li key={`${entry.pin.type}-${entry.pin.id}`} className="group/pin relative min-w-0">
                                    <Link href={entry.href} className="flex min-w-0 items-center gap-3 rounded-xl border bg-card px-3 py-2.5 pr-11 transition-all hover:border-primary/30 hover:shadow-sm">
                                        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><PinIcon entry={entry} /></span>
                                        <span className="min-w-0">
                                            <span className="block truncate text-sm font-medium">{entry.name}</span>
                                            <span className="block text-xs text-muted-foreground">{KIND_LABELS[entry.kind]}</span>
                                        </span>
                                    </Link>
                                    <PinButton projectId={project.id} type={entry.pin.type} id={entry.pin.id} name={entry.name} size="md" className="absolute right-1.5 top-1/2 -translate-y-1/2" />
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                {/* Recently updated: 5 items across types */}
                {recentlyUpdated.length > 0 && (
                    <Section title="Recently updated" icon={<History className="size-4" />}>
                        <Card>
                            <ul className="divide-y divide-border/60" aria-label="Recently updated">
                                {recentlyUpdated.map((entry) => {
                                    const kind = kindOf(entry);
                                    return (
                                        <li key={`${entry.type}-${entry.id}`}>
                                            <Link href={entry.url} className="flex min-w-0 items-center gap-3 px-4 py-2.5 transition-colors hover:bg-muted/60 first:rounded-t-xl last:rounded-b-xl">
                                                <PinIcon entry={{ kind, pageIcon: entry.icon }} className="text-muted-foreground" />
                                                <span className="min-w-0 flex-1 truncate text-sm font-medium">{entry.name}</span>
                                                <span className="hidden shrink-0 text-xs text-muted-foreground sm:inline">{KIND_LABELS[kind]}</span>
                                                <time dateTime={entry.updated_at} className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                    {formatDistanceToNow(new Date(entry.updated_at), { addSuffix: true })}
                                                </time>
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        </Card>
                    </Section>
                )}

                {/* Work: boards, lists, plans */}
                <Section title="Work" icon={<Briefcase className="size-4" />}>
                    <div className="space-y-5">
                        <Subsection title="Boards" icon={<Kanban className="size-4" />} count={project.boards.length} empty="Track issues on a Kanban or Scrum board" addLabel="board" onAdd={() => setCreateBoardDialogOpen(true)}>
                            <div className={grid}>
                                {project.boards.map((board) => (
                                    <ContentCard key={board.id} href={route('boards.show', [...params, board.slug])} projectId={project.id} pinType="board" id={board.id} name={board.name} icon={<CardIcon tint={accent}><Kanban className="size-5" /></CardIcon>}>
                                        <p className="mt-0.5 truncate text-xs text-muted-foreground">{board.description || 'No description'}</p>
                                    </ContentCard>
                                ))}
                            </div>
                        </Subsection>

                        <Subsection title="Lists" icon={<List className="size-4" />} count={project.task_lists?.length ?? 0} empty="Simple task lists with assignees and due dates" addLabel="list" onAdd={() => setCreateListDialogOpen(true)}>
                            <div className={grid}>
                                {(project.task_lists ?? []).map((tl) => (
                                    <ContentCard key={tl.id} href={route('task-lists.show', [...params, tl.slug])} projectId={project.id} pinType="list" id={tl.id} name={tl.name} icon={<CardIcon><List className="size-5" /></CardIcon>}>
                                        {tl.description && <p className="mt-0.5 truncate text-xs text-muted-foreground">{tl.description}</p>}
                                    </ContentCard>
                                ))}
                            </div>
                        </Subsection>

                        <Subsection title="Plans" icon={<GanttChart className="size-4" />} count={project.plans?.length ?? 0} empty="Phases, milestones and who does what on a timeline" addLabel="plan" onAdd={() => setCreatePlanDialogOpen(true)}>
                            <div className={grid}>
                                {(project.plans ?? []).map((plan) => (
                                    <ContentCard key={plan.id} href={route('plans.show', [...params, plan.slug])} projectId={project.id} pinType="plan" id={plan.id} name={plan.name} icon={<CardIcon tint={accent}><GanttChart className="size-5" /></CardIcon>}>
                                        <p className="mt-0.5 truncate text-xs text-muted-foreground">{plan.description || (plan.updated_at ? `Updated ${new Date(plan.updated_at).toLocaleDateString('en', { month: 'short', day: 'numeric' })}` : 'Plan')}</p>
                                    </ContentCard>
                                ))}
                            </div>
                        </Subsection>
                    </div>
                </Section>

                {/* Docs: pages and folders tree, whiteboards */}
                <Section title="Docs" icon={<BookOpen className="size-4" />}>
                    <div className="space-y-5">
                        <Subsection
                            title="Pages"
                            icon={<FileText className="size-4" />}
                            count={countTree(treePages)}
                            empty="Write docs, specs and notes"
                            addLabel="page"
                            onAdd={canCreatePages ? () => openCreateTaskModal(project.id, undefined, undefined, 'page') : undefined}
                            extraAction={canCreatePages ? (
                                <Button size="sm" variant="ghost" className="h-7 gap-1 px-2 text-xs text-muted-foreground" onClick={() => openCreateTaskModal(project.id, undefined, undefined, 'folder')}>
                                    <FolderPlus className="size-3.5" />Folder<span className="sr-only"> (new)</span>
                                </Button>
                            ) : undefined}
                        >
                            <Card><CardContent className="p-2">{treePages.map((p) => renderPageItem(p))}</CardContent></Card>
                        </Subsection>

                        <Subsection
                            title="Whiteboards"
                            icon={<PencilRuler className="size-4" />}
                            count={whiteboardPages.length}
                            empty="Sketch ideas on an Excalidraw canvas"
                            addLabel="whiteboard"
                            onAdd={canCreatePages ? () => openCreateTaskModal(project.id, undefined, undefined, 'whiteboard') : undefined}
                        >
                            <div className={grid}>
                                {whiteboardPages.map((page) => (
                                    <ContentCard key={page.id} href={route('pages.show', [...params, page.uuid])} projectId={project.id} pinType="page" id={page.id} name={page.name} icon={<CardIcon><PencilRuler className="size-5" /></CardIcon>}>
                                        <Badge variant="secondary" className="mt-1 text-xs">Whiteboard</Badge>
                                    </ContentCard>
                                ))}
                            </div>
                        </Subsection>
                    </div>
                </Section>

                {/* Customer-facing: helpdesks, lead forms */}
                <Section title="Customer-facing" icon={<Users2 className="size-4" />}>
                    <div className="space-y-5">
                        <Subsection title="Helpdesks" icon={<Headphones className="size-4" />} count={project.helpdesks?.length ?? 0} empty="Support tickets from a widget, a form or email" addLabel="helpdesk" onAdd={() => setCreateHelpdeskDialogOpen(true)}>
                            <div className={grid}>
                                {(project.helpdesks ?? []).map((hd) => (
                                    <ContentCard key={hd.id} href={route('helpdesks.show', [...params, hd.slug])} projectId={project.id} pinType="helpdesk" id={hd.id} name={hd.name} icon={<CardIcon><Headphones className="size-5" /></CardIcon>}>
                                        <p className="mt-0.5 truncate text-xs text-muted-foreground">{hd.description || 'No description'}</p>
                                    </ContentCard>
                                ))}
                            </div>
                        </Subsection>

                        {/* Lead forms (BRAI-93) */}
                        <Subsection title="Lead forms" icon={<Magnet className="size-4" />} count={project.lead_forms?.length ?? 0} empty="Multi-step forms that land leads on their own board" addLabel="lead form" onAdd={canUpdate ? () => setCreateLeadFormOpen(true) : undefined}>
                            <div className={grid}>
                                {(project.lead_forms ?? []).map((lf) => (
                                    <ContentCard key={lf.id} href={route('lead-forms.show', [...params, lf.slug])} projectId={project.id} pinType="lead_form" id={lf.id} name={lf.name} icon={<CardIcon><Magnet className="size-5" /></CardIcon>}>
                                        <Badge variant="secondary" className={cn('mt-1 text-xs', lf.status === 'published' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300')}>{lf.status === 'published' ? 'Live' : 'Draft'}</Badge>
                                    </ContentCard>
                                ))}
                            </div>
                        </Subsection>
                    </div>
                </Section>

                {/* Tools: AI assistant, automations, meeting notes → issues */}
                <Section title="Tools" icon={<Wrench className="size-4" />}>
                    <div className="space-y-3">
                        <ProjectAiAssistant workspace={workspace} project={project} aiConfigured={aiConfigured} canUpdate={canUpdate} canCreatePages={canCreatePages} />
                        {canUpdate && (
                            <Card>
                                <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
                                    <div className="flex min-w-0 flex-1 items-start gap-3">
                                        <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10"><Workflow className="size-4 text-primary" /></div>
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium">Automations</p>
                                            <p className="text-xs text-muted-foreground">When an issue or ticket arrives, moves or is assigned: assign it, move it, set fields, comment or notify — automatically.</p>
                                        </div>
                                    </div>
                                    <Button variant="outline" size="sm" className="shrink-0 gap-1.5" asChild>
                                        <Link href={route('automations.index', params)}><Workflow className="size-3.5" /> Open automations</Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        )}
                        {canCreateIssues && (
                            <Card>
                                <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
                                    <div className="flex min-w-0 flex-1 items-start gap-3">
                                        <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10"><ListChecks className="size-4 text-primary" /></div>
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium">Meeting notes → issues</p>
                                            <p className="text-xs text-muted-foreground">Paste notes from a meeting. AI suggests the action items, you review them, then create the ones you want.</p>
                                        </div>
                                    </div>
                                    <Button variant="outline" size="sm" className="shrink-0 gap-1.5" onClick={() => setMeetingNotesOpen(true)}>
                                        <ListChecks className="size-3.5" /> Extract action items
                                    </Button>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                    <ExtractActionItemsDialog open={meetingNotesOpen} onOpenChange={setMeetingNotesOpen} workspace={workspace} project={project} />
                </Section>
            </div>

            {/* Create dialogs */}
            <Dialog open={createLeadFormOpen} onOpenChange={setCreateLeadFormOpen}>
                <DialogContent className="sm:max-w-4xl">
                    <DialogHeader><DialogTitle>Create Lead Form</DialogTitle><DialogDescription>Start from a template — multi-step questions, icon cards and its own leads board. You can change everything later.</DialogDescription></DialogHeader>
                    {createLeadFormOpen && <LeadFormTemplatePicker workspaceSlug={workspace.slug} projectSlug={project.slug} onCreated={() => setCreateLeadFormOpen(false)} />}
                </DialogContent>
            </Dialog>
            <Dialog open={createHelpdeskDialogOpen} onOpenChange={setCreateHelpdeskDialogOpen}>
                <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader><DialogTitle>Create Helpdesk</DialogTitle><DialogDescription>Start from a template — support desk, contact form, registration, applications and more.</DialogDescription></DialogHeader>
                    <form onSubmit={createHelpdesk} className="space-y-4 mt-4">
                        <HelpdeskTemplatePicker
                            workspaceSlug={workspace.slug}
                            value={helpdeskForm.data.template}
                            onChange={(key, template) => helpdeskForm.setData((d) => ({ ...d, template: key, name: d.name || (template?.name ?? '') }))}
                        />
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
    );
}

/**
 * Project page (BRAI-106 order): header, Pinned, Recently updated, then Work (Boards, Lists, Plans),
 * Docs (Pages, Whiteboards), Customer-facing (Helpdesks, Lead forms) and Tools.
 */
export default function ProjectsShow(props: Props) {
    return (
        <AppLayout>
            <Head title={props.project.name} />
            <ProjectOverview {...props} />
        </AppLayout>
    );
}
