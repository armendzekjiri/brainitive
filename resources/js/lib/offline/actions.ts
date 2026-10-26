/**
 * Offline mode (BRAI-82): what the offline views (and quick capture) do. Every action changes the
 * local copy at once and queues one change with the values it was made against; the sync engine
 * sends it as soon as there is a connection.
 */
import type {
    OfflineIssueSnapshot, OfflineMutation, OfflineMyDayRow, OfflineMyDaySnapshot, OfflineSnapshotRecord, OfflineTask, OfflineTaskListSnapshot,
} from '@/types';
import { enqueue, getOfflineState, issueKey, listSnapshots, newId, saveSnapshot, taskKey, updateSnapshot } from './store';
import { syncNow } from './sync';

export const MY_DAY_KEY = 'myday:today';

function kick(): void {
    if (typeof navigator === 'undefined' || navigator.onLine) void syncNow();
}

const target = (task: OfflineTask): OfflineMutation['target'] => (task.id ? { id: task.id } : { ref: task.ref ?? null });
const sameTask = (a: OfflineTask, b: OfflineTask) => (a.id !== null && a.id === b.id) || (!!a.ref && a.ref === b.ref);
export const myDayKey = (row: Pick<OfflineMyDayRow, 'work_type' | 'work_id' | 'work_ref'>) => `my_day:${row.work_type}:${row.work_id ?? row.work_ref}`;

// ── Task lists ───────────────────────────────────────────────────────────────

export async function createTask(list: OfflineTaskListSnapshot, title: string): Promise<void> {
    const id = newId();
    const position = Math.max(0, ...list.tasks.map((t) => t.position)) + 1;
    await updateSnapshot<OfflineTaskListSnapshot>(`list:${list.id}`, (l) => ({
        ...l,
        tasks: [...l.tasks, { id: null, ref: id, title, status: 'todo', position, due_date: null, updated_at: null }],
    }));
    await enqueue({
        id,
        type: 'list_task.create',
        data: { task_list_id: list.id, title },
        entity: { kind: 'list_task', key: `list_task:ref:${id}`, label: title, parent_id: list.id, url: list.url },
    });
    kick();
}

export async function updateTask(list: OfflineTaskListSnapshot, task: OfflineTask, changes: Partial<Pick<OfflineTask, 'title' | 'status'>>): Promise<void> {
    const fields = Object.fromEntries(Object.keys(changes).map((k) => [k, task[k as 'title' | 'status']]));
    await updateSnapshot<OfflineTaskListSnapshot>(`list:${list.id}`, (l) => ({
        ...l,
        tasks: l.tasks.map((t) => (sameTask(t, task) ? { ...t, ...changes } : t)),
    }));
    await enqueue({
        type: 'list_task.update',
        target: target(task),
        base: { updated_at: task.updated_at ?? null, fields },
        data: changes,
        entity: { kind: 'list_task', key: taskKey(task), label: changes.title ?? task.title, parent_id: list.id, url: list.url },
    });
    if (changes.status) await setMyDayDone('list', task.id, task.ref ?? null, changes.status === 'done');
    kick();
}

/** Move an open task one place up or down (the whole order is sent; order has no conflicts). */
export async function moveTask(list: OfflineTaskListSnapshot, task: OfflineTask, direction: -1 | 1): Promise<void> {
    const open = list.tasks.filter((t) => t.status !== 'done').sort((a, b) => a.position - b.position);
    const index = open.findIndex((t) => sameTask(t, task));
    const swap = index + direction;
    if (index < 0 || swap < 0 || swap >= open.length) return;
    [open[index], open[swap]] = [open[swap], open[index]];
    const positions = open.map((t, i) => ({ t, i }));
    await updateSnapshot<OfflineTaskListSnapshot>(`list:${list.id}`, (l) => ({
        ...l,
        tasks: l.tasks.map((t) => {
            const hit = positions.find((p) => sameTask(p.t, t));
            return hit ? { ...t, position: hit.i } : t;
        }),
    }));
    await enqueue({
        type: 'list_task.reorder',
        data: { task_list_id: list.id, items: open.map((t) => (t.id ? { id: t.id } : { ref: t.ref })) },
        entity: { kind: 'task_list', key: `task_list:${list.id}:order`, label: `Order of ${list.name}`, parent_id: list.id, url: list.url },
    });
    kick();
}

// ── Issues ───────────────────────────────────────────────────────────────────

export type IssueChanges = Partial<Pick<OfflineIssueSnapshot, 'title' | 'column_id' | 'priority_id' | 'assigned_to' | 'due_date'>>;

export async function updateIssue(issue: OfflineIssueSnapshot, changes: IssueChanges): Promise<void> {
    const fields = Object.fromEntries(Object.keys(changes).map((k) => [k, issue[k as keyof IssueChanges]]));
    await updateSnapshot<OfflineIssueSnapshot>(`issue:${issue.id}`, (i) => ({ ...i, ...changes }));
    await enqueue({
        type: 'issue.update',
        target: { id: issue.id },
        base: { updated_at: issue.updated_at, fields },
        data: changes,
        entity: { kind: 'issue', key: issueKey(issue.id), label: `${issue.key} ${changes.title ?? issue.title}`, parent_id: issue.board.id, url: issue.url },
    });
    if (changes.column_id !== undefined) {
        const done = !!issue.columns.find((c) => c.id === changes.column_id)?.is_done_column;
        await updateSnapshot<OfflineMyDaySnapshot>(MY_DAY_KEY, (day) => ({
            ...day,
            rows: day.rows.map((r) => (r.work_type === 'board' && r.work_id === issue.id ? { ...r, done, column_id: changes.column_id } : r)),
        }));
    }
    kick();
}

export async function commentOnIssue(issue: OfflineIssueSnapshot, text: string): Promise<void> {
    const id = newId();
    await updateSnapshot<OfflineIssueSnapshot>(`issue:${issue.id}`, (i) => ({
        ...i,
        comments: [{ id: null, ref: id, author: 'You', text, created_at: new Date().toISOString() }, ...i.comments],
    }));
    await enqueue({
        id,
        type: 'issue.comment',
        target: { id: issue.id },
        data: { body: text },
        entity: { kind: 'issue', key: issueKey(issue.id), label: `Comment on ${issue.key}`, parent_id: issue.board.id, url: issue.url },
    });
    kick();
}

// ── Quick capture ────────────────────────────────────────────────────────────

export async function captureOffline(data: { text: string; url?: string | null; intended_type?: string | null; source?: string | null }): Promise<boolean> {
    if (!getOfflineState().userId) return false;
    const id = newId();
    const clean = Object.fromEntries(Object.entries(data).filter(([, v]) => v !== '' && v !== null && v !== undefined));
    const queued = await enqueue({
        id,
        type: 'capture.create',
        data: clean,
        entity: { kind: 'capture', key: `capture:${id}`, label: data.text.split('\n')[0].slice(0, 80), url: '/captures' },
    });
    kick();
    return !!queued;
}

// ── My Day ───────────────────────────────────────────────────────────────────

export async function addToMyDay(row: OfflineMyDayRow): Promise<void> {
    const day = await updateSnapshot<OfflineMyDaySnapshot>(MY_DAY_KEY, (d) =>
        d.rows.some((r) => myDayKey(r) === myDayKey(row)) ? d : { ...d, rows: [...d.rows, row] });
    if (!day) await saveSnapshot<OfflineMyDaySnapshot>('myday', 'today', { date: new Date().toLocaleDateString('en-CA'), rows: [row] });
    await enqueue({
        type: 'my_day.add',
        data: { work_type: row.work_type, ...(row.work_id ? { work_id: row.work_id } : { work_ref: row.work_ref }) },
        entity: { kind: 'my_day', key: myDayKey(row), label: row.title, url: '/my-work' },
    });
    kick();
}

export async function removeFromMyDay(row: OfflineMyDayRow): Promise<void> {
    await updateSnapshot<OfflineMyDaySnapshot>(MY_DAY_KEY, (day) => ({ ...day, rows: day.rows.filter((r) => myDayKey(r) !== myDayKey(row)) }));
    await enqueue({
        type: 'my_day.remove',
        data: { work_type: row.work_type, ...(row.work_id ? { work_id: row.work_id } : { work_ref: row.work_ref }) },
        entity: { kind: 'my_day', key: myDayKey(row), label: row.title, url: '/my-work' },
    });
    kick();
}

/** The circle on My Day: complete / reopen the task itself (issue → done / open column). */
export async function toggleMyDayDone(row: OfflineMyDayRow): Promise<void> {
    const done = !row.done;
    if (row.work_type === 'board' && row.work_id) {
        const column = done ? row.done_column_id : row.open_column_id;
        if (!column) return;
        const issues = await listSnapshots<OfflineIssueSnapshot>('issue');
        const issue = issues.find((r) => r.data.id === row.work_id)?.data;
        if (issue) {
            await updateIssue(issue, { column_id: column });
            return;
        }
        await setMyDayDone('board', row.work_id, null, done, column);
        await enqueue({
            type: 'issue.update',
            target: { id: row.work_id },
            base: { fields: { column_id: row.column_id ?? null } },
            data: { column_id: column },
            entity: { kind: 'issue', key: issueKey(row.work_id), label: `${row.key} ${row.title}`, url: row.url },
        });
        kick();
        return;
    }

    const lists = await listSnapshots<OfflineTaskListSnapshot>('list');
    for (const { data: list } of lists) {
        const task = list.tasks.find((t) => (row.work_id && t.id === row.work_id) || (!!row.work_ref && t.ref === row.work_ref));
        if (task) {
            await updateTask(list, task, { status: done ? 'done' : 'todo' });
            return;
        }
    }
    if (!row.work_id) return;
    await setMyDayDone('list', row.work_id, null, done);
    await enqueue({
        type: 'list_task.update',
        target: { id: row.work_id },
        base: { fields: { status: row.done ? 'done' : 'todo' } },
        data: { status: done ? 'done' : 'todo' },
        entity: { kind: 'list_task', key: `list_task:${row.work_id}`, label: row.title, url: row.url },
    });
    kick();
}

async function setMyDayDone(type: 'board' | 'list', id: number | null, ref: string | null, done: boolean, column?: number): Promise<void> {
    await updateSnapshot<OfflineMyDaySnapshot>(MY_DAY_KEY, (day) => ({
        ...day,
        rows: day.rows.map((r) => (r.work_type === type && ((id && r.work_id === id) || (!!ref && r.work_ref === ref))
            ? { ...r, done, ...(column ? { column_id: column } : {}) }
            : r)),
    }));
}

export type { OfflineSnapshotRecord };
