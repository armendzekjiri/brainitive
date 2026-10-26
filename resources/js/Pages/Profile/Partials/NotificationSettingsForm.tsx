import { useCallback, useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import { Loader2, Send } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { PageProps, PushDevice, PushTestResult } from '@/types';
import {
    currentEndpoint,
    endpointHash,
    isPushSupported,
    listPushDevices,
    pushPermission,
    removePushDevice,
    subscribeToPush,
    testPushDevice,
    unsubscribeFromPush,
    unsubscribeLocally,
} from '@/lib/push';
import PushDevices from './PushDevices';

type Reason = 'ok' | 'insecure' | 'unsupported' | 'unconfigured';

/**
 * Browser push for this device + every registered device (BRAI-154): turn push on / off here,
 * re-register a browser whose subscription is gone or expired, and send a test notification to any
 * device to see what its push service answers.
 */
export default function NotificationSettingsForm({ className = '' }: { className?: string }) {
    const { props } = usePage<PageProps>();
    const vapidPublicKey = props.push?.vapidPublicKey ?? null;

    // 'ok' once this browser can subscribe; otherwise an explanation (the device list still shows)
    const [reason, setReason] = useState<Reason>('ok');
    const [permission, setPermission] = useState<NotificationPermission | 'unsupported'>('default');
    // undefined until this browser's subscription has been looked up
    const [localHash, setLocalHash] = useState<string | null | undefined>(undefined);
    const [devices, setDevices] = useState<PushDevice[] | null>(null);
    const [results, setResults] = useState<Record<number, PushTestResult>>({});
    const [busy, setBusy] = useState<string | null>(null);

    const refresh = useCallback(async () => {
        setPermission(pushPermission());
        // the list doesn't wait for the service worker (this browser's endpoint)
        const endpoint = currentEndpoint().then((e) => (e ? endpointHash(e) : null));
        const list = await listPushDevices().catch(() => null);
        setDevices((prev) => list ?? prev ?? []);
        setLocalHash(await endpoint);
    }, []);

    useEffect(() => {
        // Service workers + Push require a secure context (HTTPS, or localhost).
        if (typeof window !== 'undefined' && window.isSecureContext === false) setReason('insecure');
        else if (!isPushSupported()) setReason('unsupported');
        else if (!vapidPublicKey) setReason('unconfigured');
        else setReason('ok');
        refresh();
    }, [vapidPublicKey, refresh]);

    const current = devices?.find((d) => !!localHash && d.endpoint_hash === localHash) ?? null;
    const enabled = !!current && !current.expired_at;
    // permission granted but no live row for this browser: expired, removed elsewhere, or never saved
    const needsRegister = reason === 'ok' && devices !== null && localHash !== undefined && permission === 'granted' && !enabled;
    const blocked = reason === 'ok' && permission === 'denied';

    async function turnOn(fresh: boolean) {
        if (busy || !vapidPublicKey) return;
        setBusy('subscribe');
        try {
            const ok = await subscribeToPush(vapidPublicKey, { fresh });
            if (ok) toast.success(fresh ? 'This device is registered again' : 'Browser notifications turned on');
            else toast.error('Could not enable notifications. Check your browser permissions.');
        } catch {
            toast.error('Could not register this browser with its push service. Try again in a moment.');
        } finally {
            await refresh();
            setBusy(null);
        }
    }

    async function turnOff() {
        if (busy) return;
        setBusy('unsubscribe');
        try {
            await unsubscribeFromPush();
            toast.success('Browser notifications turned off');
        } catch {
            toast.error('Something went wrong updating notifications.');
        } finally {
            await refresh();
            setBusy(null);
        }
    }

    async function sendTest(device: PushDevice) {
        if (busy) return;
        setBusy(`test:${device.id}`);
        try {
            const { result, device: updated } = await testPushDevice(device.id);
            setResults((r) => ({ ...r, [device.id]: result }));
            setDevices((list) => list?.map((d) => (d.id === updated.id ? updated : d)) ?? list);
        } catch (e: unknown) {
            const status = (e as { response?: { status?: number } })?.response?.status;
            toast.error(status === 429 ? 'Too many test notifications — wait a minute.' : 'Could not send the test notification.');
            if (status === 404) await refresh();
        } finally {
            setBusy(null);
        }
    }

    async function remove(device: PushDevice) {
        if (busy) return;
        const isCurrent = device.id === current?.id;
        if (!window.confirm(isCurrent ? 'Remove this device? It stops receiving notifications.' : `Remove “${device.label}”? It stops receiving notifications.`)) return;
        setBusy(`remove:${device.id}`);
        try {
            await removePushDevice(device.id);
            if (isCurrent) await unsubscribeLocally();
            setResults((r) => {
                const next = { ...r };
                delete next[device.id];
                return next;
            });
            toast.success('Device removed');
        } catch {
            toast.error('Could not remove the device.');
        } finally {
            await refresh();
            setBusy(null);
        }
    }

    const unavailable =
        reason === 'insecure'
            ? 'Push notifications need a secure connection (HTTPS). Open this site over https:// and try again.'
            : reason === 'unconfigured'
              ? 'Push notifications aren’t set up on the server yet. Ask an administrator to configure the VAPID keys.'
              : reason === 'unsupported'
                ? 'This browser doesn’t support push notifications. On iPhone / iPad, add Brainitive to the Home Screen (iOS 16.4+) and open it from there.'
                : null;

    return (
        <div className={className} data-testid="push-settings">
            {unavailable ? (
                <p className="text-sm text-muted-foreground">{unavailable}</p>
            ) : (
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-1">
                        <p className="text-sm font-medium">
                            {enabled
                                ? 'Browser notifications are on for this device'
                                : needsRegister
                                  ? 'This device isn’t registered'
                                  : 'Browser notifications are off'}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {needsRegister
                                ? current?.expired_at
                                    ? 'Notifications are allowed, but this browser’s subscription expired, so nothing reaches it. Register it again.'
                                    : 'Notifications are allowed, but the server has no subscription for this browser, so nothing reaches it.'
                                : 'Get a desktop notification for mentions, assignments, ticket activity, and upcoming calendar reminders — even when the app isn’t open.'}
                        </p>
                        {blocked && (
                            <p className="text-sm text-destructive">
                                Notifications are blocked for this site. Click the lock / site-info icon next to the address
                                → Notifications → Allow, then reload this page.
                            </p>
                        )}
                    </div>
                    <div className="flex shrink-0 flex-wrap gap-2">
                        {enabled && current && (
                            <Button variant="outline" onClick={() => sendTest(current)} disabled={busy !== null}>
                                {busy === `test:${current.id}` ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                                Send test notification
                            </Button>
                        )}
                        {enabled ? (
                            <Button variant="outline" onClick={turnOff} disabled={busy !== null}>
                                {busy === 'unsubscribe' ? 'Saving…' : 'Turn off'}
                            </Button>
                        ) : needsRegister ? (
                            <Button onClick={() => turnOn(true)} disabled={busy !== null}>
                                {busy === 'subscribe' ? 'Registering…' : 'Register this device'}
                            </Button>
                        ) : blocked ? null : (
                            // blocked: only the site settings can lift it, so no button (see the hint)
                            <Button onClick={() => turnOn(false)} disabled={busy !== null}>
                                {busy === 'subscribe' ? 'Saving…' : 'Turn on'}
                            </Button>
                        )}
                    </div>
                </div>
            )}

            <div className="mt-6 space-y-3">
                <div>
                    <p className="text-sm font-medium">Your devices</p>
                    <p className="text-sm text-muted-foreground">
                        Every browser that receives your notifications. Use “Send test” to check one: a device missing here
                        gets nothing — open Brainitive there and turn notifications on.
                    </p>
                </div>
                {devices === null ? (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Loader2 className="h-4 w-4 animate-spin" /> Loading devices…
                    </p>
                ) : (
                    <PushDevices
                        devices={devices}
                        currentId={current?.id ?? null}
                        results={results}
                        busy={busy}
                        onTest={sendTest}
                        onRemove={remove}
                    />
                )}
                <p className="text-xs text-muted-foreground">
                    Install Brainitive as an app to see its icon on notifications — otherwise the system shows the browser’s
                    icon.
                </p>
            </div>
        </div>
    );
}
