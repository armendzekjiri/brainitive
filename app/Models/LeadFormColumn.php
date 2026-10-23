<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A column of a lead form's board; the first column (by position) receives new leads.
 */
class LeadFormColumn extends Model
{
    protected $fillable = ['lead_form_id', 'name', 'slug', 'color', 'position', 'is_done'];

    protected function casts(): array
    {
        return ['is_done' => 'boolean'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(LeadForm::class, 'lead_form_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'column_id')->orderBy('position')->orderByDesc('id');
    }
}
