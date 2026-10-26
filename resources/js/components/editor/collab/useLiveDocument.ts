/**
 * Live co-editing of a document's draft (BRAI-54). Owns the Yjs document, its offline copy
 * (IndexedDB), the Hocuspocus connection, the one-time load of the stored draft into an empty live
 * document, and saving the live document's HTML as the draft. Plan: docs/ai/tasks/feat-live-docs.md.
 *
 * Modes: `off` (not offered) → `connecting` → `live` (also while offline, edits kept on this device)
 * or `fallback` (server unreachable and nothing on this device: single-user editing as before).
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { HocuspocusProvider } from '@hocuspocus/provider';
import { IndexeddbPersistence } from 'y-indexeddb';
import * as Y from 'yjs';
import { $generateHtmlFromNodes } from '@lexical/html';
import type { LexicalEditor } from 'lexical';
import type { PageCollabBootstrap, PageCollabProps, PageCollabSaveResponse, PageCollabSession, PageDraftHashState } from '@/types';
import { BOOTSTRAP_ORIGIN, buildBootstrapUpdate } from './bootstrap';
import { collabColor, collabDbName, collabSocketUrl, createLexicalBridge, forgetOlderLocalCopies, type CollabAwarenessData, type LiveEditorBinding } from './shared';

export type LiveMode = 'off' | 'connecting' | 'live' | 'fallback';
export type LiveConnection = 'online' | 'connecting' | 'offline';

export interface LivePeer {
    clientId: number;
    userId: number;
    name: string;
    color: string;
    edit: boolean;
}

/** No first sync within this time and nothing on this device → single-user editing. */
const CONNECT_TIMEOUT_MS = 5_000;
const LOCAL_SAVE_MS = 1_000;
/** Remote changes are saved by the "leader" only, a bit later (its author usually saved already). */
const REMOTE_SAVE_MS = 3_000;
const RETRY_SAVE_MS = 10_000;
const BOOTSTRAP_RETRY_MS = 2_500;
/** Reasons the server gives when it closes one document of a shared socket (realtime/src/server.js). */
const DOCUMENT_CLOSED = new Set(['Document replaced', 'Forbidden', 'Server closed the connection']);

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));
const randomClaim = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');

const covers = (stored: Record<string, number>, incoming: Record<string, number>) =>
    Object.entries(incoming).every(([client, clock]) => clock <= (stored[client] ?? 0));

const stateVector = (doc: Y.Doc): Record<string, number> => Object.fromEntries(Y.decodeStateVector(Y.encodeStateVector(doc)));

interface Session {
    document: string;
    epoch: number;
    edit: boolean;
}

interface Options {
    /** Live editing wanted now (document, editing or viewing an unpublished draft, `collab` prop set). */
    enabled: boolean;
    collab: PageCollabProps | null;
    routeParams: [string, string, string];
    user: { id: number; name: string };
    /** Used when the live document has no title / icon yet. */
    fallback: { name: string; icon: string | null };
    /** The user's edit right as the page knew it (when the session can't be fetched, e.g. offline). */
    canUpdate: boolean;
    onDraftState: (state: PageDraftHashState) => void;
}

export function useLiveDocument({ enabled, collab, routeParams, user, fallback, canUpdate, onDraftState }: Options) {
    const [mode, setMode] = useState<LiveMode>(enabled && collab ? 'connecting' : 'off');
    const [connection, setConnection] = useState<LiveConnection>('connecting');
    const [pending, setPending] = useState(false);
    /** Why the draft HTML couldn't be stored (the live document still has the text); null = fine. */
    const [saveFailed, setSaveFailed] = useState<string | null>(null);
    const [ready, setReady] = useState(false);
    const [canEdit, setCanEdit] = useState(false);
    const [peers, setPeers] = useState<LivePeer[]>([]);
    const [meta, setMetaState] = useState<{ name?: string; icon?: string | null }>({});
    const [binding, setBinding] = useState<LiveEditorBinding | null>(null);
    const [generation, setGeneration] = useState(0);
    /** the generation the `live` mode belongs to (a reset starts a new one) */
    const [liveGeneration, setLiveGeneration] = useState(-1);
    const [notice, setNotice] = useState<string | null>(null);

    const pageUuid = routeParams[2];
    const sessionRef = useRef<Session | null>(null);
    const docRef = useRef<Y.Doc | null>(null);
    const providerRef = useRef<HocuspocusProvider | null>(null);
    const editorRef = useRef<LexicalEditor | null>(null);
    const readyRef = useRef(false);
    const initialDoneRef = useRef(false);
    const htmlVectorRef = useRef<Record<string, number> | null>(null);
    const saveTimerRef = useRef<{ handle: ReturnType<typeof setTimeout>; at: number } | null>(null);
    const retryTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const saveChainRef = useRef<Promise<boolean>>(Promise.resolve(true));
    const resettingRef = useRef(false);
    const fallbackRef = useRef(fallback);
    fallbackRef.current = fallback;
    const onDraftStateRef = useRef(onDraftState);
    onDraftStateRef.current = onDraftState;
    const routeParamsRef = useRef(routeParams);
    routeParamsRef.current = routeParams;
    const canUpdateRef = useRef(canUpdate);
    canUpdateRef.current = canUpdate;

    /** Title as stored: never empty, never longer than the server accepts. */
    const metaName = (doc: Y.Doc) => (((doc.getMap('meta').get('name') as string | undefined)?.trim() || fallbackRef.current.name || 'Untitled')).slice(0, 255);
    const metaIcon = (doc: Y.Doc) => {
        const docMeta = doc.getMap('meta');
        return docMeta.has('icon') ? (docMeta.get('icon') as string | null) ?? null : fallbackRef.current.icon;
    };

    /** The server says this user may not edit any more: stop saving, make the editor read-only. */
    const loseEditRights = useCallback(() => {
        if (sessionRef.current) sessionRef.current.edit = false;
        setCanEdit(false);
        setNotice('You can no longer edit this page. Reload to see the latest version.');
    }, []);

    const fetchSession = useCallback(async (): Promise<PageCollabSession> =>
        (await window.axios.post<PageCollabSession>(route('pages.collab.session', routeParamsRef.current))).data, []);

    /** The draft was replaced elsewhere (discard, restore, single-user save): reopen on the new one. */
    const resetDocument = useCallback((message = 'This draft was replaced (changes discarded, a version restored or saved without live editing). You now see the latest draft.') => {
        if (resettingRef.current) return;
        resettingRef.current = true;
        setNotice(message);
        setGeneration((value) => value + 1);
    }, []);

    const isLeader = useCallback(() => {
        const provider = providerRef.current;
        const doc = docRef.current;
        if (!provider?.awareness || !doc) return true;
        const editors = [...provider.awareness.getStates().entries()]
            .filter(([, state]) => (state as { awarenessData?: CollabAwarenessData }).awarenessData?.edit)
            .map(([clientId]) => clientId);
        return editors.length === 0 || Math.min(...editors) === doc.clientID;
    }, []);

    /** Store the live document's HTML (+ title, icon) as the draft. Serialized; true = stored or nothing to store. */
    const save = useCallback((options: { force?: boolean; baseline?: boolean } = {}): Promise<boolean> => {
        const run = async (): Promise<boolean> => {
            const session = sessionRef.current;
            const doc = docRef.current;
            if (!session?.edit || !doc) return true;
            const editor = editorRef.current;
            if (!options.baseline && (!readyRef.current || !editor)) return !readyRef.current;

            // HTML and vector from the same moment: read() first flushes pending (remote) updates
            const html = options.baseline || !editor ? '' : editor.read(() => $generateHtmlFromNodes(editor));
            const vector = stateVector(doc);
            if (!options.baseline && !options.force && htmlVectorRef.current && covers(htmlVectorRef.current, vector)) return true;

            try {
                const { data } = await window.axios.put<PageCollabSaveResponse>(route('pages.collab.save', routeParamsRef.current), {
                    epoch: session.epoch,
                    content: JSON.stringify({ type: 'lexical', html }),
                    name: metaName(doc),
                    icon: metaIcon(doc),
                    vector,
                    baseline: !!options.baseline,
                });
                // 'covered' = the stored HTML isn't ours (newer or concurrent): keep saving until it is
                if (data.status === 'saved' || data.status === 'unchanged' || (data.status === 'baseline' && !htmlVectorRef.current)) htmlVectorRef.current = vector;
                if (data.status !== 'baseline') onDraftStateRef.current(data);
                setSaveFailed(null);
                return true;
            } catch (error: any) {
                const status = error?.response?.status;
                if (status === 409 && error.response.data?.reset) {
                    resetDocument();
                } else if (status === 403) {
                    loseEditRights();
                } else if (status >= 400 && status < 500 && status !== 408 && status !== 429) {
                    // refused (e.g. too large): retrying the same won't help — say so, the next change tries again
                    setSaveFailed(error.response.data?.message ?? 'The draft couldn\'t be saved.');
                } else {
                    // offline / server error: the Y document keeps the text; the HTML follows later
                    setSaveFailed('Not saved yet — retrying');
                    if (retryTimerRef.current) clearTimeout(retryTimerRef.current);
                    retryTimerRef.current = setTimeout(() => { void save(); }, RETRY_SAVE_MS);
                }
                return false;
            }
        };
        const next = saveChainRef.current.then(run, run);
        saveChainRef.current = next.catch(() => false);
        return next;
    }, [resetDocument, loseEditRights]);

    /**
     * Last chance before the tab closes or the editor goes away: unsaved HTML goes out with
     * `keepalive`, so the draft never lags behind the live document (fire and forget).
     */
    const saveOnExit = useCallback((editor: LexicalEditor | null) => {
        const session = sessionRef.current;
        const doc = docRef.current;
        if (!session?.edit || !doc || !editor || !readyRef.current || !initialDoneRef.current || resettingRef.current) return;
        const html = editor.read(() => $generateHtmlFromNodes(editor));
        const vector = stateVector(doc);
        if (!saveTimerRef.current && htmlVectorRef.current && covers(htmlVectorRef.current, vector)) return;
        if (saveTimerRef.current) clearTimeout(saveTimerRef.current.handle);
        saveTimerRef.current = null;
        const body = JSON.stringify({
            epoch: session.epoch,
            content: JSON.stringify({ type: 'lexical', html }),
            name: metaName(doc),
            icon: metaIcon(doc),
            vector,
        });
        htmlVectorRef.current = vector;
        const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
        fetch(route('pages.collab.save', routeParamsRef.current), {
            method: 'PUT',
            // browsers cap keepalive bodies at 64 KB; bigger documents rely on the next open's catch-up save
            keepalive: new Blob([body]).size < 60_000,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest' },
            body,
        }).catch(() => { /* the live document keeps the text; the next open saves it */ });
    }, []);

    useEffect(() => {
        const onPageHide = () => saveOnExit(editorRef.current);
        window.addEventListener('pagehide', onPageHide);
        return () => window.removeEventListener('pagehide', onPageHide);
    }, [saveOnExit]);

    const scheduleSave = useCallback((delay: number) => {
        const at = Date.now() + delay;
        // a local change wins over a later remote-only save
        if (saveTimerRef.current && saveTimerRef.current.at < at && delay === REMOTE_SAVE_MS) return;
        if (saveTimerRef.current) clearTimeout(saveTimerRef.current.handle);
        saveTimerRef.current = {
            at,
            handle: setTimeout(() => {
                saveTimerRef.current = null;
                // a Lexical menu (mentions, slash commands) is open: wait like the single-user autosave
                if (document.querySelector('[data-lexical-menu="true"]')) {
                    scheduleSave(LOCAL_SAVE_MS);
                    return;
                }
                void save();
            }, delay),
        };
    }, [save]);

    const notifyChange = useCallback((remote: boolean) => {
        if (!sessionRef.current?.edit || !initialDoneRef.current) return;
        if (remote && !isLeader()) return;
        scheduleSave(remote ? REMOTE_SAVE_MS : LOCAL_SAVE_MS);
    }, [isLeader, scheduleSave]);

    useEffect(() => {
        resettingRef.current = false;
        initialDoneRef.current = false;
        readyRef.current = false;
        setReady(false);
        setPeers([]);
        setMetaState({});
        setBinding(null);
        setPending(false);
        setSaveFailed(null);
        if (!enabled || !collab) {
            setMode('off');
            return;
        }
        setMode('connecting');
        setConnection('connecting');

        let alive = true;
        let doc: Y.Doc | null = null;
        let persistence: IndexeddbPersistence | null = null;
        let provider: HocuspocusProvider | null = null;
        let bridge: ReturnType<typeof createLexicalBridge> | null = null;
        const timers = new Set<ReturnType<typeof setTimeout>>();
        const later = (fn: () => void, ms: number) => {
            const handle = setTimeout(() => { timers.delete(handle); fn(); }, ms);
            timers.add(handle);
        };

        (async () => {
            let first: PageCollabSession | null = null;
            try {
                first = await fetchSession();
            } catch (error: any) {
                const status = error?.response?.status;
                if (status === 403 || status === 404) {
                    // no (more) access: don't keep a copy of the draft on this device
                    try { indexedDB.deleteDatabase(collabDbName(user.id, pageUuid, collab.epoch)); } catch { /* ignore */ }
                    if (alive) setMode('off');
                    return;
                }
                // offline: open what this device has for the epoch the page was loaded with
            }
            if (!alive) return;

            const epoch = first?.epoch ?? collab.epoch;
            const documentName = first?.document ?? `page.${pageUuid}.${epoch}`;
            const edit = first?.edit ?? canUpdateRef.current;
            sessionRef.current = { document: documentName, epoch, edit };
            htmlVectorRef.current = first?.html_vector ?? null;
            setCanEdit(edit);
            forgetOlderLocalCopies(user.id, pageUuid, epoch);

            doc = new Y.Doc();
            try {
                persistence = new IndexeddbPersistence(collabDbName(user.id, pageUuid, epoch), doc);
                await Promise.race([persistence.whenSynced, sleep(2_000)]);
            } catch {
                persistence = null; // IndexedDB blocked (private window): online only
            }
            if (!alive) return;
            const root = doc.get('root', Y.XmlText);
            const hadLocalCopy = root.length > 0;

            let firstToken: string | null = first?.token ?? null;
            provider = new HocuspocusProvider({
                url: collabSocketUrl(first?.url ?? collab.url),
                name: documentName,
                document: doc,
                token: async () => {
                    if (firstToken) {
                        const token = firstToken;
                        firstToken = null;
                        return token;
                    }
                    const session = await fetchSession();
                    if (!alive) return session.token;
                    if (session.document !== documentName) {
                        resetDocument();
                    } else {
                        htmlVectorRef.current = session.html_vector ?? htmlVectorRef.current;
                    }
                    return session.token;
                },
            });
            const live = provider;
            docRef.current = doc;
            providerRef.current = live;

            live.on('status', ({ status }: { status: string }) => {
                if (alive) setConnection(status === 'connected' ? 'online' : status === 'connecting' ? 'connecting' : 'offline');
            });
            live.on('unsyncedChanges', ({ number }: { number: number }) => alive && setPending(number > 0));
            live.on('synced', () => {
                // back online: catch the draft HTML up with what was written offline
                if (alive && initialDoneRef.current && edit) scheduleSave(LOCAL_SAVE_MS);
            });
            // The server closed this document (replaced draft, lost access, failed load): ask Brainitive
            const recheck = async () => {
                if (!alive) return;
                try {
                    const session = await fetchSession();
                    if (!alive) return;
                    if (session.document !== documentName) {
                        resetDocument();
                        return;
                    }
                    later(() => { live.detach(); live.attach(); }, 1_000);
                } catch (error: any) {
                    if (!alive) return;
                    const status = error?.response?.status;
                    if (status === 403 || status === 404) router.reload();
                    else later(recheck, 5_000);
                }
            };
            live.on('close', ({ event }: { event?: { code?: number; reason?: string } }) => {
                if (event?.code === 1000 && DOCUMENT_CLOSED.has(event.reason ?? '')) void recheck();
            });
            live.on('authenticationFailed', () => void recheck());

            const awareness = live.awareness;
            const updatePeers = () => {
                if (!alive || !awareness) return;
                const seen = new Set<number>();
                const next: LivePeer[] = [];
                awareness.getStates().forEach((state, clientId) => {
                    const data = (state as { awarenessData?: CollabAwarenessData }).awarenessData;
                    if (clientId === doc?.clientID || !data || data.userId === user.id || seen.has(data.userId)) return;
                    seen.add(data.userId);
                    // our own colour per user id, never what the other browser claims
                    next.push({ clientId, userId: data.userId, name: String((state as { name?: string }).name ?? '').slice(0, 80), color: collabColor(data.userId), edit: !!data.edit });
                });
                setPeers(next);
            };
            awareness?.on('change', updatePeers);

            const docMeta = doc.getMap('meta');
            const readMeta = () => setMetaState({
                name: docMeta.get('name') as string | undefined,
                icon: docMeta.has('icon') ? (docMeta.get('icon') as string | null) : undefined,
            });
            docMeta.observe((event) => {
                if (!alive) return;
                readMeta();
                if (event.transaction.origin !== BOOTSTRAP_ORIGIN) notifyChange(!event.transaction.local);
            });
            readMeta();
            const updateReady = () => {
                const isReady = root.length > 0;
                if (isReady !== readyRef.current) {
                    readyRef.current = isReady;
                    if (alive) setReady(isReady);
                }
            };
            doc.on('update', updateReady);
            updateReady();

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
                // stop reconnecting: this editing session is single-user ("Try again" starts over)
                live.destroy();
                provider = null;
                void persistence?.destroy();
                persistence = null;
                providerRef.current = null;
                setMode('fallback');
                return;
            }

            bridge = createLexicalBridge(live);
            setBinding({
                id: documentName,
                providerFactory: bridge.providerFactory,
                username: user.name,
                color: collabColor(user.id),
                awarenessData: { userId: user.id, edit },
                onEditor: (editor) => {
                    if (!editor) saveOnExit(editorRef.current); // the editor closes: store what it had
                    editorRef.current = editor;
                },
                onChange: notifyChange,
            });
            setLiveGeneration(generation);
            setMode('live');

            // An empty document: one editor loads the stored draft into it, the others wait for it
            const claim = randomClaim();
            while (alive && !readyRef.current) {
                if (edit && live.isSynced) {
                    try {
                        const { data } = await window.axios.post<PageCollabBootstrap>(route('pages.collab.bootstrap', routeParamsRef.current), { epoch, claim });
                        if (data.status === 'granted' && alive && !readyRef.current) {
                            Y.applyUpdate(doc, buildBootstrapUpdate(documentName, data), BOOTSTRAP_ORIGIN);
                            await save({ baseline: true });
                            break;
                        }
                    } catch (error: any) {
                        if (!alive) return;
                        if (error?.response?.status === 409) {
                            resetDocument();
                            return;
                        }
                        if (error?.response?.status === 403) {
                            loseEditRights();
                            return;
                        }
                    }
                }
                await sleep(BOOTSTRAP_RETRY_MS);
            }
            if (!alive) return;
            initialDoneRef.current = true;
            // catch-up: text in the live document that the stored draft doesn't have yet (edits made
            // offline and closed, a keepalive save that was too big, every browser closed at once)
            if (edit && readyRef.current && htmlVectorRef.current && !covers(htmlVectorRef.current, stateVector(doc))) {
                scheduleSave(LOCAL_SAVE_MS);
            }
        })();

        return () => {
            if (!resettingRef.current) saveOnExit(editorRef.current); // closing the editor / leaving the page
            alive = false;
            timers.forEach(clearTimeout);
            if (saveTimerRef.current) clearTimeout(saveTimerRef.current.handle);
            saveTimerRef.current = null;
            if (retryTimerRef.current) clearTimeout(retryTimerRef.current);
            const replaced = resettingRef.current;
            const name = sessionRef.current ? collabDbName(user.id, pageUuid, sessionRef.current.epoch) : null;
            bridge?.destroy();
            provider?.destroy();
            void persistence?.destroy();
            doc?.destroy();
            // a replaced draft's local copy is of no use any more
            if (replaced && name) {
                try { indexedDB.deleteDatabase(name); } catch { /* ignore */ }
            }
            docRef.current = null;
            providerRef.current = null;
            editorRef.current = null;
            sessionRef.current = null;
        };
    }, [enabled, collab?.url, generation, pageUuid, user.id, user.name, fetchSession, notifyChange, resetDocument, loseEditRights, save, saveOnExit, scheduleSave]);

    /** Store the HTML now (before publish / review / close). False when that failed. */
    const flush = useCallback(async () => {
        if (saveTimerRef.current) clearTimeout(saveTimerRef.current.handle);
        saveTimerRef.current = null;
        return save({ force: true });
    }, [save]);

    const setMeta = useCallback((fields: { name?: string; icon?: string | null }) => {
        const doc = docRef.current;
        if (!doc || !sessionRef.current?.edit) return;
        doc.transact(() => {
            const docMeta = doc.getMap('meta');
            if (fields.name !== undefined) docMeta.set('name', fields.name);
            if (fields.icon !== undefined) docMeta.set('icon', fields.icon);
        });
    }, []);

    /** Presence heartbeat: a newer epoch means the draft was replaced while we were connected. */
    const checkEpoch = useCallback((epoch: number | null | undefined) => {
        const session = sessionRef.current;
        if (session && typeof epoch === 'number' && epoch > session.epoch) resetDocument();
    }, [resetDocument]);

    const retry = useCallback(() => {
        setNotice(null);
        setGeneration((value) => value + 1);
    }, []);

    const hasUnsavedChanges = useCallback(() => !!providerRef.current?.hasUnsyncedChanges || !!saveTimerRef.current, []);

    // Derived right away, not only after the effect ran: the render in which `enabled` flips (or a reset
    // starts) must neither show an editable single-user editor nor keep the old live one.
    const effectiveMode: LiveMode = !enabled || !collab
        ? 'off'
        : mode === 'off' || (mode === 'live' && liveGeneration !== generation)
            ? 'connecting'
            : mode;

    return useMemo(() => ({
        mode: effectiveMode,
        connection,
        /** the server hasn't acknowledged every change yet */
        pending,
        saveFailed,
        ready,
        canEdit,
        peers,
        meta,
        binding,
        generation,
        notice,
        dismissNotice: () => setNotice(null),
        flush,
        setMeta,
        checkEpoch,
        retry,
        hasUnsavedChanges,
    }), [effectiveMode, connection, pending, saveFailed, ready, canEdit, peers, meta, binding, generation, notice, flush, setMeta, checkEpoch, retry, hasUnsavedChanges]);
}
