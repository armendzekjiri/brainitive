<?php

namespace App\Support\Captures;

use App\Models\Capture;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The private capture inbox (BRAI-70) the same way everywhere: the web page (`CaptureInboxController`),
 * the browser extension's and the MCP's "save to inbox", and the App API (`/api/v1/captures`). Every
 * query is scoped to the owner. Turning a capture into content is `CaptureConversion`.
 */
final class CaptureInbox
{
    /**
     * A user's captures in one state, newest first; `$search` matches text, URL and source.
     *
     * @return Builder<Capture>
     */
    public static function query(User $user, string $state, string $search = ''): Builder
    {
        return Capture::ownedBy($user)
            ->inState(in_array($state, Capture::STATES, true) ? $state : 'inbox')
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(fn ($q) => $q->whereLike('text', $like)->orWhereLike('url', $like)->orWhereLike('source', $like));
            })
            ->orderByDesc('captured_at')
            ->orderByDesc('id');
    }

    /** @return array{inbox: int, processed: int, archived: int} */
    public static function counts(User $user): array
    {
        $counts = [];
        foreach (Capture::STATES as $state) {
            $counts[$state] = Capture::ownedBy($user)->inState($state)->count();
        }

        /** @var array{inbox: int, processed: int, archived: int} */
        return $counts;
    }

    /**
     * Save an idea (validated `Capture::rules()` fields). `$source` names where it came from when
     * the user didn't ("Browser extension", "MCP", "Brainitive Desktop" …).
     *
     * @param  array<string, mixed>  $data
     */
    public static function create(User $user, array $data, ?string $source = null): Capture
    {
        return Capture::create([
            'user_id' => $user->id,
            'text' => $data['text'],
            'url' => $data['url'] ?? null,
            'intended_type' => $data['intended_type'] ?? null,
            'source' => ($data['source'] ?? null) ?: $source,
        ]);
    }

    public static function archive(Capture $capture): void
    {
        $capture->update(['archived_at' => $capture->archived_at ?? now()]);
    }

    public static function restore(Capture $capture): void
    {
        $capture->update(['archived_at' => null]);
    }
}
