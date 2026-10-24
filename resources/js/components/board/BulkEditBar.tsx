import { useState } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import {
    CalendarDays, CircleDot, Flag, Layers, Loader2, Shapes, Tag, Trash2, UserRound, X,
} from 'lucide-react';
import type { Board, BoardColumn, Item, ItemPriority, ItemType, Label as LabelType, PageProps, Project, User, Workspace } from '@/types';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator,
    DropdownMenuSub, DropdownMenuSubContent, DropdownMenuSubTrigger, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
    AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { ItemTypeIcon } from '@/components/ItemTypeIcon';
import { AssigneeAvatar, LabelChip, PriorityIndicator, StatusLozenge, statusCategory } from '@/components/board/ItemPrimitives';

/** What one bulk request may change (BoardBulkItemController). */
export interface BulkChanges {
    column_id?: number;
    assigned_to?: number | null;
    priority_id?: number;
    item_type_id?: number;
    sprint_id?: number | null;
    due_date?: string | null;
    add_label_ids?: number[];
    remove_label_ids?: number[];
}

interface Props {
    workspace: Workspace;
    project: Project;
    board: Board;
    /** The selected issues, in list order. */
    items: Item[];
    columns: BoardColumn[];
    users: User[];
    priorities: ItemPriority[];
    itemTypes: ItemType[];
    labels: LabelType[];
    /** Scrum boards: where issues can go (null id = backlog). */
    sprints: { id: number; name: string }[] | null;
    /** Ids among `items` the current user may delete (ItemPolicy::delete). */
    deletableIds: Set<number>;
    onClear: () => void;
    /** After a successful delete. */
    onDeleted: () => void;
}

const triggerClass = 'h-8 gap-1.5 px-2.5 text-[13px] font-medium shrink-0';

/**
 * The sticky bar of the board's list view while issues are selected (BRAI-108): one request per
 * action for all selected issues.
 */
export function BulkEditBar({
    workspace, project, board, items, columns, users, priorities, itemTypes, labels, sprints, deletableIds, onClear, onDeleted,
}: Props) {
    const [busy, setBusy] = useState(false);
    const [assigneeOpen, setAssigneeOpen] = useState(false);
    const [dueOpen, setDueOpen] = useState(false);
    const [dueDate, setDueDate] = useState('');
    const [confirmDelete, setConfirmDelete] = useState(false);
    const count = items.length;
    const noun = count === 1 ? 'issue' : 'issues';
    const params = [workspace.slug, project.slug, board.slug];
    const ids = items.map((item) => item.id);
    const allDeletable = items.every((item) => deletableIds.has(item.id));

    const options = (fallback: string, after?: () => void) => ({
        preserveScroll: true,
        preserveState: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
        onSuccess: (page: { props: unknown }) => {
            toast.success((page.props as PageProps).flash?.success ?? fallback);
            after?.();
        },
        onError: (errors: Record<string, string>) => toast.error(Object.values(errors)[0] ?? 'Could not update the selected issues.'),
    });

    const apply = (changes: BulkChanges, what: string) =>
        router.patch(route('boards.items.bulk.update', params), { item_ids: ids, changes } as never, options(`${what} for ${count} ${noun}.`));

    const destroy = () =>
        router.delete(route('boards.items.bulk.destroy', params), { data: { item_ids: ids }, ...options(`Deleted ${count} ${noun}.`, onDeleted) });

    return (
        <div
            role="toolbar"
            aria-label="Bulk edit selected issues"
            className={cn(
                'z-20 flex items-center gap-1 overflow-x-auto rounded-xl border bg-popover p-1.5 text-popover-foreground shadow-lg shadow-black/10 dark:shadow-black/40',
                // Phones: pinned to the screen next to the capture button (the table scrolls sideways there)
                'max-sm:fixed max-sm:bottom-6 max-sm:left-3 max-sm:right-20',
                'sm:sticky sm:bottom-3 sm:mx-auto sm:mt-4 sm:w-fit sm:max-w-full',
            )}
        >
            <span className="flex shrink-0 items-center gap-2 px-2 text-sm font-semibold tabular-nums" aria-live="polite">
                {busy && <Loader2 className="size-3.5 animate-spin text-muted-foreground" aria-hidden />}
                {count} selected
            </span>
            <span className="mx-0.5 h-5 w-px shrink-0 bg-border" aria-hidden />

            {/* Status */}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="sm" className={triggerClass} disabled={busy}><CircleDot className="size-3.5" />Status</Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" side="top" className="max-h-80 overflow-y-auto">
                    <DropdownMenuLabel>Move {count} {noun} to</DropdownMenuLabel>
                    {columns.map((column) => (
                        <DropdownMenuItem key={column.id} onSelect={() => apply({ column_id: column.id }, `Moved to ${column.name}`)}>
                            <StatusLozenge name={column.name} category={statusCategory(column, columns)} />
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>

            {/* Assignee (searchable) */}
            <Popover open={assigneeOpen} onOpenChange={setAssigneeOpen}>
                <PopoverTrigger asChild>
                    <Button variant="ghost" size="sm" className={triggerClass} disabled={busy} aria-haspopup="listbox"><UserRound className="size-3.5" />Assignee</Button>
                </PopoverTrigger>
                <PopoverContent align="start" side="top" className="w-64 p-0">
                    <Command>
                        <CommandInput placeholder="Search people…" aria-label="Search people" />
                        <CommandList>
                            <CommandEmpty>No one found.</CommandEmpty>
                            <CommandGroup>
                                <CommandItem value="Unassigned" onSelect={() => { setAssigneeOpen(false); apply({ assigned_to: null }, 'Unassigned'); }}>
                                    <span className="flex size-[18px] items-center justify-center rounded-full border border-dashed text-muted-foreground"><UserRound className="size-3" /></span>
                                    Unassigned
                                </CommandItem>
                                {users.map((user) => (
                                    <CommandItem key={user.id} value={`${user.name} ${user.email} #${user.id}`} onSelect={() => { setAssigneeOpen(false); apply({ assigned_to: user.id }, `Assigned to ${user.name}`); }}>
                                        <AssigneeAvatar user={user} />
                                        <span className="truncate">{user.name}</span>
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>

            {/* Priority */}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="sm" className={triggerClass} disabled={busy}><Flag className="size-3.5" />Priority</Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" side="top">
                    {priorities.map((priority) => (
                        <DropdownMenuItem key={priority.id} onSelect={() => apply({ priority_id: priority.id }, `Priority set to ${priority.name}`)}>
                            <PriorityIndicator slug={priority.slug} />
                            {priority.name}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>

            {/* Type */}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="sm" className={triggerClass} disabled={busy}><Shapes className="size-3.5" />Type</Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" side="top" className="max-h-80 overflow-y-auto">
                    {itemTypes.map((type) => (
                        <DropdownMenuItem key={type.id} onSelect={() => apply({ item_type_id: type.id }, `Type set to ${type.name}`)}>
                            <ItemTypeIcon type={type} className="size-4" />
                            {type.name}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>

            {/* Sprint (Scrum boards) */}
            {sprints && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="sm" className={triggerClass} disabled={busy}><Layers className="size-3.5" />Sprint</Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" side="top" className="max-h-80 overflow-y-auto">
                        <DropdownMenuLabel>Move {count} {noun} to</DropdownMenuLabel>
                        {sprints.map((sprint) => (
                            <DropdownMenuItem key={sprint.id} onSelect={() => apply({ sprint_id: sprint.id }, `Moved to ${sprint.name}`)}>{sprint.name}</DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onSelect={() => apply({ sprint_id: null }, 'Moved to the backlog')}>Backlog</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            )}

            {/* Due date */}
            <Popover open={dueOpen} onOpenChange={(open) => { setDueOpen(open); if (open) setDueDate(''); }}>
                <PopoverTrigger asChild>
                    <Button variant="ghost" size="sm" className={triggerClass} disabled={busy} aria-haspopup="dialog"><CalendarDays className="size-3.5" />Due date</Button>
                </PopoverTrigger>
                <PopoverContent align="start" side="top" className="w-64 space-y-3">
                    <form
                        className="space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!dueDate) return;
                            setDueOpen(false);
                            apply({ due_date: dueDate }, 'Due date set');
                        }}
                    >
                        <label className="block space-y-1.5 text-sm font-medium">
                            <span>Due date for {count} {noun}</span>
                            <input
                                type="date"
                                value={dueDate}
                                onChange={(e) => setDueDate(e.target.value)}
                                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring dark:[color-scheme:dark]"
                            />
                        </label>
                        <div className="flex justify-between gap-2">
                            <Button type="button" variant="ghost" size="sm" onClick={() => { setDueOpen(false); apply({ due_date: null }, 'Due date cleared'); }}>
                                Clear due date
                            </Button>
                            <Button type="submit" size="sm" disabled={!dueDate}>Set</Button>
                        </div>
                    </form>
                </PopoverContent>
            </Popover>

            {/* Labels: add or remove */}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="sm" className={triggerClass} disabled={busy}><Tag className="size-3.5" />Labels</Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" side="top">
                    {labels.length === 0 ? (
                        <p className="px-2 py-1.5 text-sm text-muted-foreground">This project has no labels yet.</p>
                    ) : (
                        <>
                            <DropdownMenuSub>
                                <DropdownMenuSubTrigger>Add label</DropdownMenuSubTrigger>
                                <DropdownMenuSubContent className="max-h-80 overflow-y-auto">
                                    {labels.map((label) => (
                                        <DropdownMenuItem key={label.id} onSelect={() => apply({ add_label_ids: [label.id] }, `Label “${label.name}” added`)}>
                                            <LabelChip label={label} />
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuSubContent>
                            </DropdownMenuSub>
                            <DropdownMenuSub>
                                <DropdownMenuSubTrigger>Remove label</DropdownMenuSubTrigger>
                                <DropdownMenuSubContent className="max-h-80 overflow-y-auto">
                                    {labels.map((label) => (
                                        <DropdownMenuItem key={label.id} onSelect={() => apply({ remove_label_ids: [label.id] }, `Label “${label.name}” removed`)}>
                                            <LabelChip label={label} />
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuSubContent>
                            </DropdownMenuSub>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            <span className="mx-0.5 h-5 w-px shrink-0 bg-border" aria-hidden />

            <Button
                variant="ghost"
                size="sm"
                className={cn(triggerClass, 'text-red-600 hover:bg-red-500/10 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300')}
                disabled={busy || !allDeletable}
                title={allDeletable ? undefined : 'Only project admins can delete issues created by someone else.'}
                onClick={() => setConfirmDelete(true)}
            >
                <Trash2 className="size-3.5" />Delete
            </Button>
            <Button variant="ghost" size="icon" className="size-8 shrink-0" onClick={onClear} aria-label="Clear selection" title="Clear selection (Esc)">
                <X className="size-4" />
            </Button>

            <AlertDialog open={confirmDelete} onOpenChange={setConfirmDelete}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete {count} {noun}?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {items.slice(0, 5).map((item) => item.key).join(', ')}{count > 5 ? ` and ${count - 5} more` : ''} will be deleted
                            together with their subtasks.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction className="bg-destructive text-destructive-foreground hover:bg-destructive/90" onClick={destroy}>
                            Delete {count} {noun}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
