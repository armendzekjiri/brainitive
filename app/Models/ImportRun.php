<?php

namespace App\Models;

use App\Jobs\RunImport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One import attempt (v1.41.0, BRAI-65): upload → preview (dry run, no writes) → confirm (options)
 * → queued run (resumable via ImportMapping) → completed / failed. Bound by `uuid` in routes.
 * Contract: docs/ai/tasks/release-v1.41.0.md.
 */
class ImportRun extends Model
{
    use Prunable;

    public const UPLOADED = 'uploaded';

    public const PREVIEWED = 'previewed';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::UPLOADED, self::PREVIEWED, self::QUEUED, self::RUNNING, self::COMPLETED, self::FAILED, self::CANCELLED];

    /** Statuses in which the run can still be confirmed / retried. */
    public const STARTABLE = [self::PREVIEWED, self::FAILED];

    protected $fillable = [
        'workspace_id', 'project_id', 'user_id', 'source', 'status', 'file_path', 'file_name', 'file_size',
        'source_fingerprint', 'preview', 'options', 'report', 'result', 'progress_done', 'progress_total',
        'error', 'started_at', 'finished_at',
    ];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'preview' => 'array',
            'options' => 'array',
            'report' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ImportRun $run) {
            $run->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ImportMapping::class);
    }

    /** Runs are kept 180 days (the history of what came from where); their files go earlier (routes/console.php). */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(180))->whereNotIn('status', [self::QUEUED, self::RUNNING]);
    }

    protected function pruning(): void
    {
        RunImport::deleteFiles($this);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::CANCELLED], true);
    }
}
