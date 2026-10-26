<?php

namespace App\Support\Automation;

use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Label;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\AutomationNoticeNotification;
use App\Notifications\ItemStatusChangedNotification;
use App\Notifications\TicketAssignedNotification;
use App\Support\Boards\ItemComments;
use App\Support\Boards\ProjectIssues;
use App\Support\References\ContentReferences;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * THEN: what each action does (v1.40.0, BRAI-63). plan() checks an action against the subject and the
 * actor's CURRENT permissions and returns what would happen plus, when there is something to write, a
 * closure that writes it. The dry run only reads the plan; AutomationRunner calls the closure inside
 * a transaction together with saving the action's result.
 *
 * Writes go through the same paths as the UI (model saves → ItemObserver history + "assigned you",
 * ItemComments::add, ProjectIssues::create), so automation changes notify like any other change.
 */
final class AutomationActions
{
    public const OK = 'ok';

    public const SKIPPED = 'skipped';

    public const ERROR = 'error';

    /**
     * @param  array{type: string, value: mixed, text?: string|null}  $action
     * @return array{status: string, message: string, apply: ?Closure}
     */
    public static function plan(array $action, Item|Ticket $subject, User $actor, Project $project, string $ruleName): array
    {
        $value = $action['value'] ?? null;

        return match ($action['type']) {
            'assign' => self::assign($subject, $value, $actor, $project),
            'move' => self::move($subject, (int) $value, $actor, $project),
            'set_priority' => self::setPriority($subject, (int) $value, $actor, $project),
            'set_type' => self::setType($subject, (int) $value, $actor, $project),
            'set_due_date' => self::setDueDate($subject, (int) $value, $actor),
            'add_label' => self::addLabel($subject, (int) $value, $actor, $project),
            'comment' => self::comment($subject, (string) ($action['text'] ?? ''), $actor),
            'notify' => self::notify($subject, $value, $action['text'] ?? null, $actor, $project, $ruleName),
            'create_issue' => self::createIssue($subject, (int) $value, $actor, $project),
            default => self::result(self::SKIPPED, 'Unknown action.'),
        };
    }

    public static function describe(array $action, AutomationOptions $options, string $subjectType): string
    {
        $spec = AutomationCatalog::ACTIONS[$action['type']] ?? null;
        if (! $spec) {
            return $action['type'];
        }
        $label = $spec['label'];
        if ($spec['value'] !== null) {
            $label .= ' '.($options->name($spec['value'], $action['value'] ?? null, $subjectType) ?? '(deleted)');
        }
        if (! empty($action['text'])) {
            $label .= ': "'.Str::limit($action['text'], 60).'"';
        }

        return $label;
    }

    private static function result(string $status, string $message, ?Closure $apply = null): array
    {
        return ['status' => $status, 'message' => $message, 'apply' => $apply];
    }

    private static function denied(User $actor, Item|Ticket $subject): array
    {
        $what = $subject instanceof Item ? 'issue' : 'ticket';

        return self::result(self::ERROR, "{$actor->name} may no longer edit this {$what}.");
    }

    private static function itemOnly(Item|Ticket $subject): ?array
    {
        return $subject instanceof Item ? null : self::result(self::SKIPPED, 'Only issues support this action.');
    }

    private static function assign(Item|Ticket $subject, mixed $value, User $actor, Project $project): array
    {
        if (Gate::forUser($actor)->denies('update', $subject)) {
            return self::denied($actor, $subject);
        }
        if ($value === 'reporter') {
            if (! $subject instanceof Item) {
                return self::result(self::SKIPPED, 'Tickets have no reporter in the team.');
            }
            $value = $subject->reporter_id ?? $subject->created_by;
            if (! $value) {
                return self::result(self::SKIPPED, 'The issue has no reporter.');
            }
        }

        $assignee = User::find((int) $value);
        if (! $assignee) {
            return self::result(self::ERROR, 'That person no longer exists.');
        }
        if (! $assignee->hasProjectAccess($project)) {
            return self::result(self::ERROR, "{$assignee->name} can no longer open this project.");
        }
        if ((int) $subject->assigned_to === $assignee->id) {
            return self::result(self::OK, "Already assigned to {$assignee->name}.");
        }

        return self::result(self::OK, "Assigned to {$assignee->name}.", function () use ($subject, $assignee, $actor) {
            $subject->update(['assigned_to' => $assignee->id]); // items: ItemObserver → history + Assignments::item()
            if ($subject instanceof Ticket) {
                // also when the rule acts as the assignee: the automation assigned, not them (v1.41.2)
                $assignee->notify(new TicketAssignedNotification($subject, $actor, AutomationRunner::ruleName()));
            }
        });
    }

    private static function move(Item|Ticket $subject, int $columnId, User $actor, Project $project): array
    {
        if ($skip = self::itemOnly($subject)) {
            return $skip;
        }
        /** @var Item $subject */
        $column = BoardColumn::find($columnId);
        if (! $column) {
            return self::result(self::SKIPPED, 'The column was deleted.');
        }
        if ((int) $column->board_id !== (int) $subject->board_id) {
            $board = Board::whereKey($column->board_id)->value('name');

            return self::result(self::SKIPPED, "\"{$column->name}\" is on another board".($board ? " ({$board})" : '').'.');
        }
        if (Gate::forUser($actor)->denies('update', $subject)) {
            return self::denied($actor, $subject);
        }
        if ((int) $subject->column_id === $column->id) {
            return self::result(self::OK, "Already in {$column->name}.");
        }

        return self::result(self::OK, "Moved to {$column->name}.", function () use ($subject, $column, $actor, $project) {
            $previous = $subject->column;
            $wasDone = (bool) $previous?->is_done_column;
            // like BulkItemEdit: bottom of the column; completed when entering done, reopened when leaving
            $subject->column_id = $column->id;
            $subject->position = (int) (Item::where('column_id', $column->id)->max('position') ?? 0) + 1;
            $subject->completed_at = $column->is_done_column ? ($wasDone ? $subject->completed_at : now()) : null;
            $subject->save();
            $subject->setRelation('column', $column);

            if ($previous) {
                foreach ($subject->watchers as $watcher) {
                    if ($watcher->id !== $actor->id && $watcher->hasProjectAccess($project)) {
                        $watcher->notify(new ItemStatusChangedNotification(item: $subject, oldColumn: $previous, newColumn: $column, actor: $actor));
                    }
                }
            }
        });
    }

    private static function setPriority(Item|Ticket $subject, int $priorityId, User $actor, Project $project): array
    {
        $priority = ItemPriority::forProject($project)->whereKey($priorityId)->first();
        if (! $priority) {
            return self::result(self::SKIPPED, 'The priority was deleted.');
        }
        if (Gate::forUser($actor)->denies('update', $subject)) {
            return self::denied($actor, $subject);
        }
        if ((int) $subject->priority_id === $priority->id) {
            return self::result(self::OK, "Priority is already {$priority->name}.");
        }

        return self::result(self::OK, "Priority set to {$priority->name}.", fn () => $subject->update(['priority_id' => $priority->id]));
    }

    private static function setType(Item|Ticket $subject, int $typeId, User $actor, Project $project): array
    {
        if ($skip = self::itemOnly($subject)) {
            return $skip;
        }
        $type = ItemType::forProject($project)->whereKey($typeId)->first();
        if (! $type) {
            return self::result(self::SKIPPED, 'The issue type was deleted or switched off.');
        }
        if ($type->slug === 'subtask' && ! $subject->parent_id) {
            return self::result(self::SKIPPED, 'Only subtasks can have the Subtask type.');
        }
        if (Gate::forUser($actor)->denies('update', $subject)) {
            return self::denied($actor, $subject);
        }
        if ((int) $subject->item_type_id === $type->id) {
            return self::result(self::OK, "Type is already {$type->name}.");
        }

        return self::result(self::OK, "Type set to {$type->name}.", fn () => $subject->update(['item_type_id' => $type->id]));
    }

    private static function setDueDate(Item|Ticket $subject, int $days, User $actor): array
    {
        if ($skip = self::itemOnly($subject)) {
            return $skip;
        }
        if (Gate::forUser($actor)->denies('update', $subject)) {
            return self::denied($actor, $subject);
        }
        // a wall-clock date, counted from today in the actor's time zone
        $date = now($actor->localTimezone())->startOfDay()->addDays($days)->toDateString();
        if ($subject->due_date?->toDateString() === $date) {
            return self::result(self::OK, "Due date is already {$date}.");
        }

        return self::result(self::OK, "Due date set to {$date}.", fn () => $subject->update(['due_date' => $date]));
    }

    private static function addLabel(Item|Ticket $subject, int $labelId, User $actor, Project $project): array
    {
        if ($skip = self::itemOnly($subject)) {
            return $skip;
        }
        $label = Label::where('project_id', $project->id)->whereKey($labelId)->first();
        if (! $label) {
            return self::result(self::SKIPPED, 'The label was deleted.');
        }
        if (Gate::forUser($actor)->denies('update', $subject)) {
            return self::denied($actor, $subject);
        }
        if ($subject->labels()->where('labels.id', $label->id)->exists()) {
            return self::result(self::OK, "Already labelled {$label->name}.");
        }

        return self::result(self::OK, "Label {$label->name} added.", function () use ($subject, $label) {
            $subject->labels()->attach($label->id);
            ActivityLog::log($subject, 'labels_changed', ['labels' => ['added' => [$label->name], 'removed' => []]]);
        });
    }

    private static function comment(Item|Ticket $subject, string $text, User $actor): array
    {
        if ($skip = self::itemOnly($subject)) {
            return $skip;
        }
        if (trim($text) === '') {
            return self::result(self::SKIPPED, 'The comment is empty.');
        }
        if (Gate::forUser($actor)->denies('comment', $subject)) {
            return self::result(self::ERROR, "{$actor->name} may no longer comment on this issue.");
        }
        $plain = AutomationSubject::render($text, $subject);
        $html = '<p>'.nl2br(e($plain), false).'</p>';

        return self::result(self::OK, 'Comment added: "'.Str::limit($plain, 80).'"', function () use ($subject, $actor, $html) {
            ItemComments::add($subject, $actor, $html); // history, mentions, item_commented, webhooks
        });
    }

    private static function notify(Item|Ticket $subject, mixed $value, ?string $text, User $actor, Project $project, string $ruleName): array
    {
        $recipientId = $value === 'assignee' ? $subject->assigned_to : $value;
        if (! $recipientId) {
            return self::result(self::SKIPPED, 'Nobody is assigned, so nobody was notified.');
        }
        $recipient = User::find((int) $recipientId);
        if (! $recipient) {
            return self::result(self::ERROR, 'That person no longer exists.');
        }
        if (! $recipient->hasProjectAccess($project)) {
            return self::result(self::SKIPPED, "{$recipient->name} can no longer open this project.");
        }
        $body = $text !== null && trim($text) !== '' ? AutomationSubject::render($text, $subject) : null;

        return self::result(self::OK, "Notified {$recipient->name}.", function () use ($recipient, $subject, $ruleName, $body, $actor) {
            $recipient->notify(new AutomationNoticeNotification($subject, $ruleName, $body, $actor));
        });
    }

    private static function createIssue(Item|Ticket $subject, int $boardId, User $actor, Project $project): array
    {
        if (! $subject instanceof Ticket) {
            return self::result(self::SKIPPED, 'Only tickets can create a linked issue.');
        }
        $board = Board::where('project_id', $project->id)->whereKey($boardId)->first();
        if (! $board) {
            return self::result(self::SKIPPED, 'The board was deleted.');
        }
        if (Gate::forUser($actor)->denies('createItems', $board)) {
            return self::result(self::ERROR, "{$actor->name} may no longer create issues on {$board->name}.");
        }
        if (! $board->columns()->exists()) {
            return self::result(self::SKIPPED, "{$board->name} has no columns.");
        }

        return self::result(self::OK, "Issue created on {$board->name}.", function () use ($subject, $board, $actor, $project) {
            $item = ProjectIssues::create($board, $project, $actor, [[
                'title' => Str::limit((string) $subject->subject, 250, ''),
                'description' => $subject->description,
            ]])->first();
            ContentReferences::link($project, $actor, $item, $subject);

            return "Created {$item->key} on {$board->name}.";
        });
    }
}
