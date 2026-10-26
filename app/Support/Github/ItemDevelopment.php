<?php

namespace App\Support\Github;

use App\Models\Item;
use App\Models\ItemDevLink;

/**
 * The "Development" section of an issue: what GitHub linked to it, newest first. Pull requests carry
 * `primary` (the one whose merge moves the card) and `merge_column` names the board's merge target.
 */
class ItemDevelopment
{
    private const LIMIT_PER_KIND = 30;

    /**
     * @return array{connected: bool, branches: list<array>, commits: list<array>, pull_requests: list<array>, deployments: list<array>, commit_count: int, merge_column: ?string}
     */
    public static function for(Item $item): array
    {
        $connected = $item->board?->project?->githubConnection()->exists() ?? false;
        $links = $item->devLinks()->orderByDesc('occurred_at')->get();
        $primaryId = $links->where('kind', 'pull_request')->sortBy([['primary', 'desc'], ['id', 'asc']])->first()?->id;
        $map = fn (string $kind) => $links->where('kind', $kind)->take(self::LIMIT_PER_KIND)->map(fn (ItemDevLink $l) => [
            'id' => $l->id,
            'repository' => $l->repository,
            'identifier' => $l->identifier,
            'title' => $l->title,
            'url' => $l->url,
            'state' => $l->state,
            'author' => $l->author,
            'occurred_at' => $l->occurred_at?->toIso8601String(),
        ] + ($kind === 'pull_request' ? ['primary' => $l->id === $primaryId] : []))->values()->all();

        return [
            'connected' => $connected,
            'branches' => $map('branch'),
            'commits' => $map('commit'),
            'commit_count' => $links->where('kind', 'commit')->count(),
            'pull_requests' => $map('pull_request'),
            'deployments' => $map('deployment'),
            'merge_column' => $item->board ? MergeTransitions::column($item->board)?->name : null,
        ];
    }
}
