<?php

namespace App\Support\Pages;

use App\Models\Page;
use App\Models\PageVersion;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PageUpdatedNotification;
use App\Support\PageReviews\ReviewGate;
use Illuminate\Support\Facades\DB;

/**
 * Creating, saving and publishing docs the web editor's way, shared by PageController, the MCP's
 * create_page and the App API (BRAI-411). Callers authorize first (PagePolicy) and check the plan's
 * content room (LimitGate::ensureContentRoom) before create().
 *
 * Writes from outside a live document (everything here) start a new live epoch through Page::saved, so
 * open browsers reload on the new draft (BRAI-54); the edit_revision check is what keeps such a write
 * from overwriting changes the writer never saw.
 */
final class PageWrites
{
    public const STALE = 'conflict';

    public const UNCHANGED = 'unchanged';

    /**
     * A doc, whiteboard or folder. `html` (docs only) is the body in the editor's format; without it a
     * doc starts empty.
     *
     * @param  array{name: string, type: 'page'|'folder', parent_id?: int|null, icon?: string|null, content_type?: 'doc'|'excalidraw'|null, html?: string|null}  $data
     */
    public static function create(Project $project, User $user, array $data): Page
    {
        $whiteboard = $data['type'] === 'page' && ($data['content_type'] ?? 'doc') === 'excalidraw';

        $content = match (true) {
            $data['type'] === 'folder' => null,
            $whiteboard => ['type' => 'excalidraw', 'elements' => [], 'appState' => []],
            isset($data['html']) => ['type' => 'lexical', 'html' => $data['html']],
            default => ['type' => 'doc', 'content' => []],
        };

        $defaultIcon = match (true) {
            $data['type'] === 'folder' => '📁',
            $whiteboard => '✏️',
            default => '📄',
        };

        return $project->allPages()->create([
            'name' => $data['name'],
            'type' => $data['type'],
            'parent_id' => $data['parent_id'] ?? null,
            'icon' => $data['icon'] ?? $defaultIcon,
            'created_by' => $user->id,
            'content' => $content,
        ]);
    }

    /** The page moved past the revision the writer started from (null = no check). */
    public static function isStale(Page $page, ?int $baseRevision): bool
    {
        return $baseRevision !== null && $baseRevision !== $page->edit_revision;
    }

    /**
     * The editor's autosave on the locked page row: a published page collects the change in its draft,
     * a never-published one changes directly. False (nothing saved) when the page moved past `$baseRevision`.
     *
     * @param  array{content?: array<string, mixed>|null, name?: string, icon?: string|null}  $changes
     */
    public static function saveDraft(Page $page, User $user, array $changes, ?int $baseRevision): bool
    {
        return DB::transaction(function () use ($page, $user, $changes, $baseRevision) {
            $locked = Page::whereKey($page->id)->lockForUpdate()->firstOrFail();
            if (self::isStale($locked, $baseRevision)) {
                return false;
            }
            $locked->saveDraft($user, $changes);

            return true;
        });
    }

    /**
     * First publish (or again after "unpublish"), through the review gate (BRAI-80).
     *
     * @return PageVersion|string the new version, 'published' when it already was, or ReviewGate::REFUSAL
     */
    public static function publish(Page $page, User $user, ?string $message): PageVersion|string
    {
        return ReviewGate::publishing(
            $page,
            fn (Page $locked) => $locked->isPublished() ? 'published' : null,
            fn (Page $locked) => $locked->publish($user, $message),
        );
    }

    /**
     * "Update" on a published page: saves the editor's last changes, makes the unpublished changes live as a
     * new version and (`$notify`) tells the watchers. One transaction on the locked page row, so the review
     * check (BRAI-80) sees exactly what goes live.
     *
     * @param  array{content?: array<string, mixed>|null, name?: string, icon?: string|null}  $changes
     * @return PageVersion|string the new version, self::STALE, self::UNCHANGED or ReviewGate::REFUSAL
     */
    public static function publishChanges(Page $page, User $user, array $changes, ?int $baseRevision, ?string $message, bool $notify): PageVersion|string
    {
        $result = ReviewGate::publishing(
            $page,
            function (Page $locked) use ($changes, $user, $baseRevision) {
                if (self::isStale($locked, $baseRevision)) {
                    return self::STALE;
                }
                if ($changes !== []) {
                    $locked->saveDraft($user, $changes);
                }

                return $locked->hasUnpublishedChanges() ? null : self::UNCHANGED;
            },
            fn (Page $locked) => [$locked, $locked->publishChanges($user, $message)],
        );

        if (! is_array($result)) {
            return $result;
        }
        [$published, $version] = $result;

        if ($notify) {
            $published->load('watchers');
            foreach ($published->watchers as $watcher) {
                if ($watcher->id !== $user->id) {
                    $watcher->notify(new PageUpdatedNotification($published, $user));
                }
            }
        }

        return $version;
    }
}
