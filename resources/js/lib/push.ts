import axios from 'axios';
import type { PushDevice, PushTestResult } from '@/types';

/**
 * Browser push helpers. All functions are safe to call in unsupported browsers
 * (they no-op / return false) so callers don't need to feature-detect first.
 */

export function isPushSupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

export function pushPermission(): NotificationPermission | 'unsupported' {
    if (!isPushSupported()) return 'unsupported';
    return Notification.permission;
}

/** VAPID public keys are base64url; PushManager wants an ArrayBuffer-backed view. */
function urlBase64ToUint8Array(base64String: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    const buffer = new ArrayBuffer(raw.length);
    const output = new Uint8Array(buffer);
    for (let i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i);
    return output;
}

async function getRegistration(): Promise<ServiceWorkerRegistration | null> {
    if (!isPushSupported()) return null;
    try {
        // app.tsx registers /sw.js; ready resolves once it's active — and never when registration
        // failed (private windows, blocked storage), so don't wait forever.
        return await Promise.race([
            navigator.serviceWorker.ready,
            new Promise<null>((resolve) => setTimeout(() => resolve(null), 10_000)),
        ]);
    } catch {
        return null;
    }
}

/** Whether this browser currently holds an active push subscription. */
export async function isSubscribed(): Promise<boolean> {
    const reg = await getRegistration();
    if (!reg) return false;
    // Firefox / WebKit reject when no push service is reachable (headless, privacy settings)
    const sub = await reg.pushManager.getSubscription().catch(() => null);
    return !!sub;
}

/** This browser's push endpoint, or null when it holds no subscription. */
export async function currentEndpoint(): Promise<string | null> {
    const reg = await getRegistration();
    if (!reg) return null;
    const sub = await reg.pushManager.getSubscription().catch(() => null);
    return sub?.endpoint ?? null;
}

/**
 * SHA-256 (hex) of an endpoint: the device list (BRAI-154) marks "This device" by comparing it with
 * the server's `endpoint_hash`, so endpoints never have to be sent to the page.
 */
export async function endpointHash(endpoint: string): Promise<string | null> {
    if (typeof crypto === 'undefined' || !crypto.subtle) return null;
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(endpoint));
    return Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Request permission (if needed), subscribe via PushManager, and persist the
 * subscription on the server. Returns true on success.
 *
 * `fresh`: drop this browser's existing subscription first and subscribe anew — for a
 * subscription the push service reported as expired (re-sending it would not revive it).
 */
export async function subscribeToPush(vapidPublicKey: string, { fresh = false }: { fresh?: boolean } = {}): Promise<boolean> {
    if (!isPushSupported() || !vapidPublicKey) return false;

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return false;

    const reg = await getRegistration();
    if (!reg) return false;

    let sub = await reg.pushManager.getSubscription();
    if (sub && fresh) {
        await sub.unsubscribe().catch(() => {});
        sub = null;
    }
    if (!sub) {
        sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
        });
    }

    await axios.post('/api/push-subscriptions', sub.toJSON());
    return true;
}

/** Unsubscribe locally and remove the subscription from the server. */
export async function unsubscribeFromPush(): Promise<boolean> {
    const reg = await getRegistration();
    if (!reg) return false;

    const sub = await reg.pushManager.getSubscription().catch(() => null);
    if (!sub) return true;

    const endpoint = sub.endpoint;
    await sub.unsubscribe().catch(() => {});
    await axios.delete('/api/push-subscriptions', { data: { endpoint } }).catch(() => {});
    return true;
}

// ── Devices (BRAI-154) ────────────────────────────────────────────────────

/** The signed-in user's registered browsers (Profile → Notifications). */
export async function listPushDevices(): Promise<PushDevice[]> {
    const { data } = await axios.get<{ data: PushDevice[] }>(route('push-subscriptions.index'));
    return data.data;
}

/** Remove one registered browser by id (the server only accepts the user's own). */
export async function removePushDevice(id: number): Promise<void> {
    await axios.delete(route('push-subscriptions.remove', { subscription: id }));
}

/** Send a test push to one registered browser now and get the push service's answer. */
export async function testPushDevice(id: number): Promise<{ result: PushTestResult; device: PushDevice }> {
    const { data } = await axios.post<{ result: PushTestResult; device: PushDevice }>(route('push-subscriptions.test', { subscription: id }));
    return data;
}

/** This browser's subscription only (no server call): used after removing this device's row. */
export async function unsubscribeLocally(): Promise<void> {
    const reg = await getRegistration();
    const sub = await reg?.pushManager.getSubscription().catch(() => null);
    await sub?.unsubscribe().catch(() => {});
}
