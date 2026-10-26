<?php

namespace App\Support\Live;

use App\Models\Board;
use App\Models\Item;
use App\Models\Page;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Who may join which live channel (BRAI-151) — the only place that decides it, for the token
 * endpoint and the realtime server's minute-by-minute re-check alike. A channel is `<type>.<id>`;
 * every type except `user` loads its record (not trashed, parents — up to the workspace — not
 * trashed) and asks its policy for `view`. A failing check denies that one channel, never the batch. Adding a type: one entry in TYPES + one arm in load() + tests in LiveChannelsTest.
 * Nothing changes in realtime/ (channel names are opaque there).
 *
 * One instance per request: records are loaded once per channel, however many users ask.
 */
final class LiveChannels
{
    /** Most channels one token request may ask for (the realtime server allows 50 per token). */
    public const MAX_PER_TOKEN = 50;

    // ≤ 18 digits: always inside PostgreSQL's bigint
    private const ID = '[1-9][0-9]{0,17}';

    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** type => id pattern */
    public const TYPES = [
        'user' => self::ID,   // the user's own channel (notifications, …)
        'item' => self::ID,   // a board issue — ItemPolicy::view
        'board' => self::ID,  // a board — BoardPolicy::view
        'plan' => self::ID,   // a Gantt plan — PlanPolicy::view
        'page' => self::UUID, // a document / whiteboard — PagePolicy::view
    ];

    /** @var array<string, Model|null> */
    private array $records = [];

    /**
     * @return array{0: string, 1: string}|null [type, id]
     */
    public static function parse(string $channel): ?array
    {
        $dot = strpos($channel, '.');
        if ($dot === false) {
            return null;
        }
        $type = substr($channel, 0, $dot);
        $id = substr($channel, $dot + 1);
        $pattern = self::TYPES[$type] ?? null;

        return $pattern !== null && preg_match('/\A'.$pattern.'\z/', $id) === 1 ? [$type, $id] : null;
    }

    /** May `$user` join `$channel` (and receive its events, presence and signals)? */
    public function allows(User $user, string $channel): bool
    {
        $parsed = self::parse($channel);
        if ($parsed === null || $user->isSuspended()) {
            return false;
        }
        [$type, $id] = $parsed;
        if ($type === 'user') {
            return (string) $user->id === $id;
        }
        if (! array_key_exists($channel, $this->records)) {
            $this->records[$channel] = $this->load($type, $id);
        }
        $record = $this->records[$channel];
        if ($record === null) {
            return false;
        }
        try {
            return Gate::forUser($user)->allows('view', $record);
        } catch (Throwable $e) {
            // one broken record must not fail a whole re-check (the realtime server would keep everyone)
            report($e);

            return false;
        }
    }

    /**
     * The channels of `$channels` that `$user` may join, in the given order, without duplicates.
     *
     * @param  array<int, mixed>  $channels
     * @return list<string>
     */
    public function authorize(User $user, array $channels): array
    {
        $allowed = [];
        foreach (array_unique(array_filter($channels, 'is_string')) as $channel) {
            if ($this->allows($user, $channel)) {
                $allowed[] = $channel;
            }
        }

        return $allowed;
    }

    /** The channel's record, null when it is gone (or its board / project / workspace is). */
    private function load(string $type, string $id): ?Model
    {
        try {
            return match ($type) {
                'item' => ($item = Item::with('board.project.workspace')->find($id)) && $item->board?->project?->workspace ? $item : null,
                'board' => ($board = Board::with('project.workspace')->find($id)) && $board->project?->workspace ? $board : null,
                'plan' => ($plan = Plan::with('project.workspace')->find($id)) && $plan->project?->workspace ? $plan : null,
                'page' => ($page = Page::with('project.workspace')->where('uuid', $id)->first()) && $page->project?->workspace ? $page : null,
                default => null,
            };
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
