<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A comment on a deliverable (v1.42.0, BRAI-73), plain text. From a client (always `client`
 * visibility) or from the team: `client` only when sent with "Reply to client", otherwise an
 * `internal` note the client never sees.
 */
class ClientDeliverableComment extends Model
{
    public const VISIBILITY_CLIENT = 'client';

    public const VISIBILITY_INTERNAL = 'internal';

    protected $fillable = ['client_deliverable_id', 'portal_identity_id', 'user_id', 'author_name', 'visibility', 'body'];

    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(ClientDeliverable::class, 'client_deliverable_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(PortalIdentity::class, 'portal_identity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFromTeam(): bool
    {
        return $this->user_id !== null || $this->portal_identity_id === null;
    }
}
