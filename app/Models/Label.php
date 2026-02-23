<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Label extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'slug',
        'color',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Label $label) {
            if (empty($label->slug)) {
                $label->slug = Str::slug($label->name);
            }
        });
    }

    /**
     * Get the project this label belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get all items with this label.
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class);
    }
}
