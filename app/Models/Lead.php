<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One submission of a lead form, shown as a card on the form's board. `answers` holds every
 * answer by field id; name / email / phone / company are copied from fields mapped to them.
 */
class Lead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'lead_form_id', 'column_id', 'number', 'name', 'email', 'phone', 'company',
        'answers', 'source', 'meta', 'assigned_to', 'position', 'consent_at', 'consent_text',
    ];

    protected function casts(): array
    {
        return ['answers' => 'array', 'meta' => 'array', 'consent_at' => 'datetime'];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Lead $lead) {
            $lead->uuid ??= (string) Str::uuid();
        });
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(LeadForm::class, 'lead_form_id');
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(LeadFormColumn::class, 'column_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
