<?php

namespace App\Support\Helpdesks\Sla;

use App\Models\Helpdesk;
use App\Models\HelpdeskSlaPolicy;
use App\Models\Ticket;
use App\Models\TicketReply;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A ticket's SLA state (BRAI-125), always derived from the ticket, its helpdesk's policy and "now":
 *
 * - first response: due `target` business minutes after creation; met by the first public agent reply
 *   (`first_responded_at`, set by recordReply()), or by resolving the ticket before it was due;
 * - resolution: due `target + paused` business minutes after creation; met when resolved (`completed_at`);
 *   while the ticket sits in a pause column the clock stands still (`sla_paused_at`), and the paused
 *   business minutes are added when it leaves (`sla_paused_minutes`). Pauses never move the
 *   first-response target;
 * - warn_at = `warn_percent` of the target (= due when warnings are off): when the checker looks at it;
 * - breached: answered / resolved after the due time, or still pending at or after it (a paused ticket
 *   counts at the moment the pause began). `sla_breached_at` = the earliest missed due time.
 *
 * apply() writes these columns on the model (no save). It runs from the Ticket `saving` hook on create
 * and whenever priority, column, completion or first response change; policy saves and the checker
 * call it for the tickets they touch.
 */
final class TicketSla
{
    public const COLUMNS = [
        'sla_first_response_due_at', 'sla_first_response_warn_at', 'sla_resolution_due_at', 'sla_resolution_warn_at',
        'sla_paused_at', 'sla_paused_minutes', 'sla_breached_at', 'sla_first_response_breached', 'sla_resolution_breached',
    ];

    public static function policyFor(Ticket $ticket): ?HelpdeskSlaPolicy
    {
        if ($ticket->relationLoaded('helpdesk') && $ticket->helpdesk?->relationLoaded('slaPolicy')) {
            return $ticket->helpdesk->slaPolicy;
        }

        return $ticket->helpdesk_id ? HelpdeskSlaPolicy::where('helpdesk_id', $ticket->helpdesk_id)->first() : null;
    }

    public static function apply(Ticket $ticket, ?HelpdeskSlaPolicy $policy, ?CarbonInterface $now = null): void
    {
        $now = CarbonImmutable::instance($now ?? now());
        $calendar = $policy?->calendar();
        $targets = $policy && $policy->enabled ? $policy->targetMinutes($ticket->priority_id, $calendar) : ['first_response' => null, 'resolution' => null];
        [$firstTarget, $resolutionTarget] = [$targets['first_response'], $targets['resolution']];

        if (! $policy || ($firstTarget === null && $resolutionTarget === null)) {
            self::clear($ticket);

            return;
        }

        $start = CarbonImmutable::instance($ticket->created_at ?? $now);
        $warn = $policy->warn_percent;

        // Pause bookkeeping first, so a ticket resolved straight from "waiting" counts the pause.
        $shouldPause = $resolutionTarget !== null && $ticket->completed_at === null && $policy->isPauseColumn($ticket->column_id);
        if ($shouldPause && ! $ticket->sla_paused_at) {
            $ticket->sla_paused_at = $now;
        } elseif (! $shouldPause && $ticket->sla_paused_at) {
            $ticket->sla_paused_minutes = (int) $ticket->sla_paused_minutes + $calendar->minutesBetween($ticket->sla_paused_at, $now);
            $ticket->sla_paused_at = null;
        }
        $paused = $resolutionTarget !== null ? (int) $ticket->sla_paused_minutes : 0;

        $firstDue = $firstTarget !== null ? $calendar->addMinutes($start, $firstTarget) : null;
        $resolutionDue = $resolutionTarget !== null ? $calendar->addMinutes($start, $resolutionTarget + $paused) : null;
        $warnAt = fn (int $target, int $extra, CarbonImmutable $due) => $warn
            ? $calendar->addMinutes($start, intdiv($target * min(99, max(1, $warn)), 100) + $extra)
            : $due;

        $ticket->sla_first_response_due_at = $firstDue;
        $ticket->sla_first_response_warn_at = $firstDue ? $warnAt($firstTarget, 0, $firstDue) : null;
        $ticket->sla_resolution_due_at = $resolutionDue;
        $ticket->sla_resolution_warn_at = $resolutionDue ? $warnAt($resolutionTarget, $paused, $resolutionDue) : null;

        // resolving a ticket also stops its first-response clock (no answer was needed after all)
        $firstBreached = $firstDue !== null && self::missed($ticket->first_responded_at ?? $ticket->completed_at, $now, $firstDue);
        $resolutionBreached = $resolutionDue !== null && self::missed($ticket->completed_at, $ticket->sla_paused_at ?? $now, $resolutionDue);

        $ticket->sla_first_response_breached = $firstBreached;
        $ticket->sla_resolution_breached = $resolutionBreached;
        $missed = array_filter([$firstBreached ? $firstDue : null, $resolutionBreached ? $resolutionDue : null]);
        $ticket->sla_breached_at = $missed === [] ? null : min($missed);
    }

    /** Done after the due time, or still pending at / after it. */
    private static function missed(?CarbonInterface $doneAt, CarbonInterface $now, CarbonInterface $due): bool
    {
        return $doneAt ? $doneAt->gt($due) : $now->gte($due);
    }

    private static function clear(Ticket $ticket): void
    {
        foreach (self::COLUMNS as $column) {
            $ticket->{$column} = match ($column) {
                'sla_paused_minutes' => 0,
                'sla_first_response_breached', 'sla_resolution_breached' => false,
                default => null,
            };
        }
    }

    /**
     * The first public reply by an agent (a user, not the contact; not an internal note) is the first
     * response. Every agent path creates a TicketReply, including replies that are emailed later.
     */
    public static function recordReply(TicketReply $reply): void
    {
        if (! $reply->user_id || $reply->is_internal_note) {
            return;
        }

        $ticket = Ticket::whereKey($reply->ticket_id)->whereNull('first_responded_at')->first();
        if ($ticket) {
            $ticket->first_responded_at = $reply->created_at ?? now();
            $ticket->save(); // the saving hook recomputes the SLA
        }
    }

    /**
     * After a policy change: recompute the helpdesk's open tickets (resolved ones keep their result).
     * System writes: no `updated_at` bump, no model events.
     */
    public static function recomputeHelpdesk(Helpdesk $helpdesk, HelpdeskSlaPolicy $policy): int
    {
        $count = 0;
        $now = now();
        $helpdesk->tickets()->whereNull('completed_at')->chunkById(200, function ($tickets) use ($policy, $now, &$count) {
            foreach ($tickets as $ticket) {
                if (self::recompute($ticket, $policy, $now)) {
                    $count++;
                }
            }
        });

        return $count;
    }

    /** Recompute one ticket and store it quietly if anything changed. */
    public static function recompute(Ticket $ticket, ?HelpdeskSlaPolicy $policy, ?CarbonInterface $now = null): bool
    {
        self::apply($ticket, $policy, $now);
        if (! $ticket->isDirty()) {
            return false;
        }
        $ticket->timestamps = false;
        $ticket->saveQuietly();
        $ticket->timestamps = true;

        return true;
    }
}
