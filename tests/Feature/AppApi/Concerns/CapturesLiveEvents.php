<?php

namespace Tests\Feature\AppApi\Concerns;

use App\Support\Live\BoardLive;
use App\Support\Live\Live;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use function Illuminate\Support\defer;

/**
 * App API writes must reach open boards like the web's (BRAI-152): publishing on, the live server faked,
 * and the messages one request published (as Tests\Feature\Live\LiveBoardsTest).
 */
trait CapturesLiveEvents
{
    private int $liveSeen = 0;

    protected function fakeLive(): void
    {
        BoardLive::reset();
        Cache::forget('live:publish:paused');
        Http::fake(['127.0.0.1:6299/*' => Http::response(['delivered' => 1], 202)]);
        config(['collab.url' => 'wss://brainitive.test/collab', 'collab.secret' => 'test-collab-secret', 'collab.e2e_opt_in' => false, 'live.enabled' => true, 'live.url' => null, 'live.publish_url' => 'http://127.0.0.1:6299']);
    }

    /** Forget what was published so far (fixtures). */
    protected function settleLive(): void
    {
        defer()->invoke();
        $this->liveSeen = count(Http::recorded());
    }

    /** @return Collection<int, array{channel: string, event: string, payload: array<string, mixed>}> */
    protected function livePublished(?string $channel = null): Collection
    {
        defer()->invoke();
        $messages = collect(Http::recorded())->slice($this->liveSeen)
            ->flatMap(fn (array $pair) => json_decode($pair[0]->body(), true)['messages'] ?? [])
            ->values();
        foreach ($messages as $message) {
            Live::validate($message['channel'], $message['event'], $message['payload']);
        }

        return $channel === null ? $messages : $messages->where('channel', $channel)->values();
    }
}
