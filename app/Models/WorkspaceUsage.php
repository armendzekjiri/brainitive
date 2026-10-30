<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nightly measured usage of one workspace (usage:measure, Cloud plans v1.46.0). */
class WorkspaceUsage extends Model
{
    protected $fillable = ['workspace_id', 'members', 'projects', 'content_bytes', 'file_bytes', 'measured_at'];

    protected function casts(): array
    {
        return [
            'members' => 'integer',
            'projects' => 'integer',
            'content_bytes' => 'integer',
            'file_bytes' => 'integer',
            'measured_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
