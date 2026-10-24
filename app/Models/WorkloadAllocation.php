<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit planned effort: minutes of one issue for one person in one week (BRAI-75).
 * Written only through App\Support\Workload\WorkloadPlanner, never spread automatically.
 */
class WorkloadAllocation extends Model
{
    protected $fillable = ['project_id', 'item_id', 'user_id', 'week_start', 'minutes', 'estimate_snapshot', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['week_start' => 'date:Y-m-d', 'minutes' => 'integer', 'estimate_snapshot' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
