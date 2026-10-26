/**
 * Offline mode (BRAI-82): what the offline views (and quick capture) do. Every action first queues
 * one change with the values it was made against, then updates the local copy — so nothing can
 * look saved without being on its way. A failed write says so (toast) and returns false.
 */
import { toast } from 'sonner';
import type {
    OfflineIssueSnapshot, OfflineMutation, OfflineMyDayRow, OfflineMyDaySnapshot, OfflineTask, OfflineTaskListSnapshot,
} from '@/types';
import { enqueue, getOfflineState, issueKey, listSnapshots, newId, saveSnapshot, taskKey, updateSnapshot } from './store';
import { syncNow } from './sync';

export const MY_DAY_KEY = 'myday:today';

function kick(): void {
    if (typeof navigator === 'undefined' || navigator.onLine) void syncNow();
}

/** Run an action; storage errors (quota, a database deleted by another tab) become a toast. */
async function guarded(run: () => Promise<void>): Promise<boolean> {
    try {
        await run();
        kick();
        return true;
    } catch {
        toast.error('Could not save this on the device.');
        return false;
    }
}

/** Queue a change; a missing database (offline mode turned off meanwhile) is an error, not a no-op. */
async function queue(mutation: Parameters<typeof enqueue>[0]): Promise<OfflineMutation | null> {
    if (!getOfflineState().userId) throw new Error('Offline mode is off on this device.');
    return enqueue(mutation);
}

const target = (task: OfflineTask): OfflineMutation['target'] => (task.id ? { id: task.id } : { ref: task.ref ?? null });
const sameTask = (a: OfflineTask, b: OfflineTask) => (a.id !== null && a.id === b.id) || (!!a.ref && a.ref === b.ref);
export const myDayKey = (row: Pick<OfflineMyDayRow, 'work_type' | 'work_id' | 'work_ref'>) => `my_day:${row.work_type}:${row.work_id ?? row.work_ref}`;
const myWorkUrl = () => route('my-work.index', {}, false);

// ── Task lists ───────────────────────────────────────────────────────────────

export function createTask(list: OfflineTaskListSnapshot, title: string): Promise<boolean> {
    return guarded(async () => {
        const id = newId();
        await queue({
            id,
            type: 'list_task.create',
            data: { task_list_id: list.id, title },
            entity: { kind: 'list_task', key: `list_task:ref:${id}`, label: title, parent_id: list.id, url: list.url },
        });
        const position = Math.max(0, ...list.tasks.map((t) => t.position)) + 1;
        await updateSnapshot<OfflineTaskListSnapshot>(`list:${list.id}`, (l) => ({
            ...l,
            tasks: [...l.tasks, { id: null, ref: id, title, status: 'todo', position, due_date: null, updated_at: null }],
        }));
    });
}

export function updateTask(list: OfflineTaskListSnapshot, task: OfflineTask, changes: Partial<Pick<OfflineTask, 'title' | 'status'>>): Promise<boolean> {
    return guarded(async () => {
        const fields = Object.fromEntries(Object.keys(changes).map((k) => [k, task[k as 'title' | 'status']]));
        await queue({
            type: 'list_task.update',
            target: target(task),
            base: { updated_at: task.updated_at ?? null, fields },
            data: changes,
            entity: { kind: 'list_task', key: taskKey(task), label: changes.title ?? task.title, parent_id: list.id, url: list.url },
        });
        await updateSnapshot<OfflineTaskListSnapshot>(`list:${list.id}`, (l) => ({
            ...l,
            tasks: l.tasks.map((t) => (sameTask(t, task) ? { ...t, ...changes } : t)),
        }));
        if (changes.status) await setMyDayDone('list', task.id, task.ref ?? null, changes.status === 'done');
    });
}

/** Move an open task one place up or down (the whole order is sent; order has no conflicts). */
export function moveTask(list: OfflineTaskListSnapshot, task: OfflineTask, direction: -1 | 1): Promise<boolean> {
    return guarded(async () => {
        const open = list.tasks.filter((t) => t.status !== 'done').sort((a, b) => a.position - b.position);
        const index = open.findIndex((t) => sameTask(t, task));
        const swap = index + direction;
        if (index < 0 || swap < 0 || swap >= open.length) return;
        [open[index], open[swap]] = [open[swap], open[index]];
        await queue({
            type: 'list_task.reorder',
            data: { task_list_id: list.id, items: open.map((t) => (t.id ? { id: t.id } : { ref: t.ref })) },
            entity: { kind: 'task_list', key: `task_list:${list.id}:order`, label: `Order of ${list.name}`, parent_id: list.id, url: list.url },
        });
        await updateSnapshot<OfflineTaskListSnapshot>(`list:${list.id}`, (l) => ({
            ...l,
            tasks: l.tasks.map((t) => {
                const at = open.findIndex((o) => sameTask(o, t));
                return at >= 0 ? { ...t, position: at } : t;
            }),
        }));
    });
}

// ── Issues ───────────────────────────────────────────────────────────────────

export type IssueChanges = Partial<Pick<OfflineIssueSnapshot, 'title' | 'column_id' | 'priority_id' | 'assigned_to' | 'due_date'>>;

export function updateIssue(issue: OfflineIssueSnapshot, changes: IssueChanges): Promise<boolean> {
    return guarded(() => queueIssueUpdate(issue, changes));
}

async function queueIssueUpdate(issue: OfflineIssueSnapshot, changes: IssueChanges): Promise<void> {
    const fields = Object.fromEntries(Object.keys(changes).map((k) => [k, issue[k as keyof IssueChanges]]));
    await queue({
        type: 'issue.update',
        target: { id: issue.id },
        base: { updated_at: issue.updated_at, fields },
        data: changes,
        entity: { kind: 'issue', key: issueKey(issue.id), label: `${issue.key} ${changes.title ?? issue.title}`, parent_id: issue.board.id, url: issue.url },
    });
    await updateSnapshot<OfflineIssueSnapshot>(`issue:${issue.id}`, (i) => ({ ...i, ...changes }));
    if (changes.column_id !== undefined) {
        const done = !!issue.columns.find((c) => c.id === changes.column_id)?.is_done_column;
        await setMyDayDone('board', issue.id, null, done, changes.column_id);
    }
}

export function commentOnIssue(issue: OfflineIssueSnapshot, text: string): Promise<boolean> {
    return guarded(async () => {
        const id = newId();
        await queue({
            id,
            type: 'issue.comment',
            target: { id: issue.id },
            data: { body: text },
            entity: { kind: 'issue', key: issueKey(issue.id), label: `Comment on ${issue.key}`, parent_id: issue.board.id, url: issue.url },
        });
        await updateSnapshot<OfflineIssueSnapshot>(`issue:${issue.id}`, (i) => ({
            ...i,
            comments: [{ id: null, ref: id, author: 'You', text, created_at: new Date().toISOString() }, ...i.comments],
        }));
    });
}

// ── Quick capture ────────────────────────────────────────────────────────────

export function captureOffline(data: { text: string; url?: string | null; intended_type?: string | null; source?: string | null }): Promise<boolean> {
    return guarded(async () => {
        const id = newId();
        const clean = Object.fromEntries(Object.entries(data).filter(([, v]) => v !== '' && v !== null && v !== undefined));
        await queue({
            id,
            type: 'capture.create',
            data: clean,
            entity: { kind: 'capture', key: `capture:${id}`, label: data.text.split('\n')[0].slice(0, 80), url: route('captures.index', {}, false) },
        });
    });
}

// ── My Day ───────────────────────────────────────────────────────────────────

const workData = (row: OfflineMyDayRow) => ({ work_type: row.work_type, ...(row.work_id ? { work_id: row.work_id } : { work_ref: row.work_ref }) });

export function addToMyDay(row: OfflineMyDayRow): Promise<boolean> {
    return guarded(async () => {
        await queue({ type: 'my_day.add', data: workData(row), entity: { kind: 'my_day', key: myDayKey(row), label: row.title, url: myWorkUrl() } });
        const day = await updateSnapshot<OfflineMyDaySnapshot>(MY_DAY_KEY, (d) =>
            d.rows.some((r) => myDayKey(r) === myDayKey(row)) ? d : { ...d, rows: [...d.rows, row] });
        if (!day) await saveSnapshot<OfflineMyDaySnapshot>('myday', 'today', { date: new Date().toLocaleDateString('en-CA'), rows: [row] });
    });
}

export function removeFromMyDay(row: OfflineMyDayRow): Promise<boolean> {
    return guarded(async () => {
        await queue({ type: 'my_day.remove', data: workData(row), entity: { kind: 'my_day', key: myDayKey(row), label: row.title, url: myWorkUrl() } });
        await updateSnapshot<OfflineMyDaySnapshot>(MY_DAY_KEY, (day) => ({ ...day, rows: day.rows.filter((r) => myDayKey(r) !== myDayKey(row)) }));
    });
}

/** The circle on My Day: complete / reopen the task itself (issue → done / open column). */
export function toggleMyDayDone(row: OfflineMyDayRow): Promise<boolean> {
    return guarded(async () => {
        const done = !row.done;
        if (row.work_type === 'board' && row.work_id) {
            const column = done ? row.done_column_id : row.open_column_id;
            if (!column) return;
            const issue = (await listSnapshots<OfflineIssueSnapshot>('issue')).find((r) => r.data.id === row.work_id)?.data;
            if (issue) return queueIssueUpdate(issue, { column_id: column });
            await queue({
                type: 'issue.update',
                target: { id: row.work_id },
                base: { fields: { column_id: row.column_id ?? null } },
                data: { column_id: column },
                entity: { kind: 'issue', key: issueKey(row.work_id), label: `${row.key} ${row.title}`, url: row.url },
            });
            await setMyDayDone('board', row.work_id, null, done, column);
            return;
        }

        for (const { data: list } of await listSnapshots<OfflineTaskListSnapshot>('list')) {
            const task = list.tasks.find((t) => (row.work_id && t.id === row.work_id) || (!!row.work_ref && t.ref === row.work_ref));
            if (task) {
                const status = done ? 'done' : 'todo';
                await queue({
                    type: 'list_task.update',
                    target: target(task),
                    base: { updated_at: task.updated_at ?? null, fields: { status: task.status } },
                    data: { status },
                    entity: { kind: 'list_task', key: taskKey(task), label: task.title, parent_id: list.id, url: list.url },
                });
                await updateSnapshot<OfflineTaskListSnapshot>(`list:${list.id}`, (l) => ({
                    ...l,
                    tasks: l.tasks.map((t) => (sameTask(t, task) ? { ...t, status } : t)),
                }));
                await setMyDayDone('list', task.id, task.ref ?? null, done);
                return;
            }
        }
        if (!row.work_id) return;
        await queue({
            type: 'list_task.update',
            target: { id: row.work_id },
            base: { fields: { status: row.done ? 'done' : 'todo' } },
            data: { status: done ? 'done' : 'todo' },
            entity: { kind: 'list_task', key: `list_task:${row.work_id}`, label: row.title, url: row.url },
        });
        await setMyDayDone('list', row.work_id, null, done);
    });
}

async function setMyDayDone(type: 'board' | 'list', id: number | null, ref: string | null, done: boolean, column?: number): Promise<void> {
    await updateSnapshot<OfflineMyDaySnapshot>(MY_DAY_KEY, (day) => ({
        ...day,
        rows: day.rows.map((r) => (r.work_type === type && ((id && r.work_id === id) || (!!ref && r.work_ref === ref))
            ? { ...r, done, ...(column ? { column_id: column } : {}) }
            : r)),
    }));
}
