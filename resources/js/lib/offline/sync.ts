/**
 * Offline mode (BRAI-82): the sync engine. Sends the outbox in order, one change per request, to
 * `offline.sync` — when the browser comes back online, when the tab gets focus, every 30 s and
 * right after a change while online. Each change carries its own uuid, so a retry after a lost
 * answer is never applied twice. Answers:
 * - 200 applied → removed, the local copy takes the server's values;
 * - 409 conflict / 410 deleted / 403 / 422 → moved to the Sync conflicts panel (never dropped);
 * - 424 waiting → stop and retry later (an earlier offline create hasn't arrived);
 * - 401 / 419 → stop until the user signs in again; network error / 429 / 5xx → retry later.
 */
import axios from 'axios';
import { toast } from 'sonner';
import type { OfflineConflict, OfflineConflictStatus, OfflineIssueSnapshot, OfflineMutation, OfflineMyDaySnapshot, OfflineTaskListSnapshot } from '@/types';
import {
    addConflict, getOfflineState, readOutbox, refreshOffline, removeFromOutbox, setOfflineState, takeNext, updateSnapshot,
} from './store';

const INTERVAL_MS = 30_000;
let running: Promise<void> | null = null;
let again = false;
let timer: number | null = null;
let started = false;

type SyncBody = {
    status?: string;
    message?: string;
    entity?: Record<string, unknown>;
    conflicts?: OfflineConflict['fields'];
    errors?: Record<string, string[]>;
};

/** Start the triggers once (idempotent). */
export function startSyncEngine(): void {
    if (started || typeof window === 'undefined') return;
    started = true;
    window.addEventListener('online', () => void syncNow());
    window.addEventListener('focus', () => void syncNow());
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') void syncNow();
    });
    timer = window.setInterval(() => void syncNow(), INTERVAL_MS);
    void syncNow();
}

export function stopSyncEngine(): void {
    if (timer !== null) window.clearInterval(timer);
    timer = null;
}

/** Send what's waiting. Concurrent calls share one run (and trigger one more after it). */
export function syncNow(): Promise<void> {
    if (running) {
        again = true;
        return running;
    }
    running = run().finally(() => {
        running = null;
        if (again) {
            again = false;
            void syncNow();
        }
    });
    return running;
}

async function run(): Promise<void> {
    const { userId } = getOfflineState();
    if (!userId || (typeof navigator !== 'undefined' && !navigator.onLine)) return;

    setOfflineState({ syncing: true });
    let synced = 0;
    let needsSignIn = false;
    try {
        for (;;) {
            if (getOfflineState().userId !== userId) return; // signed out meanwhile
            const next = await takeNext();
            if (!next) break;

            let status: number;
            let body: SyncBody;
            try {
                const response = await axios.post(route('offline.sync'), payload(next), {
                    validateStatus: () => true,
                    timeout: 20_000,
                    headers: { Accept: 'application/json' },
                });
                status = response.status;
                body = (response.data ?? {}) as SyncBody;
            } catch {
                break; // network: try again later, the change stays first in line
            }

            if (status === 200) {
                await applyLocally(next, body.entity ?? {});
                await removeFromOutbox(next);
                synced++;
                continue;
            }
            if (status === 401 || status === 419) {
                needsSignIn = true;
                break;
            }
            if (status === 424) {
                const ref = next.target?.ref ?? (next.data.work_ref as string | undefined);
                const outbox = await readOutbox();
                if (ref && outbox.some((m) => m.id === ref)) break; // its create is still on the way
                await toConflict(next, 'deleted', 'It was made on something that couldn’t be created.');
                continue;
            }
            const kind = conflictKind(status);
            if (!kind) break; // 429 / 5xx: later
            await toConflict(next, kind, messageFor(kind, body), body);
        }
    } finally {
        setOfflineState({ needsSignIn });
        await refreshOffline();
        setOfflineState({ syncing: false });
        if (synced > 0) {
            toast.success(synced === 1 ? '1 change synced' : `${synced} changes synced`);
        }
        const conflicts = getOfflineState().conflicts.length;
        if (conflicts > 0 && synced > 0) {
            toast.warning(conflicts === 1 ? '1 change needs your decision' : `${conflicts} changes need your decision`);
        }
    }
}

function payload(m: OfflineMutation) {
    return { id: m.id, type: m.type, target: m.target ?? null, base: m.base ?? null, data: m.data };
}

function conflictKind(status: number): OfflineConflictStatus | null {
    if (status === 409) return 'conflict';
    if (status === 410 || status === 404) return 'deleted';
    if (status === 403) return 'forbidden';
    if (status === 422) return 'invalid';
    return null;
}

function messageFor(kind: OfflineConflictStatus, body: SyncBody): string {
    if (kind === 'conflict') return 'Someone changed this while you were offline.';
    if (kind === 'deleted') return body.message ?? 'This was deleted while you were offline.';
    if (kind === 'forbidden') return 'You’re no longer allowed to change this.';
    const first = body.errors ? Object.values(body.errors)[0]?.[0] : undefined;
    return first ?? body.message ?? 'The server couldn’t accept this change.';
}

async function toConflict(m: OfflineMutation, status: OfflineConflictStatus, message: string, body: SyncBody = {}): Promise<void> {
    await addConflict({
        id: m.id,
        mutation: m,
        status,
        message,
        fields: body.conflicts,
        entity: body.entity ?? null,
        created_at: new Date().toISOString(),
    });
    await removeFromOutbox(m);
    // the fields that did apply next to a conflict: the local copy takes the server's values
    if (status === 'conflict' && body.entity) await applyLocally(m, body.entity, Object.keys(body.conflicts ?? {}));
}

/**
 * Bring the local copies up to date with what the server answered. `keep` = fields still in
 * conflict: the device keeps showing its own value for them until the user decides.
 */
export async function applyLocally(m: OfflineMutation, entity: Record<string, unknown>, keep: string[] = []): Promise<void> {
    const pick = (fields: string[]) => Object.fromEntries(fields.filter((f) => !keep.includes(f) && f in entity).map((f) => [f, entity[f]]));

    if (m.type === 'list_task.create' || m.type === 'list_task.update') {
        const listId = Number(m.type === 'list_task.create' ? m.data.task_list_id : m.entity.parent_id);
        if (!listId) return;
        const changes = pick(['title', 'status', 'position', 'due_date', 'updated_at']);
        await updateSnapshot<OfflineTaskListSnapshot>(`list:${listId}`, (list) => ({
            ...list,
            tasks: list.tasks.map((t) => {
                const match = m.type === 'list_task.create' ? t.ref === m.id : (t.id !== null && t.id === entity.id) || (!!m.target?.ref && t.ref === m.target.ref);
                return match ? { ...t, ...changes, id: Number(entity.id) || t.id } : t;
            }),
        }));
        await syncMyDayRow('list', Number(entity.id), m.type === 'list_task.create' ? m.id : m.target?.ref ?? null, { done: entity.status === 'done' && !keep.includes('status') ? true : entity.status === 'todo' && !keep.includes('status') ? false : undefined });
        return;
    }

    if (m.type === 'issue.update' || m.type === 'issue.create') {
        const id = Number(entity.id);
        if (!id) return;
        await updateSnapshot<OfflineIssueSnapshot>(`issue:${id}`, (issue) => ({
            ...issue,
            ...pick(['title', 'column_id', 'priority_id', 'assigned_to', 'due_date', 'updated_at']),
        }));
        if (!keep.includes('column_id') && 'column_id' in entity) {
            await updateSnapshot<OfflineMyDaySnapshot>('myday:today', (day) => ({
                ...day,
                rows: day.rows.map((r) => (r.work_type === 'board' && r.work_id === id
                    ? { ...r, column_id: Number(entity.column_id), done: !!entity.completed_at }
                    : r)),
            }));
        }
        return;
    }

    if (m.type === 'issue.comment') {
        const itemId = Number(entity.item_id);
        await updateSnapshot<OfflineIssueSnapshot>(`issue:${itemId}`, (issue) => ({
            ...issue,
            comments: issue.comments.map((c) => (c.ref === m.id ? { ...c, id: Number(entity.id), ref: null } : c)),
        }));
    }
}

async function syncMyDayRow(type: 'board' | 'list', id: number, ref: string | null, patch: { done?: boolean }): Promise<void> {
    await updateSnapshot<OfflineMyDaySnapshot>('myday:today', (day) => ({
        ...day,
        rows: day.rows.map((r) => {
            if (r.work_type !== type) return r;
            const match = (id && r.work_id === id) || (!!ref && r.work_ref === ref);
            if (!match) return r;
            return { ...r, work_id: id || r.work_id, ...(patch.done === undefined ? {} : { done: patch.done }) };
        }),
    }));
}
