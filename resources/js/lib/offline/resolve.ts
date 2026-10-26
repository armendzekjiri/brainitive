/**
 * Offline mode (BRAI-82): the user's decisions in the Sync conflicts panel. Nothing is resolved
 * automatically — every choice is explicit:
 * - Keep theirs: the device takes the server's values, my change is dropped.
 * - Keep mine: a new change made against their value (the server re-checks it, so a third edit in
 *   the meantime is a new conflict, never an overwrite).
 * - Keep both (issue text): theirs stays, mine is posted as a comment on the issue.
 * - Recreate: something deleted meanwhile is created again with my values.
 */
import type { OfflineConflict, OfflineIssueSnapshot, OfflineTaskListSnapshot } from '@/types';
import { enqueue, newId, removeConflict, updateSnapshot } from './store';
import { applyLocally, syncNow } from './sync';

export const FIELD_LABELS: Record<string, string> = {
    title: 'Title',
    status: 'Status',
    column_id: 'Status',
    priority_id: 'Priority',
    assigned_to: 'Assignee',
    due_date: 'Due date',
};

const TEXT_FIELDS = ['title'];

function kick(): void {
    if (typeof navigator === 'undefined' || navigator.onLine) void syncNow();
}

export function canKeepBoth(c: OfflineConflict): boolean {
    return c.status === 'conflict' && c.mutation.type === 'issue.update' && Object.keys(c.fields ?? {}).some((f) => TEXT_FIELDS.includes(f));
}

export function canRecreate(c: OfflineConflict): boolean {
    return c.status === 'deleted' && (c.mutation.type === 'list_task.update' || c.mutation.type === 'issue.update') && !!c.mutation.entity.parent_id;
}

export async function keepTheirs(c: OfflineConflict): Promise<void> {
    if (c.entity) await applyLocally(c.mutation, c.entity);
    await removeConflict(c.id);
}

export async function keepMine(c: OfflineConflict): Promise<void> {
    const fields = c.fields ?? {};
    const data = Object.fromEntries(Object.entries(fields).map(([f, v]) => [f, v.mine]));
    const base = Object.fromEntries(Object.entries(fields).map(([f, v]) => [f, v.theirs]));
    const entityId = Number(c.entity?.id) || c.mutation.target?.id || null;
    await enqueue({
        type: c.mutation.type,
        target: entityId ? { id: entityId } : c.mutation.target,
        base: { updated_at: (c.entity?.updated_at as string | undefined) ?? null, fields: base },
        data,
        entity: c.mutation.entity,
    });
    await removeConflict(c.id);
    kick();
}

export async function keepBoth(c: OfflineConflict): Promise<void> {
    const issueId = Number(c.entity?.id) || c.mutation.target?.id;
    const mine = Object.entries(c.fields ?? {})
        .filter(([f]) => TEXT_FIELDS.includes(f))
        .map(([f, v]) => `${FIELD_LABELS[f] ?? f}: ${String(v.mine ?? '')}`);
    await keepTheirs(c);
    if (!issueId || mine.length === 0) return;
    const id = newId();
    const text = `My offline edit (kept both):\n${mine.join('\n')}`;
    await updateSnapshot<OfflineIssueSnapshot>(`issue:${issueId}`, (i) => ({
        ...i,
        comments: [{ id: null, ref: id, author: 'You', text, created_at: new Date().toISOString() }, ...i.comments],
    }));
    await enqueue({
        id,
        type: 'issue.comment',
        target: { id: issueId },
        data: { body: text },
        entity: { kind: 'issue', key: `issue:${issueId}`, label: `Comment on ${c.mutation.entity.label}`, parent_id: c.mutation.entity.parent_id, url: c.mutation.entity.url },
    });
    kick();
}

export async function recreate(c: OfflineConflict): Promise<void> {
    const m = c.mutation;
    const parent = Number(m.entity.parent_id);
    const title = String(m.data.title ?? m.entity.label.replace(/^[A-Z][A-Z0-9]*-\d+\s+/, ''));
    const id = newId();

    if (m.type === 'list_task.update') {
        await updateSnapshot<OfflineTaskListSnapshot>(`list:${parent}`, (l) => ({
            ...l,
            tasks: l.tasks.map((t) => ((m.target?.id && t.id === m.target.id) || (m.target?.ref && t.ref === m.target.ref)
                ? { ...t, id: null, ref: id, title }
                : t)),
        }));
        await enqueue({
            id,
            type: 'list_task.create',
            data: { task_list_id: parent, title, ...(m.data.status ? { status: m.data.status } : {}) },
            entity: { ...m.entity, key: `list_task:ref:${id}`, label: title },
        });
    } else {
        const data: Record<string, unknown> = { board_id: parent, title };
        for (const f of ['priority_id', 'assigned_to', 'due_date']) if (m.data[f] !== undefined) data[f] = m.data[f];
        await enqueue({ id, type: 'issue.create', data, entity: { ...m.entity, key: `issue:ref:${id}`, label: title } });
    }
    await removeConflict(c.id);
    kick();
}

/** Drop a change the server refused (or something that is gone): the local copy follows the server. */
export async function discard(c: OfflineConflict): Promise<void> {
    const m = c.mutation;
    if (m.type === 'list_task.create') {
        await updateSnapshot<OfflineTaskListSnapshot>(`list:${Number(m.data.task_list_id)}`, (l) => ({ ...l, tasks: l.tasks.filter((t) => t.ref !== m.id) }));
    }
    if (m.type === 'list_task.update' && c.status === 'deleted') {
        await updateSnapshot<OfflineTaskListSnapshot>(`list:${Number(m.entity.parent_id)}`, (l) => ({
            ...l,
            tasks: l.tasks.filter((t) => !((m.target?.id && t.id === m.target.id) || (m.target?.ref && t.ref === m.target.ref))),
        }));
    }
    if (m.type === 'issue.comment' && m.target?.id) {
        await updateSnapshot<OfflineIssueSnapshot>(`issue:${m.target.id}`, (i) => ({ ...i, comments: i.comments.filter((x) => x.ref !== m.id) }));
    }
    if (c.entity && c.status === 'conflict') await applyLocally(m, c.entity);
    await removeConflict(c.id);
}
