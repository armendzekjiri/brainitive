<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An anonymous analytics event of a lead form (view, start, step reached, submit).
 */
class LeadFormEvent extends Model
{
    public const TYPES = ['view', 'start', 'step', 'submit'];

    public const UPDATED_AT = null;

    protected $fillable = ['lead_form_id', 'session_id', 'type', 'step'];
}
