import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { ArrowUpRight, Gauge } from 'lucide-react';
import type { LimitReachedPayload, PageProps } from '@/types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { KIND_NOUNS, formatAmount, onLimitReached } from '@/lib/entitlements';

/**
 * "Upgrade to continue" (Cloud plans, v1.46.0), mounted once in AppLayout. Opens when a plan limit
 * stopped an action: `flash.limit_reached` after an Inertia visit, or a 402 on an axios request
 * (the interceptor in bootstrap.ts → showLimitReached()).
 */
export function UpgradeDialog() {
    const { flash, entitlements } = usePage<PageProps>().props;
    const [payload, setPayload] = useState<LimitReachedPayload | null>(null);
    const [open, setOpen] = useState(false);

    useEffect(
        () =>
            onLimitReached((next) => {
                setPayload(next);
                setOpen(true);
            }),
        [],
    );

    // a new object per response: the same limit hit twice opens the dialog twice
    const flashed = flash?.limit_reached ?? null;
    useEffect(() => {
        if (!flashed) return;
        setPayload(flashed);
        setOpen(true);
    }, [flashed]);

    if (!payload) return null;

    const billingUrl = payload.billingUrl ?? entitlements?.billingUrl ?? null;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent className="w-[calc(100vw-2rem)] max-w-md rounded-lg">
                <DialogHeader className="text-left">
                    <div className="mb-1 flex size-10 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <Gauge className="size-5" />
                    </div>
                    <DialogTitle>Upgrade to continue</DialogTitle>
                    <DialogDescription>{payload.message}</DialogDescription>
                </DialogHeader>

                <div className="flex items-center justify-between gap-3 rounded-lg border bg-muted/40 px-4 py-3 text-sm">
                    <span className="font-medium" data-testid="limit-usage">
                        {formatAmount(payload.kind, payload.usage)} of {formatAmount(payload.kind, payload.limit)} {KIND_NOUNS[payload.kind]}
                    </span>
                    <span className="shrink-0 rounded-full bg-background px-2 py-0.5 text-xs font-medium text-muted-foreground ring-1 ring-border">
                        {payload.plan} plan
                    </span>
                </div>

                <DialogFooter className="gap-2 sm:gap-0">
                    <Button variant="outline" onClick={() => setOpen(false)}>
                        Not now
                    </Button>
                    {billingUrl && (
                        <Button asChild>
                            <a href={billingUrl}>
                                See plans &amp; upgrade
                                <ArrowUpRight className="size-4" />
                            </a>
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
