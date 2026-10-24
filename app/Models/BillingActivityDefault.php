<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ticket type → default activity for logging time (v1.38.0). Set by workspace admins on the
 * Activities page; read when someone logs time to preselect the activity.
 */
class BillingActivityDefault extends Model
{
    protected $fillable = ['workspace_id', 'item_type_id', 'billing_activity_id'];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(BillingActivity::class, 'billing_activity_id');
    }

    public function itemType(): BelongsTo
    {
        return $this->belongsTo(ItemType::class);
    }
}
