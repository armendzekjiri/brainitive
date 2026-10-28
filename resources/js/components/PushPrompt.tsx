import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Bell, X } from 'lucide-react';
import { PageProps } from '@/types';
import { isPushSupported, isSubscribed, pushPermission, subscribeToPush } from '@/lib/push';

const DISMISS_KEY = 'push-prompt-dismissed';

/**
 * A one-time, non-intrusive prompt shown shortly after login inviting the user
 * to enable browser notifications. It only appears when push is supported, the
 * user hasn't been asked yet (permission 'default'), isn't already subscribed,
 * and hasn't previously dismissed it. Users can always toggle later in Profile.
 */
export function PushPrompt() {
    const { props } = usePage<PageProps>();
    const vapidPublicKey = props.push?.vapidPublicKey ?? null;
    const user = props.auth?.user;

    const [show, setShow] = useState(false);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!user || !vapidPublicKey || !isPushSupported()) return;
        if (pushPermission() !== 'default') return; // granted or denied → don't nag
        if (localStorage.getItem(DISMISS_KEY) === '1') return;

        let cancelled = false;
        isSubscribed().then((subbed) => {
            if (cancelled || subbed) return;
            // Small delay so it doesn't slam in during the initial page paint.
            const t = setTimeout(() => setShow(true), 2500);
            return () => clearTimeout(t);
        });
        return () => {
            cancelled = true;
        };
    }, [user, vapidPublicKey]);

    function dismiss() {
        localStorage.setItem(DISMISS_KEY, '1');
        setShow(false);
    }

    async function enable() {
        if (!vapidPublicKey) return;
        setBusy(true);
        try {
            const ok = await subscribeToPush(vapidPublicKey);
            if (ok) {
                toast.success('Browser notifications enabled');
                setShow(false);
            } else {
                toast.error('Could not enable notifications. You can try later in Profile.');
            }
        } finally {
            setBusy(false);
            localStorage.setItem(DISMISS_KEY, '1');
        }
    }

    if (!show) return null;

    return (
        <div className="fixed bottom-[calc(1rem+env(safe-area-inset-bottom))] right-4 z-50 w-[min(340px,calc(100vw-2rem))] rounded-lg border bg-background p-4 shadow-lg">
            <button
                onClick={dismiss}
                className="absolute right-2 top-2 rounded-sm p-1 text-muted-foreground hover:text-foreground"
                aria-label="Dismiss"
            >
                <X className="h-4 w-4" />
            </button>
            <div className="flex gap-3">
                <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <Bell className="h-5 w-5" />
                </div>
                <div className="space-y-2 pr-4">
                    <p className="text-sm font-medium">Turn on notifications?</p>
                    <p className="text-sm text-muted-foreground">
                        Get alerts for mentions, assignments, and calendar reminders — even when the
                        app isn’t open.
                    </p>
                    <div className="flex gap-2 pt-1">
                        <Button size="sm" onClick={enable} disabled={busy}>
                            {busy ? 'Enabling…' : 'Enable'}
                        </Button>
                        <Button size="sm" variant="ghost" onClick={dismiss}>
                            Not now
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
