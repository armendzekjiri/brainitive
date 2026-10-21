<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable snapshot of a plan document (autosave checkpoints, explicit saves, restores).
 */
class PlanVersion extends Model
{
    protected $fillable = [
        'plan_id',
        'version',
        'name',
        'data',
        'settings',
        'message',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'settings' => 'array',
            'version' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
