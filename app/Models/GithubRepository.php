<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A repository a project follows through the GitHub App (BRAI-61). `repo_id` is unique: one
 * repository feeds one project. `removed_at` = the installation lost access to it.
 */
class GithubRepository extends Model
{
    protected $fillable = ['github_connection_id', 'github_installation_id', 'repo_id', 'full_name', 'private', 'removed_at'];

    protected function casts(): array
    {
        return ['repo_id' => 'integer', 'private' => 'boolean', 'removed_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GithubConnection::class, 'github_connection_id');
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(GithubInstallation::class, 'github_installation_id');
    }
}
