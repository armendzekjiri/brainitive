<?php

namespace App\Support\Offline\Appliers;

use App\Models\Capture;
use App\Support\Captures\CaptureInbox;
use App\Support\Offline\MutationContext;
use App\Support\Offline\SyncOutcome;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A quick capture written offline (BRAI-82): lands in the user's own inbox, like captures.store
 * (`CaptureInbox::create`, shared with the web, the App API, the extension and the MCP).
 */
final class CaptureMutations
{
    public const ENTITY = 'capture';

    /** Same budget as captures.store (60 a minute), so the outbox is no way around it. */
    private const PER_MINUTE = 60;

    public static function create(MutationContext $ctx): SyncOutcome
    {
        if (! RateLimiter::attempt('offline-capture:'.$ctx->user->id, self::PER_MINUTE, fn () => true, 60)) {
            throw new ThrottleRequestsException('Too many captures. Try again in a minute.');
        }

        $capture = CaptureInbox::create($ctx->user, $ctx->validate(Capture::rules()));

        return SyncOutcome::applied(self::ENTITY, ['uuid' => $capture->uuid, 'text' => $capture->text], $capture->id);
    }
}
