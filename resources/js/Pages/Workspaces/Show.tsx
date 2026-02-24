import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { PageProps, Workspace, Project, Bookmark } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
    Plus,
    FolderKanban,
    LayoutGrid,
    ArrowRight,
    Bookmark as BookmarkIcon,
    FileText,
    Settings,
    Sparkles,
} from 'lucide-react';
import { ProjectIcon } from '@/components/ProjectIcon';

interface Props extends PageProps {
    workspace: Workspace & {
        projects: (Project & { boards_count?: number; pages_count?: number })[];
        bookmarks?: Bookmark[];
    };
    userRole: string;
}

function WorkspaceLogo({ workspace, className = '' }: { workspace: Workspace; className?: string }) {
    const logoUrl = workspace.logo_display ?? workspace.logo;
    const hasImage = typeof logoUrl === 'string' && (logoUrl.startsWith('http') || logoUrl.startsWith('/'));
    const initial = workspace.name.charAt(0).toUpperCase();
    return (
        <div
            className={`flex items-center justify-center overflow-hidden rounded-2xl border-4 border-background bg-muted shadow-lg ${className}`}
            style={
                workspace.branding?.primary_color && !hasImage
                    ? { backgroundColor: workspace.branding.primary_color }
                    : undefined
            }
        >
            {hasImage ? (
                <img src={logoUrl} alt="" className="size-full object-cover" />
            ) : (
                <span className="text-3xl font-bold text-white drop-shadow-sm">{initial}</span>
            )}
        </div>
    );
}

export default function WorkspacesShow({ auth, workspace, userRole }: Props) {
    const canManage = userRole === 'admin';
    const canCreateProjects = ['admin', 'editor'].includes(userRole);
    const bookmarks = workspace.bookmarks ?? [];
    const projects = workspace.projects ?? [];

    return (
        <AppLayout>
            <Head title={workspace.name} />

            <div className="min-h-screen bg-muted/30">
                {/* Cover + profile header */}
                <div className="relative">
                    {/* Cover image or gradient */}
                    <div
                        className="h-48 w-full bg-gradient-to-br from-primary/20 via-primary/10 to-muted md:h-56"
                        style={
                            workspace.header_image_display
                                ? {
                                      backgroundImage: `url(${workspace.header_image_display})`,
                                      backgroundSize: 'cover',
                                      backgroundPosition: 'center',
                                  }
                                : undefined
                        }
                    >
                        {workspace.header_image_display && (
                            <div className="absolute inset-0 bg-gradient-to-t from-background/80 via-background/20 to-transparent" />
                        )}
                    </div>

                    {/* Profile row overlapping cover */}
                    <div className="relative z-10 -mt-16 px-6 pb-8 md:-mt-20 md:px-10">
                        <div className="mx-auto max-w-5xl">
                            <div className="flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
                                <div className="flex flex-col items-start gap-4 sm:flex-row sm:items-end">
                                    <WorkspaceLogo
                                        workspace={workspace}
                                        className="size-24 shrink-0 md:size-28"
                                    />
                                    <div className="space-y-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h1 className="text-2xl font-bold tracking-tight md:text-3xl">
                                                {workspace.name}
                                            </h1>
                                            <Badge
                                                variant="secondary"
                                                className="capitalize font-medium"
                                            >
                                                {userRole}
                                            </Badge>
                                        </div>
                                        <p className="max-w-xl text-sm text-muted-foreground md:text-base">
                                            {workspace.description || 'No description yet.'}
                                        </p>
                                        {canManage && (
                                            <Link
                                                href={route('workspaces.edit', workspace.slug)}
                                                className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                                            >
                                                <Settings className="size-4" />
                                                Edit workspace
                                            </Link>
                                        )}
                                    </div>
                                </div>
                                {canCreateProjects && (
                                    <Link
                                        href={route('projects.create', workspace.slug)}
                                        className="shrink-0"
                                    >
                                        <Button size="lg" className="gap-2 shadow-md">
                                            <Plus className="size-5" />
                                            New Project
                                        </Button>
                                    </Link>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                <div className="mx-auto max-w-5xl px-6 pb-12 md:px-10">
                    {/* Bookmarks section */}
                    <section className="mb-12">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-lg font-semibold">
                                <BookmarkIcon className="size-5 text-primary" />
                                Bookmarks
                            </h2>
                            <Link
                                href={route('bookmarks.index', workspace.slug)}
                                className="text-sm font-medium text-primary hover:underline"
                            >
                                View all
                            </Link>
                        </div>
                        {bookmarks.length === 0 ? (
                            <Card className="border-dashed bg-card/50">
                                <CardContent className="flex flex-col items-center justify-center py-10">
                                    <div className="size-14 rounded-2xl bg-primary/10 dark:bg-primary/20 flex items-center justify-center mb-4">
                                        <BookmarkIcon className="size-7 text-primary" />
                                    </div>
                                    <p className="text-sm text-muted-foreground text-center mb-4">
                                        No bookmarks yet. Save links and notes for this workspace.
                                    </p>
                                    <Link href={route('bookmarks.index', workspace.slug)}>
                                        <Button variant="outline" size="sm" className="gap-2">
                                            <Plus className="size-4" />
                                            Add bookmarks
                                        </Button>
                                    </Link>
                                </CardContent>
                            </Card>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {bookmarks.map((bookmark) => (
                                    <Link
                                        key={bookmark.id}
                                        href={route('bookmarks.show', [workspace.slug, bookmark.slug])}
                                    >
                                        <Card className="h-full transition-all hover:shadow-md hover:border-primary/30 group">
                                            <CardContent className="p-4">
                                                <div className="flex items-start gap-3">
                                                    <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 dark:bg-primary/20 overflow-hidden">
                                                        {bookmark.icon_display && (bookmark.icon_display.startsWith('http') || bookmark.icon_display.startsWith('/')) ? (
                                                            <img src={bookmark.icon_display} alt="" className="size-full object-cover" />
                                                        ) : (
                                                            <span className="text-2xl leading-none">{bookmark.icon_display || '🔖'}</span>
                                                        )}
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <h3 className="font-semibold truncate group-hover:text-primary transition-colors">
                                                            {bookmark.name}
                                                        </h3>
                                                        {bookmark.description && (
                                                            <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                                                                {bookmark.description}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <ArrowRight className="size-4 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity mt-1" />
                                                </div>
                                            </CardContent>
                                        </Card>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </section>

                    {/* Projects section */}
                    <section>
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-lg font-semibold">
                                <LayoutGrid className="size-5 text-primary" />
                                Projects
                            </h2>
                            {canCreateProjects && projects.length > 0 && (
                                <Link
                                    href={route('projects.create', workspace.slug)}
                                    className="text-sm font-medium text-primary hover:underline"
                                >
                                    + New project
                                </Link>
                            )}
                        </div>

                        {projects.length === 0 ? (
                            <Card className="border-dashed border-2 bg-card/50">
                                <CardContent className="flex flex-col items-center justify-center py-20">
                                    <div className="size-24 rounded-2xl bg-primary/10 flex items-center justify-center mb-6">
                                        <Sparkles className="size-12 text-primary" />
                                    </div>
                                    <h3 className="text-xl font-semibold mb-2">No projects yet</h3>
                                    <p className="text-muted-foreground text-center max-w-md mb-8 text-base">
                                        Create your first project to manage boards, documents, and tasks.
                                    </p>
                                    {canCreateProjects && (
                                        <Link href={route('projects.create', workspace.slug)}>
                                            <Button size="lg" className="gap-2">
                                                <Plus className="size-5" />
                                                Create Project
                                            </Button>
                                        </Link>
                                    )}
                                </CardContent>
                            </Card>
                        ) : (
                            <div className="grid gap-6 sm:grid-cols-2">
                                {projects.map((project) => (
                                    <Link
                                        key={project.id}
                                        href={route('projects.show', [workspace.slug, project.slug])}
                                    >
                                        <Card className="h-full overflow-hidden transition-all hover:shadow-lg hover:border-primary/30 group">
                                            <CardContent className="p-0">
                                                <div className="p-6 pb-4">
                                                    <div className="flex items-start gap-4 mb-4">
                                                        <ProjectIcon
                                                            project={project}
                                                            size="xl"
                                                            className="shadow-md shrink-0"
                                                        />
                                                        <div className="min-w-0 flex-1">
                                                            <h3 className="text-xl font-semibold truncate group-hover:text-primary transition-colors">
                                                                {project.name}
                                                            </h3>
                                                            <span className="text-sm font-medium text-muted-foreground">
                                                                {project.key}
                                                            </span>
                                                        </div>
                                                        <ArrowRight className="size-5 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100 group-hover:translate-x-0.5 transition-all mt-1" />
                                                    </div>
                                                    <p className="text-sm text-muted-foreground line-clamp-3 min-h-[3.75rem] mb-5">
                                                        {project.description || 'No description'}
                                                    </p>
                                                    <div className="flex flex-wrap items-center gap-5 text-sm text-muted-foreground">
                                                        <span className="flex items-center gap-2">
                                                            <LayoutGrid className="size-4 shrink-0" />
                                                            <span className="font-medium tabular-nums">
                                                                {project.boards_count ?? 0}
                                                            </span>
                                                            <span>boards</span>
                                                        </span>
                                                        <span className="flex items-center gap-2">
                                                            <FileText className="size-4 shrink-0" />
                                                            <span className="font-medium tabular-nums">
                                                                {project.pages_count ?? 0}
                                                            </span>
                                                            <span>documents</span>
                                                        </span>
                                                    </div>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
