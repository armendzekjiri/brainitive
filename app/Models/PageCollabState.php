<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Yjs state of a page's draft for live co-editing (BRAI-54). Written by the realtime server through
 * the internal API; `epoch` names the current Y document (`page.<uuid>.<epoch>`).
 */
class PageCollabState extends Model
{
    protected $fillable = [
        'page_id',
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

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
