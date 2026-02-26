<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SnippetCollection extends Model
{
    protected $fillable = [
        'workspace_id',
        'created_by',
        'uuid',
        'slug',
        'name',
        'description',
        'icon',
        'visibility',
        'position',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SnippetCollection $collection) {
            if (empty($collection->uuid)) {
                $collection->uuid = (string) Str::uuid();
            }
            if (empty($collection->slug)) {
                $collection->slug = Str::slug($collection->name);
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function snippets(): HasMany
    {
        return $this->hasMany(Snippet::class, 'collection_id')->orderBy('position');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
