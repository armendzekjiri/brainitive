<?php

namespace App\Support\Helpdesks\Sla;

use App\Models\Ticket;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The SLA status of a ticket (BRAI-125) from the dates TicketSla stores — the PHP twin of the web's
 * `resources/js/lib/sla.ts` (slaState / matchesSlaFilter), for the App API (BRAI-420). "Breached" is also
 * worked out from the due dates, so it's right before the checker has run. Keep the rules in sync with
 * sla.ts and TicketSla.
 *
 * States: none | breached | respond | resolve | paused | met; `respond` / `resolve` are at risk once the
 * warn time has passed. Filters: breached, at_risk, on_track, paused, met, none.
 */
final class SlaStatus
{
    public const FILTERS = ['breached', 'at_risk', 'on_track', 'paused', 'met', 'none'];

    /**
     * @return array{state: string, due_at: CarbonInterface|null, breached_since: CarbonInterface|null, breached_targets: list<string>, at_risk: bool}
     */
    public static function of(Ticket $ticket, CarbonInterface $now): array
    {
        $out = ['state' => 'none', 'due_at' => null, 'breached_since' => null, 'breached_targets' => [], 'at_risk' => false];
        $firstDue = $ticket->sla_first_response_due_at;
        $resolutionDue = $ticket->sla_resolution_due_at;
        if (! $firstDue && ! $resolutionDue) {
            return $out;
        }

        $completed = $ticket->completed_at;
        // resolving also ends the first-response clock (as in TicketSla)
        $responded = $ticket->first_responded_at ?? $completed;
        $paused = $ticket->sla_paused_at;

        $missed = [];
        if ($firstDue && ($ticket->sla_first_response_breached || ($responded ? $responded->gt($firstDue) : $now->gte($firstDue)))) {
            $missed['first_response'] = $firstDue;
        }
        if ($resolutionDue && ($ticket->sla_resolution_breached || ($completed ? $completed->gt($resolutionDue) : ($paused ?? $now)->gte($resolutionDue)))) {
            $missed['resolution'] = $resolutionDue;
        }
        if ($missed !== []) {
            $since = collect($missed)->sortBy(fn ($at) => $at->getTimestamp())->first();

            return ['state' => 'breached', 'breached_since' => $since, 'breached_targets' => array_keys($missed)] + $out;
        }

        if ($firstDue && ! $responded) {
            $warn = $ticket->sla_first_response_warn_at;

            return ['state' => 'respond', 'due_at' => $firstDue, 'at_risk' => $warn !== null && $warn->lt($firstDue) && $now->gte($warn)] + $out;
        }
        if ($resolutionDue && ! $completed) {
            if ($paused) {
                return ['state' => 'paused'] + $out;
            }
            $warn = $ticket->sla_resolution_warn_at;

            return ['state' => 'resolve', 'due_at' => $resolutionDue, 'at_risk' => $warn !== null && $warn->lt($resolutionDue) && $now->gte($warn)] + $out;
        }

        return ['state' => 'met'] + $out;
    }

    /**
     * Limit a ticket query to one SLA filter (the same rules as of(), in SQL).
     *
     * @param  Builder<Ticket>  $query
     */
    public static function filter(Builder $query, string $filter, CarbonInterface $now): void
    {
        $now = Carbon::instance($now)->setTimezone(config('app.timezone'));
        $t = $query->getModel()->getTable();
        $n = '?::timestamp';
        $responded = "coalesce({$t}.first_responded_at, {$t}.completed_at)";
        $breached = "(({$t}.sla_first_response_due_at is not null and (coalesce({$t}.sla_first_response_breached, false)"
            ." or ({$responded} is not null and {$responded} > {$t}.sla_first_response_due_at)"
            ." or ({$responded} is null and {$n} >= {$t}.sla_first_response_due_at)))"
            ." or ({$t}.sla_resolution_due_at is not null and (coalesce({$t}.sla_resolution_breached, false)"
            ." or ({$t}.completed_at is not null and {$t}.completed_at > {$t}.sla_resolution_due_at)"
            ." or ({$t}.completed_at is null and coalesce({$t}.sla_paused_at, {$n}) >= {$t}.sla_resolution_due_at))))";
        $breachedBindings = [$now, $now];
        $none = "({$t}.sla_first_response_due_at is null and {$t}.sla_resolution_due_at is null)";
        $respond = "({$t}.sla_first_response_due_at is not null and {$responded} is null)";
        $respondRisk = "({$t}.sla_first_response_warn_at is not null and {$t}.sla_first_response_warn_at < {$t}.sla_first_response_due_at and {$n} >= {$t}.sla_first_response_warn_at)";
        $resolvePhase = "({$t}.sla_resolution_due_at is not null and {$t}.completed_at is null)";
        $resolveRisk = "({$t}.sla_resolution_warn_at is not null and {$t}.sla_resolution_warn_at < {$t}.sla_resolution_due_at and {$n} >= {$t}.sla_resolution_warn_at)";
        $live = "not {$none} and not {$breached}";
        $resolving = "not {$respond} and {$resolvePhase} and {$t}.sla_paused_at is null";

        match ($filter) {
            'none' => $query->whereRaw($none),
            'breached' => $query->whereRaw("not {$none} and {$breached}", $breachedBindings),
            'paused' => $query->whereRaw("{$live} and not {$respond} and {$resolvePhase} and {$t}.sla_paused_at is not null", $breachedBindings),
            'met' => $query->whereRaw("{$live} and not {$respond} and not {$resolvePhase}", $breachedBindings),
            'at_risk' => $query->whereRaw("{$live} and (({$respond} and {$respondRisk}) or ({$resolving} and {$resolveRisk}))", [...$breachedBindings, $now, $now]),
            'on_track' => $query->whereRaw("{$live} and (({$respond} and not {$respondRisk}) or ({$resolving} and not {$resolveRisk}))", [...$breachedBindings, $now, $now]),
            default => null,
        };
    }
}
