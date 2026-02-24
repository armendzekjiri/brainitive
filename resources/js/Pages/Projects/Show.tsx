import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { PageProps, Workspace, Project, Board, Page as PageType } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { LayoutGrid, Plus, Settings, ArrowRight, Kanban, FileText, Folder, ChevronRight, PencilRuler } from 'lucide-react';
import { ProjectIcon } from '@/components/ProjectIcon';
import { useState } from 'react';

type PageWithChildren = PageType & { children?: PageWithChildren[] };

interface Props extends PageProps {
    workspace: Workspace;
    project: Project & { boards: Board[]; pages?: PageWithChildren[] };
}

export default function ProjectsShow({ auth, workspace, project }: Props) {
    const [createDialogOpen, setCreateDialogOpen] = useState(false);
    const { data, setData, post, processing, reset, errors } = useForm({
        name: '',
        description: '',
    });

    const createBoard = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('boards.store', [workspace.slug, project.slug]), {
            onSuccess: () => {
                setCreateDialogOpen(false);
                reset();
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
                    ) : (
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
                                <div className="flex items-start gap-5 min-w-0">
                                    <ProjectIcon project={project} size="xl" className="shadow-lg" />
                                    <div className="min-w-0">
                                        <h1 className="text-2xl md:text-3xl font-bold tracking-tight truncate">
                                            {project.name}
                                        </h1>
                                        <p className="text-muted-foreground mt-1 text-sm md:text-base line-clamp-2">
                                            {project.description || 'No description'}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2 shrink-0">
                                    <Dialog open={createDialogOpen} onOpenChange={setCreateDialogOpen}>
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
                                                    <Label htmlFor="name" className="text-base">Board Name</Label>
                                                    <Input
                                                        id="name"
                                                        value={data.name}
                                                        onChange={(e) => setData('name', e.target.value)}
                                                        placeholder="e.g., Sprint Board, Backlog"
                                                        className="h-11"
                                                        required
                                                    />
                                                    {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="description" className="text-base">Description (optional)</Label>
                                                    <Textarea
                                                        id="description"
                                                        value={data.description}
                                                        onChange={(e) => setData('description', e.target.value)}
                                                        placeholder="What is this board for?"
                                                        className="min-h-[100px]"
                                                    />
                                                </div>
                                                <div className="flex justify-end gap-3 pt-2">
                                                    <Button type="button" variant="outline" size="lg" onClick={() => setCreateDialogOpen(false)}>
                                                        Cancel
                                                    </Button>
                                                    <Button type="submit" size="lg" disabled={processing}>
                                                        {processing ? 'Creating...' : 'Create Board'}
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

                    {/* Boards + Documents + Whiteboards */}
                    <div className="grid gap-6 lg:grid-cols-2">
                        {/* Boards Section */}
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
                                        <Button size="sm" className="gap-2" onClick={() => setCreateDialogOpen(true)}>
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

                        {/* Documents & folders Section (excludes whiteboards) */}
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
                    </div>

                    {/* Whiteboards - full width section */}
                    <Card className="mt-2">
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
            </div>
        </AppLayout>
    );
}
