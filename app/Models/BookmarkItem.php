<?php

namespace App\Models;

use App\Models\Concerns\GuardsContentGrowth;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookmarkItem extends Model
{
    use GuardsContentGrowth, HasFactory;

    protected $fillable = [
        'bookmark_id',
        'type',
        'title',
        'url',
        'file_path',
        'content',
        'metadata',
        'position',
        'is_completed',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_completed' => 'boolean',
        ];
    }

    public function bookmark(): BelongsTo
    {
        return $this->belongsTo(Bookmark::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Content limit (Codex web review B): edits that grow these columns need room. */
    public function growthColumns(): array
    {
        return ['title', 'url', 'content'];
    }

    public function meteredWorkspace(): ?Workspace
    {
        return $this->bookmark?->workspace;
    }
}
