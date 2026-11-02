// Brainitive realtime server (BRAI-54): Hocuspocus for live co-editing of document drafts.
// Plan: docs/ai/tasks/feat-live-docs.md. Brainitive (Laravel) stays the source of truth:
//  - every connection carries a short-lived token from Brainitive (one document, edit or view-only);
//  - the Yjs state is loaded from / stored to Brainitive's internal API (debounced, retried);
//  - every minute Brainitive re-checks who may still be connected;
//  - a connection whose token carries an app device (`dev`, App API) is closed with 4410 once Brainitive
//    revokes that device (pushed through revokeDevices(), or found by the re-check) — BRAI-377;
//  - a web connection (`sid` + `gen`) is closed with 4401 "Signed out" once its browser session signs out or the user
//    is signed out everywhere (pushed through endSessions(), or found on connect / by the re-check) — Codex review 1.
import { Server } from '@hocuspocus/server';
import * as Y from 'yjs';
import { removeAwarenessStates } from 'y-protocols/awareness';
import { DOCUMENT_NAME, deriveKeys, inspectToken } from './token.js';
import { createLaravelClient } from './laravel.js';
import { fields } from './log.js';
import { bucket } from './live/hub.js';

const FORBIDDEN = { code: 4403, reason: 'Forbidden' };
const RESET = { code: 4409, reason: 'Document replaced' };
const TOO_LARGE = { code: 4413, reason: 'Document too large' };
/**
 * Brainitive couldn't answer the access re-check COLLAB_ACCESS_MAX_FAILURES times in a row (Codex realtime review 2):
 * fail closed. The browser asks for a new token and reconnects — authorized again, or refused while the app is down.
 */
const ACCESS_UNKNOWN = { code: 4403, reason: 'Access could not be checked' };
/** the app device of the token was signed out: drop tokens, show sign-in (same code as live channels) */
const DEVICE_REVOKED = { code: 4410, reason: 'Device signed out' };
/** the browser session of the token signed out, or the user everywhere (same code as live channels) */
const SIGNED_OUT = { code: 4401, reason: 'Signed out' };
const COLOR = /^#[0-9a-f]{6}$/i;
/** Codex realtime review 4: one socket sent too much, or one user holds too many document sockets */
const TOO_MANY_MESSAGES = { code: 4429, reason: 'Too many messages' };
const TOO_MANY_SOCKETS = { code: 4429, reason: 'Too many connections' };

/**
 * Limits of document sockets (Codex realtime review 4), like the live channels' (live/hub.js DEFAULT_LIMITS) but
 * sized for Yjs: every keystroke is an update plus an awareness message, a drag on a whiteboard many more.
 * Environment: COLLAB_MAX_SOCKETS_PER_USER, COLLAB_MAX_DOCUMENTS_PER_USER, COLLAB_MESSAGE_BURST,
 * COLLAB_MESSAGES_PER_SECOND, COLLAB_MAX_MESSAGE_BYTES.
 */
export const DEFAULT_COLLAB_LIMITS = {
    /** document sockets one user may hold at once (each open doc / whiteboard / description is one) */
    maxSocketsPerUser: 30,
    /** open documents of one user over all sockets — one socket can carry several (Hocuspocus multiplexes) */
    maxDocumentsPerUser: 30,
    /** messages per socket: this many at once, refilled at messagesPerSecond — beyond: closed (4429) */
    messageBurst: 300,
    messagesPerSecond: 100,
    /** largest single WebSocket message (bytes; larger closes the socket with 1009). null: maxStateBytes + 64 KiB */
    maxMessageBytes: null,
};

/** COLLAB_MAX_STATE_BYTES: same variable, default and 1 KiB floor as config/collab.php → CollabConfig::maxStateBytes() */
export const DEFAULT_MAX_STATE_BYTES = 16 * 1024 * 1024;
const MIN_STATE_BYTES = 1024;

/** Read a varUint (lib0 encoding) at `at`: [value, next offset], or null past the end. */
function varUint(bytes, at) {
    let value = 0;
    let shift = 0;
    for (let i = at; i < bytes.length && shift < 53; i++) {
        value += (bytes[i] & 0x7f) * 2 ** shift;
        if (bytes[i] < 0x80) return [value, i + 1];
        shift += 7;
    }
    return null;
}

/**
 * The Yjs update a client message carries (Sync / SyncReply → SyncStep2 or Update), else null. A Hocuspocus message is
 * `varString document · varUint type · …`; a sync message continues `varUint syncType · varUint8Array update`.
 */
/**
 * An upper bound of how much `update` can grow a document's encoded state: its own bytes, plus for every struct it
 * inserts (which may split an existing item) and every range it deletes (which may split up to two items and adds to the
 * delete set) the size of the extra item headers. Deleting every other character of a long text grows the state ~6× the
 * update's size — the reason a plain `update.length` budget isn't enough (Codex follow-up review). Measured worst cases
 * stay below half of this bound. Cost: decoding the update only, never the document. null: not a valid update.
 */
export function growthBound(update) {
    try {
        const { structs, ds } = Y.decodeUpdate(update);
        let ranges = 0;
        ds.clients.forEach((items) => { ranges += items.length; });
        return update.length + 32 * structs.length + 64 * ranges;
    } catch {
        return null;
    }
}

export function updateOf(message) {
    const bytes = message instanceof Uint8Array ? message : new Uint8Array(message);
    let read = varUint(bytes, 0); // document name: length + UTF-8
    if (!read) return null;
    read = varUint(bytes, read[1] + read[0]);
    if (!read || (read[0] !== 0 && read[0] !== 4)) return null; // Sync / SyncReply
    read = varUint(bytes, read[1]);
    if (!read || (read[0] !== 1 && read[0] !== 2)) return null; // SyncStep2 / Update (SyncStep1 is a state vector)
    const length = varUint(bytes, read[1]);
    if (!length || length[1] + length[0] > bytes.length) return null;
    return bytes.subarray(length[1], length[1] + length[0]);
}

export function createCollabServer({
    port = 1234,
    address = '127.0.0.1',
    secret,
    appUrl,
    /** COLLAB_APP_HOST: Host header for calls to appUrl (loopback, see appFetch.js) */
    appHost = '',
    accessIntervalMs = 60_000,
    /** re-checks of a document that may fail in a row (no answer, 5xx, 401 …) before its connections close */
    maxAccessFailures = 2,
    /** users per access request (Brainitive accepts ≤ 500 users and ≤ 500 devices per request) */
    accessBatchSize = 200,
    storeRetryMs = 15_000,
    debounce = 2_000,
    maxDebounce = 10_000,
    fetchImpl,
    logger = console,
    stopOnSignals = true,
    /** a socket must authenticate this quickly, or it is closed (no anonymous sockets holding memory) */
    authDeadlineMs = 10_000,
    /** see DEFAULT_COLLAB_LIMITS */
    limits = {},
    /**
     * Largest Yjs state of one document (bytes) — Brainitive refuses to store more (413). Codex realtime review 5: an
     * update that would grow a document past it is refused before anyone else sees it. Brainitive's own value (the
     * access answer's `max_state_bytes`) replaces this default as soon as it answers.
     */
    maxStateBytes = DEFAULT_MAX_STATE_BYTES,
}) {
    if (!secret) throw new Error('COLLAB_SECRET (or APP_KEY) is required');
    if (!appUrl) throw new Error('COLLAB_APP_URL (or APP_URL) is required');

    let stateLimit = Math.max(MIN_STATE_BYTES, maxStateBytes);
    const L = { ...DEFAULT_COLLAB_LIMITS, ...limits };
    L.maxMessageBytes ??= stateLimit + 64 * 1024;
    /** Brainitive said how large a document may be (config collab.max_state_bytes): that is the limit */
    const learnLimit = (result) => {
        const value = result?.max_state_bytes;
        if (Number.isInteger(value) && value >= MIN_STATE_BYTES) stateLimit = value;
    };
    /**
     * document → an upper bound of its encoded size: measured on load and at every store (which encodes the state
     * anyway), plus growthBound() of every update accepted since. Never re-encoded per message — near the limit that
     * cost let one editor stall the process (Codex follow-up review); an update that might not fit is refused, and the
     * next store measures the real size again.
     */
    const sizes = new WeakMap();

    /** Whether the document surely stays within stateLimit with this update applied (then counted). */
    function fits(document, update) {
        const bound = growthBound(update);
        if (bound === null) return true; // not a valid update: Yjs ignores it
        if (!sizes.has(document)) sizes.set(document, Y.encodeStateAsUpdate(document).length); // once per document
        const next = sizes.get(document) + bound;
        if (next > stateLimit) return false;
        sizes.set(document, next);
        return true;
    }
    const keys = deriveKeys(secret);
    const warn = (message) => (logger.warn ?? logger.error)?.call(logger, message);
    const laravel = createLaravelClient({ appUrl, appHost, internalKey: keys.internal, fetchImpl });
    /** documents whose last store failed: kept in memory and retried, never unloaded */
    const unsaved = new Map();
    let server;

    const closeDocument = (documentName, event) => {
        const document = server.hocuspocus.documents.get(documentName);
        document?.getConnections().forEach((connection) => connection.close(event));
    };

    const isGone = (error) => error?.status === 409 || error?.status === 404;
    // over Brainitive's size limit (or rejected): retrying can't help — stop, keep the browsers' copies
    const isRefused = (error) => error?.status === 413 || error?.status === 422;

    /** app device id → when it was revoked (unix s): its still-valid tokens can't open a document */
    const revokedDevices = new Map();
    /** browser session (`sid`) → when it signed out (unix s); user id → {gen: lowest valid generation, at} */
    const endedSessions = new Map();
    const generations = new Map();
    const isSignedOut = (userId, session, generation) => (session !== null && endedSessions.has(session))
        || (generation !== null && generation < (generations.get(userId)?.gen ?? -1));

    /** request → socket, to close sockets that never authenticate or fail to */
    const sockets = new WeakMap();
    const authenticated = new WeakSet();
    /** user id → their document sockets; socket → the users it carries (to let go on close) */
    const userSockets = new Map();
    const socketUsers = new WeakMap();
    /** sockets closed for flooding: their remaining messages are dropped */
    const flooded = new WeakSet();
    const closeSocket = (request, code, reason) => {
        const socket = sockets.get(request);
        if (socket && socket.readyState === socket.OPEN) socket.close(code, reason);
    };

    async function store(documentName, document) {
        try {
            const state = Y.encodeStateAsUpdate(document);
            sizes.set(document, state.length); // measured anyway: the size estimate starts again from here
            await laravel.storeState(documentName, state);
            clearTimeout(unsaved.get(documentName));
            unsaved.delete(documentName);
            return true;
        } catch (error) {
            if (isGone(error)) {
                // the draft was replaced (discard, restore, …) or the page deleted: send everyone away
                clearTimeout(unsaved.get(documentName));
                unsaved.delete(documentName);
                setImmediate(() => closeDocument(documentName, RESET));
                return true;
            }
            if (isRefused(error)) {
                logger.error(`[realtime] ${documentName} refused by Brainitive (${error.status}): closing it`);
                clearTimeout(unsaved.get(documentName));
                unsaved.delete(documentName);
                setImmediate(() => closeDocument(documentName, TOO_LARGE));
                return true;
            }
            logger.error(`[realtime] storing ${documentName} failed (${error?.message}); retrying`);
            clearTimeout(unsaved.get(documentName));
            unsaved.set(documentName, setTimeout(async () => {
                if (await store(documentName, document) && document.getConnectionsCount() === 0) {
                    server.hocuspocus.unloadDocument(document);
                }
            }, storeRetryMs));
            return false;
        }
    }

    server = new Server({
        name: 'brainitive-realtime',
        port,
        address,
        quiet: true,
        debounce,
        maxDebounce,
        timeout: 30_000,
        stopOnSignals: false, // handled in listen()

        async onAuthenticate({ token, documentName, connectionConfig, request }) {
            // BRAI-345: every refusal says why — to the browser (permission-denied reason + close reason,
            // shown in the "Live editing unavailable" notice) and in this server's log (journalctl)
            let payload = null;
            const refuse = (reason, detail = '') => {
                // Codex realtime review 6: the name is the client's — escaped, cut short, one structured line
                warn(`[realtime] refused ${fields({ document: documentName, user: payload?.sub, reason, detail })}`);
                setImmediate(() => closeSocket(request, 4401, `Unauthorized: ${reason}`));
                throw Object.assign(new Error(`Not authorized: ${reason}`), { reason });
            };
            if (!DOCUMENT_NAME.test(documentName)) refuse('invalid-document');
            const inspected = inspectToken(token, keys.token);
            payload = inspected.payload;
            if (!payload) refuse(inspected.problem ?? 'invalid-token', inspected.problem === 'expired-token' ? 'token older than COLLAB_TOKEN_TTL' : 'is COLLAB_SECRET the same for the app and this server?');
            if (payload.doc !== documentName) refuse('wrong-document');
            // Codex realtime review 4: at most maxSocketsPerUser sockets per user (a place is held from here on)
            const socket = sockets.get(request);
            const held = userSockets.get(payload.sub) ?? new Set();
            if (socket && !held.has(socket)) {
                if (held.size >= L.maxSocketsPerUser) {
                    warn(`[realtime] refused ${fields({ document: documentName, user: payload.sub, reason: 'too-many-connections' })}`);
                    setImmediate(() => closeSocket(request, TOO_MANY_SOCKETS.code, TOO_MANY_SOCKETS.reason));
                    throw Object.assign(new Error('Not authorized: too-many-connections'), { reason: 'too-many-connections' });
                }
                held.add(socket);
                userSockets.set(payload.sub, held);
                socketUsers.set(socket, (socketUsers.get(socket) ?? new Set()).add(payload.sub));
            }
            // and at most maxDocumentsPerUser open documents, however many share one socket (Codex follow-up review)
            let documents = 0;
            server.hocuspocus.documents.forEach((open) => open.getConnections().forEach((c) => { if (c.context?.user?.id === payload.sub) documents++; }));
            if (documents >= L.maxDocumentsPerUser) {
                warn(`[realtime] refused ${fields({ document: documentName, user: payload.sub, reason: 'too-many-connections', documents })}`);
                throw Object.assign(new Error('Not authorized: too-many-connections'), { reason: 'too-many-connections' });
            }
            const device = payload.dev ?? null;
            // a signed-out app device: 4410 (not 4401), so the app drops its tokens instead of retrying
            const refuseDevice = () => {
                warn(`[realtime] refused ${fields({ document: documentName, user: payload.sub, reason: 'device-revoked', device })}`);
                setImmediate(() => closeSocket(request, DEVICE_REVOKED.code, DEVICE_REVOKED.reason));
                throw Object.assign(new Error('Not authorized: device-revoked'), { reason: 'device-revoked' });
            };
            if (device !== null && revokedDevices.has(device)) refuseDevice();
            const session = device === null ? payload.sid ?? null : null;
            const generation = device === null ? payload.gen ?? null : null;
            // a signed-out browser session: 4401 "Signed out" — a new token needs a new sign-in
            const refuseSignedOut = () => {
                warn(`[realtime] refused ${fields({ document: documentName, user: payload.sub, reason: 'signed-out' })}`);
                setImmediate(() => closeSocket(request, SIGNED_OUT.code, SIGNED_OUT.reason));
                throw Object.assign(new Error('Not authorized: signed-out'), { reason: 'signed-out' });
            };
            if (isSignedOut(payload.sub, session, generation)) refuseSignedOut();
            // the token is only a few minutes old at most, but access can change in between: ask Brainitive
            let result;
            try {
                result = await laravel.access(documentName, [payload.sub], device !== null ? [{ id: device, user_id: payload.sub }] : [], session !== null ? [session] : []);
            } catch (error) {
                // this server can't reach the app's internal API (0: firewall / no hairpin NAT / DNS / TLS —
                // see COLLAB_APP_URL + COLLAB_APP_HOST), or it refused (401 = secret differs, 404 = page /
                // issue gone or live editing not configured in the app, 3xx, 429, 5xx): nobody gets in
                const status = error?.status ?? 0;
                const hint = status === 401 ? 'COLLAB_SECRET differs between the app and this server'
                    : status === 404 ? 'document gone, or COLLAB_URL / COLLAB_SECRET missing in the app'
                        : status === 0 ? 'check COLLAB_APP_URL / COLLAB_APP_HOST' : '';
                refuse(status === 0 ? 'app-unreachable' : `app-error-${status}`, `${error?.message ?? error}${hint ? `; ${hint}` : ''}`);
            }
            if (device !== null && Array.isArray(result?.revoked_devices) && result.revoked_devices.includes(device)) {
                revokedDevices.set(device, Math.floor(Date.now() / 1000));
                refuseDevice();
            }
            learnLimit(result);
            // a push that never arrived: what Brainitive says now also closes what is already open
            const ended = endedAmong(result, new Set(session !== null ? [session] : []));
            if (learnGenerations(result) || ended.length > 0) setImmediate(closeSignedOutConnections);
            if (device === null && isSignedOut(payload.sub, session, generation)) refuseSignedOut();
            if (!result?.current) refuse('document-replaced');
            const access = result.users?.[String(payload.sub)] ?? null;
            if (access === null) refuse('access-denied');
            const edit = payload.edit && access === 'edit';
            // view-only: Hocuspocus drops this connection's document updates
            connectionConfig.readOnly = !edit;
            authenticated.add(request);
            return { user: { id: payload.sub, name: payload.name }, edit, device, session, generation };
        },

        // Awareness is client data: everyone must announce themselves as who their token says,
        // with a plain colour (Lexical writes it into style attributes) — else they're disconnected.
        async onAwarenessUpdate({ document, added = [], updated = [] }) {
            const changed = new Set([...added, ...updated]);
            if (changed.size === 0) return;
            const states = document.awareness.getStates();
            document.connections.forEach(({ clients, connection }) => {
                const context = connection.context ?? {};
                for (const clientId of clients) {
                    if (!changed.has(clientId)) continue;
                    const state = states.get(clientId);
                    if (!state) continue;
                    const data = state.awarenessData ?? {};
                    const valid = data.userId === context.user?.id
                        && (data.edit !== true || (context.edit && !connection.readOnly))
                        && (state.color === undefined || (typeof state.color === 'string' && COLOR.test(state.color)))
                        && (state.name === undefined || state.name === context.user?.name);
                    if (!valid) {
                        removeAwarenessStates(document.awareness, [clientId], 'invalid awareness');
                        connection.close(FORBIDDEN);
                        return;
                    }
                }
            });
        },

        // a flooding socket is being closed: what it still sends is dropped, never applied or passed on.
        // Codex realtime review 5: an update that would make the document larger than Brainitive stores is refused
        // here — before Yjs applies it and before any peer sees it; only its sender's document closes (4413).
        async beforeHandleMessage({ connection, document, documentName, update }) {
            if (connection?.webSocket && flooded.has(connection.webSocket)) {
                throw Object.assign(new Error(TOO_MANY_MESSAGES.reason), TOO_MANY_MESSAGES);
            }
            if (connection?.readOnly) return; // Hocuspocus drops a viewer's updates anyway
            const incoming = updateOf(update);
            if (incoming && incoming.length > 0 && !fits(document, incoming)) {
                warn(`[realtime] refused an update ${fields({ document: documentName, user: connection?.context?.user?.id, reason: 'document-too-large', bytes: incoming.length, limit: stateLimit })}`);
                throw Object.assign(new Error(TOO_LARGE.reason), TOO_LARGE);
            }
        },

        // between onAuthenticate and here the document was loading: a sign-out / device push in that gap found no
        // connection to close (Codex follow-up review) — check again now that it exists
        async connected({ connection, context }) {
            if (Number.isInteger(context?.device)) {
                if (revokedDevices.has(context.device)) closeRevoked(connection);
            } else if (Number.isInteger(context?.user?.id) && isSignedOut(context.user.id, context.session ?? null, context.generation ?? null)) {
                closeSignedOut(connection);
            }
        },

        async onLoadDocument({ document, documentName }) {
            let state;
            try {
                state = await laravel.loadState(documentName);
            } catch (error) {
                logger.error(`[realtime] loading ${documentName} failed: ${error?.message ?? error}`);
                throw error;
            }
            if (state) Y.applyUpdate(document, state);
            sizes.set(document, state ? state.length : 0); // the stored state is the encoded document
        },

        async onStoreDocument({ document, documentName }) {
            await store(documentName, document);
        },

        async beforeUnloadDocument({ documentName }) {
            if (unsaved.has(documentName)) throw new Error('unsaved changes'); // keep it in memory
        },
    }, { maxPayload: L.maxMessageBytes });

    // every socket has authDeadlineMs to authenticate, and a message budget (before Hocuspocus sees the message)
    server.webSocketServer.on('connection', (socket, request) => {
        sockets.set(request, socket);
        const allow = bucket(L.messageBurst, L.messagesPerSecond);
        socket.prependListener('message', () => {
            if (flooded.has(socket) || allow()) return;
            flooded.add(socket);
            warn(`[realtime] closed a socket: ${fields({ user: [...(socketUsers.get(socket) ?? [])].join(',') || null, reason: 'too-many-messages' })}`);
            socket.close(TOO_MANY_MESSAGES.code, TOO_MANY_MESSAGES.reason);
        });
        const timer = setTimeout(() => {
            if (!authenticated.has(request)) closeSocket(request, 4401, 'Unauthorized');
        }, authDeadlineMs);
        timer.unref();
        socket.once('close', () => {
            clearTimeout(timer);
            for (const id of socketUsers.get(socket) ?? []) {
                const held = userSockets.get(id);
                held?.delete(socket);
                if (held?.size === 0) userSockets.delete(id);
            }
            socketUsers.delete(socket);
        });
    });

    /** Close one connection of a revoked app device: the document, then its whole socket (4410). */
    function closeRevoked(connection) {
        connection.close(DEVICE_REVOKED);
        const socket = connection.webSocket;
        if (socket && socket.readyState === socket.OPEN) socket.close(DEVICE_REVOKED.code, DEVICE_REVOKED.reason);
    }

    /** The asked sessions an access answer names as signed out — remembered (their tokens are refused) and returned. */
    function endedAmong(result, asked) {
        const at = Math.floor(Date.now() / 1000);
        const ended = (Array.isArray(result?.ended_sessions) ? result.ended_sessions : []).filter((sid) => asked.has(sid));
        ended.forEach((sid) => endedSessions.set(sid, at));
        return ended;
    }

    /** The users' current generations from an access answer (older web tokens are signed out); true when one is new. */
    function learnGenerations(result) {
        const at = Math.floor(Date.now() / 1000);
        let changed = false;
        Object.entries(result?.generations && typeof result.generations === 'object' ? result.generations : {}).forEach(([id, gen]) => {
            const user = Number(id);
            if (!Number.isInteger(user) || !Number.isInteger(gen) || gen < 0 || gen <= (generations.get(user)?.gen ?? -1)) return;
            generations.set(user, { gen, at });
            changed = true;
        });
        return changed;
    }

    /** Close one connection of a signed-out browser session: the document, then its whole socket (4401). */
    function closeSignedOut(connection) {
        connection.close(SIGNED_OUT);
        const socket = connection.webSocket;
        if (socket && socket.readyState === socket.OPEN) socket.close(SIGNED_OUT.code, SIGNED_OUT.reason);
    }

    /** Close the web connections of signed-out sessions / generations. Returns how many closed. */
    function closeSignedOutConnections() {
        let closed = 0;
        for (const [, document] of server.hocuspocus.documents) {
            for (const connection of document.getConnections()) {
                const context = connection.context ?? {};
                if (context.device !== null && context.device !== undefined) continue;
                if (!Number.isInteger(context.user?.id) || !isSignedOut(context.user.id, context.session ?? null, context.generation ?? null)) continue;
                closed++;
                closeSignedOut(connection);
            }
        }
        return closed;
    }

    /**
     * Brainitive signed these browser sessions out / these users out everywhere (pushed by the live endpoint
     * POST /internal/live/sessions/ended): close their web connections (4401) and refuse their remaining tokens.
     * @param {{sessions?: string[], users?: {id: number, generation: number}[]}} ended
     */
    function endSessions({ sessions = [], users = [] }) {
        endedAmong({ ended_sessions: sessions }, new Set(sessions));
        learnGenerations({ generations: Object.fromEntries(users.map(({ id, generation }) => [String(id), generation])) });
        return closeSignedOutConnections();
    }

    /**
     * Brainitive revoked these app devices (sign-out, password change, suspension …; the live publish endpoint
     * passes them on): close their connections and refuse their remaining tokens. Returns how many connections closed.
     */
    function revokeDevices(ids) {
        const at = Math.floor(Date.now() / 1000);
        const gone = new Set(ids.filter((id) => Number.isInteger(id) && id > 0));
        gone.forEach((id) => revokedDevices.set(id, at));
        let closed = 0;
        for (const [, document] of server.hocuspocus.documents) {
            for (const connection of document.getConnections()) {
                if (gone.has(connection.context?.device)) {
                    closed++;
                    closeRevoked(connection);
                }
            }
        }
        return closed;
    }

    /** `${document}|${user}` → access re-checks of that connection that failed in a row */
    const accessFailures = new Map();
    let rechecking = false;

    /**
     * The users of one document in requests Brainitive accepts: ≤ accessBatchSize users and ≤ 500 devices and
     * sessions each.
     * @param {Map<number, number>} extras user id → their devices + sessions
     * @returns {number[][]}
     */
    function batches(userIds, extras) {
        const out = [];
        let current = [];
        let devices = 0;
        for (const id of userIds) {
            const own = extras.get(id) ?? 0;
            if (current.length > 0 && (current.length >= accessBatchSize || devices + own > 500)) {
                out.push(current);
                current = [];
                devices = 0;
            }
            current.push(id);
            devices += own;
        }
        if (current.length > 0) out.push(current);
        return out;
    }

    /**
     * Lost project access → disconnected; lost edit rights → read-only; replaced draft → all out; a revoked app
     * device → its socket closed (4410). No answer from Brainitive maxAccessFailures rounds in a row → those
     * connections close too (fail closed — Codex realtime review 2); a clear "no" closes at once.
     */
    async function recheckAccess() {
        if (rechecking) return;
        rechecking = true;
        try {
            await recheckDocuments();
        } finally {
            rechecking = false;
        }
    }

    async function recheckDocuments() {
        // a token lives minutes at most: older revocations can't matter any more
        const now = Math.floor(Date.now() / 1000);
        revokedDevices.forEach((at, id) => { if (now - at > 3600) revokedDevices.delete(id); });
        endedSessions.forEach((at, sid) => { if (now - at > 3600) endedSessions.delete(sid); });
        generations.forEach(({ at }, id) => { if (now - at > 3600) generations.delete(id); });
        const present = new Set();
        for (const [documentName, document] of server.hocuspocus.documents) {
            const connections = document.getConnections();
            const userIds = [...new Set(connections.map((c) => c.context?.user?.id).filter(Number.isInteger))];
            if (userIds.length === 0) continue;
            // per user: the app devices and the browser sessions of their connections (each counts against the 500)
            const devicesByUser = new Map();
            const sessionsByUser = new Map();
            const add = (map, id, value) => {
                const list = map.get(id) ?? [];
                if (!list.includes(value)) list.push(value);
                map.set(id, list);
            };
            connections.forEach((c) => {
                if (Number.isInteger(c.context?.device)) add(devicesByUser, c.context.user.id, c.context.device);
                if (typeof c.context?.session === 'string') add(sessionsByUser, c.context.user.id, c.context.session);
            });
            const extras = new Map(userIds.map((id) => [id, (devicesByUser.get(id)?.length ?? 0) + (sessionsByUser.get(id)?.length ?? 0)]));
            userIds.forEach((id) => present.add(`${documentName}|${id}`));
            for (const batch of batches(userIds, extras)) {
                const inBatch = new Set(batch);
                const mine = connections.filter((c) => inBatch.has(c.context?.user?.id));
                const devices = batch.flatMap((id) => (devicesByUser.get(id) ?? []).map((device) => ({ id: device, user_id: id })));
                const sessions = batch.flatMap((id) => sessionsByUser.get(id) ?? []);
                let result;
                try {
                    result = await laravel.access(documentName, batch, devices, sessions);
                } catch (error) {
                    if (isGone(error)) {
                        closeDocument(documentName, RESET);
                        break;
                    }
                    for (const id of batch) {
                        const key = `${documentName}|${id}`;
                        const failures = (accessFailures.get(key) ?? 0) + 1;
                        accessFailures.set(key, failures);
                        if (failures < maxAccessFailures) continue;
                        accessFailures.delete(key);
                        mine.filter((c) => c.context?.user?.id === id).forEach((c) => c.close(ACCESS_UNKNOWN));
                    }
                    logger.error(`[realtime] access re-check failed: ${fields({ document: documentName, users: batch.length, error: error?.message ?? String(error) })}`);
                    continue;
                }
                learnLimit(result);
                if (!result?.current) {
                    closeDocument(documentName, RESET);
                    break;
                }
                batch.forEach((id) => accessFailures.delete(`${documentName}|${id}`));
                const asked = new Set(devices.map(({ id }) => id));
                const lost = new Set((Array.isArray(result.revoked_devices) ? result.revoked_devices : []).filter((id) => asked.has(id)));
                if (lost.size > 0) revokeDevices([...lost]);
                endedAmong(result, new Set(sessions));
                learnGenerations(result);
                const signedOut = new Set(mine.filter((c) => !Number.isInteger(c.context?.device) && Number.isInteger(c.context?.user?.id)
                    && isSignedOut(c.context.user.id, c.context.session ?? null, c.context.generation ?? null)));
                signedOut.forEach((connection) => closeSignedOut(connection));
                for (const connection of mine) {
                    if (lost.has(connection.context?.device) || signedOut.has(connection)) continue;
                    const access = result.users?.[String(connection.context?.user?.id)] ?? null;
                    if (access === null) connection.close(FORBIDDEN);
                    else connection.readOnly = access !== 'edit' || !connection.context.edit;
                }
            }
        }
        // people who left (or were closed) start over
        [...accessFailures.keys()].forEach((key) => { if (!present.has(key)) accessFailures.delete(key); });
    }

    let timer = null;
    return {
        server,
        recheckAccess,
        revokeDevices,
        endSessions,
        async listen() {
            // Not server.listen(): Hocuspocus 3.4 passes `address` where Node expects `host`, so it would
            // listen on every interface instead of COLLAB_HOST (127.0.0.1 behind nginx).
            await new Promise((resolve, reject) => {
                server.httpServer.once('error', reject);
                server.httpServer.listen({ port, host: address }, () => {
                    server.httpServer.off('error', reject);
                    resolve();
                });
            });
            if (stopOnSignals) {
                const stop = async () => {
                    await this.destroy();
                    process.exit(0);
                };
                process.once('SIGINT', stop);
                process.once('SIGTERM', stop);
            }
            timer = setInterval(() => recheckAccess().catch((e) => logger.error('[realtime] access check failed', e)), accessIntervalMs);
            timer.unref();
            return server;
        },
        async destroy() {
            clearInterval(timer);
            unsaved.forEach((handle) => clearTimeout(handle));
            // Hocuspocus waits until every document is unloaded; don't hang a shutdown on it
            await Promise.race([server.destroy(), new Promise((resolve) => setTimeout(resolve, 5_000).unref())]);
        },
    };
}
