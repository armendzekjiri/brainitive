<?php

namespace App\Support\Sync;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Where a client stands in the App API's change feed (BRAI-425, v2 BRAI-441), handed out as one opaque
 * string: what it syncs (scopes, the task lists it asked for + `mine`, the captures filter), the `since`
 * of the current run (null = a full run), when the run started, the phase + keyset position inside the
 * run, and what the client already has: the task lists resolved for its last run (`kept`), a hash of
 * their abilities and a hash of the last My Day sent. Inside a delta run `fresh` lists get a full pass
 * and `gone` lists a delete (an additive list change never restarts the other scopes).
 *
 * Not signed: every query is scoped to the token's user and re-checks visibility, so a forged cursor can
 * only skip or repeat the caller's own changes. Bound to the user id (another account's cursor starts a
 * full run). Version 1 cursors (BRAI-425) are still read.
 */
final class SyncCursor
{
    private const VERSION = 2;

    /** Captures filter: every capture (the default) or only the inbox (neither processed nor archived). */
    public const CAPTURES_ALL = 'all';

    public const CAPTURES_INBOX = 'inbox';

    public const CAPTURE_FILTERS = [self::CAPTURES_ALL, self::CAPTURES_INBOX];

    /** v1 cursors stored the phase as its index in this order. */
    private const V1_PHASES = ['taskLists', 'listTasks', 'workIssues', 'workTasks', 'captures', 'myDay', 'goneWorkIssues', 'goneWorkTasks', 'goneListTasks', 'goneTaskLists', 'goneTombstones'];

    /** Most ids a cursor keeps in `gone` (list changes during a run can add up). */
    private const MAX_GONE = 200;

    /**
     * @param  list<string>  $scopes  sorted
     * @param  list<int>  $lists  the ids the client asked for, sorted
     * @param  list<int>  $kept  the lists resolved for the current / last run (visible ones), sorted
     * @param  list<int>  $fresh  kept lists that get a full pass in this (delta) run
     * @param  list<int>  $gone  lists the client must drop in this (delta) run
     * @param  list<int|string>|null  $key
     */
    public function __construct(
        public readonly int $userId,
        public readonly bool $reset,
        public readonly ?CarbonImmutable $since,
        public readonly array $scopes,
        public readonly array $lists,
        public readonly bool $mine = false,
        public readonly string $captures = self::CAPTURES_ALL,
        public array $kept = [],
        public array $fresh = [],
        public array $gone = [],
        public ?string $canHash = null,
        public bool $allLists = false,
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
    public static function fresh(int $userId, array $scopes, array $lists, bool $mine = false, string $captures = self::CAPTURES_ALL): self
    {
        return new self($userId, true, null, self::sortedScopes($scopes), self::sortedLists($lists), $mine, self::captureFilter($captures));
    }

    /** The cursor of the next run: what changed since this one started (minus the overlap). */
    public function nextRun(int $overlapSeconds): self
    {
        $started = $this->startedAt ?? CarbonImmutable::now('UTC');

        return new self(
            $this->userId, false, $started->subSeconds($overlapSeconds), $this->scopes, $this->lists, $this->mine, $this->captures,
            kept: $this->kept, canHash: $this->canHash, myDayHash: $this->myDayHash,
        );
    }

    /**
     * Other task lists asked for: the current run starts over with the same `since` (a full run stays a
     * full run). Its start diffs the lists against `kept`: new ones get a full pass, dropped ones a delete.
     *
     * @param  list<int>  $lists
     */
    public function withLists(array $lists, bool $mine): self
    {
        if ($this->since === null) {
            return self::fresh($this->userId, $this->scopes, $lists, $mine, $this->captures);
        }

        return new self(
            $this->userId, false, $this->since, $this->scopes, self::sortedLists($lists), $mine, $this->captures,
            kept: $this->kept, fresh: $this->fresh, gone: $this->gone,
            // a run that was re-sending every list (abilities changed) still has to
            canHash: $this->allLists ? null : $this->canHash, myDayHash: $this->myDayHash,
        );
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
            'm' => $this->mine,
            'c' => $this->captures,
            'kl' => $this->kept,
            'f' => $this->fresh,
            'g' => $this->gone,
            'ch' => $this->canHash,
            'al' => $this->allLists,
            'p' => ChangeFeed::PHASE_NAMES[$this->phase] ?? null,
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
            if (! is_array($data) || ! in_array($data['v'] ?? null, [1, self::VERSION], true) || ($data['u'] ?? null) !== $userId) {
                return null;
            }
            $key = $data['k'] ?? null;
            if ($key !== null && (! is_array($key) || ! array_is_list($key) || count($key) > 2
                || array_filter($key, fn ($part) => ! is_int($part) && ! is_string($part)) !== [])) {
                return null;
            }
            $phase = self::phase($data['v'], $data['p'] ?? 0);
            if ($phase === null) {
                return null;
            }
            $lists = self::sortedLists(is_array($data['l'] ?? null) ? $data['l'] : []);
            $v1 = $data['v'] === 1;
            $captures = $data['c'] ?? self::CAPTURES_ALL;
            if (! in_array($captures, self::CAPTURE_FILTERS, true)) {
                return null;
            }

            return new self(
                userId: $userId,
                reset: (bool) ($data['r'] ?? false),
                since: is_string($data['s'] ?? null) ? CarbonImmutable::parse($data['s'], 'UTC') : null,
                scopes: self::sortedScopes(is_array($data['sc'] ?? null) ? $data['sc'] : []),
                lists: $lists,
                mine: ! $v1 && ($data['m'] ?? false) === true,
                captures: $captures,
                // a v1 cursor kept exactly the lists it asked for
                kept: $v1 ? $lists : self::sortedLists(is_array($data['kl'] ?? null) ? $data['kl'] : []),
                fresh: $v1 ? [] : self::sortedLists(is_array($data['f'] ?? null) ? $data['f'] : []),
                gone: $v1 ? [] : self::sortedLists(is_array($data['g'] ?? null) ? $data['g'] : [], self::MAX_GONE),
                canHash: is_string($data['ch'] ?? null) ? substr($data['ch'], 0, 64) : null,
                allLists: ($data['al'] ?? false) === true,
                startedAt: is_string($data['t'] ?? null) ? CarbonImmutable::parse($data['t'], 'UTC') : null,
                phase: $phase,
                key: $key,
                myDayHash: is_string($data['h'] ?? null) ? substr($data['h'], 0, 64) : null,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /** The phase's index in ChangeFeed::PHASE_NAMES: v1 stored the index, v2 the name. */
    private static function phase(int $version, mixed $phase): ?int
    {
        $name = $version === 1
            ? (is_int($phase) ? (self::V1_PHASES[$phase] ?? null) : null)
            : $phase;
        $index = is_string($name) ? array_search($name, ChangeFeed::PHASE_NAMES, true) : false;

        return $index === false ? null : $index;
    }

    public static function captureFilter(?string $filter): string
    {
        return in_array($filter, self::CAPTURE_FILTERS, true) ? $filter : self::CAPTURES_ALL;
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
    public static function sortedLists(array $lists, int $max = ChangeFeed::MAX_LISTS): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($lists, fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id))))));
        $ids = array_values(array_filter($ids, fn (int $id) => $id > 0));
        sort($ids);

        return array_slice($ids, 0, $max);
    }
}
