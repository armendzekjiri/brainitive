<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved board view (BRAI-78): the board's URL view params under a name, personal (only its
 * owner sees it) or shared with everyone who can open the board. See BoardViewConfig.
 */
class BoardView extends Model
{
    protected $fillable = ['board_id', 'user_id', 'name', 'is_shared', 'config', 'position'];

    protected function casts(): array
    {
        return ['config' => 'array', 'is_shared' => 'boolean'];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Views this user may see on the board: their own personal views + all shared ones. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn ($q) => $q->where('is_shared', true)->orWhere('user_id', $user->id));
    }

    /** @return array<string, string> */
    public function params(): array
    {
        return (array) ($this->config['params'] ?? []);
    }
}
