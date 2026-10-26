/**
 * Live whiteboards (BRAI-153): binds an Excalidraw canvas (the `excalidraw-build` bundle) to a shared
 * Yjs document on the realtime server — elements, live-added images, cursors with names, selections —
 * and stores the live scene as the whiteboard content (Laravel stays the source of truth).
 * Framework-free: used by the whiteboard editor (Blade page, entry `resources/js/whiteboard-live.ts`)
 * and by the read-only preview on the page (hooks/useLiveWhiteboardView.ts).
 * Plan: docs/ai/tasks/feat-live-whiteboards.md.
 *
 * Modes: `connecting` → `live` (also while offline: changes kept on this device) or `fallback` (server
 * unreachable and nothing on this device: today's single-user editor); `replaced` (the scene was
 * changed elsewhere: reload), `off` (no access).
 */
import { HocuspocusProvider } from '@hocuspocus/provider';
import { IndexeddbPersistence } from 'y-indexeddb';
import * as Y from 'yjs';
import { collabColor, collabSocketUrl, type CollabAwarenessData } from '@/components/editor/collab/shared';
import {
    beats, byIndex, copy, covers, cursorColor, isImageDataUrl, isSceneElement, MAX_INLINE_IMAGE_CHARS, sameRevision,
    sanitizePointer, sanitizeSelection, sharedImagePath,
    type BinaryFile, type PointerState, type SceneElement, type SharedFile,
} from './scene';

// ── what the Excalidraw bundle provides (excalidraw-build/src/main.jsx) ─────────────────────────

export interface ExcalidrawApi {
    getSceneElementsIncludingDeleted(): readonly SceneElement[];
    getAppState(): Record<string, unknown>;
    getFiles(): Record<string, BinaryFile>;
    updateScene(scene: { elements?: readonly SceneElement[]; collaborators?: Map<string, unknown>; captureUpdate?: unknown }): void;
    addFiles(files: BinaryFile[]): void;
}

export interface WhiteboardHandle {
    whenReady(): Promise<ExcalidrawApi>;
    on(type: 'change', listener: (elements: readonly SceneElement[], appState: Record<string, unknown>, files: Record<string, BinaryFile>) => void): () => void;
    on(type: 'pointer', listener: (payload: { pointer: { x: number; y: number; tool?: string }; button: 'up' | 'down' }) => void): () => void;
    setCollaborating(on: boolean): void;
    setViewMode(on: boolean): void;
}

export interface ExcalidrawStatics {
    reconcileElements(local: readonly SceneElement[], remote: readonly SceneElement[], appState: Record<string, unknown>): SceneElement[];
    restoreElements(elements: readonly SceneElement[], local: null): SceneElement[];
    CaptureUpdateAction: { NEVER: unknown };
}

// ── configuration / state ──────────────────────────────────────────────────────────────────────

export interface WhiteboardLiveConfig {
    /** COLLAB_URL: wss URL or a path on this host ("/collab") */
    url: string;
    /** epoch the page was loaded with — another one means the loaded scene is outdated */
    epoch: number;
    pageUuid: string;
    user: { id: number; name: string };
    sessionUrl: string;
    saveUrl?: string;
    bootstrapUrl?: string;
    uploadUrl?: string;
    /** read-only connection (the page preview), even for editors */
    view?: boolean;
}

export type WhiteboardLiveMode = 'connecting' | 'live' | 'fallback' | 'replaced' | 'off';

export interface WhiteboardPeer {
    clientId: number;
    userId: number;
    name: string;
    /** pastel cursor colour (dark text on it) */
    color: string;
    edit: boolean;
}

export interface WhiteboardLiveState {
    mode: WhiteboardLiveMode;
    connection: 'online' | 'connecting' | 'offline';
    /** something isn't on the server yet (Yjs update or the scene save) */
    pending: boolean;
    /** why the scene couldn't be stored; null = fine */
    saveFailed: string | null;
    edit: boolean;
    /** the live document has its scene: drawing is shared and saves are possible */
    ready: boolean;
    /** a copy is kept on this device (IndexedDB) */
    persisted: boolean;
    peers: WhiteboardPeer[];
    notice: string | null;
    editRevision: number | null;
}

export interface WhiteboardSaveResult {
    status: 'saved' | 'unchanged' | 'covered' | 'baseline';
    edit_revision: number;
    version: number | null;
}

export interface LiveWhiteboard {
    /** Store the scene now (Save / Save & exit); resolves with the server's answer, null when that failed. */
    flush(options?: { checkpoint?: boolean; image?: Blob }): Promise<WhiteboardSaveResult | null>;
    getState(): WhiteboardLiveState;
    destroy(): void;
}

interface Session {
    url: string;
    document: string;
    epoch: number;
    token: string;
    edit: boolean;
    vector: Record<string, number> | null;
    user: { id: number; name: string };
}

class HttpError extends Error {
    constructor(public status: number, public data: Record<string, unknown>) {
        super(typeof data.message === 'string' ? data.message : `Request failed (${status})`);
    }
}

const CONNECT_TIMEOUT_MS = 5_000;
const LOCAL_SAVE_MS = 2_000;
/** while someone draws without pause, the scene is still stored this often */
const MAX_SAVE_WAIT_MS = 10_000;
/** remote changes are stored by the leader only, a bit later (their author usually saved already) */
const REMOTE_SAVE_MS = 4_000;
const RETRY_SAVE_MS = 10_000;
const BOOTSTRAP_RETRY_MS = 2_500;
const PUSH_THROTTLE_MS = 50;
const POINTER_THROTTLE_MS = 50;
/** browsers cap keepalive bodies at 64 KB */
const KEEPALIVE_LIMIT = 60_000;
const LOCAL = 'brainitive-whiteboard-local';
const BOOTSTRAP = 'brainitive-whiteboard-bootstrap';
/** Reasons the server gives when it closes one document of a shared socket (realtime/src/server.js). */
const DOCUMENT_CLOSED = new Set(['Document replaced', 'Forbidden', 'Server closed the connection']);
const UPLOADABLE = new Set(['image/png', 'image/jpeg', 'image/gif', 'image/webp']);

const REPLACED_NOTICE = 'This whiteboard was changed outside live drawing (a version restored or saved without live drawing). Load the latest version to keep drawing.';

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));
const randomClaim = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');
const stateVector = (doc: Y.Doc): Record<string, number> => Object.fromEntries(Y.decodeStateVector(Y.encodeStateVector(doc)));

/** One IndexedDB per user, whiteboard and live document (namespaced: docs use `brainitive-docs-*`). */
export const whiteboardDbName = (userId: number, pageUuid: string, epoch: number) => `brainitive-whiteboards-u${userId}-${pageUuid}-e${epoch}`;

function forgetOlderLocalCopies(userId: number, pageUuid: string, epoch: number) {
    const key = `brainitive-whiteboards-epoch-u${userId}-${pageUuid}`;
    try {
        const previous = localStorage.getItem(key);
        if (previous !== null && Number(previous) !== epoch && Number.isInteger(Number(previous))) {
            indexedDB.deleteDatabase(whiteboardDbName(userId, pageUuid, Number(previous)));
        }
        localStorage.setItem(key, String(epoch));
    } catch {
        /* storage blocked: nothing to clean up */
    }
}

function headers(): Record<string, string> {
    const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
    };
}

async function post<T>(url: string, body: FormData | Record<string, unknown>, keepalive = false): Promise<T> {
    const isForm = body instanceof FormData;
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive,
        headers: isForm ? headers() : { ...headers(), 'Content-Type': 'application/json' },
        body: isForm ? body : JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new HttpError(response.status, data as Record<string, unknown>);
    return data as T;
}

function blobToDataUrl(blob: Blob): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(blob);
    });
}

export function startLiveWhiteboard(
    handle: WhiteboardHandle,
    statics: ExcalidrawStatics,
    config: WhiteboardLiveConfig,
    onState: (state: WhiteboardLiveState) => void,
): LiveWhiteboard {
    let state: WhiteboardLiveState = {
        mode: 'connecting',
        connection: 'connecting',
        pending: false,
        saveFailed: null,
        edit: false,
        ready: false,
        persisted: false,
        peers: [],
        notice: null,
        editRevision: null,
    };
    const setState = (patch: Partial<WhiteboardLiveState>) => {
        state = { ...state, ...patch };
        onState(state);
    };

    let alive = true;
    let api: ExcalidrawApi | null = null;
    let doc: Y.Doc | null = null;
    let provider: HocuspocusProvider | null = null;
    let persistence: IndexeddbPersistence | null = null;
    let session: { document: string; epoch: number; edit: boolean } | null = null;
    /** vector of the scene the server has stored (from this live document) */
    let storedVector: Record<string, number> | null = null;
    /** two-way sync runs once the live document has its scene (stored or loaded) */
    let ready = false;
    let initialDone = false;
    /** images the server has in the stored scene / that this browser already shares or loads */
    const confirmedFiles = new Set<string>();
    const knownFiles = new Set<string>();
    const loadingFiles = new Set<string>();
    const unsubscribers: Array<() => void> = [];
    const timers = new Set<ReturnType<typeof setTimeout>>();
    let saveTimer: ReturnType<typeof setTimeout> | null = null;
    let firstPendingAt: number | null = null;
    let retryTimer: ReturnType<typeof setTimeout> | null = null;
    let saveChain: Promise<unknown> = Promise.resolve();
    let pushTimer: ReturnType<typeof setTimeout> | null = null;
    let peersKey = '';

    const later = (fn: () => void, ms: number) => {
        const timer = setTimeout(() => { timers.delete(timer); fn(); }, ms);
        timers.add(timer);
    };
    const elementsMap = () => doc!.getMap<SceneElement>('elements');
    const filesMap = () => doc!.getMap<SharedFile>('files');
    const metaMap = () => doc!.getMap<unknown>('meta');
    const hasScene = () => !!doc && (elementsMap().size > 0 || metaMap().get('loaded') === true);
    const canEdit = () => !!session?.edit && state.mode === 'live';

    const fetchSession = () => post<Session>(config.sessionUrl, config.view ? { view: true } : {});

    // ── Y → canvas ────────────────────────────────────────────────────────────────────────────

    /** Merge remote elements into the canvas with Excalidraw's own reconcile (keeps what you're editing). */
    function applyRemote(ids: Iterable<string>) {
        if (!api || !doc) return;
        const local = api.getSceneElementsIncludingDeleted();
        const localById = new Map(local.map((element) => [element.id, element]));
        const remote: SceneElement[] = [];
        for (const id of ids) {
            const element = elementsMap().get(id);
            if (!isSceneElement(element)) continue;
            const mine = localById.get(id);
            if (!mine || !sameRevision(mine, element)) remote.push(element);
        }
        if (remote.length === 0) return;
        // sorted first: restoreElements repairs indices that are out of order within the given list
        const restored = statics.restoreElements(copy(remote.sort(byIndex)), null);
        const merged = statics.reconcileElements(local, restored, api.getAppState());
        api.updateScene({ elements: merged, captureUpdate: statics.CaptureUpdateAction.NEVER });
        loadMissingFiles();
    }

    /** Images other people added live: fetched from this site's storage (or inline), added to the canvas. */
    function loadMissingFiles() {
        if (!api || !doc) return;
        const have = api.getFiles();
        filesMap().forEach((entry, id) => {
            if (have[id] || loadingFiles.has(id) || !entry || typeof entry !== 'object') return;
            const mimeType = typeof entry.mimeType === 'string' && entry.mimeType.startsWith('image/') ? entry.mimeType : null;
            if (!mimeType) return;
            loadingFiles.add(id);
            knownFiles.add(id);
            const created = Number.isFinite(entry.created) ? entry.created : Date.now();
            const load = async (): Promise<string | null> => {
                if (isImageDataUrl(entry.dataURL)) return entry.dataURL;
                const path = sharedImagePath(entry.url);
                if (!path) return null;
                const response = await fetch(path, { credentials: 'same-origin' });
                if (!response.ok) return null;
                const dataUrl = await blobToDataUrl(await response.blob());
                return isImageDataUrl(dataUrl) ? dataUrl : null;
            };
            load().then((dataURL) => {
                if (alive && dataURL) api?.addFiles([{ id, dataURL, mimeType, created }]);
            }).catch(() => { /* the element shows Excalidraw's placeholder */ }).finally(() => loadingFiles.delete(id));
        });
    }

    // ── canvas → Y ────────────────────────────────────────────────────────────────────────────

    /** Write every element whose local copy beats the shared one (one transaction). */
    function pushLocal() {
        pushTimer = null;
        if (!api || !doc || !ready || !canEdit()) return;
        const shared = elementsMap();
        const appState = api.getAppState();
        // Excalidraw's reconcile keeps the element you're editing even when a newer one arrived
        const editing = new Set(['editingTextElement', 'resizingElement', 'newElement']
            .map((key) => (appState[key] as { id?: string } | null | undefined)?.id).filter(Boolean));
        const behind: string[] = [];
        let changed = false;
        doc.transact(() => {
            for (const element of api!.getSceneElementsIncludingDeleted()) {
                const current = shared.get(element.id);
                if (current && isSceneElement(current) && !beats(element, current)) {
                    // kept while it was being edited, newer elsewhere: take the shared one now
                    if (beats(current, element) && !editing.has(element.id)) behind.push(element.id);
                    continue;
                }
                if (!current && element.isDeleted) continue;
                shared.set(element.id, copy(element));
                changed = true;
            }
        }, LOCAL);
        if (behind.length > 0) applyRemote(behind);
        if (changed) noteChange(false);
        shareNewFiles();
    }

    function schedulePush() {
        if (!pushTimer) pushTimer = setTimeout(pushLocal, PUSH_THROTTLE_MS);
    }

    /** Images added on this canvas: uploaded once, then shared by id. */
    function shareNewFiles() {
        if (!api || !doc) return;
        const files = api.getFiles();
        for (const [id, file] of Object.entries(files)) {
            if (knownFiles.has(id) || filesMap().has(id)) continue;
            knownFiles.add(id);
            void shareFile(id, file);
        }
    }

    async function shareFile(id: string, file: BinaryFile) {
        if (!isImageDataUrl(file.dataURL)) return;
        const created = Number.isFinite(file.created) ? file.created : Date.now();
        let entry: SharedFile | null = null;
        if (config.uploadUrl && UPLOADABLE.has(file.mimeType)) {
            try {
                const blob = await (await fetch(file.dataURL)).blob();
                const form = new FormData();
                form.append('image', blob, `whiteboard-${id.slice(0, 40).replace(/[^A-Za-z0-9_-]/g, '')}.${file.mimeType.split('/')[1]}`);
                const result = await post<{ file?: { url?: string } }>(config.uploadUrl, form);
                const path = sharedImagePath(result.file?.url);
                if (path) entry = { mimeType: file.mimeType, created, url: path };
            } catch {
                /* inline below when small enough */
            }
        }
        if (!entry && file.dataURL.length <= MAX_INLINE_IMAGE_CHARS) entry = { mimeType: file.mimeType, created, dataURL: file.dataURL };
        if (entry && alive && doc && !filesMap().has(id)) doc.transact(() => filesMap().set(id, entry!), LOCAL);
    }

    // ── saving the scene to Brainitive ────────────────────────────────────────────────────────

    const isLeader = () => {
        const awareness = provider?.awareness;
        if (!awareness || !doc) return true;
        const editors = [...awareness.getStates().entries()]
            .filter(([, value]) => (value as { awarenessData?: CollabAwarenessData }).awarenessData?.edit)
            .map(([clientId]) => clientId);
        return editors.length === 0 || Math.min(...editors) === doc.clientID;
    };

    /** The scene as stored: the live document's elements in z-order (not this canvas), plus images it lacks. */
    function sceneForSave() {
        const elements = [...elementsMap().values()].filter((element) => isSceneElement(element) && !element.isDeleted).sort(byIndex);
        const referenced = new Set(elements.map((element) => element.fileId).filter((id): id is string => typeof id === 'string'));
        const local = api?.getFiles() ?? {};
        const files: Record<string, BinaryFile> = {};
        for (const id of referenced) {
            const file = local[id];
            if (file && !confirmedFiles.has(id) && isImageDataUrl(file.dataURL)) {
                files[id] = { id, dataURL: file.dataURL, mimeType: file.mimeType, created: file.created };
            }
        }
        const appState = api?.getAppState() ?? {};
        const pick = ['viewBackgroundColor', 'gridSize', 'gridStep', 'gridModeEnabled', 'scrollX', 'scrollY', 'zoom'];
        return {
            scene: { elements, appState: Object.fromEntries(pick.filter((key) => key in appState).map((key) => [key, appState[key]])), files },
            sentFiles: Object.keys(files),
        };
    }

    function save(options: { force?: boolean; baseline?: boolean; checkpoint?: boolean; image?: Blob } = {}): Promise<WhiteboardSaveResult | null> {
        const run = async (): Promise<WhiteboardSaveResult | null> => {
            if (!alive || !doc || !session?.edit || !config.saveUrl || state.mode !== 'live') return null;
            // before the live document has its scene, a save would store an empty whiteboard
            if (!options.baseline && !initialDone) return null;
            const vector = stateVector(doc);
            const explicit = options.force || options.checkpoint || options.baseline;
            if (!explicit && storedVector && covers(storedVector, vector)) return null;
            const { scene, sentFiles } = sceneForSave();
            const form = new FormData();
            form.append('epoch', String(session.epoch));
            form.append('scene', options.baseline ? '{}' : JSON.stringify(scene));
            form.append('vector', JSON.stringify(vector));
            if (options.baseline) form.append('baseline', '1');
            if (options.checkpoint) form.append('checkpoint', '1');
            if (options.image) form.append('image', options.image, 'preview.png');
            try {
                const result = await post<WhiteboardSaveResult>(config.saveUrl, form);
                if (result.status === 'saved' || result.status === 'unchanged' || (result.status === 'baseline' && !storedVector)) storedVector = vector;
                if (result.status === 'saved' || result.status === 'unchanged') sentFiles.forEach((id) => confirmedFiles.add(id));
                setState({ saveFailed: null, editRevision: result.edit_revision, pending: !!provider?.hasUnsyncedChanges || !!saveTimer });
                return result;
            } catch (error) {
                const status = error instanceof HttpError ? error.status : 0;
                if (status === 409 && error instanceof HttpError && error.data.reset) {
                    replaced();
                } else if (status === 403) {
                    loseAccess('You can no longer edit this whiteboard. Reload to see the latest version.');
                } else if (status >= 400 && status < 500 && status !== 408 && status !== 429) {
                    setState({ saveFailed: error instanceof Error ? error.message : 'The whiteboard couldn’t be saved.' });
                } else {
                    // offline / server error: the live document keeps the drawing; the scene follows later
                    setState({ saveFailed: 'Not saved yet — retrying' });
                    if (retryTimer) clearTimeout(retryTimer);
                    retryTimer = setTimeout(() => { void save(); }, RETRY_SAVE_MS);
                }
                return null;
            }
        };
        const next = saveChain.then(run, run);
        saveChain = next.catch(() => null);
        return next;
    }

    function scheduleSave(delay: number) {
        const now = Date.now();
        firstPendingAt ??= now;
        // never later than MAX_SAVE_WAIT_MS after the first unsaved change
        const wait = Math.max(0, Math.min(delay, firstPendingAt + MAX_SAVE_WAIT_MS - now));
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            saveTimer = null;
            firstPendingAt = null;
            void save();
        }, wait);
        if (!state.pending) setState({ pending: true });
    }

    function noteChange(remote: boolean) {
        if (!session?.edit || !initialDone) return;
        if (remote && !isLeader()) return;
        // a local change wins over a later remote-only save
        if (remote && saveTimer) return;
        scheduleSave(remote ? REMOTE_SAVE_MS : LOCAL_SAVE_MS);
    }

    /** Last chance before the tab closes: unsaved scene goes out with `keepalive` when it fits. */
    function saveOnExit() {
        if (!alive || !doc || !session?.edit || !config.saveUrl || !initialDone || state.mode !== 'live') return;
        if (pushTimer) clearTimeout(pushTimer);
        pushLocal(); // the last throttled changes
        const vector = stateVector(doc);
        if (!saveTimer && storedVector && covers(storedVector, vector)) return;
        const { scene } = sceneForSave();
        const body = JSON.stringify(scene);
        if (body.length > KEEPALIVE_LIMIT) return; // the live document has it; the next open stores it
        const form = new FormData();
        form.append('epoch', String(session.epoch));
        form.append('scene', body);
        form.append('vector', JSON.stringify(vector));
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = null;
        storedVector = vector;
        post(config.saveUrl, form, true).catch(() => { /* the live document keeps the drawing */ });
    }
    const onPageHide = () => saveOnExit();
    window.addEventListener('pagehide', onPageHide);

    // ── awareness: cursors, names, selections ─────────────────────────────────────────────────

    function updateCollaborators() {
        const awareness = provider?.awareness;
        if (!awareness || !api || !doc) return;
        const collaborators = new Map<string, unknown>();
        const peers: WhiteboardPeer[] = [];
        const seen = new Set<number>();
        awareness.getStates().forEach((value, clientId) => {
            if (clientId === doc!.clientID) return;
            const data = (value as { awarenessData?: CollabAwarenessData }).awarenessData;
            if (!data || !Number.isInteger(data.userId)) return;
            // the colour Excalidraw paints this person's cursor in (from the user id, never what the
            // other browser claims); a short plain name
            const color = cursorColor(data.userId);
            const name = String((value as { name?: unknown }).name ?? '').slice(0, 80);
            const pointer = sanitizePointer((value as { pointer?: unknown }).pointer);
            collaborators.set(String(clientId), {
                id: String(data.userId),
                username: name,
                color: { background: color, stroke: color },
                ...(pointer ? { pointer } : {}),
                button: (value as { button?: unknown }).button === 'down' ? 'down' : 'up',
                selectedElementIds: sanitizeSelection((value as { selectedElementIds?: unknown }).selectedElementIds),
            });
            if (data.userId !== config.user.id && !seen.has(data.userId)) {
                seen.add(data.userId);
                peers.push({ clientId, userId: data.userId, name, color, edit: !!data.edit });
            }
        });
        api.updateScene({ collaborators });
        const key = peers.map((peer) => `${peer.userId}:${peer.name}:${peer.edit}`).join('|');
        if (key !== peersKey) {
            peersKey = key;
            setState({ peers });
        }
    }

    let pointerTimer: ReturnType<typeof setTimeout> | null = null;
    let pendingPointer: { pointer: PointerState; button: 'up' | 'down' } | null = null;
    function publishPointer() {
        pointerTimer = null;
        const awareness = provider?.awareness;
        if (!awareness || !pendingPointer) return;
        awareness.setLocalStateField('pointer', pendingPointer.pointer);
        awareness.setLocalStateField('button', pendingPointer.button);
        pendingPointer = null;
    }

    let selectionKey = '';
    function publishSelection(appState: Record<string, unknown>) {
        const awareness = provider?.awareness;
        if (!awareness) return;
        const selected = sanitizeSelection(appState.selectedElementIds);
        const key = Object.keys(selected).sort().join(',');
        if (key === selectionKey) return;
        selectionKey = key;
        awareness.setLocalStateField('selectedElementIds', selected);
    }

    // ── life cycle ────────────────────────────────────────────────────────────────────────────

    function teardownConnection() {
        if (pushTimer) clearTimeout(pushTimer);
        pushTimer = null;
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = null;
        if (retryTimer) clearTimeout(retryTimer);
        if (pointerTimer) clearTimeout(pointerTimer);
        timers.forEach(clearTimeout);
        timers.clear();
        provider?.destroy();
        provider = null;
        void persistence?.destroy();
        persistence = null;
        initialDone = false;
        ready = false;
        doc?.destroy();
        doc = null;
        try {
            api?.updateScene({ collaborators: new Map() });
            handle.setCollaborating(false);
        } catch {
            /* the canvas is gone */
        }
    }

    /** The scene was replaced elsewhere: stop drawing live; the page offers to load the latest. */
    function replaced() {
        if (state.mode === 'replaced' || !alive) return;
        const epoch = session?.epoch;
        teardownConnection();
        if (epoch !== undefined) {
            try { indexedDB.deleteDatabase(whiteboardDbName(config.user.id, config.pageUuid, epoch)); } catch { /* ignore */ }
        }
        if (!config.view) handle.setViewMode(true);
        setState({ mode: 'replaced', notice: REPLACED_NOTICE, peers: [], pending: false });
    }

    function loseAccess(message: string) {
        if (session) session.edit = false;
        handle.setViewMode(true);
        setState({ edit: false, notice: message });
    }

    async function recheck() {
        if (!alive || state.mode !== 'live') return;
        try {
            const next = await fetchSession();
            if (!alive) return;
            if (next.document !== session?.document) {
                replaced();
                return;
            }
            if (session && !next.edit && session.edit) loseAccess('You can no longer edit this whiteboard. Reload to see the latest version.');
            later(() => { provider?.detach(); provider?.attach(); }, 1_000);
        } catch (error) {
            if (!alive) return;
            const status = error instanceof HttpError ? error.status : 0;
            if (status === 403 || status === 404) {
                teardownConnection();
                try { indexedDB.deleteDatabase(whiteboardDbName(config.user.id, config.pageUuid, session?.epoch ?? config.epoch)); } catch { /* ignore */ }
                handle.setViewMode(true);
                setState({ mode: 'off', notice: 'You no longer have access to this whiteboard.', peers: [] });
            } else {
                later(() => void recheck(), 5_000);
            }
        }
    }

    async function run() {
        api = await handle.whenReady();
        if (!alive) return;
        // what this canvas already has: the stored scene's images
        Object.keys(api.getFiles()).forEach((id) => { confirmedFiles.add(id); knownFiles.add(id); });

        let first: Session | null = null;
        try {
            first = await fetchSession();
        } catch (error) {
            const status = error instanceof HttpError ? error.status : 0;
            if (status === 403 || status === 404) {
                // no (more) access: don't keep a copy on this device
                try { indexedDB.deleteDatabase(whiteboardDbName(config.user.id, config.pageUuid, config.epoch)); } catch { /* ignore */ }
                if (!alive) return;
                if (!config.view) handle.setViewMode(true); // nothing drawn here could be stored
                setState({ mode: 'off', notice: 'Live drawing isn’t available for this whiteboard. Reload to see the latest version.' });
                return;
            }
            // offline: open what this device has for the epoch the page was loaded with
        }
        if (!alive) return;
        if (first && first.epoch !== config.epoch) {
            // the scene this page loaded was replaced meanwhile: drawing on it would bring old shapes back
            replaced();
            return;
        }

        const epoch = first?.epoch ?? config.epoch;
        const documentName = first?.document ?? `whiteboard.${config.pageUuid}.${epoch}`;
        const edit = first ? first.edit : !config.view;
        const user = first?.user ?? config.user;
        session = { document: documentName, epoch, edit };
        storedVector = first?.vector ?? null;
        setState({ edit });

        doc = new Y.Doc();
        // the page preview watches only: no copy on this device
        if (edit) {
            forgetOlderLocalCopies(config.user.id, config.pageUuid, epoch);
            try {
                persistence = new IndexeddbPersistence(whiteboardDbName(config.user.id, config.pageUuid, epoch), doc);
                await Promise.race([persistence.whenSynced, sleep(2_000)]);
                setState({ persisted: true });
            } catch {
                persistence = null; // IndexedDB blocked (private window): online only
            }
        }
        if (!alive) return;
        const hadLocalCopy = hasScene();

        let firstToken: string | null = first?.token ?? null;
        const live = new HocuspocusProvider({
            url: collabSocketUrl(first?.url ?? config.url),
            name: documentName,
            document: doc,
            token: async () => {
                if (firstToken) {
                    const token = firstToken;
                    firstToken = null;
                    return token;
                }
                const next = await fetchSession();
                if (alive && next.document !== documentName) replaced();
                return next.token;
            },
        });
        provider = live;

        live.on('status', ({ status }: { status: string }) => {
            if (alive) setState({ connection: status === 'connected' ? 'online' : status === 'connecting' ? 'connecting' : 'offline' });
        });
        live.on('unsyncedChanges', ({ number }: { number: number }) => alive && setState({ pending: number > 0 || !!saveTimer }));
        live.on('synced', () => {
            // back online: store what was drawn offline
            if (alive && initialDone && edit) scheduleSave(LOCAL_SAVE_MS);
        });
        live.on('close', ({ event }: { event?: { code?: number; reason?: string } }) => {
            if (event?.code === 1000 && DOCUMENT_CLOSED.has(event.reason ?? '')) void recheck();
        });
        live.on('authenticationFailed', () => void recheck());

        live.awareness?.setLocalState({
            name: user.name,
            color: collabColor(user.id),
            awarenessData: { userId: user.id, edit } satisfies CollabAwarenessData,
        });
        live.awareness?.on('change', ({ added, updated, removed }: { added: number[]; updated: number[]; removed: number[] }) => {
            // my own pointer moves don't change what the others look like
            if ([...added, ...updated, ...removed].some((clientId) => clientId !== doc?.clientID)) updateCollaborators();
        });

        const outcome = await new Promise<'synced' | 'failed'>((resolve) => {
            if (live.isSynced) {
                resolve('synced');
                return;
            }
            let failures = 0;
            const done = (result: 'synced' | 'failed') => {
                clearTimeout(timer);
                live.off('synced', onSynced);
                live.off('disconnect', onDisconnect);
                resolve(result);
            };
            const onSynced = () => done('synced');
            const onDisconnect = () => { if (++failures >= 2) done('failed'); };
            const timer = setTimeout(() => done('failed'), CONNECT_TIMEOUT_MS);
            live.on('synced', onSynced);
            live.on('disconnect', onDisconnect);
        });
        if (!alive) return;

        if (outcome === 'failed' && !hadLocalCopy) {
            // single-user from here on ("Try again" reloads)
            teardownConnection();
            setState({ mode: 'fallback', connection: 'offline', notice: config.view ? null : 'Live drawing unavailable — drawing alone.' });
            return;
        }

        setState({ mode: 'live' });
        handle.setCollaborating(true);
        if (!edit && !config.view) handle.setViewMode(true);

        // Y → canvas
        elementsMap().observe((event) => {
            if (!alive || event.transaction.origin === LOCAL) return;
            applyRemote(event.keysChanged);
            if (!ready && hasScene()) ready = true;
            if (event.transaction.origin !== BOOTSTRAP) noteChange(!event.transaction.local);
        });
        filesMap().observe((event) => { if (alive && event.transaction.origin !== LOCAL) loadMissingFiles(); });
        metaMap().observe(() => { if (alive && !ready && hasScene()) ready = true; });

        // canvas → Y (+ my pointer and selection for the others)
        unsubscribers.push(handle.on('change', (_elements, appState) => {
            if (!alive || !doc) return;
            publishSelection(appState);
            schedulePush();
        }));
        unsubscribers.push(handle.on('pointer', ({ pointer, button }) => {
            const safe = sanitizePointer(pointer);
            if (!safe || !provider) return;
            pendingPointer = { pointer: safe, button: button === 'down' ? 'down' : 'up' };
            if (!pointerTimer) pointerTimer = setTimeout(publishPointer, POINTER_THROTTLE_MS);
        }));

        if (hasScene()) {
            ready = true;
            applyRemote([...elementsMap().keys()]);
        }
        loadMissingFiles();
        updateCollaborators();

        // An empty live document: one editor loads the stored scene into it, the others wait for it
        const claim = randomClaim();
        while (alive && !hasScene()) {
            if (edit && live.isSynced && config.bootstrapUrl) {
                try {
                    const result = await post<{ status: string; elements?: unknown[] }>(config.bootstrapUrl, { epoch, claim });
                    if (result.status === 'granted' && alive && doc && !hasScene()) {
                        const stored = (result.elements ?? []).filter(isSceneElement);
                        // restored + indexed like Excalidraw loads a scene, so every canvas orders it the same
                        const restored = statics.restoreElements(copy(stored), null);
                        doc.transact(() => {
                            restored.forEach((element) => elementsMap().set(element.id, copy(element)));
                            metaMap().set('loaded', true);
                        }, BOOTSTRAP);
                        ready = true;
                        await save({ baseline: true });
                        break;
                    }
                } catch (error) {
                    if (!alive) return;
                    const status = error instanceof HttpError ? error.status : 0;
                    if (status === 409) {
                        replaced();
                        return;
                    }
                    if (status === 403) {
                        loseAccess('You can no longer edit this whiteboard. Reload to see the latest version.');
                        return;
                    }
                }
            }
            await sleep(BOOTSTRAP_RETRY_MS);
        }
        if (!alive) return;
        ready = true;
        initialDone = true;
        setState({ ready: true });
        // the canvas may have changed while connecting (or offline before a reload): share it
        pushLocal();
        // catch-up: drawing in the live document the stored scene doesn't have yet
        if (edit && storedVector && !covers(storedVector, stateVector(doc!))) scheduleSave(LOCAL_SAVE_MS);
    }

    run().catch((error) => {
        console.error('[whiteboard] live drawing failed', error);
        if (alive && state.mode === 'connecting') {
            teardownConnection();
            setState({ mode: 'fallback', notice: config.view ? null : 'Live drawing unavailable — drawing alone.' });
        }
    });

    return {
        async flush(options = {}) {
            if (saveTimer) clearTimeout(saveTimer);
            saveTimer = null;
            firstPendingAt = null;
            pushLocal();
            return save({ force: true, ...options });
        },
        getState: () => state,
        destroy() {
            saveOnExit();
            alive = false;
            window.removeEventListener('pagehide', onPageHide);
            unsubscribers.forEach((unsubscribe) => unsubscribe());
            teardownConnection();
        },
    };
}
