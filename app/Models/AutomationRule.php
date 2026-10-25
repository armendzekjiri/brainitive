<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A project's WHEN / IF / THEN rule (v1.40.0, BRAI-63). The definition (trigger, trigger_config,
 * conditions, actions) is written only through AutomationDefinition::validate(); a change to it bumps
 * `version`, so runs created for the old definition are skipped. The rule runs as `actor_id`.
 */
class AutomationRule extends Model
{
    public const MAX_PER_PROJECT = 50;

    protected $fillable = [
        'project_id',
        'name',
        'enabled',
        'version',
        'actor_id',
        'created_by',
        'trigger',
        'trigger_config',
        'conditions',
        'actions',
        'last_run_at',
        'last_run_status',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'version' => 'integer',
            'trigger_config' => 'array',
            'conditions' => 'array',
            'actions' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /** The hook points' one cheap check. */
    public function scopeEnabledFor(Builder $query, int $projectId, string $trigger): Builder
    {
        return $query->where('project_id', $projectId)->where('trigger', $trigger)->where('enabled', true);
    }

    /** @return array{trigger: string, trigger_config: array, conditions: array, actions: array} */
    public function definition(): array
    {
        return [
            'trigger' => $this->trigger,
            'trigger_config' => $this->trigger_config ?? [],
            'conditions' => $this->conditions ?? [],
            'actions' => $this->actions ?? [],
        ];
    }
}
