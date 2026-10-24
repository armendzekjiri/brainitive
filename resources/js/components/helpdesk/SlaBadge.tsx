import { AlertTriangle, CheckCircle2, Clock, PauseCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { slaLabel, slaState, useMinuteClock, type SlaState } from '@/lib/sla';
import type { Ticket } from '@/types';

/**
 * SLA badge on ticket cards, the list and the ticket panel (BRAI-125): "Respond in 2h", "Resolve in 5h",
 * "Breached 3h ago", "SLA paused", green "Met". Nothing when the ticket has no SLA.
 */
const STYLES: Record<Exclude<SlaState['kind'], 'none'>, string> = {
    breached: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300',
    respond: 'border-border bg-muted/60 text-foreground',
    resolve: 'border-border bg-muted/60 text-foreground',
    paused: 'border-border bg-muted text-muted-foreground',
    met: 'border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950/40 dark:text-green-300',
};

const AT_RISK = 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300';

const TARGET_NAMES = { first_response: 'first response', resolution: 'resolution' } as const;

function title(state: SlaState): string {
    switch (state.kind) {
        case 'breached':
            return `SLA breached: ${state.targets.map((t) => TARGET_NAMES[t]).join(' and ')} missed`;
        case 'respond':
            return `First response due ${state.due.toLocaleString()}`;
        case 'resolve':
            return `Resolution due ${state.due.toLocaleString()}`;
        case 'paused':
            return 'SLA paused while waiting on the customer';
        case 'met':
            return 'SLA met';
        default:
            return '';
    }
}

export default function SlaBadge({ ticket, className }: { ticket: Ticket; className?: string }) {
    const now = useMinuteClock();
    const state = slaState(ticket, now);
    const label = slaLabel(state, now);
    if (!label) return null;

    const Icon = state.kind === 'breached' ? AlertTriangle : state.kind === 'met' ? CheckCircle2 : state.kind === 'paused' ? PauseCircle : Clock;
    const atRisk = (state.kind === 'respond' || state.kind === 'resolve') && state.atRisk;

    return (
        <span
            data-testid="sla-badge"
            title={title(state)}
            className={cn(
                'inline-flex items-center gap-1 whitespace-nowrap rounded-full border px-1.5 py-0 text-[10px] font-medium leading-4',
                atRisk ? AT_RISK : STYLES[state.kind as Exclude<SlaState['kind'], 'none'>],
                className,
            )}
        >
            <Icon className="size-3" aria-hidden />
            {label}
        </span>
    );
}

/** Ticket panel: both targets with their due time and outcome. */
export function SlaDetails({ ticket }: { ticket: Ticket }) {
    const now = useMinuteClock();
    const state = slaState(ticket, now);
    if (state.kind === 'none') return null;

    const rows: { label: string; due?: string | null; done?: string | null; breached: boolean }[] = [
        {
            label: 'First response',
            due: ticket.sla_first_response_due_at,
            done: ticket.first_responded_at,
            breached: state.kind === 'breached' && state.targets.includes('first_response'),
        },
        {
            label: 'Resolution',
            due: ticket.sla_resolution_due_at,
            done: ticket.completed_at,
            breached: state.kind === 'breached' && state.targets.includes('resolution'),
        },
    ];
    const format = (value: string) => new Date(value).toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

    return (
        <div className="space-y-1.5 px-2 py-1">
            <SlaBadge ticket={ticket} className="text-xs" />
            {rows.filter((r) => r.due).map((r) => (
                <p key={r.label} className="text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">{r.label}</span>{' '}
                    {r.breached ? 'missed, was due ' : r.done ? 'met, due ' : 'due '}
                    {format(r.due!)}
                </p>
            ))}
        </div>
    );
}
