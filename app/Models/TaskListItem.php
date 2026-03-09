<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TaskListItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'task_list_id',
        'title',
        'description',
        'status',
        'assignee_id',
        'due_date',
        'recurrence',
        'position',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'description' => 'array',
            'due_date' => 'date',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function taskList(): BelongsTo
    {
        return $this->belongsTo(TaskList::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Calculate the next due date based on recurrence rule.
     */
    public function nextDueDate(): ?Carbon
    {
        if (! $this->due_date || ! $this->recurrence) {
            return null;
        }

        $base = $this->due_date instanceof Carbon
            ? $this->due_date->copy()
            : Carbon::parse($this->due_date);

        return match ($this->recurrence) {
            'daily' => $base->addDay(),
            'weekly' => $base->addWeek(),
            'monthly' => $base->addMonth(),
            'yearly' => $base->addYear(),
            default => null,
        };
    }
}
