<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Snippet extends Model
{
    protected $fillable = [
        'collection_id',
        'created_by',
        'uuid',
        'slug',
        'title',
        'description',
        'language',
        'content',
        'visibility',
        'position',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Snippet $snippet) {
            if (empty($snippet->uuid)) {
                $snippet->uuid = (string) Str::uuid();
            }
            if (empty($snippet->slug)) {
                $base = Str::slug($snippet->title) ?: 'snippet';
                $slug = $base;
                $i = 1;
                while (
                    static::where('collection_id', $snippet->collection_id)
                        ->where('slug', $slug)
                        ->exists()
                ) {
                    $slug = $base.'-'.$i++;
                }
                $snippet->slug = $slug;
            }
        });
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(SnippetCollection::class, 'collection_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Resolved visibility: inherit falls back to collection's visibility. */
    public function resolvedVisibility(): string
    {
        if ($this->visibility === 'inherit') {
            return $this->collection?->visibility ?? 'private';
        }

        return $this->visibility;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
