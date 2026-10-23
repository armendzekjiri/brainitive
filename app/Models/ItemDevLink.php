<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A branch, commit, pull request or deployment connected to an issue through its key.
 */
class ItemDevLink extends Model
{
    public const KINDS = ['branch', 'commit', 'pull_request', 'deployment'];

    protected $fillable = ['item_id', 'github_connection_id', 'kind', 'repository', 'identifier', 'title', 'url', 'state', 'author', 'meta', 'occurred_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'occurred_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
