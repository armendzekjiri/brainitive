<?php

namespace App\Http\Controllers;

use App\Models\Helpdesk;
use App\Models\HelpdeskSlaPolicy;
use App\Models\ItemPriority;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Helpdesks\Sla\BusinessCalendar;
use App\Support\Helpdesks\Sla\TicketSla;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Helpdesk settings → SLA (BRAI-125): load and save the helpdesk's policy. Whoever may edit the
 * helpdesk (`helpdesks.update`) may change it. Saving recomputes the open tickets' SLA dates.
 */
class HelpdeskSlaController extends Controller
{
    /** Longest target: 365 days (business days count as 24 h here). */
    private const MAX_TARGET_MINUTES = 365 * 1440;

    public function show(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk): JsonResponse
    {
        $this->authorize('update', $helpdesk);

        $columns = $helpdesk->columns()->get(['id', 'name', 'color', 'is_done_column']);

        return response()->json([
            'policy' => self::present($helpdesk->slaPolicy, $request->user(), $columns->all()),
            'priorities' => ItemPriority::forProject($project)->orderBy('order')->get(['id', 'name', 'slug', 'color']),
            'columns' => $columns,
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk): JsonResponse
    {
        $this->authorize('update', $helpdesk);

        $priorityIds = ItemPriority::forProject($project)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'targets' => 'present|array',
            'targets.*' => 'array',
            'targets.*.*' => 'nullable|array',
            'targets.*.*.value' => 'nullable|numeric|min:1|max:100000',
            'targets.*.*.unit' => ['nullable', Rule::in(HelpdeskSlaPolicy::UNITS)],
            'business_hours' => 'nullable|array',
            'business_hours.days' => 'required_with:business_hours|array|min:1',
            'business_hours.days.*' => 'integer|between:1,7',
            'business_hours.from' => 'required_with:business_hours|date_format:H:i',
            'business_hours.to' => 'required_with:business_hours|date_format:H:i|different:business_hours.from',
            'timezone' => 'required|timezone:all',
            'pause_column_ids' => 'present|array',
            'pause_column_ids.*' => ['integer', Rule::exists('helpdesk_columns', 'id')->where('helpdesk_id', $helpdesk->id)->where(fn ($q) => $q->where('is_done_column', false))],
            'warn_percent' => 'nullable|integer|between:1,99',
        ]);

        $targets = [];
        foreach ($validated['targets'] as $key => $entry) {
            $key = (string) $key;
            if ($key !== 'none' && ! in_array($key, $priorityIds, true)) {
                throw ValidationException::withMessages(["targets.{$key}" => 'Unknown priority.']);
            }
            $clean = [];
            foreach (HelpdeskSlaPolicy::TARGETS as $target) {
                $value = $entry[$target]['value'] ?? null;
                if ($value === null || $value === '') {
                    $clean[$target] = null;

                    continue;
                }
                $unit = $entry[$target]['unit'] ?? 'hours';
                $minutes = (float) $value * ['minutes' => 1, 'hours' => 60, 'days' => 1440][$unit];
                if ($minutes > self::MAX_TARGET_MINUTES) {
                    throw ValidationException::withMessages(["targets.{$key}.{$target}.value" => 'Targets can be at most 365 days.']);
                }
                $clean[$target] = ['value' => $value + 0, 'unit' => $unit];
            }
            if ($clean['first_response'] || $clean['resolution']) {
                $targets[$key] = $clean;
            }
        }

        $hours = $validated['business_hours'] ?? null;
        if ($hours) {
            $hours = ['days' => array_values(array_unique(array_map('intval', $hours['days']))), 'from' => $hours['from'], 'to' => $hours['to']];
            sort($hours['days']);
            BusinessCalendar::hours($hours['days'], $hours['from'], $hours['to'], $validated['timezone']); // validated above; throws if not
        }

        [$policy, $recomputed] = DB::transaction(function () use ($helpdesk, $validated, $targets, $hours) {
            $policy = HelpdeskSlaPolicy::updateOrCreate(['helpdesk_id' => $helpdesk->id], [
                'enabled' => (bool) $validated['enabled'],
                'targets' => $targets,
                'business_hours' => $hours,
                'timezone' => $validated['timezone'],
                'pause_column_ids' => array_values(array_unique(array_map('intval', $validated['pause_column_ids']))),
                'warn_percent' => $validated['warn_percent'] ?? null,
            ]);

            return [$policy, TicketSla::recomputeHelpdesk($helpdesk, $policy)];
        });

        return response()->json([
            'policy' => self::present($policy, $request->user()),
            'recomputed' => $recomputed,
            'message' => 'SLA policy saved.',
        ]);
    }

    /**
     * e2e only (APP_ENV=e2e; the route isn't even registered elsewhere): optionally move one ticket's
     * creation back by N minutes, then run the checker. Dev and production have no such route.
     */
    public function e2eCheck(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk): JsonResponse
    {
        abort_unless(app()->environment('e2e'), 404);
        $this->authorize('update', $helpdesk);

        $validated = $request->validate([
            'ticket_id' => ['nullable', 'integer', Rule::exists('tickets', 'id')->where('helpdesk_id', $helpdesk->id)],
            'backdate_minutes' => 'nullable|integer|min:0|max:100000',
        ]);

        if (! empty($validated['ticket_id']) && ! empty($validated['backdate_minutes'])) {
            $ticket = Ticket::findOrFail($validated['ticket_id']);
            $ticket->created_at = $ticket->created_at->subMinutes((int) $validated['backdate_minutes']);
            TicketSla::apply($ticket, $helpdesk->slaPolicy);
            $ticket->timestamps = false;
            $ticket->saveQuietly();
        }

        Artisan::call('app:check-helpdesk-sla');

        return response()->json(['output' => trim(Artisan::output())]);
    }

    /**
     * The policy as the settings tab edits it (defaults when the helpdesk has none yet: off, 24/7, the
     * editor's time zone, "waiting" columns pause).
     *
     * @param  array<int, object>  $columns
     */
    private static function present(?HelpdeskSlaPolicy $policy, User $user, array $columns = []): array
    {
        if (! $policy) {
            return [
                'exists' => false,
                'enabled' => false,
                'targets' => (object) [],
                'business_hours' => null,
                'timezone' => $user->localTimezone(),
                'pause_column_ids' => collect($columns)
                    ->filter(fn ($c) => ! $c->is_done_column && str_contains(strtolower($c->name), 'waiting'))
                    ->pluck('id')->values()->all(),
                'warn_percent' => 80,
            ];
        }

        return [
            'exists' => true,
            'enabled' => $policy->enabled,
            'targets' => (object) ($policy->targets ?? []),
            'business_hours' => $policy->business_hours,
            'timezone' => $policy->timezone,
            'pause_column_ids' => array_map('intval', $policy->pause_column_ids ?? []),
            'warn_percent' => $policy->warn_percent,
        ];
    }
}
