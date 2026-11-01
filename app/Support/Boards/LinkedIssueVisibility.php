<?php

namespace App\Support\Boards;

use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Project;
use App\Models\User;

/**
 * Linked issues the viewer can't open show as "Restricted" (BRAI-435): the link and its type stay
 * (a card is still blocked), but the other issue's id, key, title and board are removed before the
 * items reach the browser. Same rule as the MCP tools (ContentLinkFormatter::issueLinks).
 *
 * Expects the links loaded with the other issue's `board:…,project_id`.
 */
class LinkedIssueVisibility
{
    /**
     * @param  iterable<Item>  $items
     */
    public static function mask(iterable $items, User $user): void
    {
        $access = [];
        $visible = function (?Item $other) use ($user, &$access): bool {
            $projectId = $other?->board?->project_id;
            if ($projectId === null) {
                return false;
            }
            if (! array_key_exists($projectId, $access)) {
                $project = Project::with('workspace')->find($projectId);
                $access[$projectId] = $project !== null && $user->hasProjectAccess($project);
            }

            return $access[$projectId];
        };

        foreach ($items as $item) {
            if ($item->relationLoaded('outgoingLinks')) {
                foreach ($item->outgoingLinks as $link) {
                    if (! $visible($link->targetItem)) {
                        self::restrict($link, 'targetItem', 'target_item_id');
                    }
                }
            }
            if ($item->relationLoaded('incomingLinks')) {
                foreach ($item->incomingLinks as $link) {
                    if (! $visible($link->sourceItem)) {
                        self::restrict($link, 'sourceItem', 'source_item_id');
                    }
                }
            }
        }
    }

    private static function restrict(ItemLink $link, string $relation, string $foreignKey): void
    {
        $link->setRelation($relation, null);
        $link->setAttribute('restricted', true);
        $link->makeHidden($foreignKey);
    }
}
