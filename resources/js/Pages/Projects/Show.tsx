import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { PageProps, Workspace, Project, Board, Page as PageType, Helpdesk } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { LayoutGrid, Plus, Settings, ArrowRight, Kanban, FileText, Folder, ChevronRight, PencilRuler, Headphones, User, Calendar, Key, Users } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { ProjectIcon } from '@/components/ProjectIcon';
import { useState } from 'react';

type PageWithChildren = PageType & { children?: PageWithChildren[] };

interface Props extends PageProps {
    workspace: Workspace;
    project: Project & {
        boards: Board[];
        pages?: PageWithChildren[];
        helpdesks?: Helpdesk[];
        creator?: { id: number; name: string; avatar?: string | null; avatar_display?: string | null };
        users_count?: number;
    };
}

export default function ProjectsShow({ auth, workspace, project }: Props) {
    const [createBoardDialogOpen, setCreateBoardDialogOpen] = useState(false);
    const [createHelpdeskDialogOpen, setCreateHelpdeskDialogOpen] = useState(false);

    const boardForm = useForm({
        name: '',
        description: '',
    });
    const helpdeskForm = useForm({
        name: '',
        description: '',
    });

    const createBoard = (e: React.FormEvent) => {
        e.preventDefault();
        boardForm.post(route('boards.store', [workspace.slug, project.slug]), {
            onSuccess: () => {
                setCreateBoardDialogOpen(false);
                boardForm.reset();
            },
        });
    };

    const createHelpdesk = (e: React.FormEvent) => {
        e.preventDefault();
        helpdeskForm.post(route('helpdesks.store', [workspace.slug, project.slug]), {
            onSuccess: () => {
                setCreateHelpdeskDialogOpen(false);
                helpdeskForm.reset();
            },
        });
    };

    const pages = project.pages ?? [];
    const hasPages = pages.length > 0;

    // Split: documents (folders + non-whiteboard pages) and whiteboards (flat list)
    const documentPages = pages.filter((p) => p.type === 'folder' || !(p.type === 'page' && p.is_whiteboard));
    const whiteboardPages = (function collectWhiteboards(nodes: PageWithChildren[]): PageWithChildren[] {
        const out: PageWithChildren[] = [];
        for (const p of nodes) {
            if (p.type === 'page' && p.is_whiteboard) out.push(p);
            if (p.children?.length) out.push(...collectWhiteboards(p.children));
        }
        return out;
    })(pages);
    const hasDocuments = documentPages.length > 0;
    const hasWhiteboards = whiteboardPages.length > 0;

    function renderPageItem(page: PageWithChildren, depth = 0) {
        if (page.type === 'page' && page.is_whiteboard) return null; // Whiteboards listed in their own section
        const href = route('pages.show', [workspace.slug, project.slug, page.uuid]);
        const isFolder = page.type === 'folder';
        const children = page.children ?? [];

        return (
            <div key={page.id} className="min-w-0" style={{ marginLeft: depth > 0 ? depth * 20 : 0 }}>
                <Link
                    href={href}
                    className="flex items-center gap-3 py-2.5 px-3 -mx-3 rounded-lg hover:bg-muted/80 transition-colors group min-w-0"
                >
                    {isFolder ? (
                        <Folder className="size-5 shrink-0 text-primary" />
                    ) : (page.icon?.startsWith('data:') || page.icon?.startsWith('http') || page.icon?.startsWith('/'))
                        ? <img src={page.icon} className="size-6 rounded object-cover shrink-0" alt="" />
                        : (
                        <span className="text-lg shrink-0 w-6 text-center">{page.icon || '📄'}</span>
                    )}
                    <span className="truncate font-medium text-foreground group-hover:text-primary transition-colors">
                        {page.name}
                    </span>
                    <ChevronRight className="size-4 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity ml-auto" />
                </Link>
                {children.map((child) => renderPageItem(child, depth + 1))}
            </div>
        );
    }

    return (
        <AppLayout>
            <Head title={project.name} />

            <div className="min-h-screen bg-muted/30">
                <div className="p-6 md:p-8 lg:p-10 max-w-6xl mx-auto space-y-6">
                    {/* Header card - solid bar uses blue when no project color */}
                    <Card className="overflow-hidden border border-border bg-card shadow-sm">
                        <div
                            className="h-1.5 w-full"
                            style={{ backgroundColor: project.color || '#0E83DC' }}
                        />
                        <CardContent className="pt-5 pb-5">
                                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-6">
                                <div className="flex items-start gap-5 min-w-0 flex-1">
                                    <ProjectIcon project={project} size="xl" className="shadow-lg shrink-0" />
                                    <div className="min-w-0 flex-1">
                                        <h1 className="text-2xl md:text-3xl font-bold tracking-tight truncate">
                                            {project.name}
                                        </h1>
                                        <div className="flex flex-wrap items-center gap-x-5 gap-y-1.5 mt-2 text-sm text-muted-foreground">
                                            {project.creator && (
                                                <span className="flex items-center gap-1.5" title="Owner">
                                                    <Avatar className="size-5">
                                                        <AvatarImage src={project.creator.avatar_display ?? project.creator.avatar ?? undefined} alt="" />
                                                        <AvatarFallback className="text-[10px] bg-primary/10 text-primary">
                                                            {(project.creator.name || '?').slice(0, 2).toUpperCase()}
                                                        </AvatarFallback>
                                                    </Avatar>
                                                    <span>{project.creator.name}</span>
                                                </span>
                                            )}
                                            {project.key && (
                                                <span className="flex items-center gap-1.5" title="Project key">
                                                    <Key className="size-3.5 shrink-0" />
                                                    <span className="font-mono">{project.key}</span>
                                                </span>
                                            )}
                                            {project.created_at && (
                                                <span className="flex items-center gap-1.5" title="Created">
                                                    <Calendar className="size-3.5 shrink-0" />
                                                    <span>{new Date(project.created_at).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })}</span>
                                                </span>
                                            )}
                                            <span className="flex items-center gap-1.5" title="Boards">
                                                <LayoutGrid className="size-3.5 shrink-0" />
                                                <span>{project.boards?.length ?? 0} board{(project.boards?.length ?? 0) !== 1 ? 's' : ''}</span>
                                            </span>
                                            {project.users_count != null && (
                                                <span className="flex items-center gap-1.5" title="Members">
                                                    <Users className="size-3.5 shrink-0" />
                                                    <span>{project.users_count} member{project.users_count !== 1 ? 's' : ''}</span>
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2 shrink-0">
                                    <Dialog open={createBoardDialogOpen} onOpenChange={setCreateBoardDialogOpen}>
                                        <DialogTrigger asChild>
                                            <Button size="lg" className="gap-2">
                                                <Plus className="size-5" />
                                                New Board
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent className="sm:max-w-md">
                                            <DialogHeader>
                                                <DialogTitle className="text-xl">Create Board</DialogTitle>
                                                <DialogDescription className="text-base">
                                                    Create a new board to track your work.
                                                </DialogDescription>
                                            </DialogHeader>
                                            <form onSubmit={createBoard} className="space-y-5 mt-4">
                                                <div className="space-y-2">
                                                    <Label htmlFor="board-name" className="text-base">Board Name</Label>
                                                    <Input
                                                        id="board-name"
                                                        value={boardForm.data.name}
                                                        onChange={(e) => boardForm.setData('name', e.target.value)}
                                                        placeholder="e.g., Sprint Board, Backlog"
                                                        className="h-11"
                                                        required
                                                    />
                                                    {boardForm.errors.name && <p className="text-sm text-destructive">{boardForm.errors.name}</p>}
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="board-description" className="text-base">Description (optional)</Label>
                                                    <Textarea
                                                        id="board-description"
                                                        value={boardForm.data.description}
                                                        onChange={(e) => boardForm.setData('description', e.target.value)}
                                                        placeholder="What is this board for?"
                                                        className="min-h-[100px]"
                                                    />
                                                </div>
                                                <div className="flex justify-end gap-3 pt-2">
                                                    <Button type="button" variant="outline" size="lg" onClick={() => setCreateBoardDialogOpen(false)}>
                                                        Cancel
                                                    </Button>
                                                    <Button type="submit" size="lg" disabled={boardForm.processing}>
                                                        {boardForm.processing ? 'Creating...' : 'Create Board'}
                                                    </Button>
                                                </div>
                                            </form>
                                        </DialogContent>
                                    </Dialog>
                                    <Link href={route('projects.edit', [workspace.slug, project.slug])}>
                                        <Button variant="outline" size="icon" className="size-11">
                                            <Settings className="size-5" />
                                        </Button>
                                    </Link>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Description – extra section under title, formatted rich HTML */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-lg">Description</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {project.description && project.description.trim() !== '' ? (
                                <div
                                    className="prose prose-sm dark:prose-invert max-w-none prose-p:my-2 prose-headings:my-2 prose-headings:font-semibold prose-ul:my-2 prose-ol:my-2 prose-li:my-0.5 prose-pre:my-2 prose-pre:bg-muted prose-pre:rounded-lg prose-pre:p-3 prose-code:bg-muted prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:before:content-none prose-code:after:content-none prose-blockquote:border-primary prose-blockquote:bg-muted/50 prose-blockquote:py-1 prose-a:text-primary prose-a:no-underline hover:prose-a:underline"
                                    dangerouslySetInnerHTML={{
                                        __html: project.description.trim().startsWith('<')
                                            ? project.description
                                            : `<p>${project.description.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</p>`,
                                    }}
                                />
                            ) : (
                                <p className="text-muted-foreground text-sm">No description.</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* 2x2: Row1 = Boards | Helpdesks, Row2 = Documents & folders | Whiteboards */}
                    <div className="grid gap-6 lg:grid-cols-2">
                        {/* Row 1 - Col 1: Boards */}
                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex items-center gap-2">
                                    <div className="size-9 rounded-lg bg-primary/10 flex items-center justify-center">
                                        <LayoutGrid className="size-5 text-primary" />
                                    </div>
                                    <div>
                                        <CardTitle className="text-lg">Boards</CardTitle>
                                        <CardDescription>
                                            Kanban boards and task management
                                        </CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {project.boards.length === 0 ? (
                                    <div className="flex flex-col items-center justify-center py-12 rounded-xl border-2 border-dashed border-muted-foreground/25">
                                        <div className="size-14 rounded-full bg-primary/10 flex items-center justify-center mb-4">
                                            <LayoutGrid className="size-7 text-primary" />
                                        </div>
                                        <p className="text-sm font-medium text-foreground mb-1">No boards yet</p>
                                        <p className="text-xs text-muted-foreground text-center max-w-[240px] mb-5">
                                            Create a board to organize tasks with Kanban.
                                        </p>
                                        <Button size="sm" className="gap-2" onClick={() => setCreateBoardDialogOpen(true)}>
                                            <Plus className="size-4" />
                                            Create Board
                                        </Button>
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        {project.boards.map((board) => (
                                            <Link
                                                key={board.id}
                                                href={route('boards.show', [workspace.slug, project.slug, board.slug])}
                                            >
                                                <div className="flex items-center gap-4 p-3 rounded-xl hover:bg-muted/80 transition-colors group">
                                                    <div className="size-11 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                                        <Kanban className="size-5 text-primary" />
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <p className="font-semibold text-foreground group-hover:text-primary transition-colors truncate">
                                                            {board.name}
                                                        </p>
                                                        <div className="flex items-center gap-2 mt-0.5">
                                                            <Badge variant="secondary" className="text-xs">
                                                                {board.type.charAt(0).toUpperCase() + board.type.slice(1)}
                                                            </Badge>
                                                            <span className="text-xs text-muted-foreground truncate">
                                                                {board.description || 'No description'}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <ArrowRight className="size-4 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity" />
                                                </div>
                                            </Link>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Row 1 - Col 2: Helpdesks */}
                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <div className="size-9 rounded-lg bg-primary/10 flex items-center justify-center">
                                            <Headphones className="size-5 text-primary" />
                                        </div>
                                        <div>
                                            <CardTitle className="text-lg">Helpdesks</CardTitle>
                                            <CardDescription>
                                                Customer support ticket management
                                            </CardDescription>
                                        </div>
                                    </div>
                                    {(project.helpdesks?.length ?? 0) > 0 && (
                                        <Button size="sm" variant="outline" className="gap-1.5" onClick={() => setCreateHelpdeskDialogOpen(true)}>
                                            <Plus className="size-4" />
                                            New
                                        </Button>
                                    )}
                                </div>
                            </CardHeader>
                            <CardContent>
                                {(!project.helpdesks || project.helpdesks.length === 0) ? (
                                    <div className="flex flex-col items-center justify-center py-12 rounded-xl border-2 border-dashed border-muted-foreground/25">
                                        <div className="size-14 rounded-full bg-primary/10 flex items-center justify-center mb-4">
                                            <Headphones className="size-7 text-primary" />
                                        </div>
                                        <p className="text-sm font-medium text-foreground mb-1">No helpdesks yet</p>
                                        <p className="text-xs text-muted-foreground text-center max-w-[240px] mb-5">
                                            Create a helpdesk to manage customer support tickets.
                                        </p>
                                        <Button size="sm" className="gap-2" onClick={() => setCreateHelpdeskDialogOpen(true)}>
                                            <Plus className="size-4" />
                                            Create Helpdesk
                                        </Button>
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        {project.helpdesks?.map((helpdesk) => (
                                            <Link
                                                key={helpdesk.id}
                                                href={route('helpdesks.show', [workspace.slug, project.slug, helpdesk.slug])}
                                            >
                                                <div className="flex items-center gap-4 p-3 rounded-xl hover:bg-muted/80 transition-colors group">
                                                    <div className="size-11 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                                        <Headphones className="size-5 text-primary" />
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <p className="font-semibold text-foreground group-hover:text-primary transition-colors truncate">
                                                            {helpdesk.name}
                                                        </p>
                                                        <div className="flex items-center gap-2 mt-0.5">
                                                            <Badge variant="secondary" className="text-xs">
                                                                Helpdesk
                                                            </Badge>
                                                            <span className="text-xs text-muted-foreground truncate">
                                                                {helpdesk.description || 'No description'}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <ArrowRight className="size-4 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity" />
                                                </div>
                                            </Link>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Row 2 - Col 1: Documents & folders */}
                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex items-center gap-2">
                                    <div className="size-9 rounded-lg bg-primary/10 flex items-center justify-center">
                                        <FileText className="size-5 text-primary" />
                                    </div>
                                    <div>
                                        <CardTitle className="text-lg">Documents & folders</CardTitle>
                                        <CardDescription>
                                            Pages and folder structure
                                        </CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {!hasDocuments ? (
                                    <div className="flex flex-col items-center justify-center py-12 rounded-xl border-2 border-dashed border-muted-foreground/25">
                                        <div className="size-14 rounded-full bg-primary/10 flex items-center justify-center mb-4">
                                            <FileText className="size-7 text-primary" />
                                        </div>
                                        <p className="text-sm font-medium text-foreground mb-1">No documents yet</p>
                                        <p className="text-xs text-muted-foreground text-center max-w-[240px]">
                                            Documents and folders are managed from the sidebar. Create a page or folder.
                                        </p>
                                        <Link href={route('workspaces.show', [workspace.slug])} className="mt-5">
                                            <Button variant="outline" size="sm">
                                                Open workspace
                                            </Button>
                                        </Link>
                                    </div>
                                ) : (
                                    <div className="space-y-0.5">
                                        {documentPages.map((page) => renderPageItem(page))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Row 2 - Col 2: Whiteboards */}
                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex items-center gap-2">
                                    <div className="size-9 rounded-lg bg-primary/10 flex items-center justify-center">
                                        <PencilRuler className="size-5 text-primary" />
                                    </div>
                                    <div>
                                        <CardTitle className="text-lg">Whiteboards</CardTitle>
                                        <CardDescription>
                                            Draw and sketch with whiteboard pages
                                        </CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {!hasWhiteboards ? (
                                    <div className="flex flex-col items-center justify-center py-10 rounded-xl border-2 border-dashed border-muted-foreground/25">
                                        <PencilRuler className="size-10 text-primary/50 mb-3" />
                                        <p className="text-sm text-muted-foreground text-center max-w-[280px]">
                                            Create a whiteboard from the sidebar (Pages → Whiteboard) to draw diagrams and sketches.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {whiteboardPages.map((page) => (
                                            <Link
                                                key={page.id}
                                                href={route('pages.show', [workspace.slug, project.slug, page.uuid])}
                                            >
                                                <div className="flex items-center gap-4 p-3 rounded-xl hover:bg-muted/80 transition-colors group">
                                                    <div className="size-11 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                                        <PencilRuler className="size-5 text-primary" />
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <p className="font-semibold text-foreground group-hover:text-primary transition-colors truncate">
                                                            {page.name}
                                                        </p>
                                                        <Badge variant="secondary" className="text-xs mt-1">
                                                            Whiteboard
                                                        </Badge>
                                                    </div>
                                                    <ArrowRight className="size-4 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity" />
                                                </div>
                                            </Link>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Create Helpdesk modal (same as Add content flow) */}
                    <Dialog open={createHelpdeskDialogOpen} onOpenChange={setCreateHelpdeskDialogOpen}>
                        <DialogContent className="sm:max-w-md">
                            <DialogHeader>
                                <DialogTitle className="text-xl">Create Helpdesk</DialogTitle>
                                <DialogDescription className="text-base">
                                    Create a helpdesk to manage customer support tickets.
                                </DialogDescription>
                            </DialogHeader>
                            <form onSubmit={createHelpdesk} className="space-y-5 mt-4">
                                <div className="space-y-2">
                                    <Label htmlFor="helpdesk-name" className="text-base">Helpdesk Name</Label>
                                    <Input
                                        id="helpdesk-name"
                                        value={helpdeskForm.data.name}
                                        onChange={(e) => helpdeskForm.setData('name', e.target.value)}
                                        placeholder="e.g., Customer Support, Technical Help"
                                        className="h-11"
                                        required
                                    />
                                    {helpdeskForm.errors.name && <p className="text-sm text-destructive">{helpdeskForm.errors.name}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="helpdesk-description" className="text-base">Description (optional)</Label>
                                    <Textarea
                                        id="helpdesk-description"
                                        value={helpdeskForm.data.description}
                                        onChange={(e) => helpdeskForm.setData('description', e.target.value)}
                                        placeholder="Brief description of this helpdesk"
                                        className="min-h-[100px]"
                                    />
                                </div>
                                <div className="flex justify-end gap-3 pt-2">
                                    <Button type="button" variant="outline" size="lg" onClick={() => setCreateHelpdeskDialogOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" size="lg" disabled={helpdeskForm.processing}>
                                        {helpdeskForm.processing ? 'Creating...' : 'Create Helpdesk'}
                                    </Button>
                                </div>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>
        </AppLayout>
    );
}
