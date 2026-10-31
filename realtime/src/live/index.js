// Live channels on the realtime server's port (BRAI-151). Plan: docs/ai/tasks/feat-live-foundation.md.
// Adds a Hocuspocus extension that takes over
//  - WebSocket upgrades with `?live=1` (any path — `/collab?live=1` passes nginx's `location = /collab`
//    and Docker's `/collab*` unchanged) or on the path `/live`, and
//  - HTTP requests under /internal/ (the publish endpoint),
// and leaves everything else (document sockets, the health check) to Hocuspocus.
import { WebSocketServer } from 'ws';
import { deriveKeys } from '../token.js';
import { createAccessClient } from './access.js';
import { createLiveHub } from './hub.js';
import { createPublishHandler } from './publish.js';
import { deriveLiveKeys } from './token.js';

/** A live channel socket (not a document one)? */
export function isLiveRequest(request) {
    try {
        const url = new URL(request.url ?? '/', 'http://realtime.invalid');
        return url.searchParams.get('live') === '1' || url.pathname === '/live';
    } catch {
        return false;
    }
}

/**
 * @param {import('@hocuspocus/server').Server} server the Hocuspocus server (createCollabServer().server)
 * @returns the hub ({publish, recheckAccess, members, stats, destroy})
 */
export function attachLive(server, {
    secret,
    appUrl,
    appHost = '',
    fetchImpl,
    accessIntervalMs = 60_000,
    publishFrom = 'loopback',
    logger = console,
    limits = {},
}) {
    if (!secret) throw new Error('COLLAB_SECRET (or APP_KEY) is required');
    if (!appUrl) throw new Error('COLLAB_APP_URL (or APP_URL) is required');

    const keys = deriveLiveKeys(secret);
    const access = createAccessClient({ appUrl, appHost, internalKey: deriveKeys(secret).internal, fetchImpl });
    const hub = createLiveHub({ tokenKey: keys.token, access, logger, limits });
    const handlePublish = createPublishHandler({ key: keys.publish, hub, allowFrom: publishFrom, logger });
    const sockets = new WebSocketServer({ noServer: true, maxPayload: 8 * 1024 });
    sockets.on('connection', (socket) => hub.handleConnection(socket));

    const extension = {
        // before the configuration's own hooks; rejecting with no error stops Hocuspocus quietly
        priority: 1000,
        async onUpgrade({ request, socket, head }) {
            if (!isLiveRequest(request)) return;
            sockets.handleUpgrade(request, socket, head, (ws) => sockets.emit('connection', ws, request));
            throw null; // eslint-disable-line no-throw-literal
        },
        async onRequest({ request, response }) {
            if (await handlePublish(request, response)) throw null; // eslint-disable-line no-throw-literal
        },
    };
    server.hocuspocus.configuration.extensions.unshift(extension);

    const timer = setInterval(() => hub.recheckAccess().catch((error) => logger.error('[live] access check failed', error)), accessIntervalMs);
    timer.unref();
    server.httpServer.once('close', () => clearInterval(timer));

    return {
        ...hub,
        destroy() {
            clearInterval(timer);
            hub.destroy();
            sockets.close();
        },
    };
}
