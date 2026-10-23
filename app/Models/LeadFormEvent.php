<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * An anonymous analytics event of a lead form (view, start, step reached, submit).
 */
class LeadFormEvent extends Model
{
    use Prunable;

    public const TYPES = ['view', 'start', 'step', 'submit'];

    public const UPDATED_AT = null;

    protected $fillable = ['lead_form_id', 'session_id', 'type', 'step'];

    /** Analytics look back 90 days at most. */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(91));
    }
}
