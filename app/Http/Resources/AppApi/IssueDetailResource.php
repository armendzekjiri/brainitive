<?php

namespace App\Http\Resources\AppApi;

use App\Models\Item;
use App\Models\ItemField;
use App\Models\Sprint;
use App\Models\User;
use App\Support\Boards\IssueMarkdown;
use App\Support\Boards\ItemFieldValues;
use App\Support\Github\ItemDevelopment;
use Illuminate\Http\Request;

/**
 * One issue (`GET|POST|PATCH /items…`, BRAI-404): the card + description (Markdown, mentions
 * `@[Name](user:<id>)`), reporter, estimate / time spent, fix version, parent, sprint, the custom fields of
 * its board, GitHub development (read-only), whether the user watches it and what they may do. Load the
 * relations of `IssueDetailResource::WITH` (+ the card's).
 *
 * @mixin Item
 */
class IssueDetailResource extends IssueResource
{
    public const WITH = ['reporter', 'creator', 'release', 'parent', 'sprint', 'fieldValues'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $item = $this->resource;
        $canUpdate = $user->can('update', $item);
        $sprint = $item->sprint;

        return [
            ...parent::toArray($request),
            'description' => IssueMarkdown::toMarkdown($item->description),
            'reporter' => self::person($item->reporter),
            'creator' => self::person($item->creator),
            // minutes; set / shown as durations ("1h 30m", 1d = 8h) on the web
            'original_estimate_minutes' => $item->original_estimate === null ? null : (int) $item->original_estimate,
            'time_spent_minutes' => (int) $item->time_spent,
            'release' => $item->release ? [
                'id' => (int) $item->release->id,
                'name' => (string) $item->release->name,
                'status' => (string) $item->release->status,
            ] : null,
            'parent' => $item->parent ? [
                'id' => (int) $item->parent->id,
                'key' => $item->parent->key,
                'title' => (string) $item->parent->title,
            ] : null,
            'sprint' => $sprint instanceof Sprint ? [
                'id' => (int) $sprint->id,
                'name' => (string) $sprint->name,
                // planned | active | completed
                'status' => (string) $sprint->status,
            ] : null,
            'fields' => IssueFieldValueResource::collection($this->fields()),
            'watching' => $item->watchers()->whereKey($user->id)->exists(),
            // GitHub branches, commits, pull requests, deployments linked to the issue (read-only)
            'development' => $this->development(),
            'can' => [
                'update' => $canUpdate,
                'delete' => $user->can('delete', $item),
                // the web lets the people who may edit the issue comment on it
                'comment' => $canUpdate,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function development(): array
    {
        $dev = ItemDevelopment::for($this->resource);

        return [
            // the project has a GitHub repository connected
            'connected' => (bool) $dev['connected'],
            'branches' => DevLinkResource::collection($dev['branches']),
            'commits' => DevLinkResource::collection($dev['commits']),
            'commit_count' => (int) $dev['commit_count'],
            'pull_requests' => DevLinkResource::collection($dev['pull_requests']),
            'deployments' => DevLinkResource::collection($dev['deployments']),
            // the column a merged pull request moves the issue to (null = none)
            'merge_column' => is_string($dev['merge_column']) ? $dev['merge_column'] : null,
        ];
    }

    /** @return list<ItemField> */
    private function fields(): array
    {
        $item = $this->resource;
        $values = $item->fieldValues->keyBy('item_field_id');

        return ItemField::forBoard($item->board)
            ->map(fn (ItemField $field) => $field->setAttribute('issue_value', ItemFieldValues::valueOf($field, $values->get($field->id))))
            ->values()
            ->all();
    }

    /** @return array{id: int, name: string, avatar_url: string|null}|null */
    private static function person(?User $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }
        $avatar = $user->avatar_display;

        return ['id' => (int) $user->id, 'name' => (string) $user->name, 'avatar_url' => is_string($avatar) ? $avatar : null];
    }
}
