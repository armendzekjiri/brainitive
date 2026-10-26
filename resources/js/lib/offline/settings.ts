/**
 * Offline mode (BRAI-82): the per-device opt-in. Plain localStorage flags (no data):
 * - `brainitive-offline:enabled:<userId>` — "Work offline on this device" for that account,
 * - `brainitive-offline:user` — whose offline data this device holds (the shell opens that one),
 * - `brainitive-offline:users` — every account with offline data here, so sign-out can clear all.
 */

const ENABLED = (userId: number) => `brainitive-offline:enabled:${userId}`;
const ACTIVE = 'brainitive-offline:user';
const KNOWN = 'brainitive-offline:users';

function read(key: string): string | null {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function write(key: string, value: string | null): void {
    try {
        if (value === null) window.localStorage.removeItem(key);
        else window.localStorage.setItem(key, value);
    } catch {
        // private mode / storage blocked: offline mode just stays off
    }
}

export function isOfflineEnabled(userId: number | null | undefined): boolean {
    return !!userId && read(ENABLED(userId)) === '1';
}

export function setOfflineEnabled(userId: number, enabled: boolean): void {
    write(ENABLED(userId), enabled ? '1' : null);
}

/** The account whose offline data this device holds, or null. */
export function activeOfflineUser(): number | null {
    const id = Number(read(ACTIVE));
    return Number.isInteger(id) && id > 0 ? id : null;
}

export function setActiveOfflineUser(userId: number | null): void {
    write(ACTIVE, userId === null ? null : String(userId));
    if (userId !== null) {
        const known = knownOfflineUsers();
        if (!known.includes(userId)) write(KNOWN, JSON.stringify([...known, userId]));
    }
}

export function knownOfflineUsers(): number[] {
    try {
        const parsed: unknown = JSON.parse(read(KNOWN) ?? '[]');
        return Array.isArray(parsed) ? parsed.filter((id): id is number => Number.isInteger(id) && id > 0) : [];
    } catch {
        return [];
    }
}

export function forgetOfflineUser(userId: number): void {
    write(KNOWN, JSON.stringify(knownOfflineUsers().filter((id) => id !== userId)));
    if (activeOfflineUser() === userId) write(ACTIVE, null);
}
