<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "create a project from a template" request, keyed by the client's request_id so a
 * retried submit returns the project that was already created instead of making a second.
 */
class ProjectTemplateRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'request_id',
        'user_id',
        'workspace_id',
        'template',
        'status',
        'project_id',
        'error',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
