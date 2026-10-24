<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;

/** A ticket you watch moved to another status column (category: helpdesk). */
class TicketStatusChangedNotification extends CatalogNotification
{
    public function __construct(
        public readonly Ticket $ticket,
        public readonly User $actor,
        public readonly ?string $oldStatus,
        public readonly string $newStatus,
    ) {}

    public function catalogType(): string
    {
        return 'ticket_status_changed';
    }

    protected function message(object $notifiable): array
    {
        $helpdesk = $this->ticket->helpdesk;
        $project = $helpdesk?->project;

        return [
            'title' => "{$this->actor->name} moved {$this->ticket->key} to {$this->newStatus}",
            'body' => ($this->oldStatus ? "{$this->oldStatus} → {$this->newStatus} · " : '').$this->ticket->subject,
            'url' => $helpdesk && $project
                ? route('tickets.show', [$project->workspace->slug, $project->slug, $helpdesk->slug, $this->ticket->id])
                : url('/'),
            'actor' => $this->actor,
            'project_name' => $helpdesk && $project ? "{$project->name} · {$helpdesk->name}" : null,
            'subject_key' => $this->ticket->key,
        ];
    }
}
