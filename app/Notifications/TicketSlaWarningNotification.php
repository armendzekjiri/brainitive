<?php

namespace App\Notifications;

use App\Models\Ticket;
use Carbon\CarbonInterface;

/** A ticket's SLA target is due soon (category: helpdesk, BRAI-125). Sent by app:check-helpdesk-sla. */
class TicketSlaWarningNotification extends CatalogNotification
{
    /**
     * @param  string  $target  first_response | resolution
     */
    public function __construct(
        public readonly Ticket $ticket,
        public readonly string $target,
        public readonly CarbonInterface $dueAt,
    ) {}

    public function catalogType(): string
    {
        return 'sla_warning';
    }

    protected function message(object $notifiable): array
    {
        return TicketSlaBreachedNotification::payload($this->ticket, $notifiable,
            $this->target === 'first_response' ? "{$this->ticket->key} needs a first response soon" : "{$this->ticket->key} needs to be resolved soon",
            'Due '.TicketSlaBreachedNotification::localTime($this->dueAt, $notifiable),
        );
    }
}
