/**
 * Offline mode (BRAI-82): who may use the device's offline data, and when it is wiped.
 *
 * - Turning offline mode on stores data only for the signed-in account (its own database).
 * - Turning it off, "Clear offline data", signing out and a different account signing in on this
 *   device delete it (database + cached app shell).
 * - A session that merely expired keeps it: the same person signs in again and it syncs.
 */
import { router } from '@inertiajs/react';
import { deleteDb, isSupported, listOfflineDbUserIds } from './db';
import {
    activeOfflineUser, forgetOfflineUser, isOfflineEnabled, knownOfflineUsers, setActiveOfflineUser, setOfflineEnabled,
} from './settings';
import { cacheShell, dropShell } from './shell';
import { getOfflineState, refreshOffline, setOfflineState } from './store';
import { startSyncEngine, syncNow } from './sync';

/** Start offline mode for this (signed-in) account on this device. */
export async function activateOffline(userId: number): Promise<void> {
    if (!isSupported() || !isOfflineEnabled(userId)) return;
    const previous = activeOfflineUser();
    if (previous && previous !== userId) await clearUserData(previous);
    setActiveOfflineUser(userId);
    if (getOfflineState().userId !== userId) {
        setOfflineState({ userId, needsSignIn: false });
        await refreshOffline();
    }
    startSyncEngine();
}

/** The account's offline data is no longer reachable from this tab (signed out / turned off). */
function deactivate(): void {
    setOfflineState({ userId: null, outbox: [], conflicts: [], syncing: false, needsSignIn: false });
}

export async function enableOffline(userId: number): Promise<void> {
    setOfflineEnabled(userId, true);
    await activateOffline(userId);
    await cacheShell(true);
}

/** Turn offline mode off on this device: everything stored for the account goes. */
export async function disableOffline(userId: number): Promise<void> {
    setOfflineEnabled(userId, false);
    await clearUserData(userId);
}

/** "Clear offline data": wipe the stored copies and waiting changes, keep the setting. */
export async function clearOfflineData(userId: number): Promise<void> {
    await clearUserData(userId);
    if (isOfflineEnabled(userId)) {
        await activateOffline(userId);
        await cacheShell(true).catch(() => undefined);
    }
}

async function clearUserData(userId: number): Promise<void> {
    if (getOfflineState().userId === userId) deactivate();
    await deleteDb(userId);
    forgetOfflineUser(userId);
    if (knownOfflineUsers().length === 0) await dropShell();
}

/** Sign-out: every account's offline data on this device. */
export async function clearAllOfflineData(): Promise<void> {
    deactivate();
    const ids = new Set([...knownOfflineUsers(), ...(await listOfflineDbUserIds())]);
    const active = activeOfflineUser();
    if (active) ids.add(active);
    await Promise.all([...ids].map(async (id) => {
        await deleteDb(id);
        forgetOfflineUser(id);
    }));
    setActiveOfflineUser(null);
    await dropShell();
}

let watching = false;
let shellChecked = false;

/**
 * Follow the Inertia session (mounted once in app.tsx): start offline mode for the signed-in
 * account, wipe another account's data, and clear everything on sign-out.
 */
export function watchOfflineSession(): void {
    if (watching || typeof window === 'undefined') return;
    watching = true;

    router.on('navigate', (event) => {
        const props = event.detail.page.props as { auth?: { user?: { id: number } | null } };
        const userId = props.auth?.user?.id ?? null;
        if (!userId) return; // guest page or expired session: keep the data for the same person
        const previous = activeOfflineUser();
        if (previous && previous !== userId) {
            void clearUserData(previous);
        }
        if (isOfflineEnabled(userId)) {
            void activateOffline(userId).then(() => {
                // once per page load: a new deploy refreshes the cached shell in the background
                if (shellChecked) return;
                shellChecked = true;
                void cacheShell().catch(() => undefined);
            });
        }
    });

    router.on('before', (event) => {
        const visit = event.detail.visit;
        const path = new URL(visit.url.toString(), window.location.origin).pathname;
        const signingOut = visit.method === 'post' && path === pathOf('logout');
        // deleting the account signs out too (its own confirmation already asked)
        if (visit.method === 'delete' && path === pathOf('profile.destroy')) {
            void clearAllOfflineData();
            return;
        }
        if (!signingOut) return;
        const { outbox, conflicts } = getOfflineState();
        const waiting = outbox.length + conflicts.length;
        if (waiting > 0 && !window.confirm(`${waiting === 1 ? '1 change hasn’t' : `${waiting} changes haven’t`} synced yet. Signing out deletes ${waiting === 1 ? 'it' : 'them'} from this device. Sign out anyway?`)) {
            event.preventDefault();
            return;
        }
        void clearAllOfflineData();
    });
}

function pathOf(name: string): string | null {
    try {
        return new URL(route(name), window.location.origin).pathname;
    } catch {
        return null;
    }
}

export { syncNow };
