import { useMemo, useState, type FormEvent, type KeyboardEvent } from 'react';
import { ArrowDown, ArrowUp, Check, ChevronDown, ChevronRight, ListChecks, Sun } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { addToMyDay, createTask, moveTask, myDayKey, updateTask, MY_DAY_KEY } from '@/lib/offline/actions';
import { pendingKeys, taskKey, useOfflineState } from '@/lib/offline/store';
import { cn } from '@/lib/utils';
import type { OfflineMyDaySnapshot, OfflineTask, OfflineTaskListSnapshot } from '@/types';
import NotSyncedBadge from './NotSyncedBadge';
import { useSnapshot } from './useSnapshot';

/** A task list from this device (BRAI-82): add, complete, rename, reorder; synced later. */
export default function OfflineTaskListView({ list }: { list: OfflineTaskListSnapshot }) {
    const state = useOfflineState();
    const pending = pendingKeys(state);
    const { data: day } = useSnapshot<OfflineMyDaySnapshot>(MY_DAY_KEY);
    const [title, setTitle] = useState('');
    const [showDone, setShowDone] = useState(false);
    const open = useMemo(() => list.tasks.filter((t) => t.status !== 'done').sort((a, b) => a.position - b.position), [list.tasks]);
    const done = useMemo(() => list.tasks.filter((t) => t.status === 'done'), [list.tasks]);
    const onMyDay = new Set((day?.rows ?? []).map((r) => myDayKey(r)));

    const add = async (e: FormEvent) => {
        e.preventDefault();
        const value = title.trim();
        if (!value) return;
        setTitle('');
        await createTask(list, value);
    };

    const plan = async (task: OfflineTask) => {
        await addToMyDay({
            work_type: 'list',
            work_id: task.id,
            work_ref: task.id ? null : task.ref ?? null,
            title: task.title,
            key: list.project.key && task.id ? `${list.project.key}-L${task.id}` : '',
            url: list.url,
            project_name: list.project.name,
            done: task.status === 'done',
        });
        toast.success('Added to My Day');
    };

    const row = (task: OfflineTask, index: number) => (
        <TaskRow
            key={task.id ?? task.ref}
            task={task}
            canManage={list.canManage}
            pending={pending.has(taskKey(task))}
            planned={onMyDay.has(`my_day:list:${task.id ?? task.ref}`)}
            first={index === 0}
            last={index === open.length - 1}
            onToggle={() => void updateTask(list, task, { status: task.status === 'done' ? 'todo' : 'done' })}
            onRename={(value) => void updateTask(list, task, { title: value })}
            onMove={(dir) => void moveTask(list, task, dir)}
            onPlan={() => void plan(task)}
        />
    );

    return (
        <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6">
            <p className="truncate text-sm text-muted-foreground">{list.workspace.name} · {list.project.name}</p>
            <h1 className="mt-1 flex items-center gap-2.5 text-2xl font-bold tracking-tight">
                <ListChecks className="size-6 shrink-0 text-primary" /> <span className="min-w-0 break-words">{list.name}</span>
            </h1>
            {!list.canManage && <p className="mt-2 text-sm text-muted-foreground">You can view this list; changing it needs edit rights.</p>}

            <ul className="mt-6 divide-y divide-border/60" aria-label="Open tasks">
                {open.map(row)}
            </ul>

            {list.canManage && (
                <form onSubmit={add} className="mt-2 flex gap-2">
                    <Input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Add a task" aria-label="Add a task" maxLength={500} />
                    <Button type="submit" disabled={!title.trim()}>Add</Button>
                </form>
            )}

            {open.length === 0 && <p className="py-8 text-center text-sm text-muted-foreground">{done.length ? 'All done 🎉' : 'No tasks yet.'}</p>}

            {done.length > 0 && (
                <section className="mt-8">
                    <button
                        type="button"
                        onClick={() => setShowDone((v) => !v)}
                        aria-expanded={showDone}
                        className="flex w-full items-center gap-1.5 border-b pb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground hover:text-foreground"
                    >
                        {showDone ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
                        Completed · {done.length}
                    </button>
                    {showDone && <ul className="divide-y divide-border/60" aria-label="Completed tasks">{done.map(row)}</ul>}
                </section>
            )}
        </div>
    );
}

function TaskRow({ task, canManage, pending, planned, first, last, onToggle, onRename, onMove, onPlan }: {
    task: OfflineTask; canManage: boolean; pending: boolean; planned: boolean; first: boolean; last: boolean;
    onToggle: () => void; onRename: (title: string) => void; onMove: (dir: -1 | 1) => void; onPlan: () => void;
}) {
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(task.title);
    const isDone = task.status === 'done';

    const save = () => {
        setEditing(false);
        const next = value.trim();
        if (next && next !== task.title) onRename(next);
        else setValue(task.title);
    };
    const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') save();
        if (e.key === 'Escape') {
            setValue(task.title);
            setEditing(false);
        }
    };

    return (
        <li className="flex items-center gap-2 py-2">
            <button
                type="button"
                role="checkbox"
                aria-checked={isDone}
                aria-label={isDone ? `Reopen “${task.title}”` : `Mark “${task.title}” as done`}
                disabled={!canManage}
                onClick={onToggle}
                className={cn(
                    'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors disabled:opacity-50',
                    isDone ? 'border-brand-green bg-brand-green text-white' : 'border-muted-foreground/40 hover:border-primary',
                )}
            >
                {isDone && <Check className="h-3 w-3" />}
            </button>
            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                {editing ? (
                    <Input autoFocus value={value} onChange={(e) => setValue(e.target.value)} onBlur={save} onKeyDown={onKey} aria-label="Task title" maxLength={500} className="h-8" />
                ) : (
                    <button
                        type="button"
                        disabled={!canManage}
                        onClick={() => { setValue(task.title); setEditing(true); }}
                        className={cn('min-w-0 break-words text-left text-sm disabled:cursor-default', isDone && 'text-muted-foreground line-through')}
                        title={canManage ? 'Rename' : undefined}
                    >
                        {task.title}
                    </button>
                )}
                {pending && <NotSyncedBadge />}
            </div>
            {canManage && !isDone && (
                <div className="flex shrink-0 items-center">
                    <Button variant="ghost" size="icon" className="size-8" aria-label={`Move “${task.title}” up`} disabled={first} onClick={() => onMove(-1)}>
                        <ArrowUp className="size-4" />
                    </Button>
                    <Button variant="ghost" size="icon" className="size-8" aria-label={`Move “${task.title}” down`} disabled={last} onClick={() => onMove(1)}>
                        <ArrowDown className="size-4" />
                    </Button>
                </div>
            )}
            <Button
                variant="ghost"
                size="icon"
                className={cn('size-8 shrink-0', planned && 'text-amber-500')}
                aria-label={planned ? `“${task.title}” is on My Day` : `Add “${task.title}” to My Day`}
                disabled={planned}
                onClick={onPlan}
            >
                <Sun className="size-4" />
            </Button>
        </li>
    );
}
