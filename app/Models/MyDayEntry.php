<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One task on a user's My Day for a given local date (BRAI-74). Points at a board item
 * (`work_type = board`) or a task-list task (`list`); never changes the task itself.
 */
class MyDayEntry extends Model
{
    public const TYPES = ['board', 'list'];

    protected $fillable = ['user_id', 'work_type', 'work_id', 'plan_date', 'position'];

    protected function casts(): array
    {
        return ['plan_date' => 'date:Y-m-d', 'position' => 'integer', 'work_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
