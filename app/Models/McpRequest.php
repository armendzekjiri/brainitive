<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/** A finished MCP write, kept 7 days so retries with the same request_id are answered once. */
class McpRequest extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'tool', 'request_id', 'payload_hash', 'result'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(7));
    }
}
