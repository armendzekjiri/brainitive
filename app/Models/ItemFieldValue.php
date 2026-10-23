<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item's value for one custom field. Exactly one typed column is set, chosen by the
 * field's type; "no value" is no row at all.
 */
class ItemFieldValue extends Model
{
    /** Typed column per field type. */
    public const COLUMNS = [
        'text' => 'value_text',
        'number' => 'value_number',
        'date' => 'value_date',
        'checkbox' => 'value_bool',
        'select' => 'option_id',
    ];

    protected $fillable = [
        'item_id',
        'item_field_id',
        'value_text',
        'value_number',
        'value_date',
        'value_bool',
        'option_id',
    ];

    protected function casts(): array
    {
        return [
            'value_number' => 'float',
            'value_date' => 'date:Y-m-d',
            'value_bool' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ItemField::class, 'item_field_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ItemFieldOption::class, 'option_id');
    }
}
