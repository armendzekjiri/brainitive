<?php

namespace App\Notifications\Concerns;

use App\Models\Item;

/** Link and context line ("Website · Main Board") of a board issue, for catalog notifications. */
trait DescribesItem
{
    protected function itemUrl(Item $item): string
    {
        $board = $item->board;
        $project = $board?->project;
        $workspace = $project?->workspace;

        return $workspace && $project && $board
            ? route('boards.items.show', [$workspace->slug, $project->slug, $board->slug, $item->id])
            : url('/');
    }

    protected function itemContext(Item $item): ?string
    {
        $board = $item->board;

        return $board ? trim(($board->project?->name ? $board->project->name.' · ' : '').$board->name) : null;
    }
}
