import { useSyncExternalStore } from 'react';
import type { Ticket } from '@/types';

/**
 * Helpdesk SLA status of a ticket (BRAI-125), from the dates TicketSla (PHP) stores. "Breached" is also
 * worked out here from the due dates, so the badge is right before the checker has run (dev has no
 * scheduler). Keep the rules in sync with App\Support\Helpdesks\Sla\TicketSla.
 */
export type SlaTargetName = 'first_response' | 'resolution';

export type SlaState =
    | { kind: 'none' }
    | { kind: 'breached'; since: Date; targets: SlaTargetName[] }
    | { kind: 'respond' | 'resolve'; due: Date; atRisk: boolean }
    | { kind: 'paused' }
    | { kind: 'met' };

export type SlaFilter = 'any' | 'breached' | 'at_risk' | 'on_track' | 'paused' | 'met' | 'none';

export const SLA_FILTERS: { value: SlaFilter; label: string }[] = [
    { value: 'any', label: 'Any' },
    { value: 'breached', label: 'Breached' },
    { value: 'at_risk', label: 'At risk' },
    { value: 'on_track', label: 'On track' },
    { value: 'paused', label: 'Paused' },
    { value: 'met', label: 'Met' },
    { value: 'none', label: 'No SLA' },
];

const date = (value?: string | null) => (value ? new Date(value) : null);

export function slaState(ticket: Ticket, now: Date = new Date()): SlaState {
    const firstDue = date(ticket.sla_first_response_due_at);
    const resolutionDue = date(ticket.sla_resolution_due_at);
    if (!firstDue && !resolutionDue) return { kind: 'none' };

    const completed = date(ticket.completed_at);
    // resolving also ends the first-response clock (as in TicketSla)
    const responded = date(ticket.first_responded_at) ?? completed;
    const paused = date(ticket.sla_paused_at);

    const missed: { target: SlaTargetName; at: Date }[] = [];
    if (firstDue && (ticket.sla_first_response_breached || (responded ? responded > firstDue : now >= firstDue))) {
        missed.push({ target: 'first_response', at: firstDue });
    }
    if (resolutionDue && (ticket.sla_resolution_breached || (completed ? completed > resolutionDue : (paused ?? now) >= resolutionDue))) {
        missed.push({ target: 'resolution', at: resolutionDue });
    }
    if (missed.length) {
        return { kind: 'breached', since: new Date(Math.min(...missed.map((m) => m.at.getTime()))), targets: missed.map((m) => m.target) };
    }

    if (firstDue && !responded) {
        const warn = date(ticket.sla_first_response_warn_at);
        return { kind: 'respond', due: firstDue, atRisk: !!warn && warn < firstDue && now >= warn };
    }
    if (resolutionDue && !completed) {
        if (paused) return { kind: 'paused' };
        const warn = date(ticket.sla_resolution_warn_at);
        return { kind: 'resolve', due: resolutionDue, atRisk: !!warn && warn < resolutionDue && now >= warn };
    }

    return { kind: 'met' };
}

/** "45m", "3h", "2d". */
export function shortDuration(ms: number): string {
    const minutes = Math.max(0, Math.round(ms / 60000));
    if (minutes < 60) return `${minutes}m`;
    const hours = Math.round(minutes / 60);
    if (hours < 48) return `${hours}h`;
    return `${Math.round(hours / 24)}d`;
}

export function slaLabel(state: SlaState, now: Date = new Date()): string | null {
    switch (state.kind) {
        case 'none':
            return null;
        case 'breached':
            return `Breached ${shortDuration(now.getTime() - state.since.getTime())} ago`;
        case 'respond':
            return `Respond in ${shortDuration(state.due.getTime() - now.getTime())}`;
        case 'resolve':
            return `Resolve in ${shortDuration(state.due.getTime() - now.getTime())}`;
        case 'paused':
            return 'SLA paused';
        case 'met':
            return 'Met';
    }
}

export function matchesSlaFilter(state: SlaState, filter: SlaFilter): boolean {
    switch (filter) {
        case 'any':
            return true;
        case 'at_risk':
            return (state.kind === 'respond' || state.kind === 'resolve') && state.atRisk;
        case 'on_track':
            return (state.kind === 'respond' || state.kind === 'resolve') && !state.atRisk;
        default:
            return state.kind === filter;
    }
}

/** Most urgent first: breached (oldest breach first), then by the next due time; paused, met, none last. */
export function slaSortKey(state: SlaState): number {
    switch (state.kind) {
        case 'breached':
            return state.since.getTime() - 1e13;
        case 'respond':
        case 'resolve':
            return state.due.getTime();
        case 'paused':
            return 8e15;
        case 'met':
            return 9e15;
        default:
            return Number.POSITIVE_INFINITY;
    }
}

// One shared minute ticker, so every badge re-renders together without an interval each.
let tick = Date.now();
const listeners = new Set<() => void>();
let timer: ReturnType<typeof setInterval> | null = null;

function subscribe(listener: () => void) {
    listeners.add(listener);
    if (!timer) {
        timer = setInterval(() => {
            tick = Date.now();
            listeners.forEach((l) => l());
        }, 60_000);
    }
    return () => {
        listeners.delete(listener);
        if (!listeners.size && timer) {
            clearInterval(timer);
            timer = null;
        }
    };
}

/** The current time, updated every minute (for SLA countdowns). */
export function useMinuteClock(): Date {
    const now = useSyncExternalStore(subscribe, () => tick, () => tick);
    return new Date(Math.max(now, Date.now()));
}
