<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonInterface;

/** A ticket missed its SLA target (category: helpdesk, BRAI-125). Sent by app:check-helpdesk-sla. */
class TicketSlaBreachedNotification extends CatalogNotification
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
        return 'sla_breached';
    }

    protected function message(object $notifiable): array
    {
        return self::payload($this->ticket, $notifiable,
            $this->target === 'first_response' ? "{$this->ticket->key} missed its first-response target" : "{$this->ticket->key} missed its resolution target",
            'Was due '.self::localTime($this->dueAt, $notifiable),
        );
    }

    /** @return array{title: string, body: string, url: string, project_name: string|null, subject_key: string} */
    public static function payload(Ticket $ticket, object $notifiable, string $title, string $when): array
    {
        $helpdesk = $ticket->helpdesk;
        $project = $helpdesk?->project;

        return [
            'title' => $title,
            'body' => "{$when} · {$ticket->subject}",
            'url' => $helpdesk && $project
                ? route('tickets.show', [$project->workspace->slug, $project->slug, $helpdesk->slug, $ticket->id])
                : url('/'),
            'project_name' => $helpdesk && $project ? "{$project->name} · {$helpdesk->name}" : null,
            'subject_key' => $ticket->key,
        ];
    }

    /** "Mon, Nov 2, 14:30" in the recipient's time zone. */
    public static function localTime(CarbonInterface $at, object $notifiable): string
    {
        $timezone = $notifiable instanceof User ? $notifiable->localTimezone() : config('app.timezone');

        return $at->copy()->setTimezone($timezone)->format('D, M j, H:i');
    }
}
