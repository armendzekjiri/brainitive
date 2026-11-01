<?php

namespace App\Support\Helpdesks;

use App\Models\HelpdeskColumn;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketReplyNotification;
use App\Notifications\TicketResolvedNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Support\Events\DomainEvents;
use App\Support\Events\EventPayloads;
use App\Support\Helpdesks\Mail\TicketMailer;

/**
 * An agent's changes to a ticket (helpdesk, the web's TicketController) shared with the App API (BRAI-420):
 * replies and internal notes (the customer emailed, watchers notified, webhooks), moving it to another
 * column (watchers, the resolution message, the customer's "resolved" email) and field changes (the new
 * assignee notified). Callers authorize (TicketPolicy::update) first.
 */
final class TicketActions
{
    /** A reply ($internal false: emailed to the customer) or an internal note; $html is the editor's HTML. */
    public static function reply(Ticket $ticket, User $agent, string $html, bool $internal): TicketReply
    {
        $reply = $ticket->replies()->create([
            'user_id' => $agent->id,
            'body' => $html,
            'is_internal_note' => $internal,
        ]);

        // Notify contact if reply is not internal. A helpdesk with a connected mailbox emails the
        // reply itself, threaded (BRAI-79) — then the notification would be a second mail.
        if (! $reply->is_internal_note && $ticket->contact && ! TicketMailer::queueReply($ticket, $reply)) {
            $ticket->contact->notify(new TicketReplyNotification($ticket, $reply, $agent));
        }

        // Notify other agents watching the ticket
        foreach ($ticket->watchers()->where('user_id', '!=', $agent->id)->get() as $watcher) {
            $watcher->notify(new TicketReplyNotification($ticket, $reply, $agent));
        }

        DomainEvents::emit($ticket->helpdesk->project->workspace, 'ticket.replied', fn () => EventPayloads::ticketReply($ticket, $reply)); // webhooks (BRAI-104)

        return $reply;
    }

    /**
     * Move the ticket to a column / position. Into a done column: the optional closing message is saved as a
     * reply, and $notifyContact emails the customer that it's resolved.
     */
    public static function move(Ticket $ticket, HelpdeskColumn $column, int $position, User $agent, ?string $resolutionMessage = null, bool $notifyContact = false): void
    {
        $wasDone = (bool) $ticket->column?->is_done_column;
        $oldColumn = $ticket->column;

        $ticket->moveToColumn($column, $position);

        // BRAI-103: watchers (except whoever moved it) hear about a status change
        if ($oldColumn?->id !== $column->id) {
            foreach ($ticket->watchers()->where('users.id', '!=', $agent->id)->get() as $watcher) {
                $watcher->notify(new TicketStatusChangedNotification($ticket, $agent, $oldColumn?->name, $column->name));
            }
        }

        if ($column->is_done_column && ! $wasDone) {
            $message = trim((string) $resolutionMessage);
            if ($message !== '') {
                $ticket->replies()->create([
                    'user_id' => $agent->id,
                    'body' => collect(preg_split('/\n{2,}/', $message))
                        ->map(fn (string $p) => '<p>'.nl2br(e(trim($p))).'</p>')
                        ->implode(''),
                    'is_internal_note' => false,
                ]);
            }
            if ($notifyContact && $ticket->contact) {
                $ticket->contact->notify(new TicketResolvedNotification($ticket, $message ?: null));
            }

            DomainEvents::emit($ticket->helpdesk->project->workspace, 'ticket.resolved', fn () => ['ticket' => EventPayloads::ticket($ticket), 'resolution' => $message !== '' ? $message : null]); // webhooks (BRAI-104)
        }
    }

    /**
     * Change subject / description / priority / assignee (only the given keys); a new assignee is notified.
     *
     * @param  array{subject?: string, description?: string|null, priority_id?: int|null, assigned_to?: int|null}  $changes
     */
    public static function update(Ticket $ticket, User $agent, array $changes): void
    {
        $oldAssigneeId = $ticket->assigned_to;
        $ticket->update($changes);

        $newAssigneeId = $ticket->assigned_to;
        if ($newAssigneeId && $newAssigneeId !== $oldAssigneeId && $newAssigneeId !== $agent->id) {
            User::find($newAssigneeId)?->notify(new TicketAssignedNotification($ticket, $agent));
        }
    }
}
