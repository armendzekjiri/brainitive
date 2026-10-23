import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { Copy, Loader2 } from 'lucide-react';
import { useState } from 'react';

/**
 * Duplicate an issue. With subtasks, asks whether to copy them too. Comments,
 * attachments, watchers, work logs and history are never copied.
 */
export default function DuplicateItemDialog({
    open,
    onOpenChange,
    url,
    itemKey,
    subtaskCount,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** route('boards.items.duplicate', …) */
    url: string;
    itemKey: string;
    subtaskCount: number;
}) {
    const [withSubtasks, setWithSubtasks] = useState(true);
    const [busy, setBusy] = useState(false);

    const duplicate = () =>
        router.post(
            url,
            { with_subitems: subtaskCount > 0 && withSubtasks },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    onOpenChange(false);
                },
            },
        );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md" onClick={(e) => e.stopPropagation()}>
                <DialogHeader>
                    <DialogTitle>Duplicate {itemKey}</DialogTitle>
                    <DialogDescription>
                        Creates “Copy of …” in the same column with the same description, type, priority, assignee, dates, estimate and labels. Comments,
                        attachments, watchers and history aren’t copied.
                    </DialogDescription>
                </DialogHeader>
                {subtaskCount > 0 && (
                    <div className="space-y-2">
                        <label className="flex items-start gap-2 rounded-lg border p-3 text-sm cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                            <input type="radio" name="subtasks" className="mt-0.5 accent-primary" checked={withSubtasks} onChange={() => setWithSubtasks(true)} />
                            <span>
                                <span className="font-medium">With its {subtaskCount} subtask{subtaskCount === 1 ? '' : 's'}</span>
                                <span className="block text-muted-foreground">Each subtask is copied under the new issue.</span>
                            </span>
                        </label>
                        <label className="flex items-start gap-2 rounded-lg border p-3 text-sm cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                            <input type="radio" name="subtasks" className="mt-0.5 accent-primary" checked={!withSubtasks} onChange={() => setWithSubtasks(false)} />
                            <span>
                                <span className="font-medium">Only this issue</span>
                                <span className="block text-muted-foreground">The copy starts without subtasks.</span>
                            </span>
                        </label>
                    </div>
                )}
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>
                        Cancel
                    </Button>
                    <Button onClick={duplicate} disabled={busy}>
                        {busy ? <Loader2 className="size-4 animate-spin" /> : <Copy className="size-4" />}
                        Duplicate
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
