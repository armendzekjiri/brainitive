import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { FLAG_LABELS, formatHours } from '@/lib/workload';
import type { WorkloadReviewEntry } from '@/types';
import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import { workloadWrite } from './workloadActions';

interface Props {
    review: WorkloadReviewEntry[];
    canPlan: boolean;
    urls: { allocation: string; destroy: (id: number) => string };
}

/**
 * Inconsistencies after the source issues changed. Nothing here is applied automatically: the
 * planner keeps the planned hours (records the new estimate) or removes them.
 */
export function WorkloadReview({ review, canPlan, urls }: Props) {
    const [busy, setBusy] = useState(false);
    if (review.length === 0) return null;

    const run = (method: 'put' | 'delete', url: string, data: Record<string, unknown> = {}) => {
        setBusy(true);
        workloadWrite(method, url, data, { onFinish: () => setBusy(false) });
    };

    return (
        <section aria-labelledby="workload-review" className="rounded-lg border border-amber-300/60 bg-amber-50/60 p-4 dark:border-amber-500/30 dark:bg-amber-950/20">
            <h2 id="workload-review" className="mb-3 flex items-center gap-2 text-sm font-semibold">
                <AlertTriangle className="size-4 text-amber-600 dark:text-amber-400" /> Needs review ({review.length})
            </h2>
            <ul className="space-y-2">
                {review.map((entry, i) => {
                    const a = entry.allocation;
                    const what = a ? `${entry.item.key} for ${a.user_name}, week of ${a.week}` : entry.item.key;
                    return (
                        <li key={`${entry.kind}-${a?.id ?? entry.item.id}-${i}`} className="flex flex-col gap-2 rounded-md border bg-card p-3 sm:flex-row sm:items-center">
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                    <Badge variant="outline" className="shrink-0">{FLAG_LABELS[entry.kind] ?? entry.kind}</Badge>
                                    {entry.item.url ? (
                                        <a href={entry.item.url} className="min-w-0 truncate font-medium hover:underline">
                                            <span className="text-muted-foreground">{entry.item.key}</span> {entry.item.title}
                                        </a>
                                    ) : (
                                        <span className="min-w-0 truncate font-medium"><span className="text-muted-foreground">{entry.item.key}</span> {entry.item.title}</span>
                                    )}
                                </div>
                                <p className="mt-1 text-sm text-muted-foreground">{entry.message}</p>
                            </div>
                            {canPlan && a && (
                                <div className="flex shrink-0 gap-2">
                                    {entry.kind === 'estimate_changed' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={busy}
                                            aria-label={`Keep ${formatHours(a.minutes)} planned: ${what}`}
                                            onClick={() => run('put', urls.allocation, {
                                                item_id: entry.item.id, user_id: a.user_id, week_start: a.week, minutes: a.minutes, expected_minutes: a.minutes,
                                            })}
                                        >
                                            Keep {formatHours(a.minutes)}
                                        </Button>
                                    )}
                                    <Button size="sm" variant="ghost" disabled={busy} aria-label={`Remove planned time: ${what}`} onClick={() => run('delete', urls.destroy(a.id))}>
                                        Remove planned time
                                    </Button>
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
