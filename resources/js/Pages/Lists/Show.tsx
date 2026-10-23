import { useState, useRef, useCallback, useEffect, FormEvent } from 'react';
import { Head, router, usePage, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { PageProps, Workspace, Project, TaskList, TaskListItem, User } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem,
    DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter,
} from '@/components/ui/dialog';
import {
    Popover, PopoverContent, PopoverTrigger,
} from '@/components/ui/popover';
import { Label } from '@/components/ui/label';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
    AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { cn } from '@/lib/utils';
import {
    CheckCircle2, Circle, Plus, MoreHorizontal, Trash2, Copy, Bell, BellRing,
    Calendar, RefreshCw, User as UserIcon, ChevronDown, ChevronRight,
    Edit2, List, AlignLeft, X, Check,
} from 'lucide-react';
import { RichLexicalEditor } from '@/components/editor/RichLexicalEditorFull';
import { format, isToday, isTomorrow, isPast, addDays } from 'date-fns';

interface Props extends PageProps {
    workspace: Workspace;
    project: Project;
    taskList: TaskList & { items: TaskListItem[]; watchers: User[] };
    members: User[];
}

const RECURRENCE_LABELS: Record<string, string> = {
    daily: 'Daily',
    weekly: 'Weekly',
    monthly: 'Monthly',
    yearly: 'Yearly',
};

function dueDateLabel(date: string): { label: string; variant: 'destructive' | 'warning' | 'secondary' | 'outline' } {
    // Extract YYYY-MM-DD regardless of whether the server returned "2026-03-09",
    // "2026-03-09T00:00:00.000000Z", or any other ISO variant. Use noon to avoid
    // timezone-related off-by-one when comparing with today.
    const dateOnly = date.substring(0, 10);
    const d = new Date(`${dateOnly}T12:00:00`);
    if (isNaN(d.getTime())) return { label: date, variant: 'secondary' };
    if (isPast(d) && !isToday(d)) return { label: format(d, 'MMM d'), variant: 'destructive' };
    if (isToday(d)) return { label: 'Today', variant: 'warning' };
    if (isTomorrow(d)) return { label: 'Tomorrow', variant: 'outline' };
    if (d <= addDays(new Date(), 7)) return { label: format(d, 'MMM d'), variant: 'outline' };
    return { label: format(d, 'MMM d'), variant: 'secondary' };
}

function UserAvatar({ user, size = 'sm' }: { user: User; size?: 'sm' | 'xs' }) {
    const initials = user.name.split(' ').map((n) => n[0]).join('').toUpperCase().slice(0, 2);
    const sz = size === 'xs' ? 'size-5 text-[10px]' : 'size-6 text-xs';
    return (
        <Avatar className={sz}>
            {user.avatar && <img src={user.avatar} alt={user.name} className="rounded-full object-cover" />}
            <AvatarFallback className={sz}>{initials}</AvatarFallback>
        </Avatar>
    );
}

// ─── Item Row ─────────────────────────────────────────────────────────────────
function ItemRow({
    item,
    workspace,
    project,
    taskList,
    members,
    onUpdate,
    onDelete,
}: {
    item: TaskListItem;
    workspace: Workspace;
    project: Project;
    taskList: TaskList;
    members: User[];
    onUpdate: (id: number, data: Partial<TaskListItem> & { _optimistic?: boolean; _bounce?: boolean }) => void;
    onDelete: (id: number) => void;
}) {
    const [expanded, setExpanded] = useState(false);
    const [editing, setEditing] = useState(false);
    const [titleVal, setTitleVal] = useState(item.title);
    const [bounceDone, setBounceDone] = useState(false);
    const [descHtml, setDescHtml] = useState<string | null>(null);
    const titleRef = useRef<HTMLInputElement>(null);

    useEffect(() => { setTitleVal(item.title); }, [item.title]);

    const extractDescHtml = (desc: Record<string, any> | null | undefined): string | null => {
        if (!desc) return null;
        if (desc.type === 'lexical' && typeof desc.html === 'string') return desc.html;
        return null;
    };

    const handleToggleComplete = () => {
        if (item.recurrence && item.status === 'todo') {
            // Show bounce animation, then let server reset
            setBounceDone(true);
            setTimeout(() => setBounceDone(false), 1400);
        }
        onUpdate(item.id, { status: item.status === 'done' ? 'todo' : 'done' });
    };

    const handleSaveTitle = () => {
        setEditing(false);
        if (titleVal.trim() && titleVal !== item.title) {
            onUpdate(item.id, { title: titleVal.trim() });
        } else {
            setTitleVal(item.title);
        }
    };

    const handleDescChange = useCallback((html: string) => {
        setDescHtml(html);
    }, []);

    const handleSaveDesc = () => {
        onUpdate(item.id, { description: { type: 'lexical', html: descHtml ?? '' } });
    };

    const isDone = item.status === 'done';
    const isBouncing = bounceDone;

    return (
        <div className={cn(
            'group border rounded-lg bg-card transition-all',
            isDone && !isBouncing ? 'opacity-60' : '',
            isBouncing ? 'ring-2 ring-green-400' : '',
        )}>
            <div className="flex items-center gap-2 px-3 py-2.5">
                {/* Complete toggle */}
                <button
                    type="button"
                    onClick={handleToggleComplete}
                    className="shrink-0 text-muted-foreground hover:text-green-500 transition-colors"
                    title={isDone ? 'Mark as to-do' : 'Mark as done'}
                >
                    {isDone || isBouncing
                        ? <CheckCircle2 className="size-5 text-green-500" />
                        : <Circle className="size-5" />}
                </button>

                {/* Title */}
                {editing ? (
                    <input
                        ref={titleRef}
                        className="flex-1 text-sm bg-transparent border-b border-primary outline-none py-0.5"
                        value={titleVal}
                        onChange={(e) => setTitleVal(e.target.value)}
                        onBlur={handleSaveTitle}
                        onKeyDown={(e) => { if (e.key === 'Enter') handleSaveTitle(); if (e.key === 'Escape') { setEditing(false); setTitleVal(item.title); } }}
                        autoFocus
                    />
                ) : (
                    <span
                        className={cn('flex-1 text-sm cursor-pointer hover:text-primary transition-colors', isDone && 'line-through text-muted-foreground')}
                        onClick={() => { setEditing(true); setTimeout(() => titleRef.current?.select(), 30); }}
                    >
                        {item.title}
                    </span>
                )}

                {/* Badges row */}
                <div className="flex items-center gap-1.5 shrink-0">
                    {item.recurrence && (
                        <span title={`Repeats ${item.recurrence}`} className="text-muted-foreground">
                            <RefreshCw className="size-3" />
                        </span>
                    )}

                    {item.due_date && (() => {
                        const { label, variant } = dueDateLabel(item.due_date);
                        return (
                            <Badge variant={variant as any} className="text-xs px-1.5 py-0 h-5 gap-1">
                                <Calendar className="size-2.5" />
                                {label}
                            </Badge>
                        );
                    })()}

                    {item.assignee && <UserAvatar user={item.assignee} size="xs" />}

                    {/* Expand description */}
                    <button
                        type="button"
                        onClick={() => setExpanded(!expanded)}
                        className="opacity-0 group-hover:opacity-100 text-muted-foreground hover:text-foreground transition-all"
                        title="Description"
                    >
                        <AlignLeft className="size-3.5" />
                    </button>

                    <ItemContextMenu
                        item={item}
                        members={members}
                        onUpdate={(data) => onUpdate(item.id, data)}
                        onDelete={() => onDelete(item.id)}
                        onDuplicate={() =>
                            router.post(route('task-lists.items.duplicate', [workspace.slug, project.slug, taskList.slug, item.id]), {}, { preserveScroll: true })
                        }
                    />
                </div>
            </div>

            {/* Expanded description panel */}
            {expanded && (
                <div className="border-t px-3 pb-3 pt-2">
                    <RichLexicalEditor
                        content={extractDescHtml(item.description as any)}
                        onChange={handleDescChange}
                        editable
                        showToolbar
                        minHeight="80px"
                        maxHeight="300px"
                        placeholder="Add a description..."
                        className="border-0 shadow-none bg-transparent"
                    />
                    <div className="flex justify-end mt-2">
                        <Button size="sm" variant="outline" className="h-7 text-xs" onClick={handleSaveDesc}>
                            <Check className="size-3 mr-1" /> Save
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── Item Context Menu ────────────────────────────────────────────────────────
function ItemContextMenu({
    item,
    members,
    onUpdate,
    onDelete,
    onDuplicate,
}: {
    item: TaskListItem;
    members: User[];
    onUpdate: (data: Partial<TaskListItem>) => void;
    onDelete: () => void;
    onDuplicate: () => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button className="opacity-0 group-hover:opacity-100 text-muted-foreground hover:text-foreground transition-all">
                    <MoreHorizontal className="size-3.5" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
                {/* Assignee */}
                <div className="px-2 py-1.5">
                    <p className="text-xs text-muted-foreground mb-1.5">Assignee</p>
                    <Select
                        value={item.assignee_id ? String(item.assignee_id) : 'none'}
                        onValueChange={(v) => onUpdate({ assignee_id: v === 'none' ? null : Number(v) })}
                    >
                        <SelectTrigger className="h-7 text-xs">
                            <SelectValue placeholder="Unassigned" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Unassigned</SelectItem>
                            {members.map((m) => (
                                <SelectItem key={m.id} value={String(m.id)}>{m.name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Due date */}
                <div className="px-2 py-1.5">
                    <p className="text-xs text-muted-foreground mb-1.5">Due date</p>
                    <input
                        type="date"
                        className="w-full h-7 text-xs border rounded px-2 bg-background"
                        value={item.due_date ?? ''}
                        onChange={(e) => onUpdate({ due_date: e.target.value || null })}
                    />
                </div>

                {/* Recurrence */}
                <div className="px-2 py-1.5">
                    <p className="text-xs text-muted-foreground mb-1.5">Repeat</p>
                    <Select
                        value={item.recurrence ?? 'none'}
                        onValueChange={(v) => onUpdate({ recurrence: v === 'none' ? null : (v as TaskListItem['recurrence']) })}
                    >
                        <SelectTrigger className="h-7 text-xs">
                            <SelectValue placeholder="No repeat" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">No repeat</SelectItem>
                            <SelectItem value="daily">Daily</SelectItem>
                            <SelectItem value="weekly">Weekly</SelectItem>
                            <SelectItem value="monthly">Monthly</SelectItem>
                            <SelectItem value="yearly">Yearly</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <DropdownMenuSeparator />
                <DropdownMenuItem className="text-xs" onClick={onDuplicate}>
                    <Copy className="size-3.5 mr-2" /> Duplicate
                </DropdownMenuItem>
                <DropdownMenuItem
                    className="text-destructive focus:text-destructive text-xs"
                    onClick={onDelete}
                >
                    <Trash2 className="size-3.5 mr-2" /> Delete
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

// ─── Add Item Form ────────────────────────────────────────────────────────────
function AddItemForm({
    workspace, project, taskList, members, onCreated,
}: {
    workspace: Workspace;
    project: Project;
    taskList: TaskList;
    members: User[];
    onCreated: () => void;
}) {
    const [open, setOpen] = useState(false);
    const [title, setTitle] = useState('');
    const [assigneeId, setAssigneeId] = useState<string>('none');
    const [dueDate, setDueDate] = useState('');
    const [recurrence, setRecurrence] = useState<string>('none');
    const [submitting, setSubmitting] = useState(false);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (!title.trim()) return;
        setSubmitting(true);
        router.post(
            route('task-lists.items.store', [workspace.slug, project.slug, taskList.slug]),
            {
                title: title.trim(),
                assignee_id: assigneeId !== 'none' ? Number(assigneeId) : null,
                due_date: dueDate || null,
                recurrence: recurrence !== 'none' ? recurrence : null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setTitle('');
                    setAssigneeId('none');
                    setDueDate('');
                    setRecurrence('none');
                    setOpen(false);
                    setSubmitting(false);
                    onCreated();
                },
                onError: () => setSubmitting(false),
            }
        );
    };

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="flex items-center gap-2 w-full px-3 py-2 text-sm text-muted-foreground hover:text-foreground hover:bg-accent rounded-lg transition-colors border border-dashed"
            >
                <Plus className="size-4" />
                Add item
            </button>
        );
    }

    return (
        <form onSubmit={handleSubmit} className="border rounded-lg bg-card p-3 space-y-3">
            <Input
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="Item title"
                autoFocus
                required
                className="h-8 text-sm"
            />
            <div className="flex items-center gap-2 flex-wrap">
                <Select value={assigneeId} onValueChange={setAssigneeId}>
                    <SelectTrigger className="h-7 text-xs w-36">
                        <UserIcon className="size-3 mr-1" />
                        <SelectValue placeholder="Assignee" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="none">Unassigned</SelectItem>
                        {members.map((m) => (
                            <SelectItem key={m.id} value={String(m.id)}>{m.name}</SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <input
                    type="date"
                    className="h-7 text-xs border rounded px-2 bg-background"
                    value={dueDate}
                    onChange={(e) => setDueDate(e.target.value)}
                    title="Due date"
                />

                <Select value={recurrence} onValueChange={setRecurrence}>
                    <SelectTrigger className="h-7 text-xs w-32">
                        <RefreshCw className="size-3 mr-1" />
                        <SelectValue placeholder="Repeat" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="none">No repeat</SelectItem>
                        <SelectItem value="daily">Daily</SelectItem>
                        <SelectItem value="weekly">Weekly</SelectItem>
                        <SelectItem value="monthly">Monthly</SelectItem>
                        <SelectItem value="yearly">Yearly</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div className="flex justify-end gap-2">
                <Button type="button" size="sm" variant="ghost" className="h-7 text-xs" onClick={() => setOpen(false)}>
                    Cancel
                </Button>
                <Button type="submit" size="sm" className="h-7 text-xs" disabled={submitting}>
                    {submitting ? 'Adding…' : 'Add item'}
                </Button>
            </div>
        </form>
    );
}

// ─── Main Page ────────────────────────────────────────────────────────────────
export default function TaskListShow({ workspace, project, taskList: initialList, members }: Props) {
    const { auth } = usePage<PageProps>().props;
    const [taskList, setTaskList] = useState(initialList);
    const [filter, setFilter] = useState<'all' | 'todo' | 'done'>('all');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [renameOpen, setRenameOpen] = useState(false);
    const [renameVal, setRenameVal] = useState(taskList.name);
    const [descVal, setDescVal] = useState(taskList.description ?? '');
    const [bouncingIds, setBouncingIds] = useState<Set<number>>(new Set());

    const isWatching = taskList.watchers?.some((w) => w.id === auth.user.id) ?? false;

    // Keep local state in sync with Inertia props on navigate
    useEffect(() => { setTaskList(initialList); }, [initialList]);

    const handleToggleWatch = () => {
        router.post(
            route('task-lists.watch', [workspace.slug, project.slug, taskList.slug]),
            { watch: !isWatching },
            { preserveScroll: true }
        );
    };

    const handleUpdateItem = useCallback((id: number, data: Partial<TaskListItem>) => {
        const isCompleting = data.status === 'done';
        const item = taskList.items?.find((i) => i.id === id);
        const isRecurring = item?.recurrence;

        // Optimistic update
        setTaskList((prev) => ({
            ...prev,
            items: (prev.items ?? []).map((i) =>
                i.id === id ? { ...i, ...data } : i
            ),
        }));

        // Show bounce animation for recurring items being completed
        if (isCompleting && isRecurring) {
            setBouncingIds((prev) => new Set(prev).add(id));
        }

        router.patch(
            route('task-lists.items.update', [workspace.slug, project.slug, taskList.slug, id]),
            data as Record<string, any>,
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (isCompleting && isRecurring) {
                        setTimeout(() => {
                            setBouncingIds((prev) => { const s = new Set(prev); s.delete(id); return s; });
                        }, 1500);
                    }
                },
                onError: () => {
                    // Revert optimistic update
                    setTaskList(initialList);
                },
            }
        );
    }, [taskList, initialList, workspace.slug, project.slug]);

    const handleDeleteItem = useCallback((id: number) => {
        setTaskList((prev) => ({
            ...prev,
            items: (prev.items ?? []).filter((i) => i.id !== id),
        }));
        router.delete(
            route('task-lists.items.destroy', [workspace.slug, project.slug, taskList.slug, id]),
            { preserveScroll: true, onError: () => setTaskList(initialList) }
        );
    }, [taskList.slug, initialList, workspace.slug, project.slug]);

    const handleRename = (e: FormEvent) => {
        e.preventDefault();
        if (!renameVal.trim()) return;
        router.put(
            route('task-lists.update', [workspace.slug, project.slug, taskList.slug]),
            { name: renameVal.trim(), description: descVal },
            {
                preserveScroll: true,
                onSuccess: () => setRenameOpen(false),
            }
        );
    };

    const handleDelete = () => {
        router.delete(route('task-lists.destroy', [workspace.slug, project.slug, taskList.slug]));
    };

    const filteredItems = (taskList.items ?? []).filter((i) => {
        if (filter === 'todo') return i.status === 'todo';
        if (filter === 'done') return i.status === 'done';
        return true;
    });

    const todoCount = (taskList.items ?? []).filter((i) => i.status === 'todo').length;
    const doneCount = (taskList.items ?? []).filter((i) => i.status === 'done').length;

    return (
        <AppLayout>
            <Head title={`${taskList.name} — ${project.name}`} />

            <div className="flex flex-col h-full">
                {/* Header */}
                <div className="border-b bg-background px-6 py-3 flex items-center justify-between gap-4 shrink-0">
                    <div className="flex items-center gap-2 min-w-0">
                        <Link
                            href={route('projects.show', [workspace.slug, project.slug])}
                            className="text-sm text-muted-foreground hover:text-foreground truncate"
                        >
                            {project.name}
                        </Link>
                        <ChevronRight className="size-3.5 text-muted-foreground shrink-0" />
                        <div className="flex items-center gap-1.5 min-w-0">
                            <List className="size-4 shrink-0 text-[#0E83DC]" />
                            <span className="font-semibold text-sm truncate">{taskList.name}</span>
                        </div>
                        {taskList.description && (
                            <span className="text-xs text-muted-foreground truncate hidden md:block">— {taskList.description}</span>
                        )}
                    </div>

                    <div className="flex items-center gap-2 shrink-0">
                        {/* Watch button */}
                        <Button
                            variant="outline"
                            size="sm"
                            className={cn('h-8 px-2.5 text-xs gap-1.5', isWatching && 'bg-primary/5 text-primary border-primary/20')}
                            onClick={handleToggleWatch}
                        >
                            {isWatching
                                ? <><BellRing className="size-3.5" /> Watching</>
                                : <><Bell className="size-3.5" /> Watch</>}
                        </Button>

                        {/* More menu */}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" className="size-8">
                                    <MoreHorizontal className="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-44">
                                <DropdownMenuItem onClick={() => { setRenameVal(taskList.name); setDescVal(taskList.description ?? ''); setRenameOpen(true); }}>
                                    <Edit2 className="size-4 mr-2" /> Rename
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    className="text-destructive focus:text-destructive"
                                    onClick={() => setDeleteDialogOpen(true)}
                                >
                                    <Trash2 className="size-4 mr-2" /> Delete list
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>

                {/* Content */}
                <div className="flex-1 overflow-y-auto bg-muted/30 dark:bg-background p-6">
                    <div className="max-w-3xl mx-auto space-y-4">
                        {/* Filter tabs + stats */}
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-1 bg-card rounded-lg border p-0.5">
                                {(['all', 'todo', 'done'] as const).map((f) => (
                                    <button
                                        key={f}
                                        onClick={() => setFilter(f)}
                                        className={cn(
                                            'px-3 py-1 text-xs rounded-md transition-colors font-medium',
                                            filter === f
                                                ? 'bg-primary text-primary-foreground'
                                                : 'text-muted-foreground hover:text-foreground'
                                        )}
                                    >
                                        {f === 'all' ? 'All' : f === 'todo' ? 'To Do' : 'Done'}
                                    </button>
                                ))}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {todoCount} to do · {doneCount} done
                            </div>
                        </div>

                        {/* Items */}
                        <div className="space-y-2">
                            {filteredItems.length === 0 ? (
                                <div className="text-center py-12 text-muted-foreground">
                                    <List className="size-10 mx-auto mb-3 opacity-30" />
                                    <p className="text-sm">
                                        {filter === 'done' ? 'No completed items yet.' : filter === 'todo' ? 'Nothing left to do! 🎉' : 'No items yet. Add one below!'}
                                    </p>
                                </div>
                            ) : (
                                filteredItems.map((item) => (
                                    <ItemRow
                                        key={item.id}
                                        item={bouncingIds.has(item.id) ? { ...item, status: 'done' } : item}
                                        workspace={workspace}
                                        project={project}
                                        taskList={taskList}
                                        members={members}
                                        onUpdate={handleUpdateItem}
                                        onDelete={handleDeleteItem}
                                    />
                                ))
                            )}
                        </div>

                        {/* Add item form */}
                        {filter !== 'done' && (
                            <AddItemForm
                                workspace={workspace}
                                project={project}
                                taskList={taskList}
                                members={members}
                                onCreated={() => {}}
                            />
                        )}
                    </div>
                </div>
            </div>

            {/* Rename dialog */}
            <Dialog open={renameOpen} onOpenChange={setRenameOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Rename List</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleRename} className="space-y-4">
                        <div className="space-y-2">
                            <Label>Name</Label>
                            <Input
                                value={renameVal}
                                onChange={(e) => setRenameVal(e.target.value)}
                                required
                                autoFocus
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Description <span className="text-muted-foreground font-normal">(optional)</span></Label>
                            <Textarea
                                value={descVal}
                                onChange={(e) => setDescVal(e.target.value)}
                                rows={2}
                                placeholder="What is this list for?"
                                className="resize-none"
                            />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setRenameOpen(false)}>Cancel</Button>
                            <Button type="submit">Save</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Delete dialog */}
            <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete list?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This will permanently delete <strong>{taskList.name}</strong> and all its items. This cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction className="bg-destructive hover:bg-destructive/90" onClick={handleDelete}>
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
