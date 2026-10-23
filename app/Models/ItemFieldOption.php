<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A choice of a single-select custom field. Values point at the id, so renaming is free;
 * removing an option archives it (never deleted, values keep showing it).
 */
class ItemFieldOption extends Model
{
    protected $fillable = [
        'item_field_id',
        'label',
        'color',
        'position',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ItemField::class, 'item_field_id');
    }
}
