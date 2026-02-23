import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { PageProps, Workspace, Project, Board } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { LayoutGrid, Plus, Settings, ArrowRight, Kanban } from 'lucide-react';
import { useState } from 'react';

interface Props extends PageProps {
    workspace: Workspace;
    project: Project & { boards: Board[] };
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

    return (
        <AppLayout>
            <Head title={project.name} />

            <div className="p-8 max-w-6xl mx-auto">
                {/* Header */}
                <div className="flex items-center justify-between mb-10">
                    <div className="flex items-center gap-5">
                        <div
                            className="size-14 rounded-xl flex items-center justify-center text-white font-bold text-lg shadow-md"
                            style={{ backgroundColor: project.color || '#6366f1' }}
                        >
                            {project.key}
                        </div>
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight mb-1">{project.name}</h1>
                            <p className="text-base text-muted-foreground">
                                {project.description || 'No description'}
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
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

                {/* Boards Section */}
                <div className="mb-6">
                    <h2 className="text-xl font-semibold">Boards</h2>
                </div>

                {project.boards.length === 0 ? (
                    <Card className="border-dashed border-2">
                        <CardContent className="flex flex-col items-center justify-center py-20">
                            <div className="size-20 rounded-full bg-primary/10 flex items-center justify-center mb-6">
                                <LayoutGrid className="size-10 text-primary" />
                            </div>
                            <h3 className="text-xl font-semibold mb-2">
                                No boards yet
                            </h3>
                            <p className="text-muted-foreground text-center max-w-md mb-8 text-base">
                                Create your first board to start organizing tasks with a Kanban workflow.
                            </p>
                            <Button size="lg" className="gap-2" onClick={() => setCreateDialogOpen(true)}>
                                <Plus className="size-5" />
                                Create Board
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                        {project.boards.map((board) => (
                            <Link
                                key={board.id}
                                href={route('boards.show', [workspace.slug, project.slug, board.slug])}
                            >
                                <Card className="hover:shadow-lg hover:border-primary/50 transition-all cursor-pointer h-full group">
                                    <CardContent className="p-6">
                                        <div className="flex items-start justify-between mb-4">
                                            <div className="flex items-start gap-4">
                                                <div className="size-12 rounded-xl bg-primary/10 flex items-center justify-center">
                                                    <Kanban className="size-6 text-primary" />
                                                </div>
                                                <div>
                                                    <h3 className="text-lg font-semibold group-hover:text-primary transition-colors">
                                                        {board.name}
                                                    </h3>
                                                    <Badge variant="secondary" className="mt-1">
                                                        {board.type.charAt(0).toUpperCase() + board.type.slice(1)}
                                                    </Badge>
                                                </div>
                                            </div>
                                            <ArrowRight className="size-5 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity" />
                                        </div>
                                        <p className="text-sm text-muted-foreground line-clamp-2 min-h-[2.5rem]">
                                            {board.description || 'No description'}
                                        </p>
                                    </CardContent>
                                </Card>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
