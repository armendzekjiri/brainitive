<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable snapshot of a page, written each time it is published.
 */
class PageVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'page_id',
        'version',
        'name',
        'icon',
        'content',
        'message',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'version' => 'integer',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
