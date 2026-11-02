<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored workspace file and its size, so it counts against its workspace's file storage (WorkspaceUsageMeter).
 * `kind` null = an image or document put into rich text (EditorUploads: web editor `/upload/*`, App API
 * `POST /uploads`, Codex review); otherwise one of WorkspaceFiles::KINDS (logos, icons, bookmark files …, Codex web
 * review C). Attachments have their own tables with a size column and are never recorded here.
 */
class EditorUpload extends Model
{
    protected $fillable = ['workspace_id', 'user_id', 'path', 'size', 'kind'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
