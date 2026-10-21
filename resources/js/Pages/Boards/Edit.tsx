import AppLayout from '@/Layouts/AppLayout';
import { Head, useForm, router, Link } from '@inertiajs/react';
import { PageProps, Workspace, Project, Board, BoardColumn } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { ProjectIcon } from '@/components/ProjectIcon';
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
import {
    ArrowLeft,
    Settings,
    Plus,
    GripVertical,
    Trash2,
    Columns,
    Palette,
    Edit2,
    Kanban,
} from 'lucide-react';
import { useState, FormEvent, DragEvent } from 'react';
import axios from 'axios';

interface Props extends PageProps {
    workspace: Workspace;
    project: Project;
    board: Board & { columns: BoardColumn[] };
}

// Preset colors for columns
const columnColors = [
    { name: 'Gray', value: '#94a3b8' },
    { name: 'Blue', value: '#3b82f6' },
    { name: 'Cyan', value: '#06b6d4' },
    { name: 'Green', value: '#22c55e' },
    { name: 'Yellow', value: '#eab308' },
    { name: 'Orange', value: '#f97316' },
    { name: 'Red', value: '#ef4444' },
    { name: 'Purple', value: '#a855f7' },
    { name: 'Pink', value: '#ec4899' },
];

// Column Card Component
function ColumnCard({
    column,
    workspace,
    project,
    board,
    onEdit,
    onDragStart,
    onDragOver,
    onDragEnd,
    onDrop,
    isDragging,
    isDragOver,
}: {
    column: BoardColumn;
    workspace: Workspace;
    project: Project;
    board: Board;
    onEdit: (column: BoardColumn) => void;
    onDragStart: (e: DragEvent, column: BoardColumn) => void;
    onDragOver: (e: DragEvent, column: BoardColumn) => void;
    onDragEnd: () => void;
    onDrop: (e: DragEvent, column: BoardColumn) => void;
    isDragging: boolean;
    isDragOver: boolean;
}) {
    const itemsCount = column.items_count ?? 0;
    const handleDelete = () => {
        router.delete(route('boards.columns.destroy', [workspace.slug, project.slug, board.slug, column.id]));
    };

    return (
        <Card
            className={`border bg-background transition-all ${isDragging ? 'opacity-50 scale-95' : ''} ${isDragOver ? 'border-primary ring-2 ring-primary/20' : ''}`}
            draggable
            onDragStart={(e) => onDragStart(e, column)}
            onDragOver={(e) => onDragOver(e, column)}
            onDragEnd={onDragEnd}
            onDrop={(e) => onDrop(e, column)}
        >
            <CardContent className="p-4">
                <div className="flex items-center gap-4">
                    <div className="cursor-grab active:cursor-grabbing text-muted-foreground hover:text-foreground">
                        <GripVertical className="size-5" />
                    </div>
                    <div
                        className="w-3 h-8 rounded-full shrink-0"
                        style={{ backgroundColor: column.color || '#94a3b8' }}
                    />
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                            <h4 className="font-medium truncate">{column.name}</h4>
                            {column.is_done_column && (
                                <Badge variant="secondary" className="text-xs">
                                    Done
                                </Badge>
                            )}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {itemsCount} {itemsCount === 1 ? 'item' : 'items'}
                            {column.wip_limit && ` • WIP limit: ${column.wip_limit}`}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="ghost" size="icon" className="size-8" onClick={() => onEdit(column)}>
                            <Edit2 className="size-4" />
                        </Button>
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-8 text-destructive hover:text-destructive"
                                    disabled={itemsCount > 0}
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>Delete column?</AlertDialogTitle>
                                    <AlertDialogDescription>
                                        Are you sure you want to delete "{column.name}"? This action cannot be undone.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                    <AlertDialogAction
                                        onClick={handleDelete}
                                        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                    >
                                        Delete
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

// Add Column Dialog
function AddColumnDialog({
    workspace,
    project,
    board,
}: {
    workspace: Workspace;
    project: Project;
    board: Board;
}) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, reset, errors } = useForm({
        name: '',
        color: '#94a3b8',
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        post(route('boards.columns.store', [workspace.slug, project.slug, board.slug]), {
            onSuccess: () => {
                setOpen(false);
                reset();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button className="gap-2">
                    <Plus className="size-4" />
                    Add Column
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add Column</DialogTitle>
                    <DialogDescription>
                        Create a new column for your board workflow.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4 mt-4">
                    <div className="space-y-2">
                        <Label htmlFor="name">Column Name</Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g., In Progress, Review, Done"
                            required
                        />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label>Color</Label>
                        <div className="flex flex-wrap gap-2">
                            {columnColors.map((color) => (
                                <button
                                    key={color.value}
                                    type="button"
                                    onClick={() => setData('color', color.value)}
                                    className={`w-8 h-8 rounded-full border-2 transition-all ${
                                        data.color === color.value
                                            ? 'border-foreground scale-110'
                                            : 'border-transparent hover:scale-105'
                                    }`}
                                    style={{ backgroundColor: color.value }}
                                    title={color.name}
                                />
                            ))}
                        </div>
                    </div>

                    <div className="flex justify-end gap-3 pt-4">
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create Column'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// Edit Column Dialog
function EditColumnDialog({
    column,
    workspace,
    project,
    board,
    open,
    onClose,
}: {
    column: BoardColumn | null;
    workspace: Workspace;
    project: Project;
    board: Board;
    open: boolean;
    onClose: () => void;
}) {
    const { data, setData, put, processing, errors } = useForm({
        name: column?.name || '',
        color: column?.color || '#94a3b8',
        wip_limit: column?.wip_limit?.toString() || '',
        is_done_column: column?.is_done_column || false,
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (!column) return;
        put(route('boards.columns.update', [workspace.slug, project.slug, board.slug, column.id]), {
            onSuccess: () => onClose(),
        });
    };

    if (!column) return null;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit Column</DialogTitle>
                    <DialogDescription>
                        Update column settings.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4 mt-4">
                    <div className="space-y-2">
                        <Label htmlFor="edit-name">Column Name</Label>
                        <Input
                            id="edit-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                        />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label>Color</Label>
                        <div className="flex flex-wrap gap-2">
                            {columnColors.map((color) => (
                                <button
                                    key={color.value}
                                    type="button"
                                    onClick={() => setData('color', color.value)}
                                    className={`w-8 h-8 rounded-full border-2 transition-all ${
                                        data.color === color.value
                                            ? 'border-foreground scale-110'
                                            : 'border-transparent hover:scale-105'
                                    }`}
                                    style={{ backgroundColor: color.value }}
                                    title={color.name}
                                />
                            ))}
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="wip-limit">WIP Limit (optional)</Label>
                        <Input
                            id="wip-limit"
                            type="number"
                            min="0"
                            value={data.wip_limit}
                            onChange={(e) => setData('wip_limit', e.target.value)}
                            placeholder="No limit"
                        />
                        <p className="text-xs text-muted-foreground">
                            Maximum number of items allowed in this column
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            id="is-done"
                            checked={data.is_done_column}
                            onChange={(e) => setData('is_done_column', e.target.checked)}
                            className="size-4 rounded border-input"
                        />
                        <Label htmlFor="is-done" className="font-normal">
                            Mark as "Done" column
                        </Label>
                    </div>

                    <div className="flex justify-end gap-3 pt-4">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function BoardsEdit({ workspace, project, board }: Props) {
    const [editingColumn, setEditingColumn] = useState<BoardColumn | null>(null);
    const [columns, setColumns] = useState<BoardColumn[]>(board.columns);
    const [draggedColumn, setDraggedColumn] = useState<BoardColumn | null>(null);
    const [dragOverColumn, setDragOverColumn] = useState<BoardColumn | null>(null);

    const { data, setData, put, processing, errors } = useForm({
        name: board.name,
        description: board.description || '',
        type: board.type === 'scrum' ? 'scrum' : 'kanban',
    });

    const handleDragStart = (e: DragEvent, column: BoardColumn) => {
        setDraggedColumn(column);
        e.dataTransfer.effectAllowed = 'move';
    };

    const handleDragOver = (e: DragEvent, column: BoardColumn) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (draggedColumn && column.id !== draggedColumn.id) {
            setDragOverColumn(column);
        }
    };

    const handleDragEnd = () => {
        setDraggedColumn(null);
        setDragOverColumn(null);
    };

    const handleDrop = async (e: DragEvent, targetColumn: BoardColumn) => {
        e.preventDefault();
        if (!draggedColumn || draggedColumn.id === targetColumn.id) {
            handleDragEnd();
            return;
        }

        // Reorder columns locally
        const newColumns = [...columns];
        const draggedIndex = newColumns.findIndex(c => c.id === draggedColumn.id);
        const targetIndex = newColumns.findIndex(c => c.id === targetColumn.id);

        newColumns.splice(draggedIndex, 1);
        newColumns.splice(targetIndex, 0, draggedColumn);

        // Update positions
        const updatedColumns = newColumns.map((col, index) => ({
            ...col,
            position: index,
        }));

        setColumns(updatedColumns);
        handleDragEnd();

        // Save to backend
        try {
            await axios.post(route('boards.columns.reorder', [workspace.slug, project.slug, board.slug]), {
                columns: updatedColumns.map(col => ({ id: col.id, position: col.position })),
            });
        } catch (error) {
            // Revert on error
            setColumns(board.columns);
            console.error('Failed to reorder columns:', error);
        }
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        put(route('boards.update', [workspace.slug, project.slug, board.slug]));
    };

    const handleDeleteBoard = () => {
        router.delete(route('boards.destroy', [workspace.slug, project.slug, board.slug]));
    };

    return (
        <AppLayout>
            <Head title={`Settings - ${board.name}`} />

            <div className="min-h-full bg-muted/20">
                {/* Header */}
                <div className="bg-background border-b px-6 py-4">
                    <div className="max-w-4xl mx-auto">
                        <div className="flex items-center gap-4">
                            <Link href={route('boards.show', [workspace.slug, project.slug, board.slug])}>
                                <Button variant="ghost" size="icon" className="size-9">
                                    <ArrowLeft className="size-5" />
                                </Button>
                            </Link>
                            <div className="flex items-center gap-3">
                                <ProjectIcon project={project} size="md" />
                                <div>
                                    <h1 className="text-xl font-semibold">Board Settings</h1>
                                    <p className="text-sm text-muted-foreground">{board.name}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Content */}
                <div className="max-w-4xl mx-auto p-6 space-y-8">
                    {/* General Settings */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-3">
                                <Settings className="size-5 text-primary" />
                                General Settings
                            </CardTitle>
                            <CardDescription>
                                Update your board's basic information.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="name">Board Name</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        required
                                    />
                                    {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="description">Description (optional)</Label>
                                    <Textarea
                                        id="description"
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                        placeholder="Describe the purpose of this board..."
                                        rows={3}
                                    />
                                </div>

                                <label className="flex items-start gap-3 rounded-md border border-border p-3 cursor-pointer">
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

                                <div className="flex justify-end">
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Saving...' : 'Save Changes'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    {/* Columns */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div>
                                    <CardTitle className="flex items-center gap-3">
                                        <Columns className="size-5 text-primary" />
                                        Columns
                                    </CardTitle>
                                    <CardDescription>
                                        Manage your board's workflow columns. Drag to reorder.
                                    </CardDescription>
                                </div>
                                <AddColumnDialog workspace={workspace} project={project} board={board} />
                            </div>
                        </CardHeader>
                        <CardContent>
                            {columns.length === 0 ? (
                                <div className="text-center py-12">
                                    <div className="size-16 rounded-full bg-muted mx-auto mb-4 flex items-center justify-center">
                                        <Kanban className="size-8 text-muted-foreground" />
                                    </div>
                                    <h3 className="text-lg font-semibold mb-2">No columns yet</h3>
                                    <p className="text-muted-foreground mb-6 max-w-sm mx-auto">
                                        Add columns to define your workflow stages.
                                    </p>
                                    <AddColumnDialog workspace={workspace} project={project} board={board} />
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    {columns.map((column) => (
                                        <ColumnCard
                                            key={column.id}
                                            column={column}
                                            workspace={workspace}
                                            project={project}
                                            board={board}
                                            onEdit={setEditingColumn}
                                            onDragStart={handleDragStart}
                                            onDragOver={handleDragOver}
                                            onDragEnd={handleDragEnd}
                                            onDrop={handleDrop}
                                            isDragging={draggedColumn?.id === column.id}
                                            isDragOver={dragOverColumn?.id === column.id}
                                        />
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Danger Zone */}
                    <Card className="border-destructive/50">
                        <CardHeader>
                            <CardTitle className="text-destructive flex items-center gap-3">
                                <Trash2 className="size-5" />
                                Danger Zone
                            </CardTitle>
                            <CardDescription>
                                Irreversible actions. Proceed with caution.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center justify-between p-4 rounded-lg border border-destructive/30 bg-destructive/5">
                                <div>
                                    <h4 className="font-semibold">Delete this board</h4>
                                    <p className="text-sm text-muted-foreground">
                                        All columns and items will be permanently deleted.
                                    </p>
                                </div>
                                <AlertDialog>
                                    <AlertDialogTrigger asChild>
                                        <Button variant="destructive" className="gap-2">
                                            <Trash2 className="size-4" />
                                            Delete Board
                                        </Button>
                                    </AlertDialogTrigger>
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>Delete board?</AlertDialogTitle>
                                            <AlertDialogDescription>
                                                Are you sure you want to delete "{board.name}"? This will permanently
                                                delete all columns and items. This action cannot be undone.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                                            <AlertDialogAction
                                                onClick={handleDeleteBoard}
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
                </div>
            </div>

            {/* Edit Column Dialog */}
            <EditColumnDialog
                column={editingColumn}
                workspace={workspace}
                project={project}
                board={board}
                open={!!editingColumn}
                onClose={() => setEditingColumn(null)}
            />
        </AppLayout>
    );
}
