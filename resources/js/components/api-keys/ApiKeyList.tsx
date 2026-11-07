import { router } from '@inertiajs/react';
import { useState } from 'react';
import { KeyRound, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { ApiKeyArea, ApiKeyRow, PageProps } from '@/types';
import { ago, expirySummary, whatSummary, whereSummary } from './format';

const STATUS: Record<ApiKeyRow['status'], { label: string; className: string }> = {
    active: { label: 'Active', className: 'border-transparent bg-primary/10 text-primary' },
    expired: { label: 'Expired', className: 'border-transparent bg-muted text-muted-foreground' },
    revoked: { label: 'Revoked', className: 'border-transparent bg-destructive/10 text-destructive' },
};

type Pending = { action: 'revoke' | 'regenerate'; key: ApiKeyRow } | null;

/** The user's API keys: active first, then the ones revoked / expired in the last 30 days. */
export function ApiKeyList({ apiKeys, areas }: { apiKeys: ApiKeyRow[]; areas: ApiKeyArea[] }) {
    // kept after closing so the dialog text doesn't change while it animates out
    const [pending, setPending] = useState<Pending>(null);
    const [open, setOpen] = useState(false);
    const ask = (action: 'revoke' | 'regenerate', key: ApiKeyRow) => {
        setPending({ action, key });
        setOpen(true);
    };
    const [busy, setBusy] = useState<number | null>(null);

    const confirm = () => {
        if (!pending) return;
        const { action, key } = pending;
        setBusy(key.id);
        const options = {
            preserveScroll: true,
            onError: () => toast.error(action === 'revoke' ? 'Could not revoke the key.' : 'Could not regenerate the key.'),
            onFinish: () => setBusy(null),
        };
        if (action === 'revoke') {
            router.delete(route('profile.api-keys.destroy', key.id), {
                ...options,
                onSuccess: (page) => toast.success((page.props as PageProps).flash?.success ?? 'API key revoked.'),
            });
        } else {
            router.post(route('profile.api-keys.regenerate', key.id), {}, options);
        }
        setOpen(false);
    };

    if (apiKeys.length === 0) {
        return (
            <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed px-6 py-10 text-center">
                <KeyRound className="size-6 text-muted-foreground" aria-hidden />
                <p className="text-sm font-medium">No API keys yet</p>
                <p className="text-sm text-muted-foreground">Create one to call the Brainitive API from your scripts and apps.</p>
            </div>
        );
    }

    return (
        <>
            <ul className="divide-y rounded-lg border" aria-label="API keys">
                {apiKeys.map((key) => {
                    const status = STATUS[key.status];
                    const active = key.status === 'active';
                    return (
                        <li key={key.id} data-testid="api-key-row" className={cn('flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-start', !active && 'opacity-75')}>
                            <span className="hidden size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary sm:flex">
                                <KeyRound className="size-4" aria-hidden />
                            </span>
                            <div className="min-w-0 flex-1 space-y-1">
                                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <p className="min-w-0 break-words text-sm font-medium">{key.name}</p>
                                    <Badge variant="outline" className={status.className}>
                                        {status.label}
                                    </Badge>
                                </div>
                                <p className="font-mono text-xs text-muted-foreground">{key.prefix}…</p>
                                <p className="break-words text-xs">
                                    <span className="text-muted-foreground">Where: </span>
                                    {whereSummary(key)}
                                </p>
                                <p className="break-words text-xs">
                                    <span className="text-muted-foreground">What: </span>
                                    {whatSummary(key, areas)}
                                </p>
                                <p className="break-words text-xs text-muted-foreground">
                                    {[
                                        key.created_at ? `Created ${ago(key.created_at)}` : null,
                                        key.last_used_at ? `Last used ${ago(key.last_used_at)}${key.last_used_ip ? ` from ${key.last_used_ip}` : ''}` : 'Never used',
                                        expirySummary(key),
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                            </div>
                            {active && (
                                <div className="flex shrink-0 gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={busy !== null}
                                        onClick={() => ask('regenerate', key)}
                                        aria-label={`Regenerate ${key.name}`}
                                    >
                                        <RefreshCw className="size-4" aria-hidden />
                                        Regenerate
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="text-destructive hover:text-destructive"
                                        disabled={busy !== null}
                                        onClick={() => ask('revoke', key)}
                                        aria-label={`Revoke ${key.name}`}
                                    >
                                        <Trash2 className="size-4" aria-hidden />
                                        Revoke
                                    </Button>
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>

            <AlertDialog open={open} onOpenChange={setOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle className="break-words">
                            {pending?.action === 'revoke' ? `Revoke ${pending.key.name}?` : `Regenerate ${pending?.key.name ?? 'key'}?`}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {pending?.action === 'revoke'
                                ? 'Scripts using it stop working right away. This cannot be undone.'
                                : 'The current key stops working right away. You get a new secret with the same name and permissions.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={confirm}
                            className={pending?.action === 'revoke' ? 'bg-destructive text-destructive-foreground hover:bg-destructive/90' : undefined}
                        >
                            {pending?.action === 'revoke' ? 'Revoke key' : 'Regenerate key'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
