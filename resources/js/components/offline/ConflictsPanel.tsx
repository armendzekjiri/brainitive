import { useEffect, useState } from 'react';
import { AlertTriangle, Copy, RotateCcw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { canKeepBoth, canRecreate, discard, FIELD_LABELS, keepBoth, keepMine, keepTheirs, recreate } from '@/lib/offline/resolve';
import { getSnapshot, onSnapshotChange, useOfflineState } from '@/lib/offline/store';
import type { OfflineConflict, OfflineIssueSnapshot } from '@/types';

const OPEN_EVENT = 'offline:open-conflicts';

/** Open the Sync conflicts panel from anywhere (indicator, offline views). */
export function openSyncConflicts(): void {
    window.dispatchEvent(new CustomEvent(OPEN_EVENT));
}

const STATUS_TITLES: Record<OfflineConflict['status'], string> = {
    conflict: 'Changed by someone else',
    deleted: 'Deleted meanwhile',
    forbidden: 'Not allowed any more',
    invalid: 'Couldn’t be saved',
};

/**
 * Sync conflicts (BRAI-82): every offline change the server didn't take as is, with an explicit
 * choice per change — never resolved silently. Mounted once by the offline indicator.
 */
export default function ConflictsPanel() {
    const [open, setOpen] = useState(false);
    const { conflicts } = useOfflineState();

    useEffect(() => {
        const onOpen = () => setOpen(true);
        window.addEventListener(OPEN_EVENT, onOpen);
        return () => window.removeEventListener(OPEN_EVENT, onOpen);
    }, []);

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetContent side="right" className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>Sync conflicts</SheetTitle>
                    <SheetDescription>
                        These changes from this device weren’t saved as they are. Nothing is overwritten until you choose.
                    </SheetDescription>
                </SheetHeader>
                <div className="mt-6 space-y-4">
                    {conflicts.length === 0 && (
                        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">No conflicts — everything is in sync.</p>
                    )}
                    {conflicts.map((c) => <ConflictCard key={c.id} conflict={c} />)}
                </div>
            </SheetContent>
        </Sheet>
    );
}

function ConflictCard({ conflict: c }: { conflict: OfflineConflict }) {
    const [busy, setBusy] = useState(false);
    const issue = useIssueSnapshot(c);
    const fields = Object.entries(c.fields ?? {});

    const run = (action: (c: OfflineConflict) => Promise<void>, done: string) => async () => {
        setBusy(true);
        try {
            await action(c);
            toast.success(done);
        } catch {
            toast.error('Could not save your choice on this device.');
            setBusy(false);
        }
    };

    return (
        <article aria-label={`Conflict: ${c.mutation.entity.label}`} className="rounded-2xl border bg-card p-4 shadow-sm">
            <div className="flex items-start gap-3">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" aria-hidden />
                <div className="min-w-0 flex-1">
                    <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{STATUS_TITLES[c.status]}</p>
                    <h3 className="mt-0.5 break-words text-sm font-semibold">
                        {c.mutation.entity.url ? <a href={c.mutation.entity.url} className="hover:underline">{c.mutation.entity.label}</a> : c.mutation.entity.label}
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">{c.message}</p>
                </div>
            </div>

            {fields.length > 0 && (
                <table className="mt-3 w-full table-fixed text-sm">
                    <thead>
                        <tr className="text-left text-xs text-muted-foreground">
                            <th className="w-1/4 pb-1 font-medium">Field</th>
                            <th className="pb-1 font-medium">Mine</th>
                            <th className="pb-1 font-medium">Theirs</th>
                        </tr>
                    </thead>
                    <tbody>
                        {fields.map(([field, v]) => (
                            <tr key={field} className="border-t align-top">
                                <th scope="row" className="py-1.5 pr-2 text-left font-medium">{FIELD_LABELS[field] ?? field}</th>
                                <td className="break-words py-1.5 pr-2 text-primary">{display(field, v.mine, issue, c)}</td>
                                <td className="break-words py-1.5">{display(field, v.theirs, issue, c)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            <div className="mt-4 flex flex-wrap gap-2">
                {c.status === 'conflict' && (
                    <>
                        <Button size="sm" disabled={busy} onClick={run(keepMine, 'Keeping your version')}>Keep mine</Button>
                        <Button size="sm" variant="outline" disabled={busy} onClick={run(keepTheirs, 'Kept their version')}>Keep theirs</Button>
                        {canKeepBoth(c) && (
                            <Button size="sm" variant="outline" disabled={busy} onClick={run(keepBoth, 'Kept theirs, yours is added as a comment')}>
                                <Copy className="h-3.5 w-3.5" /> Keep both
                            </Button>
                        )}
                    </>
                )}
                {canRecreate(c) && (
                    <Button size="sm" disabled={busy} onClick={run(recreate, 'Recreating it')}>
                        <RotateCcw className="h-3.5 w-3.5" /> Recreate
                    </Button>
                )}
                {c.status !== 'conflict' && (
                    <Button size="sm" variant="outline" disabled={busy} onClick={run(discard, 'Change discarded')}>
                        <Trash2 className="h-3.5 w-3.5" /> Discard my change
                    </Button>
                )}
            </div>
        </article>
    );
}

/** The issue's offline copy: column / priority / people names for the comparison. */
function useIssueSnapshot(c: OfflineConflict): OfflineIssueSnapshot | null {
    const id = c.mutation.type.startsWith('issue.') ? Number(c.entity?.id) || c.mutation.target?.id : null;
    const [issue, setIssue] = useState<OfflineIssueSnapshot | null>(null);
    useEffect(() => {
        if (!id) return;
        const load = () => void getSnapshot<OfflineIssueSnapshot>(`issue:${id}`).then(setIssue);
        load();
        return onSnapshotChange((key) => {
            if (key === `issue:${id}`) load();
        });
    }, [id]);
    return issue;
}

function display(field: string, value: unknown, issue: OfflineIssueSnapshot | null, c: OfflineConflict): string {
    if (value === null || value === undefined || value === '') return '—';
    if (field === 'status' && c.mutation.type.startsWith('list_task')) return value === 'done' ? 'Done' : 'To do';
    if (field === 'column_id') return issue?.columns.find((x) => x.id === value)?.name ?? `Column ${String(value)}`;
    if (field === 'priority_id') return issue?.priorities.find((x) => x.id === value)?.name ?? `Priority ${String(value)}`;
    if (field === 'assigned_to') return issue?.members.find((x) => x.id === value)?.name ?? 'Someone';
    if (field === 'due_date') {
        const d = new Date(`${String(value)}T00:00:00`);
        return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    }
    return String(value);
}
