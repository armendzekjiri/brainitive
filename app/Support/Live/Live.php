<?php

namespace App\Support\Live;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Publish to live channels (BRAI-151): `Live::publish('board.12', 'item.moved', ['id' => 5, 'column_id' => 3])`.
 *
 * - **Payload rule:** live messages reach every subscriber of a channel, including one whose access
 *   ended since the last re-check (≤ 1 min). So payloads carry no content — only what tells a client
 *   what to refetch through the normal permission-checked endpoints. Enforced by validate(): a flat
 *   object, ≤ 16 keys from an allow-list (KEY: ids, uuids, versions / revisions, `*_at`, counts,
 *   positions, short kind / type / action / status / field hints), scalars (strings ≤ 64 chars) or
 *   lists of ≤ 100 scalars, ≤ 2 KB JSON. A new key name means a conscious change of KEY.
 * - **Never breaks the caller:** queued after the transaction commits (a rolled-back change publishes
 *   nothing) and sent once per request / job / command after the response (`defer`), one POST for
 *   all messages, short timeouts, every failure swallowed (reported, then 15 s pause). A message
 *   that breaks the rule throws in unit tests (so the mistake surfaces), elsewhere it is reported
 *   and dropped.
 * - Off (no-op) when live channels aren't configured or there is no publish URL.
 */
final class Live
{
    public const PATH = '/internal/live/publish';

    /** Allowed payload keys. */
    public const KEY = '/\A(id|ids|uuid|uuids|version|revision|count|position|kind|type|action|status|field|fields|[a-z][a-z0-9_]{0,30}_(id|ids|uuid|uuids|at|count|version|revision|position|kind|type|status))\z/';

    private const EVENT = '/\A[a-z][a-z0-9_.-]{0,63}\z/';

    private const PAUSE_KEY = 'live:publish:paused';

    private const PAUSE_SECONDS = 15;

    private const BATCH = 100;

    /** @var list<array{channel: string, event: string, payload: object}> */
    private static array $pending = [];

    /**
     * @param  array<string, scalar|null|list<scalar|null>>  $payload
     */
    public static function publish(string $channel, string $event, array $payload = []): void
    {
        try {
            self::validate($channel, $event, $payload);
        } catch (InvalidArgumentException $e) {
            if (app()->runningUnitTests()) {
                throw $e;
            }
            report($e);

            return;
        }
        if (LiveConfig::publishUrl() === null) {
            return;
        }
        $message = ['channel' => $channel, 'event' => $event, 'payload' => (object) $payload];
        DB::afterCommit(function () use ($message) {
            self::$pending[] = $message;
            // one flush per request / job / command, after the response; also after a failed one
            // (the change is committed)
            defer(fn () => self::flush(), 'live.publish')->always();
        });
    }

    /** Send what is queued (deferred; tests may call it). Never throws. */
    public static function flush(): void
    {
        $messages = self::$pending;
        self::$pending = [];
        foreach (array_chunk($messages, self::BATCH) as $batch) {
            if (! self::send($batch)) {
                return;
            }
        }
    }

    /**
     * @param  list<array{channel: string, event: string, payload: object}>  $messages
     */
    private static function send(array $messages): bool
    {
        $url = LiveConfig::publishUrl();
        if ($url === null) {
            return false;
        }
        try {
            if (Cache::get(self::PAUSE_KEY)) {
                return false;
            }
            $response = Http::withToken(LiveConfig::publishKey())
                ->acceptJson()
                ->connectTimeout(0.3)
                ->timeout(1)
                ->post($url.self::PATH, ['messages' => $messages]);
            if ($response->successful()) {
                return true;
            }
            report(new \RuntimeException("Live publish refused: HTTP {$response->status()}"));
        } catch (Throwable $e) {
            // the realtime server is down / restarting: nobody is live right now anyway
            report($e);
        }
        try {
            Cache::put(self::PAUSE_KEY, true, self::PAUSE_SECONDS);
        } catch (Throwable) {
            // no cache: try again next time
        }

        return false;
    }

    /**
     * @param  array<mixed>  $payload
     *
     * @throws InvalidArgumentException when the message breaks the payload rule
     */
    public static function validate(string $channel, string $event, array $payload): void
    {
        if (LiveChannels::parse($channel) === null) {
            throw new InvalidArgumentException("Live: unknown channel \"{$channel}\".");
        }
        if (preg_match(self::EVENT, $event) !== 1) {
            throw new InvalidArgumentException("Live: invalid event name \"{$event}\".");
        }
        if (count($payload) > 16) {
            throw new InvalidArgumentException('Live: at most 16 payload keys.');
        }
        foreach ($payload as $key => $value) {
            if (! is_string($key) || preg_match(self::KEY, $key) !== 1) {
                throw new InvalidArgumentException('Live: payload key "'.$key.'" is not allowed — send ids / versions / hints only (Live::KEY), clients refetch.');
            }
            $values = is_array($value) && array_is_list($value) && count($value) <= 100 ? $value : [$value];
            foreach ($values as $item) {
                if (! (is_null($item) || is_bool($item) || is_int($item) || is_float($item) || (is_string($item) && mb_strlen($item) <= 64))) {
                    throw new InvalidArgumentException("Live: \"{$key}\" must be a scalar (strings ≤ 64 chars) or a short list of them.");
                }
            }
        }
        if (strlen((string) json_encode($payload)) > 2048) {
            throw new InvalidArgumentException('Live: payload larger than 2 KB.');
        }
    }
}
