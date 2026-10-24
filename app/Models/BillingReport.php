<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A time report (or, in invoice mode, an invoice) for one client and period (v1.38.0).
 * Draft: computed live from unbilled time entries. Finalized: `snapshot` is frozen (shape in
 * docs/ai/tasks/release-v1.38.0-billing.md) and the entries are locked via billing_report_id.
 * Status: draft → finalized → sent → paid; void unlocks the entries again.
 */
class BillingReport extends Model
{
    public const STATUSES = ['draft', 'finalized', 'sent', 'paid', 'void'];

    /** Statuses whose entries are locked to the report (finalized and later, until voided). */
    public const LOCKING_STATUSES = ['finalized', 'sent', 'paid'];

    public const GROUP_BY = ['ticket', 'activity', 'person'];

    protected $fillable = [
        'workspace_id', 'client_id', 'kind', 'number', 'title', 'period_from', 'period_to', 'status', 'currency',
        'tax_rate_bp', 'prices_include_tax', 'total_minutes', 'subtotal_cents', 'tax_cents', 'total_cents',
        'options', 'snapshot', 'created_by', 'finalized_at', 'sent_at', 'sent_to', 'paid_at', 'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'tax_rate_bp' => 'integer',
            'prices_include_tax' => 'boolean',
            'total_minutes' => 'integer',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'options' => 'array',
            'snapshot' => 'array',
            'finalized_at' => 'datetime',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** Finalized, sent or paid: the snapshot is frozen and the entries are locked. */
    public function isFinal(): bool
    {
        return in_array($this->status, self::LOCKING_STATUSES, true);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /** Fixed prices this report charged (BRAI-101); deleted when it is voided. */
    public function fixedCharges(): HasMany
    {
        return $this->hasMany(BillingFixedCharge::class);
    }
}
