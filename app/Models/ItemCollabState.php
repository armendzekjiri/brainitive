<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Yjs state of an issue description for live co-editing (BRAI-151). Written by the realtime server
 * through the internal API; `epoch` names the current Y document (`item.<id>.<epoch>`).
 */
class ItemCollabState extends Model
{
    protected $fillable = [
        'item_id',
        'epoch',
        'state',
        'state_bytes',
        'html_vector',
        'bootstrap_claim',
        'bootstrap_claimed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['epoch' => 0];

    protected $casts = [
        'epoch' => 'integer',
        'state_bytes' => 'integer',
        'html_vector' => 'array',
        'bootstrap_claimed_at' => 'datetime',
    ];

    /** The state can be large and is only for the realtime server. */
    protected $hidden = ['state'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
