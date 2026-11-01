<?php

namespace App\Support\Mcp;

use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Boards\ItemLinks;
use App\Support\References\ContentReferences;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\ArrayType;

/**
 * Compact MCP shape of "Related content", "Referenced by" and "Linked issues" (BRAI-432) for
 * list_content_links, get_board_item and get_page.
 *
 * Related content / referenced by come from ContentReferences::forEndpoint(), so what the user
 * can't see is left out exactly as in the UI (a private draft never shows up, not even as a
 * count). A linked issue the user can't open (an old link into another project) is listed as
 * `restricted` without key, title or url.
 */
class ContentLinkFormatter
{
    /**
     * @return array{related: list<array<string, mixed>>, referenced_by: list<array<string, mixed>>, can_link: bool}
     */
    public static function references(Workspace $workspace, Project $project, User $user, Model $model): array
    {
        $project->setRelation('workspace', $workspace);
        $links = ContentReferences::forEndpoint($workspace, $project, $user, $model);

        $pageIds = [];
        foreach ([...$links['related'], ...$links['referenced_by']] as $entry) {
            if ($entry['type'] === 'page') {
                $pageIds[] = $entry['id'];
            }
        }
        $uuids = $pageIds === [] ? [] : Page::query()->whereKey($pageIds)->pluck('uuid', 'id')->all();

        $compact = fn (array $entry) => [
            'type' => $entry['type'],
            'kind' => $entry['label'],
            'id' => $entry['id'],
            'key' => (string) ($entry['subtitle'] ?? ''),
            'uuid' => $entry['type'] === 'page' ? (string) ($uuids[$entry['id']] ?? '') : '',
            'title' => $entry['title'],
            'url' => $entry['url'],
            'status' => (string) ($entry['status'] ?? ''),
            'origin' => $entry['manual'] && $entry['derived'] ? 'manual+derived' : ($entry['manual'] ? 'manual' : 'derived'),
            'derived_from' => (string) ($entry['derived_label'] ?? ''),
            'can_remove' => (bool) $entry['can_remove'],
        ];

        return [
            'related' => array_map($compact, $links['related']),
            'referenced_by' => array_map($compact, $links['referenced_by']),
            'can_link' => (bool) $links['can_link'],
        ];
    }

    /**
     * The issue's links in both directions, read from its side ("is blocked by" for an
     * incoming "blocks"). Links to trashed issues are left out (as related content does).
     *
     * @return list<array<string, mixed>>
     */
    public static function issueLinks(Item $item, User $user): array
    {
        $with = fn (string $relation) => [$relation => fn ($q) => $q->with('board.project.workspace')];
        $links = [
            ...$item->outgoingLinks()->with($with('targetItem'))->orderBy('id')->get()->all(),
            ...$item->incomingLinks()->with($with('sourceItem'))->orderBy('id')->get()->all(),
        ];

        $entries = [];
        foreach ($links as $link) {
            /** @var ItemLink $link */
            $outgoing = $link->source_item_id === $item->id;
            $other = $outgoing ? $link->targetItem : $link->sourceItem;
            if (! $other instanceof Item || $other->board === null) {
                continue;
            }
            $type = ItemLinks::typeFor($link, $item);
            $visible = $user->can('view', $other);

            $entries[] = [
                'link_id' => (int) $link->id,
                'link_type' => $type,
                'label' => ItemLink::getLinkTypeLabel($type),
                'direction' => $outgoing ? 'outgoing' : 'incoming',
                'restricted' => ! $visible,
                'id' => $visible ? (int) $other->id : 0,
                'key' => $visible ? (string) ($other->key ?? '') : '',
                'title' => $visible ? (string) ($other->title ?: $other->key) : '',
                'url' => $visible ? ResourceLocator::locate($other)['url'] : '',
            ];
        }

        return $entries;
    }

    /**
     * ResourceLocator::locate() for an endpoint from ContentReferences::resolve(), whose board /
     * helpdesk is loaded with a few columns only (no name for the breadcrumb).
     *
     * @return array<string, string>
     */
    public static function locate(Model $model): array
    {
        $copy = clone $model;
        foreach (['board', 'helpdesk'] as $relation) {
            $copy->unsetRelation($relation);
        }

        return ResourceLocator::locate($copy);
    }

    /**
     * Schema of one related content / referenced by entry.
     */
    public static function referenceSchema(JsonSchema $schema): ArrayType
    {
        return $schema->array()->items($schema->object([
            'type' => $schema->string()->description('item (issue), page (doc or whiteboard), ticket or plan.'),
            'kind' => $schema->string()->description('Issue, Doc, Whiteboard, Ticket or Plan.'),
            'id' => $schema->integer(),
            'key' => $schema->string()->description('Issue / ticket key, else empty.'),
            'uuid' => $schema->string()->description('Doc / whiteboard uuid, else empty.'),
            'title' => $schema->string(),
            'url' => $schema->string(),
            'status' => $schema->string()->description('draft or archived for docs, else empty.'),
            'origin' => $schema->string()->description('manual (added by a person), derived (plan row, whiteboard card or smart link — change it there) or manual+derived.'),
            'derived_from' => $schema->string()->description('Plan row, Whiteboard card or Smart link for derived links, else empty.'),
            'can_remove' => $schema->boolean(),
        ]));
    }

    /**
     * Schema of one linked issue entry.
     */
    public static function issueLinkSchema(JsonSchema $schema): ArrayType
    {
        return $schema->array()->items($schema->object([
            'link_id' => $schema->integer(),
            'link_type' => $schema->string()->description('From this issue\'s side: blocks, is_blocked_by, duplicates, is_duplicated_by, relates_to, clones, is_cloned_by.'),
            'label' => $schema->string(),
            'direction' => $schema->string()->description('outgoing (added on this issue) or incoming (added on the other one).'),
            'restricted' => $schema->boolean()->description('True when you can\'t open the other issue: id 0, no key, title or url.'),
            'id' => $schema->integer(),
            'key' => $schema->string(),
            'title' => $schema->string(),
            'url' => $schema->string(),
        ]));
    }
}
