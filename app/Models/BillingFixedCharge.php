<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fixed-price activity charged for one ticket by a finalized billing report (v1.38.0, BRAI-101).
 * Unique per item + activity, so a ticket's flat price is billed once; entries without a ticket
 * (item_id null) are charged once per report and project. Written only by ReportFinalizer and
 * removed when the report is voided.
 */
class BillingFixedCharge extends Model
{
    protected $fillable = ['workspace_id', 'project_id', 'item_id', 'billing_activity_id', 'billing_report_id', 'amount_cents'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(BillingReport::class, 'billing_report_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(BillingActivity::class, 'billing_activity_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
