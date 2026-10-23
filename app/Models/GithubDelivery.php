<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GithubDelivery extends Model
{
    use Prunable;

    protected $fillable = ['github_connection_id', 'delivery_id', 'event', 'payload', 'status', 'error', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
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
