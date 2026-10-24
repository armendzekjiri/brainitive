<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Models\User;

/** A lead of a lead form was assigned to you (category: leads). */
class LeadAssignedNotification extends CatalogNotification
{
    public function __construct(
        public readonly Lead $lead,
        public readonly User $actor,
    ) {}

    public function catalogType(): string
    {
        return 'lead_assigned';
    }

    protected function message(object $notifiable): array
    {
        $form = $this->lead->form;
        $project = $form?->project;
        $name = $this->lead->name ?: ($this->lead->email ?: "Lead #{$this->lead->number}");

        return [
            'title' => "{$this->actor->name} assigned you lead #{$this->lead->number}",
            'body' => $name,
            'url' => $form && $project
                ? route('lead-forms.show', [$project->workspace->slug, $project->slug, $form->slug]).'?lead='.$this->lead->uuid
                : url('/'),
            'actor' => $this->actor,
            'project_name' => $form && $project ? "{$project->name} · {$form->name}" : null,
            'subject_key' => "#{$this->lead->number}",
        ];
    }
}
