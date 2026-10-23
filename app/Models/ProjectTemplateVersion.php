<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable snapshot of a project template. `document` is the TemplateDocument JSON
 * (local refs + day offsets); `summary` is what the template picker shows.
 */
class ProjectTemplateVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'project_template_id',
        'version',
        'format',
        'document',
        'summary',
        'source_project_id',
        'created_by',
    ];

    /** The document is only read when a project is created from it. */
    protected $hidden = ['document'];

    protected function casts(): array
    {
        return [
            'document' => 'array',
            'summary' => 'array',
            'version' => 'integer',
            'format' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProjectTemplate::class, 'project_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
