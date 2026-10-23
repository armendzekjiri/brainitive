<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's vote on a poll inside a doc or issue description. */
class PollVote extends Model
{
    protected $fillable = [
        'scope_type',
        'scope_id',
        'poll_id',
        'option_uid',
        'user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
