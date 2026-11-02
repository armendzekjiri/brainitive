// Brainitive realtime server (BRAI-54): Hocuspocus for live co-editing of document drafts.
// Plan: docs/ai/tasks/feat-live-docs.md. Brainitive (Laravel) stays the source of truth:
//  - every connection carries a short-lived token from Brainitive (one document, edit or view-only);
//  - the Yjs state is loaded from / stored to Brainitive's internal API (debounced, retried);
//  - every minute Brainitive re-checks who may still be connected;
//  - a connection whose token carries an app device (`dev`, App API) is closed with 4410 once Brainitive
//    revokes that device (pushed through revokeDevices(), or found by the re-check) — BRAI-377.
import { Server } from '@hocuspocus/server';
import * as Y from 'yjs';
import { removeAwarenessStates } from 'y-protocols/awareness';
import { DOCUMENT_NAME, deriveKeys, inspectToken } from './token.js';
import { createLaravelClient } from './laravel.js';
import { fields } from './log.js';

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
const COLOR = /^#[0-9a-f]{6}$/i;

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
}) {
    if (!secret) throw new Error('COLLAB_SECRET (or APP_KEY) is required');
    if (!appUrl) throw new Error('COLLAB_APP_URL (or APP_URL) is required');

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

    /** request → socket, to close sockets that never authenticate or fail to */
    const sockets = new WeakMap();
    const authenticated = new WeakSet();
    const closeSocket = (request, code, reason) => {
        const socket = sockets.get(request);
        if (socket && socket.readyState === socket.OPEN) socket.close(code, reason);
    };

    async function store(documentName, document) {
        try {
            await laravel.storeState(documentName, Y.encodeStateAsUpdate(document));
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
            const device = payload.dev ?? null;
            // a signed-out app device: 4410 (not 4401), so the app drops its tokens instead of retrying
            const refuseDevice = () => {
                warn(`[realtime] refused ${fields({ document: documentName, user: payload.sub, reason: 'device-revoked', device })}`);
                setImmediate(() => closeSocket(request, DEVICE_REVOKED.code, DEVICE_REVOKED.reason));
                throw Object.assign(new Error('Not authorized: device-revoked'), { reason: 'device-revoked' });
            };
            if (device !== null && revokedDevices.has(device)) refuseDevice();
            // the token is only a few minutes old at most, but access can change in between: ask Brainitive
            let result;
            try {
                result = await laravel.access(documentName, [payload.sub], device !== null ? [{ id: device, user_id: payload.sub }] : []);
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
            if (!result?.current) refuse('document-replaced');
            const access = result.users?.[String(payload.sub)] ?? null;
            if (access === null) refuse('access-denied');
            const edit = payload.edit && access === 'edit';
            // view-only: Hocuspocus drops this connection's document updates
            connectionConfig.readOnly = !edit;
            authenticated.add(request);
            return { user: { id: payload.sub, name: payload.name }, edit, device };
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

        async onLoadDocument({ document, documentName }) {
            let state;
            try {
                state = await laravel.loadState(documentName);
            } catch (error) {
                logger.error(`[realtime] loading ${documentName} failed: ${error?.message ?? error}`);
                throw error;
            }
            if (state) Y.applyUpdate(document, state);
        },

        async onStoreDocument({ document, documentName }) {
            await store(documentName, document);
        },

        async beforeUnloadDocument({ documentName }) {
            if (unsaved.has(documentName)) throw new Error('unsaved changes'); // keep it in memory
        },
    }, { maxPayload: 16 * 1024 * 1024 });

    // every socket has authDeadlineMs to authenticate
    server.webSocketServer.on('connection', (socket, request) => {
        sockets.set(request, socket);
        const timer = setTimeout(() => {
            if (!authenticated.has(request)) closeSocket(request, 4401, 'Unauthorized');
        }, authDeadlineMs);
        timer.unref();
        socket.once('close', () => clearTimeout(timer));
    });

    /** Close one connection of a revoked app device: the document, then its whole socket (4410). */
    function closeRevoked(connection) {
        connection.close(DEVICE_REVOKED);
        const socket = connection.webSocket;
        if (socket && socket.readyState === socket.OPEN) socket.close(DEVICE_REVOKED.code, DEVICE_REVOKED.reason);
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
     * The users of one document in requests Brainitive accepts: ≤ accessBatchSize users and ≤ 500 devices each.
     * @param {Map<number, number[]>} devicesByUser
     * @returns {number[][]}
     */
    function batches(userIds, devicesByUser) {
        const out = [];
        let current = [];
        let devices = 0;
        for (const id of userIds) {
            const own = devicesByUser.get(id)?.length ?? 0;
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
        const present = new Set();
        for (const [documentName, document] of server.hocuspocus.documents) {
            const connections = document.getConnections();
            const userIds = [...new Set(connections.map((c) => c.context?.user?.id).filter(Number.isInteger))];
            if (userIds.length === 0) continue;
            const devicesByUser = new Map();
            connections.forEach((c) => {
                if (!Number.isInteger(c.context?.device)) return;
                const list = devicesByUser.get(c.context.user.id) ?? [];
                if (!list.includes(c.context.device)) list.push(c.context.device);
                devicesByUser.set(c.context.user.id, list);
            });
            userIds.forEach((id) => present.add(`${documentName}|${id}`));
            for (const batch of batches(userIds, devicesByUser)) {
                const inBatch = new Set(batch);
                const mine = connections.filter((c) => inBatch.has(c.context?.user?.id));
                const devices = batch.flatMap((id) => (devicesByUser.get(id) ?? []).map((device) => ({ id: device, user_id: id })));
                let result;
                try {
                    result = await laravel.access(documentName, batch, devices);
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
                if (!result?.current) {
                    closeDocument(documentName, RESET);
                    break;
                }
                batch.forEach((id) => accessFailures.delete(`${documentName}|${id}`));
                const asked = new Set(devices.map(({ id }) => id));
                const lost = new Set((Array.isArray(result.revoked_devices) ? result.revoked_devices : []).filter((id) => asked.has(id)));
                if (lost.size > 0) revokeDevices([...lost]);
                for (const connection of mine) {
                    if (lost.has(connection.context?.device)) continue;
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
