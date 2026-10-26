import { Check, Sun, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { myDayKey, removeFromMyDay, toggleMyDayDone, MY_DAY_KEY } from '@/lib/offline/actions';
import { pendingKeys, useOfflineState } from '@/lib/offline/store';
import { cn } from '@/lib/utils';
import type { OfflineMyDayRow, OfflineMyDaySnapshot } from '@/types';
import NotSyncedBadge from './NotSyncedBadge';
import { useSnapshot } from './useSnapshot';

/** Today's plan from this device (BRAI-82): complete / reopen, take off the plan. */
export default function OfflineMyDayView() {
    const { data: day, loading } = useSnapshot<OfflineMyDaySnapshot>(MY_DAY_KEY);
    const state = useOfflineState();
    const pending = pendingKeys(state);
    const rows = [...(day?.rows ?? [])].sort((a, b) => Number(a.done) - Number(b.done));
    const today = new Date().toLocaleDateString('en-CA');

    const isPending = (row: OfflineMyDayRow) =>
        pending.has(myDayKey(row))
        || (row.work_type === 'board' && !!row.work_id && pending.has(`issue:${row.work_id}`))
        || (row.work_type === 'list' && pending.has(row.work_id ? `list_task:${row.work_id}` : `list_task:ref:${row.work_ref}`));

    return (
        <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6">
            <h1 className="flex items-center gap-2.5 text-2xl font-bold tracking-tight">
                <Sun className="size-6 text-amber-500" /> My Day
            </h1>
            {day && day.date !== today && (
                <p className="mt-1 text-sm text-muted-foreground">Your plan as saved on {new Date(`${day.date}T00:00:00`).toLocaleDateString()}.</p>
            )}

            <section aria-label="My Day" className="mt-6">
                {!loading && rows.length === 0 && (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Nothing planned on this device. Open My Work while online, or add tasks from a list or issue with the ☀ button.
                    </p>
                )}
                <ul className="divide-y divide-border/60 rounded-2xl border bg-card">
                    {rows.map((row) => (
                        <li key={myDayKey(row)} className="flex items-center gap-3 px-3 py-2.5">
                            <button
                                type="button"
                                role="checkbox"
                                aria-checked={row.done}
                                aria-label={row.done ? `Reopen “${row.title}”` : `Mark “${row.title}” as done`}
                                disabled={row.work_type === 'board' && !(row.done ? row.open_column_id : row.done_column_id)}
                                onClick={() => void toggleMyDayDone(row)}
                                className={cn(
                                    'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors disabled:opacity-50',
                                    row.done ? 'border-brand-green bg-brand-green text-white' : 'border-muted-foreground/40 hover:border-primary',
                                )}
                            >
                                {row.done && <Check className="h-3 w-3" />}
                            </button>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    {row.url ? (
                                        <a href={row.url} className={cn('min-w-0 break-words text-sm font-medium hover:underline', row.done && 'text-muted-foreground line-through')}>{row.title}</a>
                                    ) : (
                                        <span className={cn('min-w-0 break-words text-sm font-medium', row.done && 'text-muted-foreground line-through')}>{row.title}</span>
                                    )}
                                    {isPending(row) && <NotSyncedBadge />}
                                </div>
                                <p className="truncate text-xs text-muted-foreground">{[row.key, row.project_name].filter(Boolean).join(' · ')}</p>
                            </div>
                            <Button variant="ghost" size="icon" className="size-8 shrink-0" aria-label={`Remove “${row.title}” from My Day`} onClick={() => void removeFromMyDay(row)}>
                                <X className="size-4" />
                            </Button>
                        </li>
                    ))}
                </ul>
            </section>
        </div>
    );
}
