<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The running focus timer of a user (at most one: `user_id` is unique). Elapsed time is
 * computed from `started_at`, so it survives reloads, tabs and devices (BRAI-74).
 */
class WorkTimer extends Model
{
    protected $fillable = ['user_id', 'item_id', 'started_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * What the top-bar pill needs. Shared on every page, so it is one query, and the issue is
     * only shown while the user can still open it (otherwise `item` is null: stop/discard only).
     */
    public function payload(User $user): array
    {
        $item = Item::query()
            ->join('boards', 'boards.id', '=', 'items.board_id')
            ->join('projects', 'projects.id', '=', 'boards.project_id')
            ->join('workspaces', 'workspaces.id', '=', 'projects.workspace_id')
            ->whereKey($this->item_id)
            ->whereNull('boards.deleted_at')
            ->whereNull('projects.deleted_at')
            ->whereIn('projects.id', $user->accessibleProjectIds())
            ->first(['items.id', 'items.key', 'items.title', 'boards.slug as board_slug', 'projects.slug as project_slug', 'workspaces.slug as workspace_slug']);

        return [
            'id' => $this->id,
            'started_at' => $this->started_at->toIso8601String(),
            'elapsed_seconds' => (int) $this->started_at->diffInSeconds(now()),
            'elapsed_minutes' => $this->elapsedMinutes(),
            'item' => $item ? [
                'id' => $item->id,
                'key' => $item->key,
                'title' => $item->title,
                'url' => route('boards.items.show', [$item->workspace_slug, $item->project_slug, $item->board_slug, $item->id]),
            ] : null,
        ];
    }

    /** Whole minutes since the start, at least 1 (what the review dialog suggests). */
    public function elapsedMinutes(): int
    {
        return max(1, (int) round($this->started_at->diffInSeconds(now()) / 60));
    }
}
