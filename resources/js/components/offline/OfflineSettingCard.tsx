import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { CheckCircle2, Loader2 } from 'lucide-react';
import { toast } from 'sonner';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
    AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { isSupported } from '@/lib/offline/db';
import { clearOfflineData, disableOffline, enableOffline } from '@/lib/offline/session';
import { isOfflineEnabled } from '@/lib/offline/settings';
import { cacheShell, cacheSupported, isShellCached } from '@/lib/offline/shell';
import { useOfflineState } from '@/lib/offline/store';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

type Status = 'off' | 'checking' | 'preparing' | 'ready' | 'error';

/** The service worker controls the page soon after registering; never wait forever for it. */
function workerReady(ms = 8000): Promise<boolean> {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return Promise.resolve(false);
    return Promise.race([
        navigator.serviceWorker.ready.then(() => true),
        new Promise<boolean>((resolve) => window.setTimeout(() => resolve(false), ms)),
    ]);
}

/**
 * "Work offline on this device" (BRAI-82): opt-in per device and account, off by default. Says
 * what is stored, and clears it on request (also on sign-out and when turned off).
 */
export default function OfflineSettingCard({ className = '' }: { className?: string }) {
    const user = usePage<PageProps>().props.auth.user;
    const { outbox, conflicts } = useOfflineState();
    const [enabled, setEnabled] = useState(() => isOfflineEnabled(user.id));
    const [status, setStatus] = useState<Status>(enabled ? 'checking' : 'off');
    const [error, setError] = useState<string | null>(null);
    const [confirm, setConfirm] = useState<null | 'off' | 'clear'>(null);
    const supported = isSupported() && cacheSupported() && typeof navigator !== 'undefined' && 'serviceWorker' in navigator;
    const waiting = outbox.length + conflicts.length;

    useEffect(() => {
        if (!enabled) return;
        let cancelled = false;
        void Promise.all([isShellCached(), workerReady()]).then(([cached, worker]) => {
            if (cancelled) return;
            setStatus(cached && worker ? 'ready' : 'error');
            if (!cached || !worker) setError('This device isn’t ready to open the app offline yet.');
        });
        return () => { cancelled = true; };
    }, [enabled]);

    const turnOn = async () => {
        setEnabled(true);
        setStatus('preparing');
        setError(null);
        try {
            await enableOffline(user.id);
            if (!(await workerReady())) throw new Error('The app couldn’t install its offline helper in this browser.');
            setStatus('ready');
            toast.success('This device is ready to work offline');
        } catch (e) {
            setStatus('error');
            setError(e instanceof Error ? e.message : 'Could not prepare offline mode.');
        }
    };

    const retry = async () => {
        setStatus('preparing');
        setError(null);
        try {
            await cacheShell(true);
            if (!(await workerReady())) throw new Error('The app couldn’t install its offline helper in this browser.');
            setStatus('ready');
        } catch (e) {
            setStatus('error');
            setError(e instanceof Error ? e.message : 'Could not prepare offline mode.');
        }
    };

    const turnOff = async () => {
        setConfirm(null);
        await disableOffline(user.id);
        setEnabled(false);
        setStatus('off');
        toast.success('Offline mode is off — the data on this device was deleted');
    };

    const clear = async () => {
        setConfirm(null);
        await clearOfflineData(user.id);
        toast.success('Offline data on this device was deleted');
    };

    return (
        <div className={cn('space-y-4', className)}>
            <div className="flex items-start justify-between gap-4">
                <div className="space-y-1">
                    <p id="offline-setting-label" className="text-sm font-medium">Work offline on this device</p>
                    <p className="text-sm text-muted-foreground">
                        Keep using My Day, your task lists, issues and quick capture without internet. Changes are saved on this device and
                        sent when you’re back online; if someone changed the same thing meanwhile, you decide what to keep.
                    </p>
                </div>
                <button
                    type="button"
                    role="switch"
                    aria-checked={enabled}
                    aria-labelledby="offline-setting-label"
                    // turning it off (the privacy control) is always possible
                    disabled={!enabled && (!supported || status === 'preparing')}
                    onClick={() => (enabled ? (waiting > 0 ? setConfirm('off') : void turnOff()) : void turnOn())}
                    className={cn(
                        'relative mt-0.5 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:opacity-50',
                        enabled ? 'bg-primary' : 'bg-muted-foreground/30',
                    )}
                >
                    <span className={cn('inline-block h-5 w-5 rounded-full bg-background shadow transition-transform', enabled ? 'translate-x-5' : 'translate-x-0.5')} />
                </button>
            </div>

            {!supported && <p className="text-sm text-destructive">This browser can’t keep Brainitive for offline use.</p>}

            {enabled && (
                <p className="flex items-center gap-2 text-sm" role="status">
                    {(status === 'preparing' || status === 'checking') && <><Loader2 className="h-4 w-4 animate-spin" /> Preparing this device…</>}
                    {status === 'ready' && <><CheckCircle2 className="h-4 w-4 text-brand-green" /> Ready to work offline on this device</>}
                    {status === 'error' && (
                        <>
                            <span className="text-destructive">{error}</span>
                            <Button variant="link" size="sm" className="h-auto p-0" onClick={() => void retry()}>Try again</Button>
                        </>
                    )}
                </p>
            )}

            <div className="rounded-xl bg-muted/50 p-3 text-xs leading-relaxed text-muted-foreground">
                <p className="font-medium text-foreground">What is stored on this device</p>
                <ul className="mt-1 list-disc space-y-0.5 pl-4">
                    <li>Today’s My Day, the last 20 task lists and the last 50 issues you open while online (titles, statuses, people, due dates, the latest comments).</li>
                    <li>Changes you make while offline, until they are synced.</li>
                    <li>The app itself, so it opens without internet. Documents are not included.</li>
                </ul>
                <p className="mt-1">Only for your account. Deleted when you sign out, when someone else signs in here, or when you turn this off.</p>
            </div>

            {enabled && (
                <Button variant="outline" size="sm" onClick={() => (waiting > 0 ? setConfirm('clear') : void clear())}>
                    Clear offline data
                </Button>
            )}

            <AlertDialog open={confirm !== null} onOpenChange={(o) => { if (!o) setConfirm(null); }}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{waiting === 1 ? '1 change hasn’t synced yet' : `${waiting} changes haven’t synced yet`}</AlertDialogTitle>
                        <AlertDialogDescription>
                            They exist only on this device. {confirm === 'off' ? 'Turning offline mode off' : 'Clearing the offline data'} deletes them for good.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep them</AlertDialogCancel>
                        <AlertDialogAction className="bg-destructive hover:bg-destructive/90" onClick={() => void (confirm === 'off' ? turnOff() : clear())}>
                            Delete anyway
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
