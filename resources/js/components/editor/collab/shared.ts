/**
 * Live co-editing of documents (BRAI-54) — small shared pieces: user colours, the WebSocket URL,
 * IndexedDB names and the adapter that gives Lexical's CollaborationPlugin the provider shape it
 * expects. Plan: docs/ai/tasks/feat-live-docs.md.
 */
import { HocuspocusProvider, HocuspocusProviderWebsocket } from '@hocuspocus/provider';
import type { Provider } from '@lexical/yjs';
import * as Y from 'yjs';
import type { Doc } from 'yjs';

/** Caret / avatar colours: white name labels stay readable on each (WCAG AA for large bold text). */
export const COLLAB_COLORS = ['#2563eb', '#c026d3', '#c2410c', '#0f766e', '#dc2626', '#7c3aed', '#0369a1', '#be123c', '#4d7c0f', '#a16207'];

export const collabColor = (userId: number) => COLLAB_COLORS[Math.abs(userId) % COLLAB_COLORS.length];

/** COLLAB_URL may be a full ws(s)/http(s) URL or a path on this host ("/collab" in Docker). */
export function collabSocketUrl(url: string): string {
    if (url.startsWith('/')) {
        return `${window.location.protocol === 'https:' ? 'wss' : 'ws'}://${window.location.host}${url}`;
    }
    return url.replace(/^http(s?):\/\//, 'ws$1://');
}

/** One IndexedDB per user, page and live document (namespaced: other features use their own). */
export const collabDbName = (userId: number, pageUuid: string, epoch: number) => `brainitive-docs-u${userId}-${pageUuid}-e${epoch}`;

/** Forget the local copy of an older live document of this page (best effort). */
export function forgetOlderLocalCopies(userId: number, pageUuid: string, epoch: number) {
    const key = `brainitive-docs-epoch-u${userId}-${pageUuid}`;
    try {
        const previous = Number(localStorage.getItem(key));
        if (Number.isInteger(previous) && previous !== epoch && localStorage.getItem(key) !== null) {
            indexedDB.deleteDatabase(collabDbName(userId, pageUuid, previous));
        }
        localStorage.setItem(key, String(epoch));
    } catch {
        /* storage blocked: nothing to clean up */
    }
}

/** What each browser publishes next to its caret (Lexical's awareness `awarenessData`). */
export interface CollabAwarenessData {
    userId: number;
    edit: boolean;
}

const COLOR = /^#[0-9a-f]{6}$/i;

/**
 * Awareness states are whatever other browsers send. Lexical writes `color` into style attributes and
 * `name` into the caret label: only a plain colour (else the user's own) and a short name get through.
 * The realtime server also disconnects anyone announcing someone else (realtime/src/server.js).
 */
function safeAwarenessState(state: Record<string, unknown>): Record<string, unknown> {
    const data = state.awarenessData as Partial<CollabAwarenessData> | undefined;
    const userId = typeof data?.userId === 'number' ? data.userId : 0;
    return {
        ...state,
        color: typeof state.color === 'string' && COLOR.test(state.color) ? state.color : collabColor(userId),
        name: String(state.name ?? '').slice(0, 80),
    };
}

const MIRROR_IN = 'brainitive-mirror-in';
const MIRROR_OUT = 'brainitive-mirror-out';

/**
 * Bridge between the Hocuspocus provider and Lexical's CollaborationPlugin.
 * - Lexical (v1 binding) only renders *changes* of its Y doc, never content that's already there when
 *   the binding is created — but we connect (and sync / load IndexedDB) before the editor mounts. So
 *   the editor gets its own, empty Y doc; when the plugin starts listening (its `sync` registration,
 *   right after the binding observes the doc) the provider's document is copied in and both docs are
 *   mirrored from then on (same item ids, so carets / relative positions match).
 * - Lexical wants `on('sync', isSynced)`; Hocuspocus emits `synced`. connect / disconnect from the
 *   plugin are ignored: useLiveDocument owns the provider's life cycle.
 */
export function createLexicalBridge(provider: HocuspocusProvider) {
    const transport = provider.document;
    let editorDoc: Doc | null = null;
    let unmirror: (() => void) | null = null;

    const mirror = () => {
        const target = editorDoc;
        if (!target || unmirror) return;
        Y.applyUpdate(target, Y.encodeStateAsUpdate(transport), MIRROR_IN);
        const out = (update: Uint8Array, origin: unknown) => { if (origin !== MIRROR_IN) Y.applyUpdate(transport, update, MIRROR_OUT); };
        const into = (update: Uint8Array, origin: unknown) => { if (origin !== MIRROR_OUT) Y.applyUpdate(target, update, MIRROR_IN); };
        target.on('update', out);
        transport.on('update', into);
        unmirror = () => { target.off('update', out); transport.off('update', into); };
    };

    const syncHandlers = new Map<unknown, (data: { state: boolean }) => void>();
    const statusHandlers = new Map<unknown, (arg: { status: string }) => void>();
    const awareness = provider.awareness!;
    const adapter = {
        awareness: {
            getLocalState: () => awareness.getLocalState(),
            setLocalState: (state: Record<string, unknown> | null) => awareness.setLocalState(state),
            setLocalStateField: (field: string, value: unknown) => awareness.setLocalStateField(field, value),
            on: (type: 'update', cb: () => void) => awareness.on(type, cb),
            off: (type: 'update', cb: () => void) => awareness.off(type, cb),
            getStates: () => {
                const states = new Map<number, Record<string, unknown>>();
                awareness.getStates().forEach((state, clientId) => states.set(clientId, safeAwarenessState(state)));
                return states;
            },
        },
        connect: () => undefined,
        disconnect: () => undefined,
        on(type: string, cb: (arg: never) => void) {
            if (type === 'sync') {
                mirror();
                const handler = ({ state }: { state: boolean }) => (cb as (isSynced: boolean) => void)(state);
                syncHandlers.set(cb, handler);
                provider.on('synced', handler);
                if (provider.isSynced) handler({ state: true });
            } else if (type === 'status') {
                const handler = (arg: { status: string }) => (cb as (arg: { status: string }) => void)(arg);
                statusHandlers.set(cb, handler);
                provider.on('status', handler);
            }
        },
        off(type: string, cb: (arg: never) => void) {
            const handler = type === 'sync' ? syncHandlers.get(cb) : statusHandlers.get(cb);
            if (handler) provider.off(type === 'sync' ? 'synced' : 'status', handler);
        },
    } as unknown as Provider;

    return {
        /** CollaborationPlugin's providerFactory: a fresh editor doc per mount. */
        providerFactory(id: string, yjsDocMap: Map<string, Doc>): Provider {
            unmirror?.();
            unmirror = null;
            editorDoc?.destroy();
            editorDoc = new Y.Doc();
            yjsDocMap.set(id, editorDoc);
            return adapter;
        },
        destroy() {
            unmirror?.();
            unmirror = null;
            editorDoc?.destroy();
            editorDoc = null;
        },
    };
}

/** The binding the editor needs to run in live mode (built by useLiveDocument). */
export interface LiveEditorBinding {
    /** Y document name, also Lexical's collab id. */
    id: string;
    providerFactory: (id: string, yjsDocMap: Map<string, Doc>) => Provider;
    username: string;
    color: string;
    awarenessData: CollabAwarenessData;
    /** The editor registers itself so saves can render HTML from it. */
    onEditor: (editor: import('lexical').LexicalEditor | null) => void;
    /** Local vs remote change (remote = arrived from someone else through Yjs). */
    onChange: (remote: boolean) => void;
}

// ── Why live editing is unavailable, and getting it back (BRAI-345) ──────────────────────────────

/**
 * - `unreachable`: no WebSocket to the realtime server (process down, nginx `/collab`, network);
 * - `timeout`: connected, but no answer in time (the server waits for Brainitive's access check / load);
 * - `refused`: the server refused this browser (token, access);
 * - `app`: the server can't reach Brainitive's internal API (COLLAB_APP_URL / COLLAB_APP_HOST, firewall);
 * - `session`: Brainitive itself didn't hand out a live session (offline, HTTP error, timeout).
 */
export type LiveFailureReason = 'unreachable' | 'timeout' | 'refused' | 'app' | 'session';

export interface LiveFailure {
    reason: LiveFailureReason;
    /** short technical detail for the notice and the console (close code, server reason, HTTP status) */
    detail: string;
}

const REFUSALS: Record<string, string> = {
    'invalid-token': 'sign-in token rejected',
    'expired-token': 'sign-in token expired',
    'wrong-document': 'token for another document',
    'invalid-document': 'unknown document',
    'document-replaced': 'the document was replaced',
    'access-denied': 'no access',
};

/** A permission-denied / close reason from realtime/src/server.js → what it means. */
export function refusalFailure(serverReason: string | undefined): LiveFailure {
    const reason = String(serverReason ?? '').replace(/^Unauthorized: /, '');
    if (reason === 'app-unreachable') return { reason: 'app', detail: 'it can’t reach Brainitive' };
    const appError = reason.match(/^app-error-(\d{3})$/);
    if (appError) return { reason: 'app', detail: `Brainitive answered ${appError[1]}` };
    return { reason: 'refused', detail: REFUSALS[reason] ?? (reason || 'permission denied') };
}

/** An axios error of a session request → what it means. */
export function sessionFailure(error: unknown): LiveFailure {
    const e = error as { code?: string; response?: { status?: number } };
    if (e?.response?.status) return { reason: 'session', detail: `HTTP ${e.response.status}` };
    if (e?.code === 'ECONNABORTED' || e?.code === 'ETIMEDOUT') return { reason: 'session', detail: 'no answer in time' };
    return { reason: 'session', detail: typeof navigator !== 'undefined' && navigator.onLine === false ? 'you’re offline' : 'no connection' };
}

/** One sentence for the notice, e.g. "The live editing server can’t be reached (no connection)." */
export function describeLiveFailure(failure: LiveFailure | null): string {
    if (!failure) return '';
    const what = {
        unreachable: 'The live editing server can’t be reached',
        timeout: 'The live editing server didn’t answer in time',
        refused: 'The live editing server refused the connection',
        app: 'The live editing server can’t reach Brainitive',
        session: 'Brainitive couldn’t start live editing',
    }[failure.reason];
    return failure.detail ? `${what} (${failure.detail}).` : `${what}.`;
}

/** Waits between automatic attempts to get live editing back (then 60 s each). */
export const RECOVERY_DELAYS_MS = [5_000, 10_000, 20_000, 40_000, 60_000];

/**
 * Run `attempt` with backoff until it returns true — also right away when the browser comes back
 * online or the tab becomes visible again (a laptop waking up). Returns the stop function.
 */
export function retryWithBackoff(attempt: () => Promise<boolean>, delays = RECOVERY_DELAYS_MS): () => void {
    let stopped = false;
    let running = false;
    let tries = 0;
    let timer: ReturnType<typeof setTimeout> | null = null;
    const run = async () => {
        if (stopped || running) return;
        running = true;
        if (timer) clearTimeout(timer);
        timer = null;
        let done = false;
        try {
            done = await attempt();
        } catch {
            done = false;
        }
        running = false;
        if (stopped) return;
        if (done) {
            stop();
            return;
        }
        tries += 1;
        schedule();
    };
    const schedule = () => {
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => { void run(); }, delays[Math.min(tries, delays.length - 1)]);
    };
    const now = () => { if (document.visibilityState !== 'hidden') void run(); };
    const stop = () => {
        stopped = true;
        if (timer) clearTimeout(timer);
        window.removeEventListener('online', now);
        document.removeEventListener('visibilitychange', now);
    };
    window.addEventListener('online', now);
    document.addEventListener('visibilitychange', now);
    schedule();
    return stop;
}

/**
 * One connection attempt to see whether live editing works again (fallback mode): a throw-away
 * provider (own Y doc, no awareness, no IndexedDB) that only waits for the first sync. Null = works.
 */
export function probeLiveConnection(url: string, documentName: string, token: string, timeoutMs = 10_000): Promise<LiveFailure | null> {
    return new Promise((resolve) => {
        const websocketProvider = new HocuspocusProviderWebsocket({ url });
        const doc = new Y.Doc();
        const provider = new HocuspocusProvider({ websocketProvider, name: documentName, document: doc, token, awareness: null });
        let opened = false;
        let finished = false;
        const finish = (result: LiveFailure | null) => {
            if (finished) return;
            finished = true;
            clearTimeout(timer);
            provider.destroy();
            websocketProvider.destroy();
            doc.destroy();
            resolve(result);
        };
        const timer = setTimeout(() => finish(opened
            ? { reason: 'timeout', detail: `no answer within ${Math.round(timeoutMs / 1000)} s` }
            : { reason: 'unreachable', detail: 'no connection' }), timeoutMs);
        provider.on('open', () => { opened = true; });
        provider.on('synced', () => finish(null));
        provider.on('authenticationFailed', ({ reason }: { reason: string }) => finish(refusalFailure(reason)));
        websocketProvider.on('disconnect', ({ event }: { event?: CloseEvent }) => {
            if (event?.reason?.startsWith('Unauthorized: ')) finish(refusalFailure(event.reason));
            else finish({ reason: 'unreachable', detail: opened ? `connection closed (${event?.code ?? '?'})` : `no connection (${event?.code ?? '?'})` });
        });
        provider.attach();
    });
}
