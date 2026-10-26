<?php

namespace App\Support\Offline\Appliers;

use App\Models\Capture;
use App\Support\Offline\MutationContext;
use App\Support\Offline\SyncOutcome;

/** A quick capture written offline (BRAI-82): lands in the user's own inbox, like captures.store. */
final class CaptureMutations
{
    public const ENTITY = 'capture';

    public static function create(MutationContext $ctx): SyncOutcome
    {
        $capture = Capture::create(['user_id' => $ctx->user->id] + $ctx->validate(Capture::rules()));

        return SyncOutcome::applied(self::ENTITY, ['uuid' => $capture->uuid, 'text' => $capture->text], $capture->id);
    }
}
