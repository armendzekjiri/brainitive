// Live channels (BRAI-151): pub/sub, presence and short client signals over one WebSocket per tab.
// Plan + protocol: docs/ai/tasks/feat-live-foundation.md. Brainitive decides who may join what (the
// token lists the channels, re-checked every minute); this hub only routes messages.
//
// Client → server: {type: 'auth', token} · {type: 'subscribe', channel, presence?} ·
//   {type: 'unsubscribe', channel} · {type: 'signal', channel, name, data} · {type: 'ping'}
// Server → client: {type: 'ready', user, channels} · {type: 'subscribed', channel, members} ·
//   {type: 'event', channel, event, payload} · {type: 'presence', channel, members} ·
//   {type: 'signal', channel, name, data, from: {id, name}} · {type: 'revoked', channel} ·
//   {type: 'error', code, channel?} · {type: 'pong'}
import { CHANNEL, verifyLiveToken } from './token.js';

export const CLOSE = {
    badRequest: [4400, 'Bad request'],
    unauthorized: [4401, 'Unauthorized'],
    tooMany: [4429, 'Too many messages'],
    tooManySockets: [4429, 'Too many connections'],
    accessUnknown: [4403, 'Access could not be checked'],
    shutdown: [1001, 'Server shutting down'],
};

export const SIGNAL_NAME = /^[a-z][a-z0-9_.:-]{0,39}$/;

export const DEFAULT_LIMITS = {
    authDeadlineMs: 10_000,
    messageBurst: 40,
    messagesPerSecond: 8,
    signalBurst: 10,
    signalsPerSecond: 4,
    maxSubscriptions: 50,
    maxGrants: 50,
    maxSocketsPerUser: 20,
    /** distinct channels one user may be subscribed to over all their sockets */
    maxUserChannels: 100,
    /** sockets that haven't authenticated yet, over everyone */
    maxPending: 500,
    /** failed access re-checks in a row before a user's sockets are closed (fail closed) */
    maxAccessFailures: 3,
    maxSignalBytes: 1024,
    pingMs: 30_000,
    presenceDelayMs: 50,
};

/** Token bucket: `capacity` at once, refilled at `perSecond`. */
function bucket(capacity, perSecond) {
    let tokens = capacity;
    let last = Date.now();
    return () => {
        const now = Date.now();
        tokens = Math.min(capacity, tokens + ((now - last) / 1000) * perSecond);
        last = now;
        if (tokens < 1) return false;
        tokens -= 1;
        return true;
    };
}

export function createLiveHub({ tokenKey, access, logger = console, limits = {} }) {
    const L = { ...DEFAULT_LIMITS, ...limits };
    /** every open live socket */
    const clients = new Set();
    /** channel → subscribed clients */
    const channels = new Map();
    const presenceTimers = new Map();
    /** `${user}|${channel}` → signal bucket, shared by all of a user's sockets */
    const signalLimits = new Map();
    /** `${user}|${channel}` → when access was revoked (unix s): older tokens can't grant it back */
    const revoked = new Map();
    /** user id → access re-checks failed in a row */
    const accessFailures = new Map();
    let rechecking = false;

    const sendRaw = (client, text) => {
        if (client.socket.readyState === client.socket.OPEN) client.socket.send(text);
    };
    const send = (client, message) => sendRaw(client, JSON.stringify(message));
    const close = (client, [code, reason]) => {
        if (client.socket.readyState === client.socket.OPEN || client.socket.readyState === client.socket.CONNECTING) {
            client.socket.close(code, reason);
        }
    };

    /** Who is here: one entry per user (several tabs = one member), in join order. */
    function members(channel) {
        const byUser = new Map();
        for (const client of channels.get(channel) ?? []) {
            if (client.user && client.subs.get(channel)?.presence && !byUser.has(client.user.id)) {
                byUser.set(client.user.id, { id: client.user.id, name: client.user.name, avatar: client.user.avatar });
            }
        }
        return [...byUser.values()];
    }

    /** Coalesced: a burst of joins / leaves sends one list. */
    function schedulePresence(channel) {
        if (presenceTimers.has(channel)) return;
        const timer = setTimeout(() => {
            presenceTimers.delete(channel);
            const subscribers = channels.get(channel);
            if (!subscribers) return;
            const text = JSON.stringify({ type: 'presence', channel, members: members(channel) });
            subscribers.forEach((client) => sendRaw(client, text));
        }, L.presenceDelayMs);
        timer.unref?.();
        presenceTimers.set(channel, timer);
    }

    function unsubscribe(client, channel) {
        const subscription = client.subs.get(channel);
        if (!subscription) return;
        client.subs.delete(channel);
        const subscribers = channels.get(channel);
        subscribers?.delete(client);
        if (subscribers?.size === 0) channels.delete(channel);
        if (subscription.presence) schedulePresence(channel);
    }

    /** Access lost: out of the channel and no new subscription without a new token. */
    function revoke(client, channel) {
        revoked.set(`${client.user.id}|${channel}`, Math.floor(Date.now() / 1000));
        const had = client.grants.delete(channel) || client.subs.has(channel);
        unsubscribe(client, channel);
        if (had) send(client, { type: 'revoked', channel });
    }

    function authenticate(client, token) {
        const payload = verifyLiveToken(token, tokenKey);
        // one socket = one user for its whole life
        if (!payload || (client.user && client.user.id !== payload.sub)) return close(client, CLOSE.unauthorized);
        if (!client.user) {
            let sockets = 0;
            clients.forEach((other) => { if (other.user?.id === payload.sub) sockets++; });
            if (sockets >= L.maxSocketsPerUser) return close(client, CLOSE.tooManySockets);
            clearTimeout(client.deadline);
        }
        client.user = { id: payload.sub, name: payload.name.slice(0, 80), avatar: payload.avatar ? payload.avatar.slice(0, 500) : null };
        for (const channel of payload.ch) {
            if (client.grants.has(channel)) continue;
            // revoked since this token was issued: only a newer token (Brainitive asked again) brings it back
            if ((revoked.get(`${payload.sub}|${channel}`) ?? -1) >= payload.iat) continue;
            // full: forget the oldest grant this socket isn't subscribed to (a long-lived tab moves on)
            if (client.grants.size >= L.maxGrants) {
                const idle = [...client.grants].find((granted) => !client.subs.has(granted));
                if (idle === undefined) break;
                client.grants.delete(idle);
            }
            client.grants.add(channel);
        }
        send(client, { type: 'ready', user: { id: client.user.id }, channels: [...client.grants] });
    }

    function subscribe(client, { channel, presence }) {
        if (typeof channel !== 'string' || !CHANNEL.test(channel)) return close(client, CLOSE.badRequest);
        if (!client.grants.has(channel)) return send(client, { type: 'error', code: 'forbidden', channel });
        const before = client.subs.get(channel);
        if (!before && (client.subs.size >= L.maxSubscriptions || userChannels(client.user.id, channel) >= L.maxUserChannels)) {
            return send(client, { type: 'error', code: 'too_many_subscriptions', channel });
        }
        const announce = presence === true;
        client.subs.set(channel, { presence: announce });
        if (!channels.has(channel)) channels.set(channel, new Set());
        channels.get(channel).add(client);
        send(client, { type: 'subscribed', channel, members: members(channel) });
        if (announce !== (before?.presence ?? false)) schedulePresence(channel);
    }

    function signal(client, { channel, name, data }) {
        if (typeof channel !== 'string' || !client.subs.has(channel)) return send(client, { type: 'error', code: 'not_subscribed', channel: typeof channel === 'string' ? channel : null });
        const body = JSON.stringify(data ?? null);
        if (typeof name !== 'string' || !SIGNAL_NAME.test(name) || Buffer.byteLength(body) > L.maxSignalBytes) {
            return send(client, { type: 'error', code: 'invalid_signal', channel });
        }
        const key = `${client.user.id}|${channel}`;
        if (!signalLimits.has(key)) signalLimits.set(key, { take: bucket(L.signalBurst, L.signalsPerSecond), used: 0 });
        const limit = signalLimits.get(key);
        limit.used = Date.now();
        if (!limit.take()) return send(client, { type: 'error', code: 'rate_limited', channel });
        // the sender comes from the token, never from the message
        const text = `{"type":"signal","channel":${JSON.stringify(channel)},"name":${JSON.stringify(name)},"data":${body},"from":${JSON.stringify({ id: client.user.id, name: client.user.name })}}`;
        channels.get(channel)?.forEach((other) => { if (other !== client) sendRaw(other, text); });
    }

    function onMessage(client, raw, isBinary) {
        if (!client.messageLimit()) return close(client, CLOSE.tooMany);
        if (isBinary) return close(client, CLOSE.badRequest);
        let message;
        try {
            message = JSON.parse(raw.toString('utf8'));
        } catch {
            return close(client, CLOSE.badRequest);
        }
        if (!message || typeof message !== 'object' || typeof message.type !== 'string') return close(client, CLOSE.badRequest);
        if (message.type === 'auth') return authenticate(client, message.token);
        if (!client.user) return close(client, CLOSE.unauthorized);
        switch (message.type) {
            case 'subscribe': return subscribe(client, message);
            case 'unsubscribe': return typeof message.channel === 'string' ? unsubscribe(client, message.channel) : close(client, CLOSE.badRequest);
            case 'signal': return signal(client, message);
            case 'ping': return send(client, { type: 'pong' });
            default: return close(client, CLOSE.badRequest);
        }
    }

    /** Distinct channels `userId` is subscribed to over all sockets, `except` one. */
    function userChannels(userId, except) {
        const set = new Set();
        clients.forEach((other) => {
            if (other.user?.id === userId) other.subs.forEach((_, channel) => { if (channel !== except) set.add(channel); });
        });
        return set.size;
    }

    function handleConnection(socket) {
        let pending = 0;
        clients.forEach((other) => { if (!other.user) pending++; });
        if (pending >= L.maxPending) {
            socket.close(...CLOSE.tooManySockets);
            return;
        }
        const client = {
            socket,
            user: null,
            grants: new Set(),
            subs: new Map(),
            messageLimit: bucket(L.messageBurst, L.messagesPerSecond),
            alive: true,
            deadline: null,
        };
        clients.add(client);
        // a socket must authenticate quickly, or it is closed (no anonymous sockets holding memory)
        client.deadline = setTimeout(() => { if (!client.user) close(client, CLOSE.unauthorized); }, L.authDeadlineMs);
        client.deadline.unref?.();
        socket.on('message', (raw, isBinary) => {
            try {
                onMessage(client, raw, isBinary);
            } catch (error) {
                logger.error('[live] message failed', error);
                close(client, CLOSE.badRequest);
            }
        });
        socket.on('pong', () => { client.alive = true; });
        socket.on('error', () => { /* the close event follows */ });
        socket.on('close', () => {
            clearTimeout(client.deadline);
            clients.delete(client);
            [...client.subs.keys()].forEach((channel) => unsubscribe(client, channel));
        });
    }

    /** Laravel → everyone subscribed to `channel`. Returns the number of sockets it went to. */
    function publish(channel, event, payload) {
        const subscribers = channels.get(channel);
        if (!subscribers) return 0;
        const text = JSON.stringify({ type: 'event', channel, event, payload });
        subscribers.forEach((client) => sendRaw(client, text));
        return subscribers.size;
    }

    /**
     * Ask Brainitive which channels each connected user may still use; drop the rest. Only subscribed
     * channels are checked (grants nobody subscribed to are forgotten: the browser asks for a new
     * token when it needs one). A failing request is retried per user, so one entry can't shield the
     * others; a user whose check keeps failing is disconnected (fail closed).
     */
    async function recheckAccess() {
        if (rechecking) return;
        rechecking = true;
        try {
            const wanted = new Map();
            clients.forEach((client) => {
                if (!client.user) return;
                client.grants = new Set([...client.grants].filter((channel) => client.subs.has(channel)));
                if (!wanted.has(client.user.id)) wanted.set(client.user.id, new Set());
                client.subs.forEach((_, channel) => wanted.get(client.user.id).add(channel));
            });
            const entries = [...wanted.entries()].filter(([, set]) => set.size > 0);
            for (let i = 0; i < entries.length; i += 100) {
                const chunk = entries.slice(i, i + 100);
                if (!(await checkChunk(chunk)) && chunk.length > 1) {
                    for (const entry of chunk) await checkChunk([entry]);
                }
            }
        } finally {
            rechecking = false;
        }
    }

    /** @returns {Promise<boolean>} whether Brainitive answered */
    async function checkChunk(chunk) {
        let allowed;
        try {
            allowed = await access(chunk.map(([id, set]) => ({ id, channels: [...set] })));
        } catch (error) {
            if (chunk.length === 1) {
                const [id] = chunk[0];
                const failures = (accessFailures.get(id) ?? 0) + 1;
                accessFailures.set(id, failures);
                logger.error(`[live] access check for user ${id} failed (${error?.message}), ${failures} in a row`);
                if (failures >= L.maxAccessFailures) {
                    accessFailures.delete(id);
                    clients.forEach((client) => { if (client.user?.id === id) close(client, CLOSE.accessUnknown); });
                }
            }
            return false;
        }
        for (const [id, checked] of chunk) {
            accessFailures.delete(id);
            const ok = new Set(Array.isArray(allowed[String(id)]) ? allowed[String(id)] : []);
            clients.forEach((client) => {
                if (client.user?.id !== id) return;
                // only what was asked about: subscriptions made meanwhile wait for the next round
                [...client.grants, ...client.subs.keys()].forEach((channel) => {
                    if (checked.has(channel) && !ok.has(channel)) revoke(client, channel);
                });
            });
        }
        return true;
    }

    const ping = setInterval(() => {
        const now = Date.now();
        signalLimits.forEach((limit, key) => { if (now - limit.used > 60_000) signalLimits.delete(key); });
        // a token lives minutes at most: older revocations can't matter any more
        revoked.forEach((at, key) => { if (now / 1000 - at > 3600) revoked.delete(key); });
        clients.forEach((client) => {
            if (!client.alive) return client.socket.terminate();
            client.alive = false;
            if (client.socket.readyState === client.socket.OPEN) client.socket.ping();
        });
    }, L.pingMs);
    ping.unref?.();

    return {
        handleConnection,
        publish,
        recheckAccess,
        members,
        /** for tests / health */
        stats: () => ({ sockets: clients.size, channels: channels.size }),
        destroy() {
            clearInterval(ping);
            presenceTimers.forEach((timer) => clearTimeout(timer));
            presenceTimers.clear();
            clients.forEach((client) => close(client, CLOSE.shutdown));
        },
    };
}
