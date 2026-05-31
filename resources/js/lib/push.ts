import axios from 'axios';

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
        // app.tsx registers /sw.js; ready resolves once it's active.
        return await navigator.serviceWorker.ready;
    } catch {
        return null;
    }
}

/** Whether this browser currently holds an active push subscription. */
export async function isSubscribed(): Promise<boolean> {
    const reg = await getRegistration();
    if (!reg) return false;
    const sub = await reg.pushManager.getSubscription();
    return !!sub;
}

/**
 * Request permission (if needed), subscribe via PushManager, and persist the
 * subscription on the server. Returns true on success.
 */
export async function subscribeToPush(vapidPublicKey: string): Promise<boolean> {
    if (!isPushSupported() || !vapidPublicKey) return false;

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return false;

    const reg = await getRegistration();
    if (!reg) return false;

    let sub = await reg.pushManager.getSubscription();
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

    const sub = await reg.pushManager.getSubscription();
    if (!sub) return true;

    const endpoint = sub.endpoint;
    await sub.unsubscribe().catch(() => {});
    await axios.delete('/api/push-subscriptions', { data: { endpoint } }).catch(() => {});
    return true;
}
