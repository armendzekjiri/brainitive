<?php

namespace App\Notifications;

use App\Models\Item;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Concerns\DescribesItem;

/**
 * "Notify" action of an automation rule (v1.40.0, BRAI-63; category: status). The rule's actor is
 * the actor; the body is the rule's message (placeholders already filled) or the subject's title.
 */
class AutomationNoticeNotification extends CatalogNotification
{
    use DescribesItem;

    public function __construct(
        public readonly Item|Ticket $subject,
        public readonly string $ruleName,
        public readonly ?string $text,
        public readonly ?User $actor,
    ) {}

    public function catalogType(): string
    {
        return 'automation_notice';
    }

    protected function message(object $notifiable): array
    {
        $key = (string) $this->subject->key;

        if ($this->subject instanceof Ticket) {
            $helpdesk = $this->subject->helpdesk;
            $project = $helpdesk?->project;

            return [
                'title' => "Automation \"{$this->ruleName}\": {$key}",
                'body' => $this->text ?: $this->subject->subject,
                'url' => $helpdesk && $project
                    ? route('tickets.show', [$project->workspace->slug, $project->slug, $helpdesk->slug, $this->subject->id])
                    : url('/'),
                'actor' => $this->actor,
                'project_name' => $project ? trim($project->name.' · '.$helpdesk->name) : null,
                'subject_key' => $key,
            ];
        }

        return [
            'title' => "Automation \"{$this->ruleName}\": {$key}",
            'body' => $this->text ?: $this->subject->title,
            'url' => $this->itemUrl($this->subject),
            'actor' => $this->actor,
            'project_name' => $this->itemContext($this->subject),
            'subject_key' => $key,
        ];
    }
}
