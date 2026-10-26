<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * A change sent from a device's offline outbox (BRAI-82), kept 30 days so a retry is answered
 * with the stored outcome instead of being applied again. Only final outcomes are stored
 * (applied = 200, conflict = 409); rejected changes wrote nothing and may simply be re-checked.
 */
class OfflineMutation extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'client_id', 'type', 'payload_hash', 'status', 'entity_type', 'entity_id', 'response'];

    protected function casts(): array
    {
        return ['response' => 'array', 'status' => 'integer', 'entity_id' => 'integer'];
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(30));
    }
}
