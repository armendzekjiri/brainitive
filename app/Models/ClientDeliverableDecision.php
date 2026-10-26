<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client's decision on one exact deliverable version (v1.42.0, BRAI-73): who (identity + name/email
 * kept for the audit trail), what, when, which version. Never updated or deleted.
 */
class ClientDeliverableDecision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['client_deliverable_id', 'portal_identity_id', 'identity_name', 'identity_email', 'decision', 'note', 'version', 'created_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'datetime'];
    }

    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(ClientDeliverable::class, 'client_deliverable_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(PortalIdentity::class, 'portal_identity_id');
    }
}
