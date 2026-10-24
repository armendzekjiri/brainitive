<?php

namespace App\Notifications;

use App\Models\Sprint;
use App\Models\User;

/** A sprint with issues assigned to you was completed (category: sprints). */
class SprintCompletedNotification extends CatalogNotification
{
    public function __construct(
        public readonly Sprint $sprint,
        public readonly ?User $actor,
        public readonly int $openCount,
        public readonly int $doneCount,
    ) {}

    public function catalogType(): string
    {
        return 'sprint_completed';
    }

    protected function message(object $notifiable): array
    {
        $board = $this->sprint->board;
        $project = $board?->project;

        return [
            'title' => ($this->actor ? "{$this->actor->name} completed" : 'Sprint completed:')." {$this->sprint->name}",
            'body' => "{$this->doneCount} done · {$this->openCount} open moved on",
            'url' => $board && $project
                ? route('boards.reports', [$project->workspace->slug, $project->slug, $board->slug, 'sprint' => $this->sprint->id])
                : url('/'),
            'actor' => $this->actor,
            'project_name' => $board && $project ? "{$project->name} · {$board->name}" : null,
            'subject_key' => null,
        ];
    }
}
