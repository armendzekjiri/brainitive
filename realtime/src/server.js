// Brainitive realtime server (BRAI-54): Hocuspocus for live co-editing of document drafts.
// Plan: docs/ai/tasks/feat-live-docs.md. Brainitive (Laravel) stays the source of truth:
//  - every connection carries a short-lived token from Brainitive (one document, edit or view-only);
//  - the Yjs state is loaded from / stored to Brainitive's internal API (debounced, retried);
//  - every minute Brainitive re-checks who may still be connected.
import { Server } from '@hocuspocus/server';
import * as Y from 'yjs';
import { removeAwarenessStates } from 'y-protocols/awareness';
import { DOCUMENT_NAME, deriveKeys, inspectToken } from './token.js';
import { createLaravelClient } from './laravel.js';

const FORBIDDEN = { code: 4403, reason: 'Forbidden' };
const RESET = { code: 4409, reason: 'Document replaced' };
const TOO_LARGE = { code: 4413, reason: 'Document too large' };
const COLOR = /^#[0-9a-f]{6}$/i;

export function createCollabServer({
    port = 1234,
    address = '127.0.0.1',
    secret,
    appUrl,
    /** COLLAB_APP_HOST: Host header for calls to appUrl (loopback, see appFetch.js) */
    appHost = '',
    accessIntervalMs = 60_000,
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
                warn(`[realtime] refused ${documentName.slice(0, 100)}${payload ? ` for user ${payload.sub}` : ''}: ${reason}${detail ? ` — ${detail}` : ''}`);
                setImmediate(() => closeSocket(request, 4401, `Unauthorized: ${reason}`));
                throw Object.assign(new Error(`Not authorized: ${reason}`), { reason });
            };
            if (!DOCUMENT_NAME.test(documentName)) refuse('invalid-document');
            const inspected = inspectToken(token, keys.token);
            payload = inspected.payload;
            if (!payload) refuse(inspected.problem ?? 'invalid-token', inspected.problem === 'expired-token' ? 'token older than COLLAB_TOKEN_TTL' : 'is COLLAB_SECRET the same for the app and this server?');
            if (payload.doc !== documentName) refuse('wrong-document');
            // the token is only a few minutes old at most, but access can change in between: ask Brainitive
            let result;
            try {
                result = await laravel.access(documentName, [payload.sub]);
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
            if (!result?.current) refuse('document-replaced');
            const access = result.users?.[String(payload.sub)] ?? null;
            if (access === null) refuse('access-denied');
            const edit = payload.edit && access === 'edit';
            // view-only: Hocuspocus drops this connection's document updates
            connectionConfig.readOnly = !edit;
            authenticated.add(request);
            return { user: { id: payload.sub, name: payload.name }, edit };
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

    /** Lost project access → disconnected; lost edit rights → read-only; replaced draft → all out. */
    async function recheckAccess() {
        for (const [documentName, document] of server.hocuspocus.documents) {
            const connections = document.getConnections();
            const userIds = [...new Set(connections.map((c) => c.context?.user?.id).filter(Number.isInteger))];
            if (userIds.length === 0) continue;
            let result;
            try {
                result = await laravel.access(documentName, userIds);
            } catch (error) {
                if (isGone(error)) closeDocument(documentName, RESET);
                else logger.error(`[realtime] access re-check of ${documentName} failed: ${error?.message ?? error}`);
                continue; // Brainitive unreachable: keep everyone, try again next round
            }
            if (!result.current) {
                closeDocument(documentName, RESET);
                continue;
            }
            for (const connection of connections) {
                const access = result.users?.[String(connection.context?.user?.id)] ?? null;
                if (access === null) connection.close(FORBIDDEN);
                else connection.readOnly = access !== 'edit' || !connection.context.edit;
            }
        }
    }

    let timer = null;
    return {
        server,
        recheckAccess,
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
