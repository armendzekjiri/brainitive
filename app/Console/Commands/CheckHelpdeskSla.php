<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketSlaBreachedNotification;
use App\Notifications\TicketSlaWarningNotification;
use App\Support\Helpdesks\Sla\TicketSla;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Helpdesk SLA checker (BRAI-125), every minute in production; dev and e2e run it by hand / through the
 * e2e route. Looks at open tickets of enabled policies whose warning or due time has passed, records
 * breaches on the ticket (TicketSla), and sends `sla_warning` / `sla_breached` for each pending target to
 * the assignee, or the project admins when unassigned. Once per (ticket, kind, target, person): dedup in
 * `sent_item_reminders`, so running it again sends nothing new.
 */
class CheckHelpdeskSla extends Command
{
    protected $signature = 'app:check-helpdesk-sla';

    protected $description = 'Record helpdesk SLA breaches and warn before and on them';

    /** @var array<int, Collection<int, User>> admins per project, for this run */
    private array $admins = [];

    public function handle(): int
    {
        $now = now();
        $sent = 0;

        Ticket::query()
            ->select('tickets.*')
            ->join('helpdesks', 'helpdesks.id', '=', 'tickets.helpdesk_id')
            ->join('helpdesk_sla_policies', 'helpdesk_sla_policies.helpdesk_id', '=', 'tickets.helpdesk_id')
            ->whereNull('helpdesks.deleted_at')
            ->where('helpdesk_sla_policies.enabled', true)
            ->whereNull('tickets.completed_at')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('tickets.first_responded_at')->where('tickets.sla_first_response_warn_at', '<=', $now))
                ->orWhere(fn ($q) => $q->whereNull('tickets.sla_paused_at')->where('tickets.sla_resolution_warn_at', '<=', $now)))
            ->with(['helpdesk.slaPolicy', 'helpdesk.project.workspace', 'assignee'])
            ->chunkById(200, function ($tickets) use ($now, &$sent) {
                foreach ($tickets as $ticket) {
                    TicketSla::recompute($ticket, $ticket->helpdesk->slaPolicy, $now);
                    $sent += $this->notify($ticket, $now);
                }
            }, 'tickets.id', 'id');

        $this->info("Sent {$sent} SLA notification(s).");

        return self::SUCCESS;
    }

    private function notify(Ticket $ticket, CarbonInterface $now): int
    {
        $sent = 0;
        foreach (['first_response', 'resolution'] as $target) {
            $pending = $target === 'first_response'
                ? $ticket->first_responded_at === null
                : $ticket->completed_at === null && $ticket->sla_paused_at === null;
            $due = $ticket->{"sla_{$target}_due_at"};
            $warnAt = $ticket->{"sla_{$target}_warn_at"};
            if (! $pending || ! $due) {
                continue;
            }

            if ($now->gte($due)) {
                $kind = 'breached';
            } elseif ($warnAt && $warnAt->lt($due) && $now->gte($warnAt)) {
                $kind = 'warning';
            } else {
                continue;
            }

            foreach ($this->recipients($ticket) as $user) {
                if (! $this->claim($user, "ticket_sla:{$ticket->id}:{$kind}:{$target}")) {
                    continue;
                }
                $user->notify($kind === 'breached'
                    ? new TicketSlaBreachedNotification($ticket, $target, $due)
                    : new TicketSlaWarningNotification($ticket, $target, $due));
                $sent++;
            }
        }

        return $sent;
    }

    /** @return Collection<int, User> the assignee, or the project admins when nobody is assigned */
    private function recipients(Ticket $ticket): Collection
    {
        $project = $ticket->helpdesk->project;
        if (! $project) {
            return collect();
        }
        if ($ticket->assignee && $ticket->assignee->hasProjectAccess($project)) {
            return collect([$ticket->assignee]);
        }

        return $this->admins[$project->id] ??= $this->projectAdmins($project);
    }

    /** @return Collection<int, User> */
    private function projectAdmins(Project $project): Collection
    {
        return $project->admins()->get()->filter(fn (User $user) => $user->hasProjectAccess($project))->values();
    }

    /** Atomically mark a notification as sent; false when it already was. */
    private function claim(User $user, string $key): bool
    {
        return DB::table('sent_item_reminders')->insertOrIgnore([
            'user_id' => $user->id,
            'reminder_key' => $key,
            'created_at' => now(),
        ]) === 1;
    }
}
