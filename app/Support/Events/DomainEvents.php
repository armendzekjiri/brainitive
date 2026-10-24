<?php

namespace App\Support\Events;

use App\Models\User;
use App\Models\Workspace;

/**
 * One call per thing that happened in a workspace (v1.39.0). Outgoing webhooks (ticket 3) subscribe
 * to these names; nothing else may depend on the dispatch being synchronous.
 *
 *     DomainEvents::dispatch($workspace, 'item.created', ['item' => [...]], $actor);
 *
 * Envelope sent to webhook endpoints (contract: docs/ai/tasks/release-v1.39.0.md):
 *     {id, event, occurred_at, workspace: {id, slug, name}, actor: {id, name}|null, data: {...}}
 * `data` holds plain arrays only (no models, no secrets, no internal-only fields such as rates).
 *
 * Foundation stub: a no-op until ticket 3 implements it. Keep the signature.
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

    public static function dispatch(Workspace $workspace, string $event, array $data, ?User $actor = null): void
    {
        if (! array_key_exists($event, self::EVENTS)) {
            throw new \InvalidArgumentException("Unknown domain event [{$event}] — add it to DomainEvents::EVENTS.");
        }

        // Ticket 3 (outgoing webhooks) queues one delivery per matching active webhook here.
    }
}
