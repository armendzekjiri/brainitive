import { DragEvent, FormEvent, useEffect, useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { addWeeks, format } from 'date-fns';
import { ChevronDown, Loader2, MoreHorizontal, Plus, Search, Target } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { BoardHeader } from '@/components/board/BoardHeader';
import { AssigneeAvatar, PriorityIndicator, StatusLozenge, statusCategory } from '@/components/board/ItemPrimitives';
import { ItemTypeIcon } from '@/components/ItemTypeIcon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Board, BoardColumn, Item, PageProps, Project, Workspace } from '@/types';
import { cn } from '@/lib/utils';

interface Sprint {
    id: number;
    name: string;
    goal?: string | null;
    status: 'planned' | 'active' | 'completed';
    start_date?: string | null;
    end_date?: string | null;
    items: Item[];
}

interface Props extends PageProps {
    workspace: Workspace;
    project: Project;
    board: Board & { columns: BoardColumn[] };
    sprints: Sprint[];
    backlogItems: Item[];
    completedSprintCount: number;
    canManage: boolean;
}

/** null = the backlog list. */
type ListId = number | null;

const DURATIONS = [
    { value: '1', label: '1 week' },
    { value: '2', label: '2 weeks' },
    { value: '3', label: '3 weeks' },
    { value: '4', label: '4 weeks' },
    { value: 'custom', label: 'Custom' },
];

const points = (items: Item[]) => items.reduce((sum, item) => sum + (item.story_points ?? 0), 0);

export default function BacklogPage({ workspace, project, board, sprints, backlogItems, completedSprintCount, canManage }: Props) {
    const params = [workspace.slug, project.slug, board.slug];

    // Local copy for optimistic drag & drop; re-synced whenever the server answers
    const buildLists = () => {
        const lists = new Map<ListId, Item[]>();
        sprints.forEach((sprint) => lists.set(sprint.id, sprint.items));
        lists.set(null, backlogItems);
        return lists;
    };
    const [lists, setLists] = useState<Map<ListId, Item[]>>(buildLists);
    useEffect(() => setLists(buildLists()), [sprints, backlogItems]);

    const [search, setSearch] = useState('');
    const [collapsed, setCollapsed] = useState<Set<ListId>>(new Set());
    const [dragged, setDragged] = useState<{ id: number; from: ListId } | null>(null);
    const [dropTarget, setDropTarget] = useState<{ list: ListId; beforeId: number | null } | null>(null);
    const [startSprint, setStartSprint] = useState<Sprint | null>(null);
    const [editSprint, setEditSprint] = useState<Sprint | null>(null);
    const [deleteSprint, setDeleteSprint] = useState<Sprint | null>(null);

    const activeSprint = sprints.find((sprint) => sprint.status === 'active');
    const matches = (item: Item) => {
        const query = search.trim().toLowerCase();
        return !query || item.title.toLowerCase().includes(query) || item.key.toLowerCase().includes(query);
    };

    const toggle = (list: ListId) => setCollapsed((current) => {
        const next = new Set(current);
        next.has(list) ? next.delete(list) : next.add(list);
        return next;
    });

    const handleDrop = (e: DragEvent, list: ListId) => {
        e.preventDefault();
        if (!dragged || !canManage) return;
        const beforeId = dropTarget?.list === list ? dropTarget.beforeId : null;
        const source = lists.get(dragged.from) ?? [];
        const item = source.find((i) => i.id === dragged.id);
        if (!item || beforeId === item.id) {
            setDragged(null);
            setDropTarget(null);
            return;
        }

        const target = (dragged.from === list ? source : lists.get(list) ?? []).filter((i) => i.id !== item.id);
        const index = beforeId === null ? target.length : Math.max(0, target.findIndex((i) => i.id === beforeId));
        target.splice(index, 0, { ...item, sprint_id: list });

        const next = new Map(lists);
        if (dragged.from !== list) next.set(dragged.from, source.filter((i) => i.id !== item.id));
        next.set(list, target);
        setLists(next);
        setDragged(null);
        setDropTarget(null);

        router.post(route('boards.backlog.rank', params), { sprint_id: list, item_ids: target.map((i) => i.id) }, {
            preserveScroll: true,
            preserveState: true,
            onError: () => setLists(buildLists()),
        });
    };

    const renderRows = (list: ListId, items: Item[]) => {
        const visible = items.filter(matches);
        return (
            <div
                className={cn('min-h-[44px] rounded-[4px] transition-colors', dragged && 'bg-muted/30', dropTarget?.list === list && dropTarget.beforeId === null && 'ring-2 ring-primary/40')}
                onDragOver={(e) => {
                    if (!dragged) return;
                    e.preventDefault();
                    if (dropTarget?.list !== list || (e.target as HTMLElement).dataset.dropEnd) setDropTarget({ list, beforeId: null });
                }}
                onDrop={(e) => handleDrop(e, list)}
            >
                {visible.length === 0 ? (
                    <p data-drop-end="1" className="px-3 py-3 text-[13px] text-muted-foreground border border-dashed border-border rounded-[4px]">
                        {search ? 'No matching issues.' : list === null ? 'Your backlog is empty.' : 'Plan this sprint by dragging issues here from the backlog.'}
                    </p>
                ) : (
                    <ul className="divide-y divide-border rounded-[4px] border border-border bg-card">
                        {visible.map((item) => (
                            <BacklogRow
                                key={item.id}
                                item={item}
                                columns={board.columns}
                                href={route('boards.items.show', [...params, item.id])}
                                draggable={canManage}
                                isDropBefore={dropTarget?.list === list && dropTarget.beforeId === item.id}
                                onDragStart={() => setDragged({ id: item.id, from: list })}
                                onDragEnd={() => { setDragged(null); setDropTarget(null); }}
                                onDragOverRow={() => dragged && setDropTarget({ list, beforeId: item.id })}
                            />
                        ))}
                    </ul>
                )}
            </div>
        );
    };

    const backlog = lists.get(null) ?? [];

    return (
        <AppLayout>
            <Head title={`Backlog - ${board.name}`} />
            <div className="flex flex-col h-full overflow-hidden bg-card">
                <BoardHeader
                    workspace={workspace}
                    project={project}
                    board={board}
                    activeTab="backlog"
                    actions={canManage ? (
                        <Button size="sm" variant="secondary" className="h-8 rounded-[4px]" onClick={() => router.post(route('boards.sprints.store', params), {}, { preserveScroll: true })}>
                            Create sprint
                        </Button>
                    ) : undefined}
                />

                <div className="flex items-center gap-3 px-6 py-3 shrink-0">
                    <div className="relative">
                        <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 size-[14px] text-muted-foreground" />
                        <Input type="search" placeholder="Search backlog" value={search} onChange={(e) => setSearch(e.target.value)} className="pl-8 h-8 w-52 text-[13px]" />
                    </div>
                    {completedSprintCount > 0 && (
                        <Link href={route('boards.reports', params)} className="text-[13px] text-muted-foreground hover:text-foreground hover:underline">
                            {completedSprintCount} completed sprint{completedSprintCount === 1 ? '' : 's'}
                        </Link>
                    )}
                </div>

                <div className="flex-1 overflow-y-auto px-6 pb-10 space-y-4">
                    {sprints.map((sprint) => {
                        const items = lists.get(sprint.id) ?? [];
                        const isActive = sprint.status === 'active';
                        return (
                            <section key={sprint.id} className="rounded-md bg-muted/50 dark:bg-secondary/30 p-2">
                                <header className="flex flex-wrap items-center gap-x-3 gap-y-1 px-1.5 py-1.5">
                                    <button type="button" onClick={() => toggle(sprint.id)} className="inline-flex items-center gap-1.5 text-[14px] font-semibold text-foreground">
                                        <ChevronDown className={cn('size-4 text-muted-foreground transition-transform', collapsed.has(sprint.id) && '-rotate-90')} />
                                        {sprint.name}
                                    </button>
                                    {sprint.start_date && sprint.end_date && (
                                        <span className="text-[13px] text-muted-foreground">
                                            {format(new Date(sprint.start_date), 'MMM d')} – {format(new Date(sprint.end_date), 'MMM d')}
                                        </span>
                                    )}
                                    <span className="text-[13px] text-muted-foreground">
                                        ({items.length} issue{items.length === 1 ? '' : 's'}{points(items) ? ` · ${points(items)} pts` : ''})
                                    </span>
                                    {isActive && <StatusLozenge name="Active" category="in_progress" />}
                                    <span className="flex-1" />
                                    {canManage && (isActive ? (
                                        <Link href={route('boards.show', params)}>
                                            <Button size="sm" variant="secondary" className="h-8 rounded-[4px]">Go to board</Button>
                                        </Link>
                                    ) : (
                                        <Button
                                            size="sm"
                                            className="h-8 rounded-[4px]"
                                            disabled={!!activeSprint || items.length === 0}
                                            title={activeSprint ? 'Complete the active sprint first' : items.length === 0 ? 'Add issues to the sprint first' : undefined}
                                            onClick={() => setStartSprint(sprint)}
                                        >
                                            Start sprint
                                        </Button>
                                    ))}
                                    {canManage && (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button size="icon" variant="ghost" className="size-8" aria-label="Sprint actions"><MoreHorizontal className="size-4" /></Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem onClick={() => setEditSprint(sprint)}>Edit sprint</DropdownMenuItem>
                                                {!isActive && (
                                                    <>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem className="text-destructive focus:text-destructive" onClick={() => setDeleteSprint(sprint)}>Delete sprint</DropdownMenuItem>
                                                    </>
                                                )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    )}
                                </header>
                                {sprint.goal && !collapsed.has(sprint.id) && (
                                    <p className="flex items-center gap-1.5 px-2 pb-2 text-[13px] text-muted-foreground"><Target className="size-3.5" />{sprint.goal}</p>
                                )}
                                {!collapsed.has(sprint.id) && renderRows(sprint.id, items)}
                            </section>
                        );
                    })}

                    {/* Backlog */}
                    <section className="rounded-md p-2">
                        <header className="flex items-center gap-3 px-1.5 py-1.5">
                            <button type="button" onClick={() => toggle(null)} className="inline-flex items-center gap-1.5 text-[14px] font-semibold text-foreground">
                                <ChevronDown className={cn('size-4 text-muted-foreground transition-transform', collapsed.has(null) && '-rotate-90')} />
                                Backlog
                            </button>
                            <span className="text-[13px] text-muted-foreground">({backlog.length} issue{backlog.length === 1 ? '' : 's'}{points(backlog) ? ` · ${points(backlog)} pts` : ''})</span>
                        </header>
                        {!collapsed.has(null) && (
                            <>
                                {renderRows(null, backlog)}
                                {canManage && <CreateBacklogIssue params={params} columns={board.columns} />}
                            </>
                        )}
                    </section>
                </div>
            </div>

            {startSprint && <StartSprintDialog sprint={startSprint} params={params} onClose={() => setStartSprint(null)} />}
            {editSprint && <EditSprintDialog sprint={editSprint} params={params} onClose={() => setEditSprint(null)} />}
            <Dialog open={!!deleteSprint} onOpenChange={(open) => !open && setDeleteSprint(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Delete {deleteSprint?.name}?</DialogTitle>
                        <DialogDescription>Its issues go back to the backlog. Nothing else is deleted.</DialogDescription>
                    </DialogHeader>
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={() => setDeleteSprint(null)}>Cancel</Button>
                        <Button
                            variant="destructive"
                            onClick={() => deleteSprint && router.delete(route('boards.sprints.destroy', [...params, deleteSprint.id]), { preserveScroll: true, onSuccess: () => setDeleteSprint(null) })}
                        >
                            Delete sprint
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function BacklogRow({ item, columns, href, draggable, isDropBefore, onDragStart, onDragEnd, onDragOverRow }: {
    item: Item;
    columns: BoardColumn[];
    href: string;
    draggable: boolean;
    isDropBefore: boolean;
    onDragStart: () => void;
    onDragEnd: () => void;
    onDragOverRow: () => void;
}) {
    const column = columns.find((c) => c.id === item.column_id);
    const done = !!item.completed_at;
    return (
        <li
            draggable={draggable}
            onDragStart={(e) => { e.dataTransfer.effectAllowed = 'move'; onDragStart(); }}
            onDragEnd={onDragEnd}
            onDragOver={(e) => { e.preventDefault(); e.stopPropagation(); onDragOverRow(); }}
            className={cn(
                'group relative flex items-center gap-2.5 px-3 h-10 text-[14px] hover:bg-muted/50 transition-colors',
                draggable && 'cursor-grab active:cursor-grabbing',
                isDropBefore && 'before:absolute before:inset-x-0 before:-top-px before:h-0.5 before:bg-primary',
            )}
        >
            <ItemTypeIcon type={item.item_type} className="size-4 shrink-0" />
            <Link href={href} className={cn('shrink-0 text-[13px] font-medium text-muted-foreground hover:text-primary hover:underline', done && 'line-through')}>{item.key}</Link>
            <Link href={href} className="min-w-0 flex-1 truncate text-foreground hover:underline">{item.title}</Link>
            {item.labels?.slice(0, 2).map((label) => (
                <span key={label.id} className="hidden md:inline-flex h-5 items-center px-1.5 rounded-[3px] bg-muted text-[11px] font-medium text-foreground/75">{label.name}</span>
            ))}
            {column && <StatusLozenge name={column.name} category={statusCategory(column, columns)} className="shrink-0" />}
            <span className="inline-grid place-items-center min-w-[24px] h-5 px-1.5 rounded-full bg-muted text-[11px] font-semibold text-foreground/80" title="Story points">
                {item.story_points ?? '–'}
            </span>
            {item.priority && <PriorityIndicator slug={item.priority.slug} />}
            <AssigneeAvatar user={item.assignee} size={24} />
        </li>
    );
}

function CreateBacklogIssue({ params, columns }: { params: string[]; columns: BoardColumn[] }) {
    const [open, setOpen] = useState(false);
    const [title, setTitle] = useState('');
    const [saving, setSaving] = useState(false);
    const firstColumn = useMemo(() => [...columns].sort((a, b) => a.position - b.position)[0], [columns]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!title.trim() || !firstColumn) return;
        setSaving(true);
        router.post(route('boards.items.store', params), { title: title.trim(), column_id: firstColumn.id, sprint_id: null }, {
            preserveScroll: true,
            onSuccess: () => setTitle(''),
            onFinish: () => setSaving(false),
        });
    };

    if (!open) {
        return (
            <button type="button" onClick={() => setOpen(true)} className="mt-1 flex w-full items-center gap-2 rounded-[4px] px-3 h-9 text-[14px] text-muted-foreground hover:bg-muted/60 hover:text-foreground">
                <Plus className="size-4" /> Create issue
            </button>
        );
    }
    return (
        <form onSubmit={submit} className="mt-1 flex items-center gap-2">
            <Input autoFocus value={title} onChange={(e) => setTitle(e.target.value)} onKeyDown={(e) => e.key === 'Escape' && setOpen(false)} placeholder="What needs to be done?" className="h-9" />
            <Button type="submit" size="sm" className="h-9" disabled={saving || !title.trim()}>{saving ? <Loader2 className="size-4 animate-spin" /> : 'Create'}</Button>
        </form>
    );
}

function StartSprintDialog({ sprint, params, onClose }: { sprint: Sprint; params: string[]; onClose: () => void }) {
    const today = format(new Date(), 'yyyy-MM-dd');
    const [name, setName] = useState(sprint.name);
    const [duration, setDuration] = useState('2');
    const [startDate, setStartDate] = useState(today);
    const [endDate, setEndDate] = useState(format(addWeeks(new Date(), 2), 'yyyy-MM-dd'));
    const [goal, setGoal] = useState(sprint.goal ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (duration !== 'custom') setEndDate(format(addWeeks(new Date(startDate + 'T12:00:00'), Number(duration)), 'yyyy-MM-dd'));
    }, [duration, startDate]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setSaving(true);
        router.post(route('boards.sprints.start', [...params, sprint.id]), { name, goal: goal || null, start_date: startDate, end_date: endDate }, {
            onError: (errs) => setErrors(errs),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Start sprint</DialogTitle>
                    <DialogDescription>{sprint.items.length} issue{sprint.items.length === 1 ? '' : 's'} will be included in this sprint.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="sprint-name">Sprint name</Label>
                        <Input id="sprint-name" value={name} onChange={(e) => setName(e.target.value)} required />
                    </div>
                    <div className="space-y-1.5">
                        <Label>Duration</Label>
                        <Select value={duration} onValueChange={setDuration}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>{DURATIONS.map((d) => <SelectItem key={d.value} value={d.value}>{d.label}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="sprint-start">Start date</Label>
                            <Input id="sprint-start" type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} required />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="sprint-end">End date</Label>
                            <Input id="sprint-end" type="date" value={endDate} onChange={(e) => { setDuration('custom'); setEndDate(e.target.value); }} required />
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="sprint-goal">Sprint goal</Label>
                        <Textarea id="sprint-goal" rows={3} value={goal} onChange={(e) => setGoal(e.target.value)} placeholder="What should this sprint achieve?" />
                    </div>
                    {Object.values(errors).map((message) => <p key={message} className="text-sm text-destructive">{message}</p>)}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={saving}>{saving && <Loader2 className="size-4 mr-1.5 animate-spin" />}Start</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditSprintDialog({ sprint, params, onClose }: { sprint: Sprint; params: string[]; onClose: () => void }) {
    const [name, setName] = useState(sprint.name);
    const [goal, setGoal] = useState(sprint.goal ?? '');
    const [startDate, setStartDate] = useState(sprint.start_date?.slice(0, 10) ?? '');
    const [endDate, setEndDate] = useState(sprint.end_date?.slice(0, 10) ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.patch(route('boards.sprints.update', [...params, sprint.id]), {
            name, goal: goal || null, start_date: startDate || null, end_date: endDate || null,
        }, { preserveScroll: true, onSuccess: onClose, onError: (errs) => setErrors(errs) });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Edit sprint</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="edit-sprint-name">Sprint name</Label>
                        <Input id="edit-sprint-name" value={name} onChange={(e) => setName(e.target.value)} required />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-sprint-start">Start date</Label>
                            <Input id="edit-sprint-start" type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-sprint-end">End date</Label>
                            <Input id="edit-sprint-end" type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="edit-sprint-goal">Sprint goal</Label>
                        <Textarea id="edit-sprint-goal" rows={3} value={goal} onChange={(e) => setGoal(e.target.value)} />
                    </div>
                    {Object.values(errors).map((message) => <p key={message} className="text-sm text-destructive">{message}</p>)}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                        <Button type="submit">Save</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
