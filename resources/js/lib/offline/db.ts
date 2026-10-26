/**
 * Offline mode (BRAI-82): a tiny promise wrapper around IndexedDB, no dependency.
 *
 * One database per signed-in user: `brainitive-offline-<userId>` with
 * - `snapshots` (key): My Day, task lists and issues the user opened online (bounded),
 * - `outbox` (seq, auto-increment): changes waiting to sync, in order,
 * - `conflicts` (id): changes the server didn't take as is.
 * Live co-editing of documents keeps its own `brainitive-docs-*` databases; never touch them here.
 */

export const DB_PREFIX = 'brainitive-offline-';
const VERSION = 1;

export type StoreName = 'snapshots' | 'outbox' | 'conflicts';

const connections = new Map<number, Promise<IDBDatabase>>();

export const dbName = (userId: number) => `${DB_PREFIX}${userId}`;

export function isSupported(): boolean {
    return typeof indexedDB !== 'undefined';
}

function request<T>(req: IDBRequest<T>): Promise<T> {
    return new Promise((resolve, reject) => {
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

export function openDb(userId: number): Promise<IDBDatabase> {
    const open = connections.get(userId);
    if (open) return open;

    const promise = new Promise<IDBDatabase>((resolve, reject) => {
        const req = indexedDB.open(dbName(userId), VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains('snapshots')) db.createObjectStore('snapshots', { keyPath: 'key' });
            if (!db.objectStoreNames.contains('outbox')) db.createObjectStore('outbox', { keyPath: 'seq', autoIncrement: true });
            if (!db.objectStoreNames.contains('conflicts')) db.createObjectStore('conflicts', { keyPath: 'id' });
        };
        req.onsuccess = () => {
            const db = req.result;
            // "Clear offline data" / sign out deletes the database: let it go at once.
            db.onversionchange = () => {
                db.close();
                connections.delete(userId);
            };
            resolve(db);
        };
        req.onerror = () => reject(req.error);
    });
    connections.set(userId, promise);
    promise.catch(() => connections.delete(userId));
    return promise;
}

async function tx<T>(userId: number, store: StoreName, mode: IDBTransactionMode, run: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    const db = await openDb(userId);
    return request(run(db.transaction(store, mode).objectStore(store)));
}

export const idbGet = <T>(userId: number, store: StoreName, key: IDBValidKey) =>
    tx<T | undefined>(userId, store, 'readonly', (s) => s.get(key) as IDBRequest<T | undefined>);

export const idbAll = <T>(userId: number, store: StoreName) =>
    tx<T[]>(userId, store, 'readonly', (s) => s.getAll() as IDBRequest<T[]>);

export const idbPut = <T>(userId: number, store: StoreName, value: T) =>
    tx<IDBValidKey>(userId, store, 'readwrite', (s) => s.put(value));

export const idbDelete = (userId: number, store: StoreName, key: IDBValidKey) =>
    tx<undefined>(userId, store, 'readwrite', (s) => s.delete(key));

/** Delete a user's whole offline database (closes our own connection first). */
export async function deleteDb(userId: number): Promise<void> {
    const open = connections.get(userId);
    connections.delete(userId);
    if (open) {
        try {
            (await open).close();
        } catch {
            // never opened
        }
    }
    if (!isSupported()) return;
    await new Promise<void>((resolve) => {
        const req = indexedDB.deleteDatabase(dbName(userId));
        req.onsuccess = () => resolve();
        req.onerror = () => resolve();
        // another tab still has it open: it closes on `versionchange`, the delete then completes
        req.onblocked = () => resolve();
    });
}

/** Every offline database on this device (all users), where the browser can list them. */
export async function listOfflineDbUserIds(): Promise<number[]> {
    if (!isSupported() || typeof indexedDB.databases !== 'function') return [];
    try {
        const dbs = await indexedDB.databases();
        return dbs
            .map((d) => d.name ?? '')
            .filter((name) => name.startsWith(DB_PREFIX))
            .map((name) => Number(name.slice(DB_PREFIX.length)))
            .filter((id) => Number.isInteger(id) && id > 0);
    } catch {
        return [];
    }
}
