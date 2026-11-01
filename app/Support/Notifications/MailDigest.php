<?php

namespace App\Support\Notifications;

use App\Jobs\SendNotificationDigest;
use App\Models\Board;
use App\Models\Item;
use App\Models\Page;
use App\Models\PendingNotificationEmail;
use App\Models\TaskList;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\NotificationCatalog;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Notification email digest (BRAI-429): activity emails to members wait up to
 * `notifications.email_digest_seconds` (NOTIFICATION_EMAIL_DIGEST_SECONDS, default 60) from the
 * first one and go out as ONE email per person and workspace. Bell, web push and live updates are
 * never delayed — only the `mail` channel is swapped (RespectsPreferences::channelsFor →
 * DigestMailChannel → capture()).
 *
 * The window is fixed: it starts with the first pending email and is never extended; anything later
 * opens the next window. SendNotificationDigest (a delayed queue job, no cron) claims the due rows,
 * re-checks preferences and access, and sends.
 */
final class MailDigest
{
    /**
     * The types whose email is batched: label in the list, noun for the subject line ("3 assignments").
     * Everything else — account / security mail, guest mail, helpdesk, leads, bookings, reviews,
     * reminders, workspace notices — is sent at once as before (docs/ai/tasks/feat-notification-email-digest.md).
     */
    public const TYPES = [
        'item_assigned' => ['label' => 'Assigned', 'noun' => 'assignment'],
        'task_assigned' => ['label' => 'Assigned', 'noun' => 'assignment'],
        'mentioned' => ['label' => 'Mention', 'noun' => 'mention'],
        'item_commented' => ['label' => 'Comment', 'noun' => 'comment'],
        'item_status_changed' => ['label' => 'Status', 'noun' => 'status change'],
        'page_updated' => ['label' => 'Page', 'noun' => 'page update'],
        'sprint_started' => ['label' => 'Sprint', 'noun' => 'sprint update'],
        'sprint_completed' => ['label' => 'Sprint', 'noun' => 'sprint update'],
    ];

    /** Models a line can point at: their `view` policy is checked before sending (and only these are loaded). */
    public const SUBJECTS = [Item::class, Page::class, TaskList::class, Board::class];

    public static function seconds(): int
    {
        return max(0, (int) config('notifications.email_digest_seconds', 0));
    }

    /**
     * On when an interval is set and a real queue runs the delayed job. On the sync queue a "delayed"
     * job runs at once (before the window ends), so emails go out immediately there, as before.
     */
    public static function enabled(): bool
    {
        if (self::seconds() === 0) {
            return false;
        }
        $connection = config('queue.default');

        return config("queue.connections.{$connection}.driver", 'sync') !== 'sync';
    }

    /** Does this email wait for the digest? */
    public static function batches(object $notifiable, string $type): bool
    {
        return $notifiable instanceof User && isset(self::TYPES[$type]) && self::enabled();
    }

    /**
     * Store one email for the digest: join the open window of this person + workspace, or open one and
     * queue its job. An exact duplicate of a line that is still waiting is dropped.
     */
    public static function capture(User $user, DigestEntry $entry): void
    {
        $workspaceId = (int) $entry->workspace->getKey();
        $subjectType = $entry->subject::class;
        $subjectId = (int) $entry->subject->getKey();
        $dedupe = sha1(json_encode([$entry->type, $subjectType, $subjectId, $entry->toArray()]) ?: '');

        $write = fn () => DB::transaction(function () use ($user, $entry, $workspaceId, $subjectType, $subjectId, $dedupe) {
            $waiting = PendingNotificationEmail::query()
                ->where('user_id', $user->id)
                ->where('workspace_id', $workspaceId)
                ->whereNull('claim_token');

            if ((clone $waiting)->where('dedupe_key', $dedupe)->exists()) {
                return; // the same email is already waiting (a retried job, the same event twice)
            }

            $now = now();
            $openUntil = (clone $waiting)->where('due_at', '>', $now)->min('due_at');
            $dueAt = $openUntil !== null
                ? Carbon::parse($openUntil)
                : $now->copy()->addSeconds(self::seconds())->startOfSecond();

            PendingNotificationEmail::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'type' => $entry->type,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'dedupe_key' => $dedupe,
                'entry' => $entry->toArray(),
                'due_at' => $dueAt,
            ]);

            if ($openUntil === null) {
                // In the same transaction: with the database queue the job and the line commit together.
                SendNotificationDigest::dispatch($user->id, $workspaceId)->delay($dueAt);
            }
        });

        try {
            Cache::lock("mail-digest:capture:{$user->id}:{$workspaceId}", 10)->block(5, $write);
        } catch (LockTimeoutException) {
            $write(); // worst case the window splits in two — never lose the email
        }
    }

    /**
     * The lines of claimed rows that may still be sent: the person is active and still in the
     * workspace, email is still on for the type's category, the subject still exists in this workspace
     * and they may view it. Exact duplicates collapse to one. Oldest first.
     *
     * @param  Collection<int, PendingNotificationEmail>  $rows
     * @return list<array<string, mixed>>
     */
    public static function deliverable(?User $user, ?Workspace $workspace, Collection $rows): array
    {
        if (! $user || $user->isSuspended() || ! $workspace || ! $user->hasWorkspaceAccess($workspace)) {
            return [];
        }

        $lines = [];
        $seen = [];
        foreach ($rows->sortBy('id') as $row) {
            if (isset($seen[$row->dedupe_key]) || ! isset(self::TYPES[$row->type])) {
                continue;
            }
            $seen[$row->dedupe_key] = true;

            if (! NotificationCatalog::preferences($user, NotificationCatalog::category($row->type))['email']) {
                continue;
            }
            if (! self::canView($user, $workspace, $row->subject_type, $row->subject_id)) {
                continue;
            }

            $lines[] = (array) $row->entry + [
                'type' => $row->type,
                'label' => self::TYPES[$row->type]['label'],
                'at' => $row->created_at,
            ];
        }

        return $lines;
    }

    /** The workspace a subject belongs to (null when a parent is gone). */
    public static function workspaceOf(Model $subject): ?Workspace
    {
        $project = match (true) {
            $subject instanceof Item => $subject->board?->project,
            $subject instanceof Page, $subject instanceof TaskList, $subject instanceof Board => $subject->project,
            default => null,
        };

        return $project?->workspace;
    }

    private static function canView(User $user, Workspace $workspace, ?string $type, ?int $id): bool
    {
        if ($type === null || $id === null || ! in_array($type, self::SUBJECTS, true)) {
            return false;
        }

        try {
            $subject = $type::query()->find($id);

            return $subject !== null
                && self::workspaceOf($subject)?->is($workspace) === true
                && Gate::forUser($user)->allows('view', $subject);
        } catch (\Throwable $e) {
            report($e);

            return false; // a parent was deleted underneath it: drop the line rather than guess
        }
    }
}
