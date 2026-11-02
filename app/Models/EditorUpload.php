<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image or document put into rich text (EditorUploads: web editor `/upload/*`, App API `POST /uploads`): the
 * stored file and its size, so it counts against its workspace's file storage (WorkspaceUsageMeter, Codex review).
 */
class EditorUpload extends Model
{
    protected $fillable = ['workspace_id', 'user_id', 'path', 'size'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
