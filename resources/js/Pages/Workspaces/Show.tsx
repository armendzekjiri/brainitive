import AppLayout from '@/Layouts/AppLayout';
import { openCreateProject } from '@/components/projects/CreateProjectDialog';
import { Head, Link } from '@inertiajs/react';
import { PageProps, Workspace, Project, Bookmark, SnippetCollection, User } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    Plus,
    LayoutGrid,
    ArrowRight,
    Bookmark as BookmarkIcon,
    FileText,
    Settings,
    Sparkles,
    Code2,
    Users,
    Calendar,
    TrendingUp,
    CheckSquare,
    ListChecks,
} from 'lucide-react';
import { ProjectIcon } from '@/components/ProjectIcon';
import { cn } from '@/lib/utils';
import PageIcon from '@/components/page/PageIcon';

type ProjRef = { slug: string; name: string; color?: string | null };
type DueItem = { id: number; key: string; title: string; due_date: string; board?: { slug: string; name: string; project?: ProjRef } };
type ActivityItem = { id: number; key: string; title: string; updated_at: string; completed_at?: string | null; assignee?: User | null; board?: { slug: string; project?: ProjRef } };
type PinnedBoard = { id: number; name: string; slug: string; active_count: number; done_count: number; project?: ProjRef };
type ListItem = { id: number; name: string; slug: string; items_count?: number; project?: ProjRef };
type DocItem = { id: number; name: string; slug: string; icon?: string | null; project?: ProjRef };

interface Props extends PageProps {
    workspace: Workspace & {
        projects: (Project & { boards_count?: number; pages_count?: number; items_active?: number; items_done?: number })[];
        bookmarks?: Bookmark[];
        snippet_collections?: SnippetCollection[];
        users?: User[];
    };
    userRole: string;
    stats: { open_tasks: number; done_last_7d: number; avg_cycle_days: number | null };
    upcomingDue: DueItem[];
    recentActivity: ActivityItem[];
    pinnedBoards: PinnedBoard[];
    lists: ListItem[];
    documents: DocItem[];
}

function relativeTime(iso: string): string {
    const d = new Date(iso).getTime();
    const m = Math.floor((Date.now() - d) / 60000);
    if (m < 1) return 'just now';
    if (m < 60) return `${m}m ago`;
    const h = Math.floor(m / 60);
    if (h < 24) return `${h}h ago`;
    const days = Math.floor(h / 24);
    if (days === 1) return 'yesterday';
    if (days < 7) return `${days}d ago`;
    return new Date(iso).toLocaleDateString('en', { month: 'short', day: 'numeric' });
}

function dueLabel(iso: string): { label: string; tone: string } {
    const due = new Date(iso); due.setHours(0, 0, 0, 0);
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const days = Math.round((due.getTime() - today.getTime()) / 86400000);
    if (days <= 0) return { label: 'Due today', tone: 'text-red-600 bg-red-500/10' };
    if (days === 1) return { label: 'Tomorrow', tone: 'text-amber-600 bg-amber-500/10' };
    return { label: `in ${days} days`, tone: 'text-muted-foreground bg-muted' };
}

function StatCard({ label, value, sub }: { label: string; value: React.ReactNode; sub?: React.ReactNode }) {
    return (
        <div className="rounded-xl border border-border bg-card p-4">
            <div className="text-xs font-medium text-muted-foreground">{label}</div>
            <div className="mt-1.5 flex items-baseline gap-2">
                <span className="text-2xl font-bold tabular-nums tracking-tight">{value}</span>
            </div>
            {sub && <div className="mt-1 text-[11px]">{sub}</div>}
        </div>
    );
}

function SideCard({ title, action, children }: { title: string; action?: React.ReactNode; children: React.ReactNode }) {
    return (
        <section className="rounded-xl border border-border bg-card">
            <header className="flex items-center justify-between px-4 py-3 border-b border-border/60">
                <h3 className="text-sm font-semibold">{title}</h3>
                {action}
            </header>
            <div className="p-3">{children}</div>
        </section>
    );
}

export default function WorkspacesShow({ workspace, userRole, stats, upcomingDue, recentActivity, pinnedBoards, lists, documents }: Props) {
    const canManage = userRole === 'admin';
    const canCreateProjects = ['admin', 'editor'].includes(userRole);
    const bookmarks = workspace.bookmarks ?? [];
    const projects = workspace.projects ?? [];
    const snippetCollections = workspace.snippet_collections ?? [];
    const members = workspace.users ?? [];

    const totalBoards = projects.reduce((s, p) => s + (p.boards_count ?? 0), 0);
    const totalDocs = projects.reduce((s, p) => s + (p.pages_count ?? 0), 0);
    const accent = workspace.branding?.primary_color || 'hsl(var(--primary))';

    const logoUrl = workspace.logo_display ?? workspace.logo;
    const hasLogo = typeof logoUrl === 'string' && (logoUrl.startsWith('http') || logoUrl.startsWith('/'));

    const health = stats.done_last_7d > 0
        ? { label: 'Healthy', tone: 'text-green-600 bg-green-500/10' }
        : stats.open_tasks > 0
            ? { label: 'Quiet', tone: 'text-amber-600 bg-amber-500/10' }
            : { label: 'Clear', tone: 'text-muted-foreground bg-muted' };

    return (
        <AppLayout>
            <Head title={workspace.name} />

            <div className="min-h-screen bg-muted/30">
                {/* ── Identity banner ── */}
                <div className="relative overflow-hidden border-b border-border">
                    {workspace.header_image_display ? (
                        <div
                            className="absolute inset-0"
                            style={{ backgroundImage: `url(${workspace.header_image_display})`, backgroundSize: 'cover', backgroundPosition: 'center' }}
                        />
                    ) : (
                        <div className="absolute inset-0" style={{ background: `linear-gradient(135deg, ${accent}, color-mix(in srgb, ${accent} 55%, #0b1620))` }} />
                    )}
                    <div className="absolute inset-0 bg-black/10" />

                    <div className="relative px-6 md:px-8 py-7 flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-4 min-w-0">
                            <div className="size-12 shrink-0 overflow-hidden rounded-xl bg-white/15 ring-1 ring-white/25 grid place-items-center backdrop-blur-sm">
                                {hasLogo ? (
                                    <img src={logoUrl as string} alt={workspace.name} className="size-full object-cover" />
                                ) : (
                                    <span className="text-lg font-bold text-white">{workspace.name.charAt(0).toUpperCase()}</span>
                                )}
                            </div>
                            <div className="min-w-0">
                                <div className="text-[11px] font-semibold uppercase tracking-wider text-white/70">Workspace</div>
                                <h1 className="text-2xl md:text-[28px] font-bold text-white leading-tight truncate">{workspace.name}</h1>
                                <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[12px] text-white/85">
                                    {workspace.owner && (
                                        <span className="inline-flex items-center gap-1.5">
                                            <Avatar className="size-4">
                                                {workspace.owner.avatar_display && <AvatarImage src={workspace.owner.avatar_display} alt={workspace.owner.name} />}
                                                <AvatarFallback className="text-[8px] bg-white/25 text-white">{workspace.owner.name.charAt(0)}</AvatarFallback>
                                            </Avatar>
                                            {workspace.owner.name}
                                        </span>
                                    )}
                                    <span className="inline-flex items-center rounded-full bg-white/15 px-2 py-0.5 font-mono text-[10.5px] uppercase tracking-wide">{workspace.slug}</span>
                                    <span className="inline-flex items-center gap-1.5"><Calendar className="size-3" />Created {new Date(workspace.created_at).toLocaleDateString('en', { month: 'short', day: 'numeric', year: 'numeric' })}</span>
                                    <span className="inline-flex items-center gap-1.5"><LayoutGrid className="size-3" />{totalBoards} board{totalBoards !== 1 ? 's' : ''} · {totalDocs} doc{totalDocs !== 1 ? 's' : ''}</span>
                                    <span className="inline-flex items-center gap-1.5"><Users className="size-3" />{members.length} member{members.length !== 1 ? 's' : ''}</span>
                                </div>
                            </div>
                        </div>
                        <div className="flex items-center gap-2 shrink-0">
                            {canManage && (
                                <Link href={route('workspaces.edit', workspace.slug)}>
                                    <Button size="sm" className="h-8 gap-1.5 bg-white/15 text-white hover:bg-white/25 border border-white/20"><Users className="size-3.5" />Invite</Button>
                                </Link>
                            )}
                            {canManage && (
                                <Link href={route('workspaces.edit', workspace.slug)}>
                                    <Button size="icon" className="size-8 bg-white/15 text-white hover:bg-white/25 border border-white/20"><Settings className="size-3.5" /></Button>
                                </Link>
                            )}
                            {canCreateProjects && (
                                <Button size="sm" className="h-8 gap-1.5 bg-white text-primary hover:bg-white/90" onClick={() => openCreateProject()}><Plus className="size-3.5" />New project</Button>
                            )}
                        </div>
                    </div>
                </div>

                {/* ── Body grid ── */}
                <div className="mx-auto max-w-6xl px-6 py-6 md:px-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <main className="min-w-0 space-y-8">
                        {/* Stats */}
                        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                            <StatCard label="Open tasks" value={stats.open_tasks} sub={<span className="text-muted-foreground">across {projects.length} project{projects.length !== 1 ? 's' : ''}</span>} />
                            <StatCard label="Done (7d)" value={stats.done_last_7d} sub={<span className="inline-flex items-center gap-1 text-green-600"><TrendingUp className="size-3" />last 7 days</span>} />
                            <StatCard label="Avg cycle" value={stats.avg_cycle_days != null ? `${stats.avg_cycle_days}d` : '—'} sub={<span className="text-muted-foreground">to close</span>} />
                            <StatCard label="Health" value={<span className="text-lg">{health.label}</span>} sub={<span className={cn('inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 font-medium', health.tone)}><span className="size-1.5 rounded-full bg-current" />{stats.open_tasks} open</span>} />
                        </div>

                        {/* Pinned (recently active boards) */}
                        {pinnedBoards.length > 0 && (
                            <section>
                                <h2 className="mb-3 text-sm font-semibold text-muted-foreground uppercase tracking-wide">Pinned</h2>
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {pinnedBoards.map((b) => {
                                        const tone = b.project?.color || '#0E83DC';
                                        const total = b.active_count + b.done_count;
                                        return (
                                            <Link
                                                key={b.id}
                                                href={route('boards.show', [workspace.slug, b.project?.slug ?? '', b.slug])}
                                                className="group rounded-xl border border-border bg-card p-3 transition-all hover:-translate-y-px hover:shadow-md"
                                            >
                                                <div className="flex items-center gap-2.5">
                                                    <span className="size-8 shrink-0 rounded-lg grid place-items-center" style={{ background: tone }}>
                                                        <LayoutGrid className="size-4 text-white" />
                                                    </span>
                                                    <div className="min-w-0">
                                                        <div className="text-sm font-semibold truncate group-hover:text-primary transition-colors">{b.name}</div>
                                                        <div className="text-[11px] text-muted-foreground">{b.active_count} active · {b.done_count} done</div>
                                                    </div>
                                                </div>
                                                <div className="mt-3 flex h-1.5 overflow-hidden rounded-full bg-muted">
                                                    {total > 0 && <span style={{ flex: b.done_count, background: '#16A34A' }} />}
                                                    {total > 0 && <span style={{ flex: b.active_count, background: tone }} />}
                                                </div>
                                            </Link>
                                        );
                                    })}
                                </div>
                            </section>
                        )}

                        {/* Projects */}
                        <section>
                            <div className="mb-3 flex items-center justify-between">
                                <h2 className="flex items-center gap-2 text-sm font-semibold text-muted-foreground uppercase tracking-wide">All projects</h2>
                                {canCreateProjects && projects.length > 0 && (
                                    <button type="button" onClick={() => openCreateProject()} className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                                        <Plus className="size-3.5" />New project
                                    </button>
                                )}
                            </div>

                            {projects.length === 0 ? (
                                <Card className="border-dashed border-2 bg-card/50">
                                    <CardContent className="flex flex-col items-center justify-center py-16">
                                        <div className="size-20 rounded-2xl bg-primary/10 flex items-center justify-center mb-5">
                                            <Sparkles className="size-10 text-primary" />
                                        </div>
                                        <h3 className="text-lg font-semibold mb-2">No projects yet</h3>
                                        <p className="text-muted-foreground text-center max-w-md mb-6 text-sm">Create your first project to manage boards, documents, and tasks.</p>
                                        {canCreateProjects && (
                                            <Button className="gap-2" onClick={() => openCreateProject()}><Plus className="size-4" />Create project</Button>
                                        )}
                                    </CardContent>
                                </Card>
                            ) : (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {projects.map((project) => {
                                        const total = (project.items_active ?? 0) + (project.items_done ?? 0);
                                        const pct = total > 0 ? Math.round(((project.items_done ?? 0) / total) * 100) : 0;
                                        const tone = project.color || '#0E83DC';
                                        return (
                                            <Link key={project.id} href={route('projects.show', [workspace.slug, project.slug])}>
                                                <Card className="h-full overflow-hidden transition-all hover:shadow-md hover:border-primary/30 group">
                                                    <CardContent className="p-5">
                                                        <div className="flex items-start gap-3 mb-3">
                                                            <ProjectIcon project={project} size="lg" className="shadow-sm shrink-0" />
                                                            <div className="min-w-0 flex-1">
                                                                <h3 className="font-semibold truncate group-hover:text-primary transition-colors">{project.name}</h3>
                                                                <span className="font-mono text-xs text-muted-foreground">{project.key}</span>
                                                            </div>
                                                            <ArrowRight className="size-4 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100 group-hover:translate-x-0.5 transition-all mt-0.5" />
                                                        </div>
                                                        <p className="text-xs text-muted-foreground line-clamp-2 min-h-[2rem] mb-4">{project.description || 'No description'}</p>
                                                        <div className="mb-3 flex items-center gap-2">
                                                            <div className="flex-1 h-1.5 overflow-hidden rounded-full bg-muted">
                                                                <div className="h-full rounded-full" style={{ width: `${pct}%`, background: tone }} />
                                                            </div>
                                                            <span className="text-[11px] font-medium text-muted-foreground tabular-nums">{pct}%</span>
                                                        </div>
                                                        <div className="flex items-center gap-4 text-xs text-muted-foreground">
                                                            <span className="inline-flex items-center gap-1.5"><LayoutGrid className="size-3.5" /><span className="font-medium tabular-nums">{project.boards_count ?? 0}</span> boards</span>
                                                            <span className="inline-flex items-center gap-1.5"><FileText className="size-3.5" /><span className="font-medium tabular-nums">{project.pages_count ?? 0}</span> docs</span>
                                                            <span className="inline-flex items-center gap-1.5"><CheckSquare className="size-3.5" /><span className="font-medium tabular-nums">{project.items_active ?? 0}</span> open</span>
                                                        </div>
                                                    </CardContent>
                                                </Card>
                                            </Link>
                                        );
                                    })}
                                </div>
                            )}
                        </section>

                        {/* Bookmarks */}
                        <section>
                            <div className="mb-3 flex items-center justify-between">
                                <h2 className="flex items-center gap-2 text-sm font-semibold text-muted-foreground uppercase tracking-wide"><BookmarkIcon className="size-4" />Bookmarks</h2>
                                <Link href={route('bookmarks.index', workspace.slug)} className="text-xs font-medium text-primary hover:underline">View all</Link>
                            </div>
                            {bookmarks.length === 0 ? (
                                <Card className="border-dashed bg-card/50">
                                    <CardContent className="flex flex-col items-center justify-center py-8">
                                        <div className="size-12 rounded-xl bg-primary/10 flex items-center justify-center mb-3"><BookmarkIcon className="size-6 text-primary" /></div>
                                        <p className="text-sm text-muted-foreground text-center mb-3">No bookmarks yet.</p>
                                        <Link href={route('bookmarks.index', workspace.slug)}><Button variant="outline" size="sm" className="gap-2"><Plus className="size-4" />Add bookmarks</Button></Link>
                                    </CardContent>
                                </Card>
                            ) : (
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {bookmarks.map((bookmark) => (
                                        <Link key={bookmark.id} href={route('bookmarks.show', [workspace.slug, bookmark.slug])}>
                                            <Card className="h-full transition-all hover:shadow-md hover:border-primary/30 group">
                                                <CardContent className="p-4">
                                                    <div className="flex items-start gap-3">
                                                        <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 overflow-hidden">
                                                            {bookmark.icon_display && (bookmark.icon_display.startsWith('http') || bookmark.icon_display.startsWith('/')) ? (
                                                                <img src={bookmark.icon_display} alt="" className="size-full object-cover" />
                                                            ) : (
                                                                <span className="text-xl leading-none">{bookmark.icon_display || '🔖'}</span>
                                                            )}
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <h3 className="font-semibold truncate group-hover:text-primary transition-colors">{bookmark.name}</h3>
                                                            {bookmark.description && <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{bookmark.description}</p>}
                                                        </div>
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
                                <h2 className="flex items-center gap-2 text-sm font-semibold text-muted-foreground uppercase tracking-wide"><ListChecks className="size-4" />Lists</h2>
                            </div>
                            {lists.length === 0 ? (
                                <Card className="border-dashed bg-card/50">
                                    <CardContent className="flex flex-col items-center justify-center py-8">
                                        <div className="size-12 rounded-xl bg-primary/10 flex items-center justify-center mb-3"><ListChecks className="size-6 text-primary" /></div>
                                        <p className="text-sm text-muted-foreground text-center">No lists yet.</p>
                                    </CardContent>
                                </Card>
                            ) : (
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {lists.map((list) => (
                                        <Link key={list.id} href={list.project ? route('task-lists.show', [workspace.slug, list.project.slug, list.slug]) : '#'}>
                                            <Card className="h-full transition-all hover:shadow-md hover:border-primary/30 group">
                                                <CardContent className="p-4">
                                                    <div className="flex items-start gap-3">
                                                        <div className="flex size-10 shrink-0 items-center justify-center rounded-xl" style={{ background: `${list.project?.color || '#0E83DC'}1A` }}>
                                                            <ListChecks className="size-5" style={{ color: list.project?.color || '#0E83DC' }} />
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <h3 className="font-semibold truncate group-hover:text-primary transition-colors">{list.name}</h3>
                                                            <p className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                                                                {list.project && <span className="inline-flex items-center gap-1"><span className="size-1.5 rounded-full" style={{ background: list.project.color || '#94a3b8' }} />{list.project.name}</span>}
                                                                <span>·</span>
                                                                <span>{list.items_count ?? 0} item{(list.items_count ?? 0) !== 1 ? 's' : ''}</span>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </CardContent>
                                            </Card>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </section>

                        {/* Snippets */}
                        <section>
                            <div className="mb-3 flex items-center justify-between">
                                <h2 className="flex items-center gap-2 text-sm font-semibold text-muted-foreground uppercase tracking-wide"><Code2 className="size-4" />Snippets</h2>
                                <Link href={route('snippets.index', workspace.slug)} className="text-xs font-medium text-primary hover:underline">View all</Link>
                            </div>
                            {snippetCollections.length === 0 ? (
                                <Card className="border-dashed bg-card/50">
                                    <CardContent className="flex flex-col items-center justify-center py-8">
                                        <div className="size-12 rounded-xl bg-primary/10 flex items-center justify-center mb-3"><Code2 className="size-6 text-primary" /></div>
                                        <p className="text-sm text-muted-foreground text-center mb-3">No snippet collections yet.</p>
                                        <Link href={route('snippets.index', workspace.slug)}><Button variant="outline" size="sm" className="gap-2"><Plus className="size-4" />Add snippets</Button></Link>
                                    </CardContent>
                                </Card>
                            ) : (
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {snippetCollections.map((collection) => (
                                        <Link key={collection.id} href={route('snippets.show', [workspace.slug, collection.id])}>
                                            <Card className="h-full transition-all hover:shadow-md hover:border-primary/30 group">
                                                <CardContent className="p-4">
                                                    <div className="flex items-start gap-3">
                                                        <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 overflow-hidden">
                                                            {collection.icon && (collection.icon.startsWith('http') || collection.icon.startsWith('/')) ? (
                                                                <img src={collection.icon} alt="" className="size-6 rounded object-contain" />
                                                            ) : (
                                                                <span className="text-xl leading-none">{collection.icon || '📋'}</span>
                                                            )}
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <h3 className="font-semibold truncate group-hover:text-primary transition-colors">{collection.name}</h3>
                                                            <p className="mt-0.5 text-xs text-muted-foreground">{collection.snippets_count ?? 0} snippet{(collection.snippets_count ?? 0) !== 1 ? 's' : ''}</p>
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
                            <div className="mb-3 flex items-center justify-between">
                                <h2 className="flex items-center gap-2 text-sm font-semibold text-muted-foreground uppercase tracking-wide"><FileText className="size-4" />Documents</h2>
                            </div>
                            {documents.length === 0 ? (
                                <Card className="border-dashed bg-card/50">
                                    <CardContent className="flex flex-col items-center justify-center py-8">
                                        <div className="size-12 rounded-xl bg-primary/10 flex items-center justify-center mb-3"><FileText className="size-6 text-primary" /></div>
                                        <p className="text-sm text-muted-foreground text-center">No documents yet.</p>
                                    </CardContent>
                                </Card>
                            ) : (
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {documents.map((doc) => (
                                        <Link key={doc.id} href={doc.project ? route('pages.show', [workspace.slug, doc.project.slug, doc.id]) : '#'}>
                                            <Card className="h-full transition-all hover:shadow-md hover:border-primary/30 group">
                                                <CardContent className="p-4">
                                                    <div className="flex items-start gap-3">
                                                        <div className="flex size-10 shrink-0 items-center justify-center rounded-xl" style={{ background: `${doc.project?.color || '#0E83DC'}1A` }}>
                                                            {doc.icon ? (
                                                                <PageIcon icon={doc.icon} className="text-xl" imageClassName="size-6" />
                                                            ) : (
                                                                <FileText className="size-5" style={{ color: doc.project?.color || '#0E83DC' }} />
                                                            )}
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <h3 className="font-semibold truncate group-hover:text-primary transition-colors">{doc.name}</h3>
                                                            {doc.project && (
                                                                <p className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                                                                    <span className="size-1.5 rounded-full" style={{ background: doc.project.color || '#94a3b8' }} />
                                                                    {doc.project.name}
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                </CardContent>
                                            </Card>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </section>
                    </main>

                    {/* ── Side rail ── */}
                    <aside className="space-y-5">
                        <SideCard title="Recent activity">
                            {recentActivity.length === 0 ? (
                                <p className="px-1 py-2 text-xs text-muted-foreground">No recent activity.</p>
                            ) : (
                                <div className="space-y-1">
                                    {recentActivity.map((it) => {
                                        const proj = it.board?.project;
                                        const href = it.board && proj ? route('boards.items.show', [workspace.slug, proj.slug, it.board.slug, it.id]) : '#';
                                        return (
                                            <Link key={it.id} href={href} className="flex items-start gap-2.5 rounded-lg px-2 py-1.5 hover:bg-muted/60 transition-colors">
                                                <Avatar className="size-6 shrink-0 mt-0.5">
                                                    {it.assignee?.avatar_display && <AvatarImage src={it.assignee.avatar_display} alt={it.assignee.name} />}
                                                    <AvatarFallback className="text-[9px] bg-brand-slate text-white">{(it.assignee?.name ?? '?').charAt(0).toUpperCase()}</AvatarFallback>
                                                </Avatar>
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-xs leading-snug">
                                                        <span className="font-mono text-[10px] text-muted-foreground mr-1">{it.key}</span>
                                                        <span className="font-medium">{it.title}</span>
                                                    </div>
                                                    <div className="mt-0.5 flex items-center gap-1.5 text-[10.5px] text-muted-foreground">
                                                        {proj && <span className="inline-flex items-center gap-1"><span className="size-1.5 rounded-full" style={{ background: proj.color || '#94a3b8' }} />{proj.name}</span>}
                                                        <span>·</span>
                                                        <span>{relativeTime(it.updated_at)}</span>
                                                    </div>
                                                </div>
                                            </Link>
                                        );
                                    })}
                                </div>
                            )}
                        </SideCard>

                        <SideCard title="Upcoming due dates">
                            {upcomingDue.length === 0 ? (
                                <p className="px-1 py-2 text-xs text-muted-foreground">Nothing due soon.</p>
                            ) : (
                                <div className="space-y-1">
                                    {upcomingDue.map((it) => {
                                        const proj = it.board?.project;
                                        const href = it.board && proj ? route('boards.items.show', [workspace.slug, proj.slug, it.board.slug, it.id]) : '#';
                                        const dl = dueLabel(it.due_date);
                                        const d = new Date(it.due_date);
                                        return (
                                            <Link key={it.id} href={href} className="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-muted/60 transition-colors">
                                                <div className="flex size-9 shrink-0 flex-col items-center justify-center rounded-lg border border-border bg-muted/40">
                                                    <span className="text-sm font-bold leading-none tabular-nums">{d.getDate()}</span>
                                                    <span className="text-[9px] uppercase text-muted-foreground">{d.toLocaleDateString('en', { month: 'short' })}</span>
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-xs font-medium truncate">{it.title}</div>
                                                    <span className={cn('mt-0.5 inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-medium', dl.tone)}>
                                                        <span className="size-1.5 rounded-full bg-current" />{dl.label}
                                                    </span>
                                                </div>
                                            </Link>
                                        );
                                    })}
                                </div>
                            )}
                        </SideCard>

                        <SideCard
                            title="Team"
                            action={canManage && (
                                <Link href={route('workspaces.edit', workspace.slug)} className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"><Plus className="size-3" />Invite</Link>
                            )}
                        >
                            {members.length === 0 ? (
                                <p className="px-1 py-2 text-xs text-muted-foreground">No members yet.</p>
                            ) : (
                                <div className="space-y-0.5">
                                    {members.slice(0, 8).map((m) => (
                                        <div key={m.id} className="flex items-center gap-2.5 rounded-lg px-2 py-1.5">
                                            <Avatar className="size-7 shrink-0">
                                                {m.avatar_display && <AvatarImage src={m.avatar_display} alt={m.name} />}
                                                <AvatarFallback className="text-[10px] bg-brand-slate text-white">{m.name.charAt(0).toUpperCase()}</AvatarFallback>
                                            </Avatar>
                                            <div className="min-w-0 flex-1">
                                                <div className="text-xs font-medium truncate">{m.name}</div>
                                                <div className="text-[10.5px] text-muted-foreground">{m.id === workspace.owner_id ? 'Owner' : 'Member'}</div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </SideCard>
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}
