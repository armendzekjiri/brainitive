import { browser } from 'wxt/browser';
import type { Mode, PendingCapture, Session } from './types';

/**
 * Tokens and settings stay in `storage.local` on this device — never `storage.sync`.
 * The pending capture (context menu / screenshot → popup hand-off) lives in
 * `storage.session`, which the browser clears when it closes.
 */

export interface RegisteredClient {
    clientId: string;
    redirectUri: string;
}

export async function getSession(): Promise<Session | null> {
    const { session } = await browser.storage.local.get('session');
    return (session as Session | undefined) ?? null;
}

export async function setSession(session: Session | null): Promise<void> {
    if (session) {
        await browser.storage.local.set({ session });
    } else {
        await browser.storage.local.remove('session');
    }
}

export async function getClient(server: string): Promise<RegisteredClient | null> {
    const { clients } = await browser.storage.local.get('clients');
    return ((clients as Record<string, RegisteredClient> | undefined) ?? {})[server] ?? null;
}

export async function setClient(server: string, client: RegisteredClient): Promise<void> {
    const { clients } = await browser.storage.local.get('clients');
    await browser.storage.local.set({ clients: { ...((clients as object) ?? {}), [server]: client } });
}

export async function forgetClient(server: string): Promise<void> {
    const { clients } = await browser.storage.local.get('clients');
    const rest = { ...((clients as Record<string, RegisteredClient>) ?? {}) };
    delete rest[server];
    await browser.storage.local.set({ clients: rest });
}

/** The last server typed in, so a self-hoster doesn't retype it after signing out. */
export async function getLastServer(): Promise<string | null> {
    const { lastServer } = await browser.storage.local.get('lastServer');
    return (lastServer as string | undefined) ?? null;
}

export async function setLastServer(server: string): Promise<void> {
    await browser.storage.local.set({ lastServer: server });
}

/** Last destination per mode, e.g. `{ issue: { boardId: 3, columnId: 9 } }`. */
export async function getLastDestination<T>(mode: Mode): Promise<T | null> {
    const { lastDestination } = await browser.storage.local.get('lastDestination');
    return ((lastDestination as Record<string, T> | undefined) ?? {})[mode] ?? null;
}

export async function setLastDestination(mode: Mode, value: unknown): Promise<void> {
    const { lastDestination } = await browser.storage.local.get('lastDestination');
    await browser.storage.local.set({ lastDestination: { ...((lastDestination as object) ?? {}), [mode]: value } });
}

export async function getLastMode(): Promise<Mode> {
    const { lastMode } = await browser.storage.local.get('lastMode');
    return (lastMode as Mode | undefined) ?? 'issue';
}

export async function setLastMode(mode: Mode): Promise<void> {
    await browser.storage.local.set({ lastMode: mode });
}

export async function setPendingCapture(capture: PendingCapture): Promise<void> {
    await browser.storage.session.set({ pendingCapture: capture });
}

/** Read once: the popup takes the hand-off and clears it. */
export async function takePendingCapture(): Promise<PendingCapture | null> {
    const { pendingCapture } = await browser.storage.session.get('pendingCapture');
    if (pendingCapture) {
        await browser.storage.session.remove('pendingCapture');
    }
    return (pendingCapture as PendingCapture | undefined) ?? null;
}
