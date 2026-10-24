<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One created occurrence of a RecurringIssue (BRAI-123): unique per (template, date) — the
 * scheduler's idempotency key — and the link from the issue back to its template (card badge).
 */
class RecurringIssueOccurrence extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['recurring_issue_id', 'occurrence_date', 'item_id'];

    protected function casts(): array
    {
        return ['occurrence_date' => 'date:Y-m-d'];
    }

    public function recurringIssue(): BelongsTo
    {
        return $this->belongsTo(RecurringIssue::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
