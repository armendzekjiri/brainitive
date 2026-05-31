import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { PageProps } from '@/types';
import {
    isPushSupported,
    isSubscribed,
    pushPermission,
    subscribeToPush,
    unsubscribeFromPush,
} from '@/lib/push';

export default function NotificationSettingsForm({ className = '' }: { className?: string }) {
    const { props } = usePage<PageProps>();
    const vapidPublicKey = props.push?.vapidPublicKey ?? null;

    // 'ok' once we can actually offer the toggle; otherwise an explanation.
    type Reason = 'ok' | 'insecure' | 'unsupported' | 'unconfigured';
    const [reason, setReason] = useState<Reason>('ok');
    const [enabled, setEnabled] = useState(false);
    const [busy, setBusy] = useState(false);
    const [blocked, setBlocked] = useState(false);

    useEffect(() => {
        // Service workers + Push require a secure context (HTTPS, or localhost).
        if (typeof window !== 'undefined' && window.isSecureContext === false) {
            setReason('insecure');
            return;
        }
        if (!isPushSupported()) {
            setReason('unsupported');
            return;
        }
        if (!vapidPublicKey) {
            // Browser is fine; the server just hasn't been given VAPID keys.
            setReason('unconfigured');
            return;
        }
        setReason('ok');
        setBlocked(pushPermission() === 'denied');
        isSubscribed().then(setEnabled);
    }, [vapidPublicKey]);

    async function toggle() {
        if (busy) return;
        setBusy(true);
        try {
            if (enabled) {
                await unsubscribeFromPush();
                setEnabled(false);
                toast.success('Browser notifications turned off');
            } else {
                const ok = await subscribeToPush(vapidPublicKey!);
                if (ok) {
                    setEnabled(true);
                    toast.success('Browser notifications turned on');
                } else {
                    setBlocked(pushPermission() === 'denied');
                    toast.error('Could not enable notifications. Check your browser permissions.');
                }
            }
        } catch {
            toast.error('Something went wrong updating notifications.');
        } finally {
            setBusy(false);
        }
    }

    if (reason !== 'ok') {
        const message =
            reason === 'insecure'
                ? 'Push notifications need a secure connection (HTTPS). Open this site over https:// and try again.'
                : reason === 'unconfigured'
                  ? 'Push notifications aren’t set up on the server yet. Ask an administrator to configure the VAPID keys.'
                  : 'Your browser doesn’t support push notifications.';
        return (
            <div className={className}>
                <p className="text-sm text-muted-foreground">{message}</p>
            </div>
        );
    }

    return (
        <div className={className}>
            <div className="flex items-center justify-between gap-4">
                <div className="space-y-1">
                    <p className="text-sm font-medium">
                        {enabled ? 'Browser notifications are on' : 'Browser notifications are off'}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        Get a desktop notification for mentions, assignments, ticket activity, and
                        upcoming calendar reminders — even when the app isn’t open.
                    </p>
                    {blocked && (
                        <p className="text-sm text-destructive">
                            Notifications are blocked in your browser settings. Allow them for this
                            site, then try again.
                        </p>
                    )}
                </div>
                <Button onClick={toggle} disabled={busy} variant={enabled ? 'outline' : 'default'}>
                    {busy ? 'Saving…' : enabled ? 'Turn off' : 'Turn on'}
                </Button>
            </div>
        </div>
    );
}
