import { cn } from '@/lib/utils';
import type { MyWorkItem } from '@/types';
import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

/**
 * The circle in front of a task in My Work and My Day: click to complete it, click again to reopen.
 * Board issues move to the board's first done column (or back to its first open column) through the
 * normal move endpoint, so history and watcher notifications behave as on the board; list tasks
 * toggle their status (a recurring task rolls on to its next date).
 */
export default function WorkDoneToggle({ item, workspaceSlug, projectSlug, className }: { item: MyWorkItem; workspaceSlug: string; projectSlug: string; className?: string }) {
    const serverDone = !!item.completed_at || item.status === 'done';
    const [done, setDone] = useState(serverDone);
    const [busy, setBusy] = useState(false);
    useEffect(() => setDone(serverDone), [serverDone]);

    const targetColumn = item.work_type === 'board' ? (done ? item.board?.open_column_id : item.board?.done_column_id) : null;
    const unavailable = item.work_type === 'board' && !targetColumn;
    const label = done ? `Reopen “${item.title}”` : `Mark “${item.title}” as done`;

    const toggle = () => {
        if (busy || unavailable) return;
        const next = !done;
        const options = {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                setBusy(true);
                setDone(next);
            },
            onSuccess: () => toast.success(next ? `${item.key} done.` : `${item.key} reopened.`),
            onError: () => {
                setDone(!next);
                toast.error(next ? 'Could not complete it.' : 'Could not reopen it.');
            },
            onFinish: () => setBusy(false),
        };
        if (item.work_type === 'board') {
            router.patch(route('boards.items.move', [workspaceSlug, projectSlug, item.source.slug, item.id]), { column_id: targetColumn, position: 0 }, options);
        } else {
            router.patch(route('task-lists.items.update', [workspaceSlug, projectSlug, item.source.slug, item.id]), { status: next ? 'done' : 'todo' }, options);
        }
    };

    return (
        <button
            type="button"
            onClick={toggle}
            disabled={busy || unavailable}
            role="checkbox"
            aria-checked={done}
            aria-label={label}
            title={unavailable ? 'This board has no done column' : done ? 'Reopen' : 'Mark as done'}
            className={cn(
                'grid size-[18px] shrink-0 place-items-center rounded-full border transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed',
                done ? 'border-brand-green bg-brand-green text-white' : 'border-muted-foreground/40 bg-card text-transparent hover:border-brand-green hover:text-brand-green/60',
                unavailable && 'opacity-40',
                className,
            )}
        >
            <Check className="size-3" strokeWidth={3} />
        </button>
    );
}
