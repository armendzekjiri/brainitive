<?php

namespace App\Notifications;

use App\Models\Sprint;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** A sprint with issues assigned to you started (category: sprints). */
class SprintStartedNotification extends CatalogNotification
{
    public function __construct(
        public readonly Sprint $sprint,
        public readonly ?User $actor,
        public readonly int $openCount,
        public readonly int $doneCount,
    ) {}

    public function catalogType(): string
    {
        return 'sprint_started';
    }

    protected function digestSubject(): ?Model
    {
        return $this->sprint->board;
    }

    protected function message(object $notifiable): array
    {
        $board = $this->sprint->board;
        $project = $board?->project;
        $ends = $this->sprint->end_date ? ' · ends '.$this->sprint->end_date->format('M j') : '';

        return [
            'title' => ($this->actor ? "{$this->actor->name} started" : 'Sprint started:')." {$this->sprint->name}",
            'body' => "{$this->openCount} open · {$this->doneCount} done{$ends}",
            'url' => $board && $project
                ? route('boards.show', [$project->workspace->slug, $project->slug, $board->slug])
                : url('/'),
            'actor' => $this->actor,
            'project_name' => $board && $project ? "{$project->name} · {$board->name}" : null,
            'subject_key' => null,
        ];
    }
}
