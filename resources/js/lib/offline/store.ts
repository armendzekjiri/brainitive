/**
 * Offline mode (BRAI-82): the device-side state — snapshots of what the user opened online, the
 * outbox of changes made offline, and the conflicts the server answered. Module-level store
 * (useSyncExternalStore), like `lib/sidebarPreferences.ts`; IndexedDB is the source of truth.
 */
import { useSyncExternalStore } from 'react';
import type { OfflineConflict, OfflineMutation, OfflineSnapshotRecord } from '@/types';
import { idbAll, idbDelete, idbGet, idbPut, isSupported } from './db';
import { activeOfflineUser } from './settings';

export interface OfflineState {
    /** Signed-in account with offline mode on (this device), or null. */
    userId: number | null;
    online: boolean;
    outbox: OfflineMutation[];
    conflicts: OfflineConflict[];
    syncing: boolean;
    /** The server refused our session (signed out / expired): sync waits for a sign-in. */
    needsSignIn: boolean;
}

const LIMITS: Record<OfflineSnapshotRecord['kind'], number> = { list: 20, issue: 50, myday: 1 };

let state: OfflineState = {
    userId: null,
    online: typeof navigator === 'undefined' ? true : navigator.onLine,
    outbox: [],
    conflicts: [],
    syncing: false,
    needsSignIn: false,
};
const listeners = new Set<() => void>();

export function getOfflineState(): OfflineState {
    return state;
}

export function setOfflineState(patch: Partial<OfflineState>): void {
    state = { ...state, ...patch };
    listeners.forEach((l) => l());
}

export function subscribeOffline(listener: () => void): () => void {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

export function useOfflineState(): OfflineState {
    return useSyncExternalStore(subscribeOffline, getOfflineState, getOfflineState);
}

if (typeof window !== 'undefined') {
    window.addEventListener('online', () => setOfflineState({ online: true }));
    window.addEventListener('offline', () => setOfflineState({ online: false }));
    // signed out / another account in another tab: this tab lets go of the data at once
    window.addEventListener('storage', (event) => {
        if (event.key !== null && event.key !== 'brainitive-offline:user') return;
        if (state.userId && activeOfflineUser() !== state.userId) {
            setOfflineState({ userId: null, outbox: [], conflicts: [], syncing: false, needsSignIn: false });
        }
    });
}

/**
 * The user whose data we may read / write right now, or null (offline mode off). Also checks the
 * device-wide owner (localStorage, shared by all tabs), so a tab that missed a sign-out elsewhere
 * never recreates the deleted database.
 */
function uid(): number | null {
    return state.userId && isSupported() && activeOfflineUser() === state.userId ? state.userId : null;
}

/** Reload outbox + conflicts into the store. */
export async function refreshOffline(): Promise<void> {
    const userId = uid();
    if (!userId) {
        setOfflineState({ outbox: [], conflicts: [] });
        return;
    }
    try {
        const [outbox, conflicts] = await Promise.all([
            idbAll<OfflineMutation>(userId, 'outbox'),
            idbAll<OfflineConflict>(userId, 'conflicts'),
        ]);
        if (state.userId !== userId) return;
        setOfflineState({
            outbox: outbox.sort((a, b) => (a.seq ?? 0) - (b.seq ?? 0)),
            conflicts: conflicts.sort((a, b) => a.created_at.localeCompare(b.created_at)),
        });
    } catch {
        // storage unavailable: the indicator just shows nothing pending
    }
}

// ── Snapshots ────────────────────────────────────────────────────────────────

export async function saveSnapshot<T>(kind: OfflineSnapshotRecord['kind'], id: string | number, data: T): Promise<void> {
    const userId = uid();
    if (!userId) return;
    const record: OfflineSnapshotRecord<T> = { key: `${kind}:${id}`, kind, data, viewedAt: Date.now() };
    try {
        await idbPut(userId, 'snapshots', record);
        snapshotListeners.forEach((l) => l(record.key));
        // bounded: keep the most recently opened ones
        const same = (await idbAll<OfflineSnapshotRecord>(userId, 'snapshots')).filter((r) => r.kind === kind).sort((a, b) => b.viewedAt - a.viewedAt);
        await Promise.all(same.slice(LIMITS[kind]).map((r) => idbDelete(userId, 'snapshots', r.key)));
    } catch {
        // quota / private mode: offline copies are best effort
    }
}

export async function getSnapshot<T>(key: string): Promise<T | null> {
    const userId = uid();
    if (!userId) return null;
    try {
        return ((await idbGet<OfflineSnapshotRecord<T>>(userId, 'snapshots', key))?.data) ?? null;
    } catch {
        return null;
    }
}

export async function listSnapshots<T>(kind: OfflineSnapshotRecord['kind']): Promise<OfflineSnapshotRecord<T>[]> {
    const userId = uid();
    if (!userId) return [];
    try {
        const all = await idbAll<OfflineSnapshotRecord<T>>(userId, 'snapshots');
        return all.filter((r) => r.kind === kind).sort((a, b) => b.viewedAt - a.viewedAt);
    } catch {
        return [];
    }
}

/** Change a stored snapshot in place (keeps its viewedAt). */
export async function updateSnapshot<T>(key: string, change: (data: T) => T): Promise<T | null> {
    const userId = uid();
    if (!userId) return null;
    const record = await idbGet<OfflineSnapshotRecord<T>>(userId, 'snapshots', key);
    if (!record) return null;
    const data = change(record.data);
    await idbPut(userId, 'snapshots', { ...record, data });
    snapshotListeners.forEach((l) => l(key));
    return data;
}

const snapshotListeners = new Set<(key: string) => void>();

/** Views re-read their snapshot when sync or a resolution changed it. */
export function onSnapshotChange(listener: (key: string) => void): () => void {
    snapshotListeners.add(listener);
    return () => snapshotListeners.delete(listener);
}

// ── Outbox ───────────────────────────────────────────────────────────────────

export function newId(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

const sameTarget = (a: OfflineMutation['target'], b: OfflineMutation['target']) =>
    !!a && !!b && ((!!a.id && a.id === b.id) || (!!a.ref && a.ref === b.ref));

export type NewMutation = Omit<OfflineMutation, 'id' | 'created_at' | 'seq' | 'sent'> & { id?: string };

/**
 * Queue a change. Edits of something still waiting to sync are folded into it (never into a
 * change that was already sent — the server may have it): a rename of a task created offline
 * becomes part of the create, two renames become one, keeping the oldest base value per field.
 */
export function enqueue(mutation: NewMutation, options: { rebase?: boolean } = {}): Promise<OfflineMutation | null> {
    return withOutboxLock(() => enqueueLocked(mutation, options.rebase ?? false));
}

let outboxLock: Promise<unknown> = Promise.resolve();

/**
 * Serialise outbox writes with the sync engine picking the next change — across tabs too (Web
 * Locks; a per-tab queue where the browser has none): a change is either folded in before it is
 * sent, or sent first and then never touched again.
 */
export function withOutboxLock<T>(run: () => Promise<T>): Promise<T> {
    const locked = () => (typeof navigator !== 'undefined' && navigator.locks
        ? navigator.locks.request('brainitive-offline-outbox', run) as Promise<T>
        : run());
    const next = outboxLock.then(locked, locked);
    outboxLock = next.catch(() => undefined);
    return next;
}

/**
 * `rebase` ("keep mine" after a conflict): a newer pending edit of the same field wins over the
 * resolution, and every field is now based on the server's value (theirs).
 */
async function enqueueLocked(mutation: NewMutation, rebase: boolean): Promise<OfflineMutation | null> {
    const userId = uid();
    if (!userId) return null;
    const pending = (await idbAll<OfflineMutation>(userId, 'outbox')).filter((m) => !m.sent);

    if (mutation.type === 'list_task.update' && mutation.target?.ref) {
        const create = pending.find((m) => m.type === 'list_task.create' && m.id === mutation.target?.ref);
        if (create) {
            const merged = { ...create, data: { ...create.data, ...mutation.data }, entity: { ...create.entity, label: String(mutation.data.title ?? create.entity.label) } };
            await idbPut(userId, 'outbox', merged);
            await refreshOffline();
            return merged;
        }
    }

    if (mutation.type === 'list_task.update' || mutation.type === 'issue.update') {
        const earlier = pending.find((m) => m.type === mutation.type && sameTarget(m.target, mutation.target));
        if (earlier) {
            const merged: OfflineMutation = rebase
                ? {
                    ...earlier,
                    data: { ...mutation.data, ...earlier.data },
                    base: { ...earlier.base, fields: { ...(earlier.base?.fields ?? {}), ...(mutation.base?.fields ?? {}) } },
                }
                : {
                    ...earlier,
                    data: { ...earlier.data, ...mutation.data },
                    base: { ...earlier.base, fields: { ...(mutation.base?.fields ?? {}), ...(earlier.base?.fields ?? {}) } },
                    entity: { ...earlier.entity, label: mutation.entity.label },
                };
            await idbPut(userId, 'outbox', merged);
            await refreshOffline();
            return merged;
        }
    }

    if (mutation.type === 'list_task.reorder') {
        // the newest order replaces an unsent older one, at the end of the line (it may name tasks
        // created after the older one)
        const earlier = pending.find((m) => m.type === 'list_task.reorder' && m.data.task_list_id === mutation.data.task_list_id);
        if (earlier?.seq !== undefined) await idbDelete(userId, 'outbox', earlier.seq);
    }

    if (mutation.type === 'my_day.remove') {
        // added and removed again before it ever synced: nothing to send
        const added = pending.find((m) => m.type === 'my_day.add' && m.entity.key === mutation.entity.key);
        if (added?.seq !== undefined) {
            await idbDelete(userId, 'outbox', added.seq);
            await refreshOffline();
            return null;
        }
    }

    const record: OfflineMutation = { ...mutation, id: mutation.id ?? newId(), created_at: new Date().toISOString() };
    const seq = await idbPut(userId, 'outbox', record);
    await refreshOffline();
    return { ...record, seq: Number(seq) };
}

/** The next change to send, marked as sent (under the outbox lock). */
export function takeNext(): Promise<OfflineMutation | null> {
    return withOutboxLock(async () => {
        const userId = uid();
        if (!userId) return null;
        const [next] = await readOutbox();
        if (!next) return null;
        if (!next.sent) await idbPut(userId, 'outbox', { ...next, sent: true });
        return { ...next, sent: true };
    });
}

export async function removeFromOutbox(mutation: OfflineMutation): Promise<void> {
    const userId = uid();
    if (!userId || mutation.seq === undefined) return;
    await idbDelete(userId, 'outbox', mutation.seq);
}

export async function readOutbox(): Promise<OfflineMutation[]> {
    const userId = uid();
    if (!userId) return [];
    return (await idbAll<OfflineMutation>(userId, 'outbox')).sort((a, b) => (a.seq ?? 0) - (b.seq ?? 0));
}

// ── Conflicts ────────────────────────────────────────────────────────────────

export async function addConflict(conflict: OfflineConflict): Promise<void> {
    const userId = uid();
    if (!userId) return;
    await idbPut(userId, 'conflicts', conflict);
}

export async function removeConflict(id: string): Promise<void> {
    const userId = uid();
    if (!userId) return;
    await idbDelete(userId, 'conflicts', id);
    await refreshOffline();
}

/** Keys of everything waiting to sync or in conflict: rows show "not synced yet". */
export function pendingKeys(s: OfflineState): Set<string> {
    const keys = new Set<string>();
    s.outbox.forEach((m) => keys.add(m.entity.key));
    s.conflicts.forEach((c) => keys.add(c.mutation.entity.key));
    return keys;
}

export const taskKey = (task: { id: number | null; ref?: string | null }) => (task.id ? `list_task:${task.id}` : `list_task:ref:${task.ref}`);
export const issueKey = (id: number) => `issue:${id}`;
