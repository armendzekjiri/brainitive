/**
 * Live channels (BRAI-151) for components. Every hook is a no-op while live is off (shared prop
 * `live` = null: no socket, no token request) and accepts `null` as the channel (not ready yet).
 * Channels: `user.{id}` · `item.{id}` · `board.{id}` · `plan.{id}` · `page.{uuid}`.
 *
 * Events carry ids and hints only — refetch through the normal endpoints (the payload rule,
 * docs/ai/tasks/feat-live-foundation.md).
 */
import type { LiveChannelStatus, LiveEvent, LiveMember, LiveSender, LiveSharedConfig } from '@/types';
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { liveClient, type LiveListener } from './client';

/** The shared `live` prop: null when live channels are off. */
export function useLiveConfig(): LiveSharedConfig | null {
    return (usePage().props as { live?: LiveSharedConfig | null }).live ?? null;
}

/** Subscribe with a listener whose callbacks may change on every render (kept in a ref). */
function useSubscription(channel: string | null, listener: LiveListener, presence: boolean) {
    const config = useLiveConfig();
    const latest = useRef(listener);
    latest.current = listener;
    const enabled = config !== null && channel !== null;
    const debug = config?.debug ?? false;

    useEffect(() => {
        if (!enabled || channel === null) return;
        return liveClient(debug).subscribe(channel, {
            presence,
            onEvent: (e) => latest.current.onEvent?.(e),
            onPresence: (m) => latest.current.onPresence?.(m),
            onSignal: (n, d, f) => latest.current.onSignal?.(n, d, f),
            onStatus: (s) => latest.current.onStatus?.(s),
            onResync: () => latest.current.onResync?.(),
        });
    }, [enabled, channel, presence, debug]);

    return enabled;
}

export interface LiveChannelOptions {
    /** Something changed (Live::publish): refetch what the event points to. */
    onEvent?: (event: LiveEvent) => void;
    /** Back after a disconnect — events of the gap are lost: refetch. */
    onResync?: () => void;
}

/**
 * Listen to a channel's events. `status`: 'off' (live disabled or not subscribed), 'connecting',
 * 'live', 'offline' (reconnecting), 'forbidden' (no access).
 */
export function useLiveChannel(channel: string | null, options: LiveChannelOptions = {}): { status: LiveChannelStatus } {
    const [status, setStatus] = useState<LiveChannelStatus>('off');
    const enabled = useSubscription(channel, { ...options, onStatus: setStatus }, false);
    return { status: enabled ? status : 'off' };
}

/**
 * Who is in the channel right now (this user included, one entry per person) — and announce this
 * tab while mounted. Empty while live is off.
 */
export function useLivePresence(channel: string | null): LiveMember[] {
    const [members, setMembers] = useState<LiveMember[]>([]);
    const enabled = useSubscription(channel, { onPresence: setMembers }, true);
    useEffect(() => setMembers([]), [channel]);
    return enabled ? members : [];
}

/**
 * Short, ephemeral signals to the channel's other members (typing, focus, …), rate-limited by the
 * server (≈ 4 per second, ≤ 1 KB). `from` is set by the server, never by the sender. Returns `send`
 * (a no-op while not live). Never put content in a signal.
 */
export function useLiveSignal<T = unknown>(channel: string | null, name: string, onSignal?: (data: T, from: LiveSender) => void): (data?: T) => boolean {
    const config = useLiveConfig();
    useSubscription(channel, { onSignal: (n, data, from) => { if (n === name) onSignal?.(data as T, from); } }, false);
    return useCallback(
        (data?: T) => (config !== null && channel !== null ? liveClient(config.debug).signal(channel, name, data ?? null) : false),
        [config, channel, name],
    );
}
