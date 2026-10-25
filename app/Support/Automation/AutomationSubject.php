<?php

namespace App\Support\Automation;

use App\Models\Board;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What a rule acts on: a board issue or a helpdesk ticket (v1.40.0, BRAI-63). Small helpers so the
 * engine can treat both the same way (project, key, title, assignee).
 */
final class AutomationSubject
{
    public static function type(Model $model): ?string
    {
        return match (true) {
            $model instanceof Item => AutomationCatalog::SUBJECT_ITEM,
            $model instanceof Ticket => AutomationCatalog::SUBJECT_TICKET,
            default => null,
        };
    }

    public static function projectId(Item|Ticket $subject): ?int
    {
        $id = $subject instanceof Item
            ? ($subject->project_id ?: Board::withTrashed()->whereKey($subject->board_id)->value('project_id'))
            : Helpdesk::withTrashed()->whereKey($subject->helpdesk_id)->value('project_id');

        return $id ? (int) $id : null;
    }

    /** The live subject, or null when it was deleted or no longer belongs to the project. */
    public static function find(string $type, int $id, int $projectId): Item|Ticket|null
    {
        if ($type === AutomationCatalog::SUBJECT_TICKET) {
            $ticket = Ticket::find($id);

            return $ticket && Helpdesk::whereKey($ticket->helpdesk_id)->where('project_id', $projectId)->exists() ? $ticket : null;
        }

        $item = Item::find($id);

        return $item && Board::whereKey($item->board_id)->where('project_id', $projectId)->exists() ? $item : null;
    }

    /** Find by key ("WEB-12" / ticket "SUP-3", stored upper-case) inside the project, for the dry run. */
    public static function findByKey(string $type, string $key, int $projectId): Item|Ticket|null
    {
        $key = strtoupper(trim($key));
        if ($type === AutomationCatalog::SUBJECT_TICKET) {
            return Ticket::whereIn('helpdesk_id', Helpdesk::where('project_id', $projectId)->select('id'))
                ->where('key', $key)->first();
        }

        return Item::whereIn('board_id', Board::where('project_id', $projectId)->select('id'))
            ->where('key', $key)->first();
    }

    public static function title(Item|Ticket $subject): string
    {
        return (string) ($subject instanceof Item ? $subject->title : $subject->subject);
    }

    /** "WEB-12 Fix login" — the run log keeps this snapshot, so it stays readable after deletes. */
    public static function label(Item|Ticket $subject): string
    {
        return Str::limit(trim($subject->key.' '.self::title($subject)), 250);
    }

    /** {{item.key}} / {{item.title}} (and {{ticket.key}} / {{ticket.subject}}) in plain text. */
    public static function render(string $text, Item|Ticket $subject): string
    {
        $title = self::title($subject);

        return strtr($text, [
            '{{item.key}}' => (string) $subject->key,
            '{{item.title}}' => $title,
            '{{ticket.key}}' => (string) $subject->key,
            '{{ticket.subject}}' => $title,
        ]);
    }
}
