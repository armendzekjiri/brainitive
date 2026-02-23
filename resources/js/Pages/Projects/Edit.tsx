import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { PageProps, Workspace, Project, Label as LabelType } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { ArrowLeft, Save, Plus, Pencil, Trash2, Tag } from 'lucide-react';
import { useState } from 'react';

interface Props extends PageProps {
    workspace: Workspace;
    project: Project & { labels?: LabelType[] };
}

// Predefined colors for labels
const LABEL_COLORS = [
    '#ef4444', // red
    '#f97316', // orange
    '#f59e0b', // amber
    '#84cc16', // lime
    '#22c55e', // green
    '#14b8a6', // teal
    '#06b6d4', // cyan
    '#3b82f6', // blue
    '#6366f1', // indigo
    '#8b5cf6', // violet
    '#a855f7', // purple
    '#ec4899', // pink
    '#64748b', // slate
];

function LabelForm({
    mode,
    label,
    workspace,
    project,
    onClose,
}: {
    mode: 'create' | 'edit';
    label?: LabelType;
    workspace: Workspace;
    project: Project;
    onClose: () => void;
}) {
    const { data, setData, post, put, processing, reset, errors } = useForm({
        name: label?.name || '',
        color: label?.color || LABEL_COLORS[0],
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (mode === 'create') {
            post(route('projects.labels.store', [workspace.slug, project.slug]), {
                onSuccess: () => {
                    reset();
                    onClose();
                },
            });
        } else if (label) {
            put(route('projects.labels.update', [workspace.slug, project.slug, label.id]), {
                onSuccess: () => onClose(),
            });
        }
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
                <Label htmlFor="label-name">Label Name</Label>
                <Input
                    id="label-name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    placeholder="e.g., Bug, Feature, Urgent"
                    required
                />
                {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
            </div>
            <div className="space-y-2">
                <Label>Color</Label>
                <div className="flex flex-wrap gap-2">
                    {LABEL_COLORS.map((color) => (
                        <button
                            key={color}
                            type="button"
                            className={`w-8 h-8 rounded-full transition-all ${
                                data.color === color
                                    ? 'ring-2 ring-offset-2 ring-primary scale-110'
                                    : 'hover:scale-105'
                            }`}
                            style={{ backgroundColor: color }}
                            onClick={() => setData('color', color)}
                        />
                    ))}
                </div>
                {errors.color && <p className="text-sm text-destructive">{errors.color}</p>}
            </div>
            <div className="flex items-center gap-2 pt-2">
                <Badge
                    style={{ backgroundColor: data.color, color: '#fff' }}
                    className="px-3 py-1"
                >
                    {data.name || 'Preview'}
                </Badge>
            </div>
            <div className="flex justify-end gap-2 pt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing ? 'Saving...' : mode === 'create' ? 'Create Label' : 'Save Changes'}
                </Button>
            </div>
        </form>
    );
}

export default function ProjectsEdit({ auth, workspace, project }: Props) {
    const [createLabelOpen, setCreateLabelOpen] = useState(false);
    const [editingLabel, setEditingLabel] = useState<LabelType | null>(null);

    const { data, setData, put, processing, errors, isDirty } = useForm({
        name: project.name || '',
        description: project.description || '',
    });

    const handleSaveProject = (e: React.FormEvent) => {
        e.preventDefault();
        put(route('projects.update', [workspace.slug, project.slug]));
    };

    const handleDeleteLabel = (labelId: number) => {
        router.delete(route('projects.labels.destroy', [workspace.slug, project.slug, labelId]));
    };

    return (
        <AppLayout>
            <Head title={`Settings - ${project.name}`} />

            <div className="min-h-full bg-background">
                {/* Header */}
                <div className="border-b bg-background/95 backdrop-blur sticky top-0 z-10">
                    <div className="max-w-4xl mx-auto px-6 py-4">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-4">
                                <Link href={route('projects.show', [workspace.slug, project.slug])}>
                                    <Button variant="ghost" size="icon" className="size-8">
                                        <ArrowLeft className="size-4" />
                                    </Button>
                                </Link>
                                <div>
                                    <h1 className="text-xl font-semibold">Project Settings</h1>
                                    <p className="text-sm text-muted-foreground">{project.name}</p>
                                </div>
                            </div>
                            {isDirty && (
                                <Button onClick={handleSaveProject} disabled={processing} className="gap-2">
                                    <Save className="size-4" />
                                    {processing ? 'Saving...' : 'Save Changes'}
                                </Button>
                            )}
                        </div>
                    </div>
                </div>

                {/* Content */}
                <div className="max-w-4xl mx-auto px-6 py-8 space-y-8">
                    {/* General Settings */}
                    <Card>
                        <CardHeader>
                            <CardTitle>General</CardTitle>
                            <CardDescription>Basic project information</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSaveProject} className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="name">Project Name</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Project name"
                                        required
                                    />
                                    {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="description">Description</Label>
                                    <Textarea
                                        id="description"
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                        placeholder="What is this project about?"
                                        className="min-h-[100px]"
                                    />
                                    {errors.description && (
                                        <p className="text-sm text-destructive">{errors.description}</p>
                                    )}
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    {/* Labels */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0">
                            <div className="space-y-1">
                                <CardTitle className="flex items-center gap-2">
                                    <Tag className="size-5" />
                                    Labels
                                </CardTitle>
                                <CardDescription>
                                    Labels help categorize and filter items across your project
                                </CardDescription>
                            </div>
                            <Dialog open={createLabelOpen} onOpenChange={setCreateLabelOpen}>
                                <DialogTrigger asChild>
                                    <Button variant="outline" size="sm" className="gap-2">
                                        <Plus className="size-4" />
                                        Add Label
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>Create Label</DialogTitle>
                                        <DialogDescription>
                                            Create a new label to categorize items in your project.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <LabelForm
                                        mode="create"
                                        workspace={workspace}
                                        project={project}
                                        onClose={() => setCreateLabelOpen(false)}
                                    />
                                </DialogContent>
                            </Dialog>
                        </CardHeader>
                        <CardContent>
                            {project.labels && project.labels.length > 0 ? (
                                <div className="space-y-2">
                                    {project.labels.map((label) => (
                                        <div
                                            key={label.id}
                                            className="flex items-center justify-between p-3 rounded-lg border bg-muted/20 hover:bg-muted/40 transition-colors"
                                        >
                                            <Badge
                                                style={{ backgroundColor: label.color, color: '#fff' }}
                                                className="px-3 py-1 text-sm"
                                            >
                                                {label.name}
                                            </Badge>
                                            <div className="flex items-center gap-1">
                                                <Dialog
                                                    open={editingLabel?.id === label.id}
                                                    onOpenChange={(open) => !open && setEditingLabel(null)}
                                                >
                                                    <DialogTrigger asChild>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8"
                                                            onClick={() => setEditingLabel(label)}
                                                        >
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                    </DialogTrigger>
                                                    <DialogContent>
                                                        <DialogHeader>
                                                            <DialogTitle>Edit Label</DialogTitle>
                                                            <DialogDescription>
                                                                Update the label name or color.
                                                            </DialogDescription>
                                                        </DialogHeader>
                                                        {editingLabel && (
                                                            <LabelForm
                                                                mode="edit"
                                                                label={editingLabel}
                                                                workspace={workspace}
                                                                project={project}
                                                                onClose={() => setEditingLabel(null)}
                                                            />
                                                        )}
                                                    </DialogContent>
                                                </Dialog>
                                                <AlertDialog>
                                                    <AlertDialogTrigger asChild>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8 text-destructive hover:text-destructive"
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    </AlertDialogTrigger>
                                                    <AlertDialogContent>
                                                        <AlertDialogHeader>
                                                            <AlertDialogTitle>Delete Label</AlertDialogTitle>
                                                            <AlertDialogDescription>
                                                                Are you sure you want to delete the "{label.name}" label?
                                                                This will remove it from all items.
                                                            </AlertDialogDescription>
                                                        </AlertDialogHeader>
                                                        <AlertDialogFooter>
                                                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                            <AlertDialogAction
                                                                onClick={() => handleDeleteLabel(label.id)}
                                                                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                            >
                                                                Delete
                                                            </AlertDialogAction>
                                                        </AlertDialogFooter>
                                                    </AlertDialogContent>
                                                </AlertDialog>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="text-center py-8 text-muted-foreground">
                                    <Tag className="size-12 mx-auto mb-3 opacity-50" />
                                    <p className="text-sm">No labels yet</p>
                                    <p className="text-xs">Create labels to categorize your items</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Danger Zone */}
                    <Card className="border-destructive/50">
                        <CardHeader>
                            <CardTitle className="text-destructive">Danger Zone</CardTitle>
                            <CardDescription>
                                Irreversible and destructive actions
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center justify-between p-4 rounded-lg border border-destructive/30 bg-destructive/5">
                                <div>
                                    <p className="font-medium">Delete Project</p>
                                    <p className="text-sm text-muted-foreground">
                                        Permanently delete this project and all its data
                                    </p>
                                </div>
                                <AlertDialog>
                                    <AlertDialogTrigger asChild>
                                        <Button variant="destructive">Delete Project</Button>
                                    </AlertDialogTrigger>
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>Delete Project</AlertDialogTitle>
                                            <AlertDialogDescription>
                                                This action cannot be undone. This will permanently delete the
                                                project "{project.name}" and all its boards, items, and data.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                                            <AlertDialogAction
                                                onClick={() =>
                                                    router.delete(
                                                        route('projects.destroy', [workspace.slug, project.slug])
                                                    )
                                                }
                                                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                            >
                                                Delete Project
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
