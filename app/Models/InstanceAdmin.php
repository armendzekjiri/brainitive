<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceAdmin extends Model
{
    protected $fillable = [
        'user_id',
    ];

    /**
     * Get the user that is an instance admin.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
