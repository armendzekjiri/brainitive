import TaskComposer from '@/components/lists/TaskComposer';
import TaskDetailSheet from '@/components/lists/TaskDetailSheet';
import TaskRow, { type TaskRowHandlers } from '@/components/lists/TaskRow';
import { dueInfo, nextOccurrence } from '@/components/lists/taskDates';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
    AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { PageProps, Project, TaskList, TaskListItem, User, Workspace } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Bell, BellRing, ChevronDown, ChevronRight, Edit2, ListChecks, MoreHorizontal, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState, type DragEvent, type FormEvent } from 'react';
import { toast } from 'sonner';

interface Props extends PageProps {
    workspace: Workspace;
    project: Project;
    taskList: TaskList & { items: TaskListItem[]; watchers: User[] };
    members: User[];
    canManage: boolean;
}

/** Fields the item update endpoint accepts (TaskListController::updateItem). */
const FIELDS = ['title', 'description', 'status', 'assignee_id', 'due_date', 'due_time', 'start_date', 'start_time', 'recurrence', 'reminder_minutes'] as const;

export default function TaskListShow({ workspace, project, taskList, members, canManage }: Props) {
    const { auth } = usePage<PageProps>().props;
    const [items, setItems] = useState(taskList.items ?? []);
    const [openId, setOpenId] = useState<number | null>(null);
    const [showCompleted, setShowCompleted] = useState(false);
    const [drag, setDrag] = useState<{ id: number; over: number | null } | null>(null);
    const [deleting, setDeleting] = useState<TaskListItem | null>(null);
    const [deleteListOpen, setDeleteListOpen] = useState(false);
    const [renameOpen, setRenameOpen] = useState(false);
    const [renameVal, setRenameVal] = useState(taskList.name);
    const [descVal, setDescVal] = useState(taskList.description ?? '');

    // Server state wins after every visit; edits below are optimistic until it answers.
    useEffect(() => setItems(taskList.items ?? []), [taskList.items]);

    // A reminder links to "#task-<id>": open that task.
    useEffect(() => {
        const match = window.location.hash.match(/^#task-(\d+)$/);
        if (match) setOpenId(Number(match[1]));
    }, []);

    const listArgs = [workspace.slug, project.slug, taskList.slug];
    const itemUrl = (name: string, item: TaskListItem) => route(name, [...listArgs, item.id]);
    const open = useMemo(() => items.filter((i) => i.status !== 'done').sort((a, b) => a.position - b.position), [items]);
    const completed = useMemo(() => items.filter((i) => i.status === 'done').sort((a, b) => b.updated_at.localeCompare(a.updated_at)), [items]);
    const overdue = open.filter((i) => dueInfo(i)?.tone === 'overdue').length;
    const progress = items.length ? Math.round((completed.length / items.length) * 100) : 0;
    const openItem = items.find((i) => i.id === openId) ?? null;
    const isWatching = taskList.watchers?.some((w) => w.id === auth.user.id) ?? false;

    const patch = (item: TaskListItem, changes: Partial<TaskListItem>) => {
        setItems((prev) => prev.map((i) => (i.id === item.id ? { ...i, ...changes, updated_at: new Date().toISOString() } : i)));
        const data = Object.fromEntries(Object.entries(changes).filter(([k]) => (FIELDS as readonly string[]).includes(k)));
        router.patch(itemUrl('task-lists.items.update', item), data as never, {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                setItems(taskList.items ?? []);
                toast.error(Object.values(errors)[0] ?? 'Could not save the task.');
            },
        });
    };

    const handlers: TaskRowHandlers = {
        onOpen: (item) => setOpenId(item.id),
        onUpdate: patch,
        onToggle: (item) => {
            if (item.status === 'done') return patch(item, { status: 'todo' });
            if (item.recurrence && item.due_date) {
                // The server keeps a repeating task open and moves it to the next date.
                const next = nextOccurrence(item.due_date, item.recurrence);
                setItems((prev) => prev.map((i) => (i.id === item.id ? { ...i, due_date: next } : i)));
                router.patch(itemUrl('task-lists.items.update', item), { status: 'done' }, {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => toast.success(`Done — next on ${dueInfo({ due_date: next, due_time: item.due_time, status: 'todo' })?.label}`),
                    onError: () => {
                        setItems(taskList.items ?? []);
                        toast.error('Could not complete the task.');
                    },
                });
                return;
            }
            patch(item, { status: 'done' });
        },
        onDuplicate: (item) => router.post(itemUrl('task-lists.items.duplicate', item), {}, {
            preserveScroll: true,
            preserveState: true,
            onError: () => toast.error('Could not duplicate the task.'),
        }),
        onDelete: (item) => setDeleting(item),
    };

    const confirmDelete = () => {
        if (!deleting) return;
        const item = deleting;
        setDeleting(null);
        if (openId === item.id) setOpenId(null);
        setItems((prev) => prev.filter((i) => i.id !== item.id));
        router.delete(itemUrl('task-lists.items.destroy', item), {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                setItems(taskList.items ?? []);
                toast.error('Could not delete the task.');
            },
        });
    };

    // ── Drag to reorder (open tasks only) ──────────────────────────────────
    // Dragging down drops below the target, dragging up above it, so every slot is reachable.
    const dropsBelow = (target: TaskListItem) => !!drag && open.findIndex((i) => i.id === drag.id) < open.findIndex((i) => i.id === target.id);
    const dropOn = (target: TaskListItem) => {
        if (!drag || drag.id === target.id) return setDrag(null);
        const below = dropsBelow(target);
        const order = open.filter((i) => i.id !== drag.id);
        const moved = open.find((i) => i.id === drag.id);
        if (!moved) return setDrag(null);
        order.splice(order.findIndex((i) => i.id === target.id) + (below ? 1 : 0), 0, moved);
        const positions = new Map(order.map((i, index) => [i.id, index]));
        setItems((prev) => prev.map((i) => (positions.has(i.id) ? { ...i, position: positions.get(i.id)! } : i)));
        setDrag(null);
        window.axios
            .post(route('task-lists.items.reorder', listArgs), { items: order.map((i, index) => ({ id: i.id, position: index })) })
            // Refresh the confirmed list, so later rollbacks don't restore the old order.
            .then(() => router.reload({ only: ['taskList'] }))
            .catch(() => {
                setItems(taskList.items ?? []);
                toast.error('Could not save the new order.');
            });
    };
    const dragProps = (item: TaskListItem) => ({
        onDragStart: (e: DragEvent) => {
            e.dataTransfer.effectAllowed = 'move';
            setDrag({ id: item.id, over: null });
        },
        onDragOver: (e: DragEvent) => {
            if (!drag) return;
            e.preventDefault();
            if (drag.over !== item.id) setDrag({ ...drag, over: item.id });
        },
        onDrop: (e: DragEvent) => {
            e.preventDefault();
            dropOn(item);
        },
        onDragEnd: () => setDrag(null),
        over: drag?.over === item.id && drag.id !== item.id ? (dropsBelow(item) ? ('below' as const) : ('above' as const)) : null,
        dragging: drag?.id === item.id,
    });

    const handleRename = (e: FormEvent) => {
        e.preventDefault();
        if (!renameVal.trim()) return;
        router.put(route('task-lists.update', listArgs), { name: renameVal.trim(), description: descVal }, {
            preserveScroll: true,
            onSuccess: () => setRenameOpen(false),
            onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Could not rename the list.'),
        });
    };

    return (
        <AppLayout>
            <Head title={`${taskList.name} — ${project.name}`} />

            <div className="flex h-full flex-col">
                <div className="flex shrink-0 items-center justify-between gap-4 border-b bg-background px-6 py-3">
                    <nav className="flex min-w-0 items-center gap-2 text-sm" aria-label="Breadcrumb">
                        <Link href={route('projects.show', [workspace.slug, project.slug])} className="truncate text-muted-foreground hover:text-foreground">
                            {project.name}
                        </Link>
                        <ChevronRight className="size-3.5 shrink-0 text-muted-foreground" />
                        <span className="truncate font-medium">{taskList.name}</span>
                    </nav>
                    <div className="flex shrink-0 items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            className={cn('h-8 gap-1.5 px-2.5 text-xs', isWatching && 'border-primary/20 bg-primary/5 text-primary')}
                            onClick={() => router.post(route('task-lists.watch', listArgs), { watch: !isWatching }, {
                                preserveScroll: true,
                                onError: () => toast.error('Could not change watching.'),
                            })}
                        >
                            {isWatching ? <><BellRing className="size-3.5" /> Watching</> : <><Bell className="size-3.5" /> Watch</>}
                        </Button>
                        {canManage && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" size="icon" className="size-8" aria-label="List actions">
                                        <MoreHorizontal className="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-44">
                                    <DropdownMenuItem onClick={() => { setRenameVal(taskList.name); setDescVal(taskList.description ?? ''); setRenameOpen(true); }}>
                                        <Edit2 className="mr-2 size-4" /> Rename
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem className="text-destructive focus:text-destructive" onClick={() => setDeleteListOpen(true)}>
                                        <Trash2 className="mr-2 size-4" /> Delete list
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto">
                    <div className="mx-auto max-w-3xl px-4 py-8 sm:px-6">
                        <header className="mb-6 px-1.5">
                            <h1 className="flex items-center gap-2.5 text-2xl font-bold tracking-tight">
                                <ListChecks className="size-6 text-primary" /> {taskList.name}
                            </h1>
                            {taskList.description && <p className="mt-1 text-sm text-muted-foreground">{taskList.description}</p>}
                            {items.length > 0 && (
                                <div className="mt-4 flex items-center gap-3">
                                    <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuenow={progress} aria-valuemin={0} aria-valuemax={100} aria-label="Tasks done">
                                        <div className="h-full rounded-full bg-brand-green transition-[width] duration-500" style={{ width: `${progress}%` }} />
                                    </div>
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {completed.length} of {items.length} done
                                        {overdue > 0 && <span className="text-destructive"> · {overdue} overdue</span>}
                                    </span>
                                </div>
                            )}
                        </header>

                        <div className="divide-y divide-border/60">
                            {open.map((item) => (
                                <TaskRow key={item.id} item={item} members={members} canManage={canManage} handlers={handlers} drag={dragProps(item)} />
                            ))}
                        </div>

                        {canManage && (
                            <div className="mt-1">
                                <TaskComposer storeUrl={route('task-lists.items.store', listArgs)} members={members} />
                            </div>
                        )}

                        {open.length === 0 && (
                            <div className="px-1.5 py-10 text-center">
                                <ListChecks className="mx-auto mb-3 size-10 text-muted-foreground/30" />
                                <p className="text-sm font-medium">{completed.length ? 'All done 🎉' : 'No tasks yet'}</p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {completed.length ? 'Nothing left on this list.' : canManage ? 'Add the first one above — dates, people and reminders are optional.' : 'Nothing has been added to this list yet.'}
                                </p>
                            </div>
                        )}

                        {completed.length > 0 && (
                            <section className="mt-8">
                                <button
                                    type="button"
                                    onClick={() => setShowCompleted((v) => !v)}
                                    aria-expanded={showCompleted}
                                    className="flex w-full items-center gap-1.5 border-b px-1.5 pb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground hover:text-foreground"
                                >
                                    {showCompleted ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
                                    Completed · {completed.length}
                                </button>
                                {showCompleted && (
                                    <div className="divide-y divide-border/60">
                                        {completed.map((item) => (
                                            <TaskRow key={item.id} item={item} members={members} canManage={canManage} handlers={handlers} />
                                        ))}
                                    </div>
                                )}
                            </section>
                        )}
                    </div>
                </div>
            </div>

            <TaskDetailSheet item={openItem} members={members} canManage={canManage} handlers={handlers} onClose={() => {
                setOpenId(null);
                if (window.location.hash.startsWith('#task-')) history.replaceState(null, '', window.location.pathname + window.location.search);
            }} />

            <AlertDialog open={!!deleting} onOpenChange={(o) => { if (!o) setDeleting(null); }}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete task?</AlertDialogTitle>
                        <AlertDialogDescription>“{deleting?.title}” will be removed from this list.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction className="bg-destructive hover:bg-destructive/90" onClick={confirmDelete}>Delete</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <Dialog open={renameOpen} onOpenChange={setRenameOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Rename list</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleRename} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="list-name">Name</Label>
                            <Input id="list-name" value={renameVal} onChange={(e) => setRenameVal(e.target.value)} required autoFocus maxLength={255} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="list-description">Description <span className="font-normal text-muted-foreground">(optional)</span></Label>
                            <Textarea id="list-description" value={descVal} onChange={(e) => setDescVal(e.target.value)} rows={2} maxLength={1000} placeholder="What is this list for?" className="resize-none" />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setRenameOpen(false)}>Cancel</Button>
                            <Button type="submit">Save</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={deleteListOpen} onOpenChange={setDeleteListOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete list?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This will permanently delete <strong>{taskList.name}</strong> and all its tasks. This cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction className="bg-destructive hover:bg-destructive/90" onClick={() => router.delete(route('task-lists.destroy', listArgs))}>
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
