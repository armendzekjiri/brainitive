<?php

namespace App\Notifications;

use App\Models\TaskListItem;
use App\Models\User;

/** A task-list task was assigned to you by someone else (category: assignments). */
class TaskAssignedNotification extends CatalogNotification
{
    public function __construct(
        public readonly TaskListItem $task,
        public readonly User $actor,
    ) {}

    public function catalogType(): string
    {
        return 'task_assigned';
    }

    protected function message(object $notifiable): array
    {
        $list = $this->task->taskList;
        $project = $list?->project;
        $workspace = $project?->workspace;

        return [
            'title' => "{$this->actor->name} assigned you a task".($list ? " in {$list->name}" : ''),
            'body' => $this->task->title,
            'url' => $list && $project && $workspace
                ? route('task-lists.show', [$workspace->slug, $project->slug, $list->slug]).'#task-'.$this->task->id
                : url('/'),
            'actor' => $this->actor,
            'project_name' => $project && $list ? "{$project->name} · {$list->name}" : null,
            'subject_key' => $project ? "{$project->key}-L{$this->task->id}" : null,
        ];
    }
}
