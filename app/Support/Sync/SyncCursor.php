<?php

namespace App\Support\Sync;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Where a client stands in the App API's change feed (BRAI-425), handed out as one opaque string:
 * the scope it syncs (scopes + kept task lists), the `since` of the current run (null = a full run),
 * when the run started, the phase + keyset position inside the run, and a hash of the last My Day
 * sent. Not signed: every query is scoped to the token's user and re-checks visibility, so a forged
 * cursor can only skip or repeat the caller's own changes. Bound to the user id (another account's
 * cursor starts a full run).
 */
final class SyncCursor
{
    private const VERSION = 1;

    /**
     * @param  list<string>  $scopes  sorted
     * @param  list<int>  $lists  sorted
     * @param  list<int|string>|null  $key
     */
    public function __construct(
        public readonly int $userId,
        public readonly bool $reset,
        public readonly ?CarbonImmutable $since,
        public readonly array $scopes,
        public readonly array $lists,
        public ?CarbonImmutable $startedAt = null,
        public int $phase = 0,
        public ?array $key = null,
        public ?string $myDayHash = null,
    ) {}

    /**
     * A full run (everything in scope, no deletions).
     *
     * @param  list<string>  $scopes
     * @param  list<int>  $lists
     */
    public static function fresh(int $userId, array $scopes, array $lists): self
    {
        return new self($userId, true, null, self::sortedScopes($scopes), self::sortedLists($lists));
    }

    /** The cursor of the next run: what changed since this one started (minus the overlap). */
    public function nextRun(int $overlapSeconds): self
    {
        $started = $this->startedAt ?? CarbonImmutable::now('UTC');

        return new self($this->userId, false, $started->subSeconds($overlapSeconds), $this->scopes, $this->lists, myDayHash: $this->myDayHash);
    }

    public function encode(): string
    {
        $payload = [
            'v' => self::VERSION,
            'u' => $this->userId,
            'r' => $this->reset,
            's' => $this->since?->format('Y-m-d\TH:i:s\Z'),
            't' => $this->startedAt?->format('Y-m-d\TH:i:s\Z'),
            'sc' => $this->scopes,
            'l' => $this->lists,
            'p' => $this->phase,
            'k' => $this->key,
            'h' => $this->myDayHash,
        ];

        return rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    }

    /** The cursor, or null when it isn't one of ours / belongs to another user / is malformed. */
    public static function decode(string $cursor, int $userId): ?self
    {
        try {
            $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
            $data = is_string($raw) ? json_decode($raw, true, 4, JSON_THROW_ON_ERROR) : null;
            if (! is_array($data) || ($data['v'] ?? null) !== self::VERSION || ($data['u'] ?? null) !== $userId) {
                return null;
            }
            $key = $data['k'] ?? null;
            if ($key !== null && (! is_array($key) || ! array_is_list($key) || count($key) > 2
                || array_filter($key, fn ($part) => ! is_int($part) && ! is_string($part)) !== [])) {
                return null;
            }

            return new self(
                userId: $userId,
                reset: (bool) ($data['r'] ?? false),
                since: is_string($data['s'] ?? null) ? CarbonImmutable::parse($data['s'], 'UTC') : null,
                scopes: self::sortedScopes(is_array($data['sc'] ?? null) ? $data['sc'] : []),
                lists: self::sortedLists(is_array($data['l'] ?? null) ? $data['l'] : []),
                startedAt: is_string($data['t'] ?? null) ? CarbonImmutable::parse($data['t'], 'UTC') : null,
                phase: max(0, (int) ($data['p'] ?? 0)),
                key: $key,
                myDayHash: is_string($data['h'] ?? null) ? substr($data['h'], 0, 64) : null,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<mixed>  $scopes
     * @return list<string>
     */
    public static function sortedScopes(array $scopes): array
    {
        $scopes = array_values(array_unique(array_intersect(array_filter($scopes, 'is_string'), ChangeFeed::SCOPES)));
        sort($scopes);

        return $scopes;
    }

    /**
     * @param  array<mixed>  $lists
     * @return list<int>
     */
    public static function sortedLists(array $lists): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($lists, fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id))))));
        $ids = array_values(array_filter($ids, fn (int $id) => $id > 0));
        sort($ids);

        return array_slice($ids, 0, ChangeFeed::MAX_LISTS);
    }
}
