<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's declared default hours per week for one project (BRAI-75). No row = not set.
 */
class WorkloadCapacity extends Model
{
    protected $fillable = ['project_id', 'user_id', 'weekly_minutes', 'updated_by'];

    protected function casts(): array
    {
        return ['weekly_minutes' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
