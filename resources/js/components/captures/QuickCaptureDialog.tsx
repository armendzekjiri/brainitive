import { FormEvent, KeyboardEvent as ReactKeyboardEvent, useEffect, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Lightbulb, Loader2 } from 'lucide-react';
import { toast } from 'sonner';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { CAPTURE_TYPES, isCaptureShortcut } from '@/lib/captures';
import { captureOffline } from '@/lib/offline/actions';
import { getOfflineState } from '@/lib/offline/store';
import type { CaptureType } from '@/types';

const OPEN_EVENT = 'quick-capture:open';

/** Open the global capture dialog from anywhere (top bar, command palette, mobile button). */
export function openQuickCapture(): void {
    window.dispatchEvent(new CustomEvent(OPEN_EVENT));
}

type FormData = { text: string; url: string; intended_type: CaptureType | ''; source: string };

/**
 * Quick capture (BRAI-70): jot an idea down without choosing a project. Mounted once in
 * AppLayout; opens on `openQuickCapture()` and on the "C" shortcut (never while typing).
 * Everything lands in the user's private inbox (`/captures`).
 */
export function QuickCaptureDialog() {
    const [open, setOpen] = useState(false);
    const form = useForm<FormData>({ text: '', url: '', intended_type: '', source: '' });

    useEffect(() => {
        const onOpen = () => setOpen(true);
        const onKeyDown = (e: KeyboardEvent) => {
            if (!isCaptureShortcut(e)) return;
            e.preventDefault();
            setOpen(true);
        };
        window.addEventListener(OPEN_EVENT, onOpen);
        window.addEventListener('keydown', onKeyDown);
        return () => {
            window.removeEventListener(OPEN_EVENT, onOpen);
            window.removeEventListener('keydown', onKeyDown);
        };
    }, []);

    const close = (next: boolean) => {
        setOpen(next);
        if (!next) form.clearErrors();
    };

    const submit = (e?: FormEvent) => {
        e?.preventDefault();
        if (form.processing) return;
        // Offline mode (BRAI-82): no connection → saved on this device, synced later.
        if (!navigator.onLine && getOfflineState().userId) {
            void captureOffline(form.data).then((saved) => {
                if (!saved) return toast.error('Could not save the capture on this device.');
                form.reset();
                setOpen(false);
                toast.success('Saved on this device — it syncs when you’re back online');
            });
            return;
        }
        form.post(route('captures.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
                toast.success('Saved to your captures', {
                    action: { label: 'Open', onClick: () => router.visit(route('captures.index')) },
                });
            },
            onError: () => toast.error('Could not save the capture.'),
        });
    };

    const onTextKeyDown = (e: ReactKeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
            e.preventDefault();
            submit();
        }
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Lightbulb className="h-5 w-5 text-amber-500" />
                        Quick capture
                    </DialogTitle>
                    <DialogDescription>
                        Saved to your private captures. Only you can see them; organize them into a board card, task, doc, bookmark or
                        snippet later.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="quick-capture-text">Idea</Label>
                        <Textarea
                            id="quick-capture-text"
                            autoFocus
                            rows={5}
                            value={form.data.text}
                            onChange={(e) => form.setData('text', e.target.value)}
                            onKeyDown={onTextKeyDown}
                            placeholder="What's on your mind? Markdown and ``` code blocks are kept."
                        />
                        {form.errors.text && <p className="text-sm text-destructive">{form.errors.text}</p>}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="quick-capture-url">Link (optional)</Label>
                            <Input
                                id="quick-capture-url"
                                type="url"
                                inputMode="url"
                                value={form.data.url}
                                onChange={(e) => form.setData('url', e.target.value)}
                                placeholder="https://…"
                            />
                            {form.errors.url && <p className="text-sm text-destructive">{form.errors.url}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="quick-capture-type">Organize as</Label>
                            <Select
                                value={form.data.intended_type || 'none'}
                                onValueChange={(v) => form.setData('intended_type', v === 'none' ? '' : (v as CaptureType))}
                            >
                                <SelectTrigger id="quick-capture-type" aria-label="Organize as">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Not sure yet</SelectItem>
                                    {CAPTURE_TYPES.map((t) => (
                                        <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="quick-capture-source">Source (optional)</Label>
                        <Input
                            id="quick-capture-source"
                            value={form.data.source}
                            onChange={(e) => form.setData('source', e.target.value)}
                            placeholder="e.g. Call with Ana, a Slack thread"
                        />
                        {form.errors.source && <p className="text-sm text-destructive">{form.errors.source}</p>}
                    </div>

                    <DialogFooter className="gap-2 sm:gap-0">
                        <span className="mr-auto hidden self-center text-xs text-muted-foreground sm:inline">⌘/Ctrl + Enter to save</span>
                        <Button type="button" variant="outline" onClick={() => close(false)}>Cancel</Button>
                        <Button type="submit" disabled={form.processing || form.data.text.trim() === ''}>
                            {form.processing && <Loader2 className="h-4 w-4 animate-spin" />}
                            Save capture
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Round "capture" button for small screens (the top-bar button is hidden there). */
export function QuickCaptureFab() {
    return (
        <button
            type="button"
            onClick={openQuickCapture}
            aria-label="Quick capture"
            title="Quick capture"
            className="fixed bottom-20 right-4 z-40 flex h-12 w-12 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg shadow-primary/30 transition hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 md:hidden"
        >
            <Lightbulb className="h-6 w-6" />
        </button>
    );
}
