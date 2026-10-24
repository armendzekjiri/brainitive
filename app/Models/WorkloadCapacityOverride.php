<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Capacity for one person, project and week that replaces the default (leave = 0, part weeks).
 */
class WorkloadCapacityOverride extends Model
{
    protected $fillable = ['project_id', 'user_id', 'week_start', 'minutes', 'note', 'updated_by'];

    protected function casts(): array
    {
        return ['week_start' => 'date:Y-m-d', 'minutes' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
