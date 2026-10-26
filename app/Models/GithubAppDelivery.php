<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery to the GitHub App's webhook, stored once per X-GitHub-Delivery (BRAI-61).
 * `github_connection_id` is set when it was routed to a project.
 */
class GithubAppDelivery extends Model
{
    use Prunable;

    protected $fillable = ['delivery_id', 'event', 'installation_id', 'github_connection_id', 'payload', 'status', 'error', 'processed_at'];

    protected function casts(): array
    {
        return ['installation_id' => 'integer', 'processed_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GithubConnection::class, 'github_connection_id');
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(14));
    }
}
