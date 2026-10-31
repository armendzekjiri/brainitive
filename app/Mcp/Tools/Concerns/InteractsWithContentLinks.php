<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Board;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Boards\ItemLinks;
use App\Support\Mcp\ToolError;
use App\Support\References\ContentReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Resolve the endpoints of "Related content" and "Linked issues" (BRAI-432) from what an AI
 * has: an issue id or key, a doc uuid, a ticket id or key, a plan id or slug — always inside
 * the project, through ContentReferences::visibleEndpoint() (the same rule as the UI). Missing,
 * trashed, in another project or not visible all answer the same "not found".
 */
trait InteractsWithContentLinks
{
    /** Type names an AI may use → the stable type keys of ContentReference::TYPES. */
    private static array $contentTypeAliases = [
        'item' => 'item', 'issue' => 'item', 'card' => 'item',
        'page' => 'page', 'doc' => 'page', 'document' => 'page', 'whiteboard' => 'page',
        'ticket' => 'ticket',
        'plan' => 'plan',
    ];

    protected const CONTENT_TYPES_HELP = 'item (issue / board card), page (doc or whiteboard), ticket (helpdesk ticket), plan (Gantt plan)';

    /**
     * @throws ToolError
     */
    protected function contentType(string $type, string $field = 'type'): string
    {
        $key = self::$contentTypeAliases[mb_strtolower(trim($type))] ?? null;
        if ($key === null) {
            throw new ToolError("Unknown {$field} \"{$type}\". Valid types: ".self::CONTENT_TYPES_HELP.'. Task-list tasks, folders, boards and snippets can\'t be linked.');
        }

        return $key;
    }

    /** An id / key / uuid / slug argument as a string (clients send numbers or strings). */
    protected function contentRef(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A visible endpoint of the project by type + id / key / uuid / slug.
     *
     * @throws ToolError
     */
    protected function contentEndpoint(User $user, Project $project, string $type, string|int $ref, string $field = 'id'): Model
    {
        $type = $this->contentType($type, $field === 'id' ? 'type' : str_replace('_id', '_type', $field));
        $ref = trim((string) $ref);

        $id = $ref === '' ? null : (ctype_digit($ref) ? (int) $ref : $this->contentIdByHandle($project, $type, $ref));
        $model = $id ? ContentReferences::visibleEndpoint($user, $project, $type, $id) : null;

        if ($model === null) {
            $hint = match ($type) {
                'item' => 'Pass the card id (from get_board) or the issue key, e.g. '.($project->key ?: 'KEY').'-12.',
                'page' => 'Pass the doc / whiteboard uuid (from list_pages or search).',
                'ticket' => 'Pass the ticket id or key.',
                'plan' => 'Pass the plan id or slug (from list_plans).',
            };

            throw new ToolError("No {$type} \"{$ref}\" ({$field}) in this project that you can open. Links stay inside one project. {$hint}");
        }

        return $model;
    }

    /**
     * An issue of the project by id or key.
     *
     * @throws ToolError
     */
    protected function projectIssue(User $user, Project $project, string|int $ref, string $field): Item
    {
        /** @var Item $item */
        $item = $this->contentEndpoint($user, $project, 'item', $ref, $field);

        return $item;
    }

    /**
     * A "Linked issues" type; "is blocked by" / "is-blocked-by" are accepted too.
     *
     * @throws ToolError
     */
    protected function issueLinkType(string $type): string
    {
        $normalized = str_replace([' ', '-'], '_', mb_strtolower(trim($type)));
        if (! in_array($normalized, ItemLinks::types(), true)) {
            throw new ToolError("Unknown link_type \"{$type}\". Link types: ".implode(', ', ItemLinks::types()).'.');
        }

        return $normalized;
    }

    private function contentIdByHandle(Project $project, string $type, string $handle): ?int
    {
        $id = match ($type) {
            'item' => Item::query()
                ->whereIn('board_id', Board::query()->where('project_id', $project->id)->select('id'))
                ->whereRaw('upper(key) = ?', [mb_strtoupper($handle)])
                ->value('id'),
            'ticket' => Ticket::query()
                ->whereIn('helpdesk_id', Helpdesk::query()->where('project_id', $project->id)->select('id'))
                ->whereRaw('upper(key) = ?', [mb_strtoupper($handle)])
                ->value('id'),
            // a uuid column on Postgres: anything that isn't a uuid would be a query error
            'page' => Str::isUuid($handle) ? Page::query()->where('project_id', $project->id)->where('uuid', $handle)->value('id') : null,
            'plan' => Plan::query()->where('project_id', $project->id)->where('slug', $handle)->value('id'),
            default => null,
        };

        return $id === null ? null : (int) $id;
    }
}
