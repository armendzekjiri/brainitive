<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One execution of an automation rule for one event (v1.40.0, BRAI-63). Unique per rule, rule
 * version and event id, so a duplicate delivery never runs twice. `results` holds one entry per
 * action ({action, label, status ok|skipped|error, message}); a retried run skips the actions that
 * already succeeded. `conditions` = the rule's conditions as last checked for it ({match, items:
 * [{label, passed}]}, BRAI-144; null on older runs).
 */
class AutomationRun extends Model
{
    use Prunable;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCESS = 'success';

    public const SKIPPED = 'skipped';

    public const ERROR = 'error';

    public const FINISHED = [self::SUCCESS, self::SKIPPED, self::ERROR];

    public const KEEP_DAYS = 30;

    protected $fillable = [
        'automation_rule_id',
        'project_id',
        'rule_version',
        'event_id',
        'trigger',
        'subject_type',
        'subject_id',
        'subject_label',
        'context',
        'conditions',
        'status',
        'message',
        'results',
        'attempts',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'conditions' => 'array',
            'results' => 'array',
            'rule_version' => 'integer',
            'subject_id' => 'integer',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, self::FINISHED, true);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
