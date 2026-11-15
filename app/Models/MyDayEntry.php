<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a user's plan for a given local date (My Day BRAI-74, My Week v1.53.0). Either points
 * at a board item (`work_type = board`) or a task-list task (`list`) — never changing the task
 * itself — or is a free-form note (`note`: own `title`, `color`, `completed_at`, no `work_id`).
 */
class MyDayEntry extends Model
{
    /** Kinds of linked work (what the App API, offline sync and `MyDayPlan::resolve()` know). */
    public const TYPES = ['board', 'list'];

    public const NOTE = 'note';

    /** Colour dots a note can carry (null = plain circle). */
    public const COLORS = ['red', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink'];

    protected $fillable = ['user_id', 'work_type', 'work_id', 'plan_date', 'position', 'title', 'color', 'completed_at'];

    protected function casts(): array
    {
        return [
            'plan_date' => 'date:Y-m-d',
            'position' => 'integer',
            'work_id' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function isNote(): bool
    {
        return $this->work_type === self::NOTE;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
