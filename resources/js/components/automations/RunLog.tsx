import { Fragment, useState } from 'react';
import { formatDistanceToNow } from 'date-fns';
import { CheckCircle2, ChevronDown, ChevronRight, CircleSlash, Loader2, XCircle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { AutomationRun, AutomationRunResult, AutomationRunStatus } from '@/types';

/**
 * The automation run history (v1.40.0, BRAI-63): one row per run with its status, the issue or ticket
 * it ran for and, expanded, the result of every action. Used on Automations/Index (all rules) and
 * Automations/Edit (one rule).
 */
const STATUS: Record<AutomationRunStatus, { label: string; className: string }> = {
    queued: { label: 'Queued', className: 'bg-muted text-muted-foreground' },
    running: { label: 'Running', className: 'bg-blue-500/15 text-blue-700 dark:text-blue-300' },
    success: { label: 'Success', className: 'bg-green-500/15 text-green-700 dark:text-green-300' },
    skipped: { label: 'Skipped', className: 'bg-amber-500/15 text-amber-700 dark:text-amber-300' },
    error: { label: 'Error', className: 'bg-red-500/15 text-red-700 dark:text-red-300' },
};

export function RunStatusBadge({ status }: { status: AutomationRunStatus }) {
    const s = STATUS[status] ?? STATUS.queued;
    return <Badge variant="secondary" className={cn('rounded-full border-0 font-medium', s.className)}>{s.label}</Badge>;
}

function ResultIcon({ status }: { status: AutomationRunResult['status'] }) {
    if (status === 'ok') return <CheckCircle2 className="size-4 shrink-0 text-green-600" aria-label="Done" />;
    if (status === 'skipped') return <CircleSlash className="size-4 shrink-0 text-amber-600" aria-label="Skipped" />;
    return <XCircle className="size-4 shrink-0 text-red-600" aria-label="Error" />;
}

const ago = (iso: string | null) => (iso ? formatDistanceToNow(new Date(iso), { addSuffix: true }) : '');

export default function RunLog({ runs, showRule = false }: { runs: AutomationRun[]; showRule?: boolean }) {
    const [open, setOpen] = useState<number | null>(null);

    if (runs.length === 0) {
        return <p className="py-6 text-center text-sm text-muted-foreground">No runs yet. Runs appear here when a rule is switched on and its trigger happens.</p>;
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm" aria-label="Run history">
                <thead>
                    <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <th className="w-6 py-2" />
                        <th className="py-2 pr-3 font-medium">Status</th>
                        {showRule && <th className="py-2 pr-3 font-medium">Rule</th>}
                        <th className="py-2 pr-3 font-medium">For</th>
                        <th className="py-2 pr-3 font-medium">Trigger</th>
                        <th className="py-2 font-medium">When</th>
                    </tr>
                </thead>
                <tbody>
                    {runs.map((run) => {
                        const expanded = open === run.id;
                        return (
                            <Fragment key={run.id}>
                                <tr className="border-b last:border-0 hover:bg-muted/40">
                                    <td className="py-2 align-top">
                                        <button
                                            type="button"
                                            onClick={() => setOpen(expanded ? null : run.id)}
                                            aria-expanded={expanded}
                                            aria-label={`Details of run ${run.id}`}
                                            className="rounded p-0.5 text-muted-foreground hover:text-foreground"
                                        >
                                            {expanded ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                                        </button>
                                    </td>
                                    <td className="py-2 pr-3 align-top">
                                        <span className="inline-flex items-center gap-1.5">
                                            {run.status === 'running' && <Loader2 className="size-3.5 animate-spin" />}
                                            <RunStatusBadge status={run.status} />
                                        </span>
                                    </td>
                                    {showRule && <td className="py-2 pr-3 align-top font-medium">{run.rule?.name ?? '—'}</td>}
                                    <td className="max-w-[18rem] truncate py-2 pr-3 align-top" title={run.subject ?? undefined}>{run.subject ?? '—'}</td>
                                    <td className="py-2 pr-3 align-top text-muted-foreground">{run.trigger}</td>
                                    <td className="whitespace-nowrap py-2 align-top text-muted-foreground" title={run.created_at ?? undefined}>{ago(run.created_at)}</td>
                                </tr>
                                {expanded && (
                                    <tr className="border-b bg-muted/30">
                                        <td />
                                        <td colSpan={showRule ? 5 : 4} className="py-3 pr-3">
                                            {run.message && <p className="mb-2 text-sm">{run.message}</p>}
                                            {run.results.length > 0 ? (
                                                <ul className="space-y-1.5">
                                                    {run.results.map((result, i) => (
                                                        <li key={i} className="flex items-start gap-2">
                                                            <ResultIcon status={result.status} />
                                                            <span><span className="font-medium">{result.label}</span> — <span className="text-muted-foreground">{result.message}</span></span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            ) : (
                                                !run.message && <p className="text-sm text-muted-foreground">No actions ran yet.</p>
                                            )}
                                            <p className="mt-2 text-xs text-muted-foreground">Rule version {run.rule_version}</p>
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
