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

/**
 * The session (with its tokens) is read by the background only. `account` mirrors the
 * non-secret part — which server you're signed in to — for the extension pages (Codex
 * CLI/extension review 8).
 */
export async function setSession(session: Session | null): Promise<void> {
    if (session) {
        await browser.storage.local.set({ session, account: { server: session.server } satisfies Account });
    } else {
        await browser.storage.local.remove(['session', 'account']);
    }
}

export interface Account {
    server: string;
}

export async function getAccount(): Promise<Account | null> {
    const { account } = await browser.storage.local.get('account');
    return (account as Account | undefined) ?? null;
}

/** Sessions stored before v1.2 have no `account` yet. */
export async function syncAccount(): Promise<void> {
    const session = await getSession();
    if (session && !(await getAccount())) {
        await browser.storage.local.set({ account: { server: session.server } satisfies Account });
    }
}

/**
 * Keep storage.local (the session's tokens) away from content scripts where the browser
 * supports it (`setAccessLevel`; Chrome restricts only some areas). Content scripts of this
 * extension don't read it anyway; this is defense in depth.
 */
export async function restrictStorageToExtensionPages(): Promise<void> {
    const area = browser.storage.local as { setAccessLevel?: (options: { accessLevel: string }) => Promise<void> };
    try {
        await area.setAccessLevel?.({ accessLevel: 'TRUSTED_CONTEXTS' });
    } catch {
        // not supported for this area / browser
    }
}

/** Session generation: changes on every sign-in and sign-out (see `auth.ts`). */
export async function getGeneration(): Promise<number> {
    const { sessionGeneration } = await browser.storage.local.get('sessionGeneration');
    return (sessionGeneration as number | undefined) ?? 0;
}

export async function bumpGeneration(): Promise<void> {
    await browser.storage.local.set({ sessionGeneration: (await getGeneration()) + 1 });
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
