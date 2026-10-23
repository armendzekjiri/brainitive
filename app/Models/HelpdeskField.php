<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class HelpdeskField extends Model
{
    use HasFactory;

    protected $fillable = [
        'helpdesk_id',
        'name',
        'label',
        'type',
        'options',
        'is_required',
        'position',
        'is_visible_on_card',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_required' => 'boolean',
            'is_visible_on_card' => 'boolean',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (HelpdeskField $field) {
            if (empty($field->name)) {
                $field->name = Str::slug($field->label);
            }
        });
    }

    /**
     * Get the helpdesk this field belongs to.
     */
    public function helpdesk(): BelongsTo
    {
        return $this->belongsTo(Helpdesk::class);
    }

    /**
     * Get all field values for this field.
     */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(TicketFieldValue::class);
    }
}
