<?php

namespace App\Support\Events;

use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Support\Webhooks\WebhookDispatcher;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * One call per thing that happened in a workspace (v1.39.0). Outgoing webhooks (BRAI-104) subscribe
 * to these names; nothing else may depend on the dispatch being synchronous.
 *
 *     DomainEvents::dispatch($workspace, 'item.created', ['item' => [...]], $actor);
 *     DomainEvents::emit($workspaceOrId, 'item.created', fn () => ['item' => EventPayloads::item($item)]);
 *
 * Envelope sent to webhook endpoints (contract: docs/ai/tasks/release-v1.39.0.md):
 *     {id, event, occurred_at, workspace: {id, slug, name}, actor: {id, name}|null, data: {...}}
 * `data` holds plain arrays only (no models, no secrets, no internal-only fields such as rates).
 *
 * The envelope is built when the event happens; the deliveries are created after the surrounding
 * DB transaction commits (nothing is sent for a rolled-back change). A failure here is reported,
 * never thrown into the user's request (except in unit tests, so payload bugs surface).
 */
final class DomainEvents
{
    /** event name => human label (shown when picking events for a webhook). */
    public const EVENTS = [
        'item.created' => 'Issue created',
        'item.updated' => 'Issue updated',
        'item.moved' => 'Issue moved to another column',
        'item.completed' => 'Issue completed',
        'item.assigned' => 'Issue assigned',
        'item.commented' => 'Comment added to an issue',
        'item.deleted' => 'Issue deleted',
        'page.published' => 'Page published',
        'sprint.started' => 'Sprint started',
        'sprint.completed' => 'Sprint completed',
        'ticket.created' => 'Helpdesk ticket created',
        'ticket.replied' => 'Helpdesk ticket reply',
        'ticket.resolved' => 'Helpdesk ticket resolved',
        'lead.created' => 'Lead received',
        'time_entry.created' => 'Time logged',
        'billing_report.finalized' => 'Report / invoice finalized',
        'billing_report.paid' => 'Invoice marked paid',
    ];

    /** Sent by "Send test" only; not subscribable. */
    public const PING = 'ping';

    public static function dispatch(Workspace $workspace, string $event, array $data, ?User $actor = null): void
    {
        self::assertKnown($event);

        try {
            $envelope = self::envelope($workspace, $event, $data, $actor ?? self::currentUser());
            $workspaceId = $workspace->id;

            DB::afterCommit(function () use ($workspaceId, $envelope) {
                try {
                    foreach (Webhook::listeningTo($workspaceId, $envelope['event'])->get() as $webhook) {
                        WebhookDispatcher::queue($webhook, $envelope);
                    }
                } catch (Throwable $e) {
                    self::failed($e);
                }
            });
        } catch (Throwable $e) {
            self::failed($e);
        }
    }

    /**
     * dispatch(), but the payload is only built (and the workspace only loaded) when an active
     * webhook of that workspace listens to $event — hook points stay one cheap query otherwise.
     *
     * @param  Closure(): array<string, mixed>  $data
     */
    public static function emit(Workspace|int|null $workspace, string $event, Closure $data, ?User $actor = null): void
    {
        self::assertKnown($event);

        $run = function () use ($workspace, $event, $data, $actor) {
            $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;
            if (! $workspaceId || ! Webhook::listeningTo($workspaceId, $event)->exists()) {
                return;
            }
            $workspace = $workspace instanceof Workspace ? $workspace : Workspace::find($workspaceId);
            if ($workspace) {
                self::dispatch($workspace, $event, $data(), $actor);
            }
        };

        try {
            // inside the caller's transaction: a savepoint, so a failing payload query can't abort it (Postgres)
            DB::transactionLevel() > 0 ? DB::transaction($run) : $run();
        } catch (Throwable $e) {
            self::failed($e);
        }
    }

    /**
     * The JSON body of a delivery.
     *
     * @return array{id: string, event: string, occurred_at: string, workspace: array, actor: ?array, data: array}
     */
    public static function envelope(Workspace $workspace, string $event, array $data, ?User $actor): array
    {
        return [
            'id' => (string) Str::uuid(),
            'event' => $event,
            'occurred_at' => now()->utc()->toIso8601String(),
            'workspace' => ['id' => $workspace->id, 'slug' => $workspace->slug, 'name' => $workspace->name],
            'actor' => $actor ? ['id' => $actor->id, 'name' => $actor->name] : null,
            'data' => $data,
        ];
    }

    /** @return array<string, array<string, string>> area label => [event => label] (events picker) */
    public static function grouped(): array
    {
        $areas = ['item' => 'Issues', 'page' => 'Pages', 'sprint' => 'Sprints', 'ticket' => 'Helpdesk', 'lead' => 'Leads', 'time_entry' => 'Time tracking', 'billing_report' => 'Billing'];
        $groups = [];
        foreach (self::EVENTS as $event => $label) {
            $groups[$areas[Str::before($event, '.')] ?? 'Other'][$event] = $label;
        }

        return $groups;
    }

    private static function assertKnown(string $event): void
    {
        if (! array_key_exists($event, self::EVENTS)) {
            throw new \InvalidArgumentException("Unknown domain event [{$event}] — add it to DomainEvents::EVENTS.");
        }
    }

    private static function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private static function failed(Throwable $e): void
    {
        if (app()->runningUnitTests()) {
            throw $e;
        }
        report($e);
    }
}
