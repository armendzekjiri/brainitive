<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A saved project status update (BRAI-64). The health status is chosen by a person — the
 * app never computes one. `sections` = {progress, risks, next_steps}: lists of
 * {text, sources: ["item:12", "plan:3", "metric:overdue"]}. `metrics` = the numbers at
 * `as_of` plus the listed issue ids (never titles; see ReportSources).
 */
class ProjectHealthReport extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['on_track', 'at_risk', 'off_track'];

    public const SECTIONS = ['progress', 'risks', 'next_steps'];

    protected $fillable = [
        'uuid',
        'project_id',
        'created_by',
        'status',
        'summary',
        'sections',
        'metrics',
        'as_of',
        'period_start',
        'period_end',
        'timezone',
        'ai_assisted',
    ];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'metrics' => 'array',
            'as_of' => 'datetime',
            'period_start' => 'date',
            'period_end' => 'date',
            'ai_assisted' => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ProjectHealthReport $report) {
            $report->uuid ??= (string) Str::uuid();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
