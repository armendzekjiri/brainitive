import { useState } from 'react';
import { Check, Copy, Globe, Loader2, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    shareUrl: string | null;
    canManage: boolean;
    shareRoute: string;
    onChange: (url: string | null) => void;
}

/**
 * "Publish to web": a read-only link anyone can open without an account.
 */
export function ShareDialog({ open, onOpenChange, shareUrl, canManage, shareRoute, onChange }: Props) {
    const [busy, setBusy] = useState(false);
    const [copied, setCopied] = useState(false);

    const call = async (method: 'post' | 'delete') => {
        setBusy(true);
        try {
            const { data } = await window.axios[method](shareRoute);
            onChange(data.share_url);
        } finally {
            setBusy(false);
        }
    };

    const copy = async () => {
        if (!shareUrl) return;
        await navigator.clipboard?.writeText(shareUrl).catch(() => {});
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2"><Globe className="size-4" />Share a read-only link</DialogTitle>
                    <DialogDescription>
                        Anyone with the link can view this plan — no account needed. They can zoom and filter, but not edit. Project members always see it in Brainitive.
                    </DialogDescription>
                </DialogHeader>
                {shareUrl ? (
                    <div className="mt-2 space-y-4">
                        <div className="flex gap-2">
                            <Input readOnly value={shareUrl} onFocus={(e) => e.target.select()} aria-label="Share link" />
                            <Button type="button" variant="outline" onClick={copy} className="shrink-0 gap-1.5">
                                {copied ? <Check className="size-4" /> : <Copy className="size-4" />}{copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                        {canManage && (
                            <div className="flex flex-wrap justify-between gap-2">
                                <Button type="button" variant="ghost" size="sm" disabled={busy} onClick={() => call('post')} className="gap-1.5 text-muted-foreground">
                                    <RefreshCw className="size-3.5" />New link (old one stops working)
                                </Button>
                                <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => call('delete')} className="text-destructive hover:text-destructive">
                                    Turn off link
                                </Button>
                            </div>
                        )}
                    </div>
                ) : canManage ? (
                    <div className="mt-2 flex justify-end">
                        <Button type="button" disabled={busy} onClick={() => call('post')} className="gap-1.5">
                            {busy ? <Loader2 className="size-4 animate-spin" /> : <Globe className="size-4" />}Create link
                        </Button>
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">Sharing is off. Ask an editor of this project to create a link.</p>
                )}
            </DialogContent>
        </Dialog>
    );
}
