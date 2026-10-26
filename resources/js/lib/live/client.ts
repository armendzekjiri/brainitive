/**
 * Live channels (BRAI-151): one WebSocket per tab to the realtime server, shared by every
 * subscription. Plan + protocol: docs/ai/tasks/feat-live-foundation.md. Use the hooks in ./hooks.
 *
 * - Tokens come from `POST live.token` (the channels this user may join, checked by Brainitive's
 *   policies); a fresh one on every connect, another one (re-auth on the open socket) when a new
 *   channel shows up. Channels Brainitive refuses are "forbidden" and never retried in this tab.
 * - Reconnects with exponential backoff (1 s → 30 s, jitter), at once when the browser is back
 *   online; a tab hidden for 2 minutes disconnects and reconnects when visible again.
 * - Events published while disconnected are lost: subscribers get `onResync` after a gap and refetch.
 * - Off for good (until reload) when the token endpoint says live is off (404) or the session ended.
 */
import type { LiveChannelStatus, LiveEvent, LiveMember, LiveSender, LiveTokenResponse } from '@/types';
import axios from 'axios';

/** Same palette as the live document carets (components/editor/collab/shared.ts). */
const COLORS = ['#2563eb', '#c026d3', '#c2410c', '#0f766e', '#dc2626', '#7c3aed', '#0369a1', '#be123c', '#4d7c0f', '#a16207'];
export const liveColor = (userId: number) => COLORS[Math.abs(userId) % COLORS.length];

const HIDDEN_DISCONNECT_MS = 2 * 60_000;
const IDLE_DISCONNECT_MS = 10_000;
const TOKEN_BATCH_MS = 30;
const MAX_BACKOFF_MS = 30_000;
/** a refused channel isn't asked for again in this tab for a minute (access may come back) */
const DENIED_MS = 60_000;

export interface LiveListener {
    /** announce this tab in the channel's presence */
    presence?: boolean;
    onEvent?: (event: LiveEvent) => void;
    onPresence?: (members: LiveMember[]) => void;
    onSignal?: (name: string, data: unknown, from: LiveSender) => void;
    onStatus?: (status: LiveChannelStatus) => void;
    /** the subscription is back after a gap: refetch what may have changed meanwhile */
    onResync?: () => void;
}

interface ChannelState {
    listeners: Set<LiveListener>;
    status: LiveChannelStatus;
    members: LiveMember[];
    /** joined at least once on some socket (the next join is a resync) */
    joinedBefore: boolean;
    /** joined on the current socket, with this presence flag */
    joinedWith: boolean | null;
    /** a forbidden answer already led to a fresh token once */
    retried: boolean;
}

type ServerMessage =
    | { type: 'ready'; channels: string[] }
    | { type: 'subscribed'; channel: string; members: Omit<LiveMember, 'color'>[] }
    | { type: 'presence'; channel: string; members: Omit<LiveMember, 'color'>[] }
    | { type: 'event'; channel: string; event: string; payload: Record<string, unknown> }
    | { type: 'signal'; channel: string; name: string; data: unknown; from: LiveSender }
    | { type: 'revoked'; channel: string }
    | { type: 'error'; code: string; channel?: string | null }
    | { type: 'pong' };

/** LIVE_URL / COLLAB_URL may be a full ws(s)/http(s) URL or a path on this host ("/collab?live=1"). */
function socketUrl(url: string): string {
    if (url.startsWith('/')) return `${window.location.protocol === 'https:' ? 'wss' : 'ws'}://${window.location.host}${url}`;
    return url.replace(/^http(s?):\/\//, 'ws$1://');
}

const withColor = (members: Omit<LiveMember, 'color'>[]): LiveMember[] => members.map((m) => ({ ...m, color: liveColor(m.id) }));

export class LiveClient {
    private socket: WebSocket | null = null;
    private ready = false;
    private stopped = false;
    private attempt = 0;
    private connecting = false;
    private readonly channels = new Map<string, ChannelState>();
    private grants = new Set<string>();
    /** channel → refused until (ms) */
    private readonly denied = new Map<string, number>();
    /** hidden for a while: disconnected until the tab is visible again */
    private paused = false;
    /** channels of each auth sent on this socket, in order (each gets one `ready` back) */
    private pendingAuths: string[][] = [];
    private reconnectTimer: number | null = null;
    private hiddenTimer: number | null = null;
    private idleTimer: number | null = null;
    private tokenTimer: number | null = null;

    constructor() {
        window.addEventListener('online', () => this.connectSoon(0));
        document.addEventListener('visibilitychange', () => this.onVisibility());
    }

    /** Subscribe; returns the unsubscribe function. */
    subscribe(channel: string, listener: LiveListener): () => void {
        let state = this.channels.get(channel);
        if (!state) {
            state = { listeners: new Set(), status: 'connecting', members: [], joinedBefore: false, joinedWith: null, retried: false };
            this.channels.set(channel, state);
        }
        state.listeners.add(listener);
        if (this.idleTimer !== null) window.clearTimeout(this.idleTimer);
        this.idleTimer = null;

        if (this.stopped) state.status = 'off';
        else if (this.isDenied(channel)) state.status = 'forbidden';
        listener.onStatus?.(state.status);
        if (state.members.length) listener.onPresence?.(state.members);

        if (!this.stopped) {
            if (!this.socket) this.connectSoon(0);
            else this.join(channel);
        }

        return () => this.unsubscribe(channel, listener);
    }

    /** Send a short signal (typing, …) to the channel's other members. Dropped while not live. */
    signal(channel: string, name: string, data: unknown = null): boolean {
        if (this.channels.get(channel)?.status !== 'live') return false;
        return this.send({ type: 'signal', channel, name, data });
    }

    status(channel: string): LiveChannelStatus {
        return this.channels.get(channel)?.status ?? (this.stopped ? 'off' : 'connecting');
    }

    members(channel: string): LiveMember[] {
        return this.channels.get(channel)?.members ?? [];
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────────

    private unsubscribe(channel: string, listener: LiveListener) {
        const state = this.channels.get(channel);
        if (!state) return;
        state.listeners.delete(listener);
        if (state.listeners.size === 0) {
            this.channels.delete(channel);
            if (state.joinedWith !== null) this.send({ type: 'unsubscribe', channel });
        } else {
            this.join(channel); // the presence flag may have changed
        }
        if (this.channels.size === 0 && this.socket) {
            // nobody listens any more (page change): keep the socket a moment for the next page
            this.idleTimer = window.setTimeout(() => {
                if (this.channels.size === 0) this.disconnect();
            }, IDLE_DISCONNECT_MS);
        }
    }

    private isDenied(channel: string) {
        const until = this.denied.get(channel);
        if (until === undefined) return false;
        if (until > Date.now()) return true;
        this.denied.delete(channel);
        return false;
    }

    private deny(channel: string) {
        this.denied.set(channel, Date.now() + DENIED_MS);
    }

    private wantsPresence(state: ChannelState) {
        for (const listener of state.listeners) if (listener.presence) return true;
        return false;
    }

    /** Subscribe on the current socket (or ask for a token first). */
    private join(channel: string) {
        const state = this.channels.get(channel);
        if (!state || !this.ready || state.status === 'forbidden' || this.isDenied(channel)) return;
        if (!this.grants.has(channel)) {
            this.requestToken();
            return;
        }
        const presence = this.wantsPresence(state);
        if (state.joinedWith === presence) return;
        state.joinedWith = presence;
        this.send({ type: 'subscribe', channel, presence });
    }

    private setStatus(channel: string, state: ChannelState, status: LiveChannelStatus) {
        if (state.status === status) return;
        state.status = status;
        state.listeners.forEach((l) => l.onStatus?.(status));
    }

    private send(message: Record<string, unknown>): boolean {
        if (!this.socket || this.socket.readyState !== WebSocket.OPEN) return false;
        this.socket.send(JSON.stringify(message));
        return true;
    }

    /** Ask Brainitive for a token covering every wanted channel this socket can't join yet. */
    private requestToken() {
        if (this.tokenTimer !== null) return;
        this.tokenTimer = window.setTimeout(async () => {
            this.tokenTimer = null;
            const missing = [...this.channels.entries()]
                .filter(([c, state]) => !this.grants.has(c) && state.status !== 'forbidden' && !this.isDenied(c))
                .map(([c]) => c)
                .slice(0, 50);
            if (missing.length === 0 || !this.ready) return;
            const token = await this.fetchToken(missing);
            if (token && this.ready && this.send({ type: 'auth', token: token.token })) this.pendingAuths.push(token.channels);
        }, TOKEN_BATCH_MS);
    }

    private async fetchToken(channels: string[]): Promise<LiveTokenResponse | null> {
        try {
            const { data } = await axios.post<LiveTokenResponse>(route('live.token'), { channels });
            data.denied.forEach((channel) => {
                this.deny(channel);
                const state = this.channels.get(channel);
                if (state) this.setStatus(channel, state, 'forbidden');
            });
            return data;
        } catch (error) {
            const status = axios.isAxiosError(error) ? error.response?.status : undefined;
            // live is off for this browser, or the session ended: stay quiet until the next page load
            if (status === 404 || status === 401 || status === 419 || status === 403) this.stop();
            return null;
        }
    }

    private connectSoon(delay: number) {
        if (this.stopped || this.paused || this.socket || this.connecting || this.channels.size === 0) return;
        if (this.reconnectTimer !== null) window.clearTimeout(this.reconnectTimer);
        this.reconnectTimer = window.setTimeout(() => {
            this.reconnectTimer = null;
            this.connect();
        }, delay);
    }

    private scheduleReconnect() {
        const base = Math.min(MAX_BACKOFF_MS, 1000 * 2 ** this.attempt);
        this.attempt = Math.min(this.attempt + 1, 10);
        this.connectSoon(base / 2 + Math.random() * (base / 2));
    }

    private async connect() {
        if (this.stopped || this.paused || this.socket || this.connecting || this.channels.size === 0) return;
        this.connecting = true;
        const wanted = [...this.channels.keys()].filter((c) => !this.isDenied(c)).slice(0, 50);
        const token = wanted.length ? await this.fetchToken(wanted) : null;
        this.connecting = false;
        if (this.stopped || this.paused || this.socket) return;
        if (!token) {
            if (wanted.length) this.scheduleReconnect();
            return;
        }

        let socket: WebSocket;
        try {
            socket = new WebSocket(socketUrl(token.url));
        } catch {
            this.scheduleReconnect();
            return;
        }
        this.socket = socket;
        this.pendingAuths = [token.channels];
        socket.onopen = () => socket.send(JSON.stringify({ type: 'auth', token: token.token }));
        socket.onmessage = (e) => {
            if (this.socket !== socket || typeof e.data !== 'string') return;
            try {
                this.onMessage(JSON.parse(e.data) as ServerMessage);
            } catch {
                /* a bad message from the server: ignore it */
            }
        };
        socket.onclose = () => {
            if (this.socket !== socket) return;
            this.socket = null;
            this.ready = false;
            this.grants = new Set();
            this.pendingAuths = [];
            this.channels.forEach((state, channel) => {
                state.joinedWith = null;
                if (state.status !== 'forbidden') this.setStatus(channel, state, 'offline');
            });
            this.scheduleReconnect();
        };
    }

    private onMessage(message: ServerMessage) {
        switch (message.type) {
            case 'ready': {
                this.ready = true;
                this.attempt = 0;
                this.grants = new Set(message.channels);
                // allowed by Brainitive but not kept by the server (this socket holds too many): give up
                // on those instead of asking for tokens forever
                for (const channel of this.pendingAuths.shift() ?? []) {
                    const state = this.channels.get(channel);
                    if (this.grants.has(channel) || !state) continue;
                    this.deny(channel);
                    this.setStatus(channel, state, 'forbidden');
                }
                this.channels.forEach((_, channel) => this.join(channel));
                return;
            }
            case 'subscribed': {
                const state = this.channels.get(message.channel);
                if (!state) return;
                state.members = withColor(message.members);
                this.setStatus(message.channel, state, 'live');
                state.listeners.forEach((l) => l.onPresence?.(state.members));
                if (state.joinedBefore) state.listeners.forEach((l) => l.onResync?.());
                state.joinedBefore = true;
                state.retried = false;
                return;
            }
            case 'presence': {
                const state = this.channels.get(message.channel);
                if (!state) return;
                state.members = withColor(message.members);
                state.listeners.forEach((l) => l.onPresence?.(state.members));
                return;
            }
            case 'event': {
                const event: LiveEvent = { channel: message.channel, event: message.event, payload: message.payload ?? {} };
                this.channels.get(message.channel)?.listeners.forEach((l) => l.onEvent?.(event));
                return;
            }
            case 'signal':
                this.channels.get(message.channel)?.listeners.forEach((l) => l.onSignal?.(message.name, message.data, message.from));
                return;
            case 'revoked': {
                // access lost: asked again only by a new subscription, a minute from now at the earliest
                this.grants.delete(message.channel);
                this.deny(message.channel);
                const state = this.channels.get(message.channel);
                if (!state) return;
                state.joinedWith = null;
                state.members = [];
                state.listeners.forEach((l) => l.onPresence?.([]));
                this.setStatus(message.channel, state, 'forbidden');
                return;
            }
            case 'error': {
                if (message.code !== 'forbidden' || !message.channel) return;
                const state = this.channels.get(message.channel);
                if (!state) return;
                state.joinedWith = null;
                this.grants.delete(message.channel);
                // the server forgot an old grant of this socket: one fresh token, then give up
                if (!state.retried) {
                    state.retried = true;
                    this.requestToken();
                } else {
                    this.deny(message.channel);
                    this.setStatus(message.channel, state, 'forbidden');
                }
                return;
            }
        }
    }

    private onVisibility() {
        if (document.visibilityState === 'hidden') {
            if (this.hiddenTimer === null) {
                this.hiddenTimer = window.setTimeout(() => {
                    this.hiddenTimer = null;
                    this.paused = true;
                    this.disconnect();
                }, HIDDEN_DISCONNECT_MS);
            }
            return;
        }
        if (this.hiddenTimer !== null) window.clearTimeout(this.hiddenTimer);
        this.hiddenTimer = null;
        this.paused = false;
        if (!this.socket) {
            this.attempt = 0;
            this.connectSoon(0);
        }
    }

    /** Close the socket on purpose (hidden tab, nobody listening); reconnects when needed again. */
    private disconnect() {
        const socket = this.socket;
        if (!socket) return;
        this.socket = null;
        this.ready = false;
        this.grants = new Set();
        this.pendingAuths = [];
        socket.close(1000, 'idle');
        this.channels.forEach((state, channel) => {
            state.joinedWith = null;
            if (state.status !== 'forbidden') this.setStatus(channel, state, 'offline');
        });
    }

    private stop() {
        this.stopped = true;
        this.disconnect();
        this.channels.forEach((state, channel) => this.setStatus(channel, state, 'off'));
    }
}

let client: LiveClient | null = null;

/** The tab's one client (created on first use). */
export function liveClient(debug = false): LiveClient {
    if (!client) {
        client = new LiveClient();
        if (debug) exposeForE2e(client);
    }
    return client;
}

/** e2e only: lets specs join channels and read presence / signals without UI. */
function exposeForE2e(live: LiveClient) {
    const log: { events: LiveEvent[]; signals: { channel: string; name: string; data: unknown; from: LiveSender }[] } = { events: [], signals: [] };
    const leave = new Map<string, () => void>();
    (window as unknown as Record<string, unknown>).__brainitiveLive = {
        log,
        join(channel: string, presence = false) {
            leave.get(channel)?.();
            leave.set(channel, live.subscribe(channel, {
                presence,
                onEvent: (e) => log.events.push(e),
                onSignal: (name, data, from) => log.signals.push({ channel, name, data, from }),
            }));
        },
        leave(channel: string) {
            leave.get(channel)?.();
            leave.delete(channel);
        },
        status: (channel: string) => live.status(channel),
        members: (channel: string) => live.members(channel).map((m) => m.name),
        signal: (channel: string, name: string, data: unknown) => live.signal(channel, name, data),
    };
}
