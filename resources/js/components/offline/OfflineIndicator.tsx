import { useState } from 'react';
import { AlertTriangle, Cloud, CloudOff, Loader2, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useOfflineState } from '@/lib/offline/store';
import { syncNow } from '@/lib/offline/sync';
import { cn } from '@/lib/utils';
import ConflictsPanel, { openSyncConflicts } from './ConflictsPanel';

const changes = (n: number) => (n === 1 ? '1 change' : `${n} changes`);

/**
 * Online / offline state in the top bar (BRAI-82): "Offline", "3 changes waiting to sync",
 * sync conflicts. Quiet when online with nothing pending. Also mounts the Sync conflicts panel.
 */
export default function OfflineIndicator({ className }: { className?: string }) {
    const state = useOfflineState();
    const [open, setOpen] = useState(false);
    const enabled = !!state.userId;
    const pending = state.outbox.length;
    const conflicts = state.conflicts.length;

    if (!enabled && state.online) return null;

    const label = !state.online
        ? pending > 0 ? `Offline · ${changes(pending)} waiting to sync` : 'Offline'
        : conflicts > 0 ? `${conflicts === 1 ? '1 sync conflict' : `${conflicts} sync conflicts`}`
            : pending > 0 ? `${changes(pending)} waiting to sync`
                : 'All changes synced';
    const tone = conflicts > 0
        ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300'
        : !state.online || pending > 0
            ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200'
            : 'border-transparent text-muted-foreground';
    const Icon = conflicts > 0 ? AlertTriangle : !state.online ? CloudOff : state.syncing ? Loader2 : Cloud;
    const quiet = state.online && pending === 0 && conflicts === 0;

    return (
        <>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        aria-label={label}
                        title={label}
                        className={cn('flex h-8 items-center gap-1.5 rounded-full border px-2.5 text-xs font-medium transition-colors hover:opacity-90', tone, quiet && 'px-2', className)}
                    >
                        <Icon className={cn('h-3.5 w-3.5 shrink-0', state.syncing && state.online && 'animate-spin')} aria-hidden />
                        {!quiet && <span className="hidden max-w-[16rem] truncate sm:inline">{label}</span>}
                        {!quiet && (pending > 0 || conflicts > 0) && <span className="sm:hidden">{conflicts || pending}</span>}
                    </button>
                </PopoverTrigger>
                <PopoverContent align="end" className="w-80 space-y-3 text-sm">
                    <div>
                        <p className="font-semibold">{state.online ? 'Online' : 'You’re offline'}</p>
                        <p className="mt-0.5 text-muted-foreground">
                            {!enabled
                                ? 'Turn on “Work offline on this device” in your profile to keep working without internet.'
                                : !state.online
                                    ? 'Changes to tasks, My Day, issues and captures are saved on this device and sent when you’re back online.'
                                    : state.needsSignIn
                                        ? 'Sign in again to send the changes waiting on this device.'
                                        : pending > 0 ? `${changes(pending)} waiting to sync.` : 'Everything on this device is synced.'}
                        </p>
                    </div>
                    {enabled && pending > 0 && (
                        <ul className="max-h-40 space-y-1 overflow-y-auto rounded-lg bg-muted/50 p-2 text-xs" aria-label="Waiting to sync">
                            {state.outbox.slice(0, 20).map((m) => (
                                <li key={m.id} className="truncate">{m.entity.label}</li>
                            ))}
                        </ul>
                    )}
                    <div className="flex flex-wrap gap-2">
                        {enabled && conflicts > 0 && (
                            <Button size="sm" variant="destructive" onClick={() => { setOpen(false); openSyncConflicts(); }}>
                                Review conflicts
                            </Button>
                        )}
                        {enabled && state.online && pending > 0 && (
                            <Button size="sm" variant="outline" disabled={state.syncing} onClick={() => void syncNow()}>
                                <RefreshCw className="h-3.5 w-3.5" /> Sync now
                            </Button>
                        )}
                        {enabled && !state.online && (
                            <Button size="sm" variant="outline" onClick={() => window.location.reload()}>
                                Open offline view
                            </Button>
                        )}
                    </div>
                </PopoverContent>
            </Popover>
            {enabled && <ConflictsPanel />}
        </>
    );
}
