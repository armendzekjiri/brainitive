import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader,
    AlertDialogTitle, AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { Board, BoardColumn, Project, Workspace, WorkflowRuleSettings } from '@/types';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Columns3, GripVertical, Lock, Plus, Trash2 } from 'lucide-react';
import { DragEvent, FormEvent, useEffect, useState } from 'react';
import { toast } from 'sonner';

export const COLUMN_COLORS = [
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

interface Props {
    workspace: Workspace;
    project: Project;
    board: Board & { columns: BoardColumn[] };
    rules: Record<number, WorkflowRuleSettings>;
    onShowRule: (columnId: number) => void;
}

type ColumnChanges = { name?: string; color?: string; wip_limit?: number | null; is_done_column?: boolean };

/** One column, edited in place: name (Enter / leaving the field saves), colour, done flag, WIP limit, delete. */
function ColumnRow({ column, params, hasRule, usedInRules, onShowRule, drag }: {
    column: BoardColumn;
    params: string[];
    hasRule: boolean;
    usedInRules: boolean;
    onShowRule: () => void;
    drag: {
        onDragStart: (e: DragEvent) => void;
        onDragOver: (e: DragEvent) => void;
        onDragEnd: () => void;
        onDrop: (e: DragEvent) => void;
        isDragging: boolean;
        isDragOver: boolean;
    };
}) {
    const [name, setName] = useState(column.name);
    const [wip, setWip] = useState(column.wip_limit ? String(column.wip_limit) : '');
    const itemsCount = column.items_count ?? 0;

    const save = (changes: ColumnChanges) => {
        router.put(route('boards.columns.update', [...params, column.id]), {
            name: column.name,
            color: column.color ?? null,
            wip_limit: column.wip_limit ?? null,
            is_done_column: column.is_done_column,
            ...changes,
        }, {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                toast.error(Object.values(errors)[0] ?? 'Could not save the column.');
                setName(column.name);
                setWip(column.wip_limit ? String(column.wip_limit) : '');
            },
        });
    };

    const saveName = () => {
        const next = name.trim();
        if (!next) return setName(column.name);
        if (next !== column.name) save({ name: next });
    };
    const saveWip = () => {
        const next = wip.trim() === '' ? null : Math.max(0, Number(wip));
        if (next !== (column.wip_limit ?? null)) save({ wip_limit: next });
    };

    return (
        <li
            className={cn(
                'flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-3 transition-colors sm:flex-nowrap',
                drag.isDragging && 'opacity-50',
                drag.isDragOver && 'bg-primary/5 ring-2 ring-inset ring-primary/30',
            )}
            aria-label={`Column ${column.name}`}
            draggable
            onDragStart={drag.onDragStart}
            onDragOver={drag.onDragOver}
            onDragEnd={drag.onDragEnd}
            onDrop={drag.onDrop}
        >
            <span className="cursor-grab text-muted-foreground hover:text-foreground active:cursor-grabbing" title="Drag to reorder">
                <GripVertical className="size-4" />
            </span>
            <Popover>
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        className="size-5 shrink-0 rounded-full ring-1 ring-border transition-transform hover:scale-110"
                        style={{ backgroundColor: column.color || '#94a3b8' }}
                        aria-label={`Colour of ${column.name}`}
                    />
                </PopoverTrigger>
                <PopoverContent className="w-auto p-2" align="start">
                    <div className="grid grid-cols-5 gap-2">
                        {COLUMN_COLORS.map((color) => (
                            <button
                                key={color.value}
                                type="button"
                                title={color.name}
                                aria-label={color.name}
                                onClick={() => save({ color: color.value })}
                                className={cn('size-7 rounded-full border-2 transition-transform hover:scale-110', (column.color || '#94a3b8') === color.value ? 'border-foreground' : 'border-transparent')}
                                style={{ backgroundColor: color.value }}
                            />
                        ))}
                    </div>
                </PopoverContent>
            </Popover>
            <Input
                value={name}
                onChange={(e) => setName(e.target.value)}
                onBlur={saveName}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') (e.target as HTMLInputElement).blur();
                    if (e.key === 'Escape') setName(column.name);
                }}
                maxLength={255}
                aria-label="Column name"
                className="h-8 min-w-0 flex-1 basis-40 border-transparent bg-transparent px-2 font-medium shadow-none hover:border-input focus-visible:border-input"
            />
            <div className="flex w-full items-center gap-3 pl-7 sm:w-auto sm:pl-0">
                <label className="flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground" title="Cards here count as done">
                    <input
                        type="checkbox"
                        checked={column.is_done_column}
                        onChange={(e) => save({ is_done_column: e.target.checked })}
                        className="size-4 accent-[hsl(var(--primary))]"
                    />
                    Done
                </label>
                <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    WIP
                    <Input
                        type="number"
                        min={0}
                        value={wip}
                        onChange={(e) => setWip(e.target.value)}
                        onBlur={saveWip}
                        onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()}
                        placeholder="—"
                        aria-label={`WIP limit of ${column.name}`}
                        className="h-8 w-16 px-2 text-sm"
                    />
                </label>
                <span className="whitespace-nowrap text-right text-xs tabular-nums text-muted-foreground sm:w-16">{itemsCount} {itemsCount === 1 ? 'card' : 'cards'}</span>
                <button
                    type="button"
                    onClick={onShowRule}
                    className={cn('grid size-8 place-items-center rounded-md transition-colors hover:bg-secondary', hasRule ? 'text-primary' : 'text-muted-foreground/50 hover:text-foreground')}
                    title={hasRule ? 'Has a workflow rule — edit it' : 'Add a workflow rule'}
                    aria-label={hasRule ? `Edit the workflow rule of ${column.name}` : `Add a workflow rule to ${column.name}`}
                >
                    <Lock className="size-4" />
                </button>
                <AlertDialog>
                    <AlertDialogTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8 text-destructive hover:text-destructive"
                            disabled={itemsCount > 0}
                            title={itemsCount > 0 ? `Move or delete its ${itemsCount} cards first` : `Delete ${column.name}`}
                            aria-label={`Delete ${column.name}`}
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Delete column?</AlertDialogTitle>
                            <AlertDialogDescription>
                                “{column.name}” will be removed from the board. This can't be undone.
                                {(hasRule || usedInRules) && ' Its workflow rule goes too, and rules that allowed cards from it are updated.'}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={() => router.delete(route('boards.columns.destroy', [...params, column.id]), {
                                    preserveScroll: true,
                                    onSuccess: (page) => toast.success(String((page.props as { flash?: { success?: string } }).flash?.success ?? 'Column deleted.')),
                                })}
                                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                            >
                                Delete
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </div>
        </li>
    );
}

/** Board settings → Columns: the board's statuses in order, edited in place. */
export default function ColumnsSettings({ workspace, project, board, rules, onShowRule }: Props) {
    const params = [workspace.slug, project.slug, board.slug];
    const [columns, setColumns] = useState<BoardColumn[]>(board.columns);
    // create / update / delete reload `board`; keep the drag-reorderable copy in step
    useEffect(() => setColumns(board.columns), [board.columns]);
    const [dragged, setDragged] = useState<BoardColumn | null>(null);
    const [over, setOver] = useState<BoardColumn | null>(null);
    const [newName, setNewName] = useState('');
    const [newColor, setNewColor] = useState(COLUMN_COLORS[0].value);
    const [adding, setAdding] = useState(false);
    const columnError = (usePage().props.errors as Record<string, string> | undefined)?.column;
    const usedAsSource = new Set(Object.values(rules).flatMap((r) => r.allowed_from_column_ids));

    const drop = async (target: BoardColumn) => {
        if (!dragged || dragged.id === target.id) return;
        const next = [...columns];
        next.splice(next.findIndex((c) => c.id === dragged.id), 1);
        next.splice(next.findIndex((c) => c.id === target.id), 0, dragged);
        const ordered = next.map((c, i) => ({ ...c, position: i }));
        setColumns(ordered);
        try {
            await axios.post(route('boards.columns.reorder', params), { columns: ordered.map((c) => ({ id: c.id, position: c.position })) });
            router.reload({ only: ['board'] });
        } catch {
            setColumns(board.columns);
            toast.error('Could not save the new order.');
        }
    };

    const add = (e: FormEvent) => {
        e.preventDefault();
        if (!newName.trim()) return;
        setAdding(true);
        router.post(route('boards.columns.store', params), { name: newName.trim(), color: newColor }, {
            preserveScroll: true,
            onSuccess: () => setNewName(''),
            onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Could not add the column.'),
            onFinish: () => setAdding(false),
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2"><Columns3 className="size-5" /> Columns &amp; statuses</CardTitle>
                <CardDescription>
                    Each column is a status. Drag to reorder, click a name to rename it. Done columns complete their cards; a WIP limit
                    turns the column red when it holds more (moving stays possible).
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {columnError && <p className="text-sm text-destructive">{columnError}</p>}
                {columns.length === 0 ? (
                    <div className="rounded-md border border-dashed py-10 text-center">
                        <Columns3 className="mx-auto mb-3 size-8 text-muted-foreground" />
                        <p className="font-medium">No columns yet</p>
                        <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">Add the stages cards go through — for example To Do, In Progress, Testing and Done.</p>
                    </div>
                ) : (
                    <ul className="divide-y rounded-md border" aria-label="Columns">
                        {columns.map((column) => (
                            <ColumnRow
                                key={`${column.id}:${column.name}:${column.wip_limit ?? ''}`}
                                column={column}
                                params={params}
                                hasRule={!!rules[column.id]}
                                usedInRules={usedAsSource.has(column.id)}
                                onShowRule={() => onShowRule(column.id)}
                                drag={{
                                    onDragStart: (e) => { setDragged(column); e.dataTransfer.effectAllowed = 'move'; },
                                    onDragOver: (e) => { e.preventDefault(); if (dragged && dragged.id !== column.id) setOver(column); },
                                    onDragEnd: () => { setDragged(null); setOver(null); },
                                    onDrop: (e) => { e.preventDefault(); drop(column); setDragged(null); setOver(null); },
                                    isDragging: dragged?.id === column.id,
                                    isDragOver: over?.id === column.id,
                                }}
                            />
                        ))}
                    </ul>
                )}

                <form onSubmit={add} className="flex flex-wrap items-center gap-2">
                    <Popover>
                        <PopoverTrigger asChild>
                            <button type="button" className="size-8 shrink-0 rounded-full ring-1 ring-border" style={{ backgroundColor: newColor }} aria-label="Colour of the new column" />
                        </PopoverTrigger>
                        <PopoverContent className="w-auto p-2" align="start">
                            <div className="grid grid-cols-5 gap-2">
                                {COLUMN_COLORS.map((color) => (
                                    <button
                                        key={color.value}
                                        type="button"
                                        title={color.name}
                                        aria-label={color.name}
                                        onClick={() => setNewColor(color.value)}
                                        className={cn('size-7 rounded-full border-2', newColor === color.value ? 'border-foreground' : 'border-transparent')}
                                        style={{ backgroundColor: color.value }}
                                    />
                                ))}
                            </div>
                        </PopoverContent>
                    </Popover>
                    <Input
                        value={newName}
                        onChange={(e) => setNewName(e.target.value)}
                        placeholder="New column name, e.g. Testing"
                        aria-label="New column name"
                        maxLength={255}
                        className="h-9 min-w-0 flex-1 basis-48"
                    />
                    <Button type="submit" className="gap-1.5" disabled={adding || !newName.trim()}>
                        <Plus className="size-4" />Add column
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}
