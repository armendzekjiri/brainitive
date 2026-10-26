/**
 * Offline mode (BRAI-82): copies of what the user opened online, taken from the page props the
 * page already has (no extra requests). Bounded in the store (20 lists, 50 issues, today's plan).
 * No-ops unless offline mode is on for the signed-in account on this device.
 */
import { useEffect } from 'react';
import type {
    BoardColumn, Item, ItemPriority, MyDayPlan, OfflineIssueSnapshot, OfflineMutation, OfflineMyDaySnapshot, OfflineTaskListSnapshot, Project, TaskList, TaskListItem, User, Workspace,
} from '@/types';
import { getOfflineState, saveSnapshot, subscribeOffline } from './store';

const COMMENTS = 20;
const TEXT_LIMIT = 4000;

/** Plain text of rich-text HTML (never rendered as HTML offline). */
export function htmlToText(html: string | null | undefined): string {
    if (!html) return '';
    try {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        doc.querySelectorAll('br').forEach((br) => br.replaceWith('\n'));
        doc.querySelectorAll('p, li, h1, h2, h3, h4, h5, h6, blockquote, pre').forEach((el) => el.append('\n'));
        return (doc.body.textContent ?? '').replace(/\n{3,}/g, '\n\n').trim().slice(0, TEXT_LIMIT);
    } catch {
        return '';
    }
}

const ymd = (value: string | null | undefined) => (value ? value.slice(0, 10) : null);

export function taskListSnapshot(args: {
    workspace: Workspace; project: Project; taskList: TaskList & { items: TaskListItem[] }; canManage: boolean; url: string;
}): OfflineTaskListSnapshot {
    const { workspace, project, taskList, canManage, url } = args;
    return {
        id: taskList.id,
        name: taskList.name,
        url,
        workspace: { slug: workspace.slug, name: workspace.name },
        project: { id: project.id, slug: project.slug, name: project.name, key: project.key ?? null },
        canManage,
        tasks: (taskList.items ?? []).map((t) => ({
            id: t.id,
            title: t.title,
            status: t.status,
            position: t.position,
            due_date: ymd(t.due_date),
            updated_at: t.updated_at,
        })),
    };
}

export function issueSnapshot(args: {
    item: Item; workspace: Workspace; project: Project; board: { id: number; slug: string; name: string };
    columns: Pick<BoardColumn, 'id' | 'name' | 'is_done_column'>[]; priorities: Pick<ItemPriority, 'id' | 'name'>[];
    members: Pick<User, 'id' | 'name'>[]; canEdit: boolean; url: string;
}): OfflineIssueSnapshot {
    const { item, workspace, project, board, columns, priorities, members, canEdit, url } = args;
    return {
        id: item.id,
        key: item.key,
        title: item.title,
        url,
        board: { id: board.id, slug: board.slug, name: board.name },
        project: { slug: project.slug, name: project.name },
        workspace: { slug: workspace.slug },
        column_id: item.column_id,
        priority_id: item.priority_id ?? null,
        assigned_to: item.assigned_to ?? null,
        due_date: ymd(item.due_date),
        description_text: htmlToText(item.description),
        updated_at: item.updated_at ?? null,
        columns: columns.map((c) => ({ id: c.id, name: c.name, is_done_column: !!c.is_done_column })),
        priorities: priorities.map((p) => ({ id: p.id, name: p.name })),
        members: members.map((m) => ({ id: m.id, name: m.name })),
        comments: (item.comments ?? []).slice(0, COMMENTS).map((c) => ({
            id: c.id,
            author: c.user?.name ?? 'Someone',
            text: htmlToText(c.body),
            created_at: c.created_at,
        })),
        canEdit,
    };
}

export function myDaySnapshot(plan: MyDayPlan): OfflineMyDaySnapshot {
    return {
        date: plan.date,
        rows: plan.rows.map(({ item, project }) => ({
            work_type: item.work_type,
            work_id: item.id,
            title: item.title,
            key: item.key,
            url: item.work_type === 'board' && item.board && project.workspace
                ? route('boards.items.show', [project.workspace.slug, project.slug, item.board.slug, item.id], false)
                : item.task_list && project.workspace
                    ? route('task-lists.show', [project.workspace.slug, project.slug, item.task_list.slug], false)
                    : null,
            project_name: project.name,
            done: !!(item.completed_at || item.column?.is_done_column || item.status === 'done'),
            column_id: item.work_type === 'board' ? item.column?.id ?? null : null,
            done_column_id: item.board?.done_column_id ?? null,
            open_column_id: item.board?.open_column_id ?? null,
        })),
    };
}

/**
 * Save `build()` whenever `deps` change while offline mode is on (and once it gets turned on).
 * Pages call it with their own props: `useOfflineSnapshot('list', id, () => …, [taskList])`.
 */
export function useOfflineSnapshot(kind: 'list' | 'issue' | 'myday', id: string | number | null, build: () => unknown, deps: unknown[]): void {
    useEffect(() => {
        if (id === null) return;
        const save = () => {
            const { userId, outbox } = getOfflineState();
            if (userId) void saveSnapshot(kind, id, overlayPending(kind, build(), outbox));
        };
        save();
        // offline mode may only come up after the first render (IndexedDB opens async)
        let had = !!getOfflineState().userId;
        return subscribeOffline(() => {
            const has = !!getOfflineState().userId;
            if (has && !had) save();
            had = has;
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [kind, id, ...deps]);
}

/**
 * A fresh copy from the server doesn't know the changes still waiting in the outbox: put them back
 * on top, so a reload while a change is on its way never makes it look lost.
 */
export function overlayPending(kind: 'list' | 'issue' | 'myday', data: unknown, outbox: OfflineMutation[]): unknown {
    if (outbox.length === 0) return data;

    if (kind === 'list') {
        const list = data as OfflineTaskListSnapshot;
        let tasks = [...list.tasks];
        for (const m of outbox) {
            if (m.type === 'list_task.create' && Number(m.data.task_list_id) === list.id && !tasks.some((t) => t.ref === m.id)) {
                tasks.push({ id: null, ref: m.id, title: String(m.data.title ?? ''), status: m.data.status === 'done' ? 'done' : 'todo', position: tasks.length + 1 });
            }
            if (m.type === 'list_task.update' && Number(m.entity.parent_id) === list.id) {
                tasks = tasks.map((t) => ((m.target?.id && t.id === m.target.id) || (m.target?.ref && t.ref === m.target.ref) ? { ...t, ...(m.data as Partial<typeof t>) } : t));
            }
        }
        return { ...list, tasks };
    }

    if (kind === 'issue') {
        const issue = data as OfflineIssueSnapshot;
        let next = { ...issue };
        for (const m of outbox) {
            if (m.target?.id !== issue.id) continue;
            if (m.type === 'issue.update') next = { ...next, ...(m.data as Partial<OfflineIssueSnapshot>) };
            if (m.type === 'issue.comment' && !next.comments.some((c) => c.ref === m.id)) {
                next = { ...next, comments: [{ id: null, ref: m.id, author: 'You', text: String(m.data.body ?? ''), created_at: m.created_at }, ...next.comments] };
            }
        }
        return next;
    }

    const day = data as OfflineMyDaySnapshot;
    const removed = new Set(outbox.filter((m) => m.type === 'my_day.remove').map((m) => m.entity.key));
    return { ...day, rows: day.rows.filter((r) => !removed.has(`my_day:${r.work_type}:${r.work_id ?? r.work_ref}`)) };
}
