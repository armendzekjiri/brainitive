/**
 * Live co-editing of documents (BRAI-54) — small shared pieces: user colours, the WebSocket URL,
 * IndexedDB names and the adapter that gives Lexical's CollaborationPlugin the provider shape it
 * expects. Plan: docs/ai/tasks/feat-live-docs.md.
 */
import type { HocuspocusProvider } from '@hocuspocus/provider';
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
