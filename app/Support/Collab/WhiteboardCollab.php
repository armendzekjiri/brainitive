<?php

namespace App\Support\Collab;

use App\Models\Page;
use App\Models\PageCollabState;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Live whiteboards (BRAI-153): several people draw on one Excalidraw scene through the same realtime
 * server as live documents (Y document `whiteboard.<uuid>.<epoch>`, same `page_collab_states` row).
 * Brainitive stays the source of truth: the browsers store the scene of the live document as the
 * whiteboard's `content` through Page::saveWhiteboard (checkpoints, derived issue-card references,
 * search). Plan: docs/ai/tasks/feat-live-whiteboards.md.
 */
final class WhiteboardCollab
{
    /** Largest inline image a browser may hand over in the scene (data URL characters). */
    public const MAX_FILE_CHARS = 15 * 1024 * 1024;

    /** Live drawing exists for whiteboard pages only — never a side door into a document (BRAI-80). */
    public static function supports(Page $page): bool
    {
        return $page->isPage() && $page->is_whiteboard;
    }

    /**
     * 'edit' for editors; 'view' for everyone else who can open the page — a whiteboard has no draft,
     * its readers already see the live scene; null otherwise.
     *
     * @return 'edit'|'view'|null
     */
    public static function access(Page $page, User $user, ?bool $canUpdate = null): ?string
    {
        if (! self::supports($page) || $page->status === 'archived' || $user->isSuspended()) {
            return null;
        }
        if ($canUpdate ?? $user->can('update', $page)) {
            return 'edit';
        }

        return $user->can('view', $page) ? 'view' : null;
    }

    /**
     * Whether (and which epoch) the browser should open live; null = today's single-user whiteboard.
     *
     * @return array{epoch: int, url: string}|null
     */
    public static function props(Page $page, User $user, ?bool $canUpdate = null): ?array
    {
        if (! CollabConfig::enabled() || self::access($page, $user, $canUpdate) === null) {
            return null;
        }

        return ['epoch' => PageCollab::epoch($page) ?? 0, 'url' => CollabConfig::url()];
    }

    /**
     * Server URL, document name and a fresh token. `$viewOnly`: the page preview never draws, so even
     * an editor gets a read-only connection there.
     *
     * @param  'edit'|'view'  $access
     * @return array<string, mixed>
     */
    public static function session(Page $page, User $user, string $access, bool $viewOnly = false): array
    {
        PageCollab::ensureState($page);
        $state = PageCollabState::where('page_id', $page->id)->firstOrFail(['id', 'epoch', 'html_vector']);
        $document = CollabDocument::name($page->uuid, $state->epoch, CollabDocument::WHITEBOARD);
        $edit = $access === 'edit' && ! $viewOnly;
        $token = CollabToken::issue($user, $document, $edit);

        return [
            'url' => CollabConfig::url(),
            'document' => $document,
            'epoch' => $state->epoch,
            'token' => $token['token'],
            'expires_at' => $token['expires_at'],
            'edit' => $edit,
            'vector' => $state->html_vector === null ? null : (object) $state->html_vector,
        ];
    }

    /**
     * An editor synced an empty live whiteboard: one of them (per lease) gets the stored elements to
     * write into it; the others wait for them to arrive through the sync.
     *
     * @return array<string, mixed>
     */
    public static function claimBootstrap(Page $page, int $epoch, string $claim): array
    {
        return PageCollab::claim($page, $epoch, $claim, function (Page $page) {
            $content = $page->fresh()?->content;
            $elements = is_array($content) && is_array($content['elements'] ?? null) ? array_values($content['elements']) : [];

            return ['elements' => $elements];
        });
    }

    /**
     * Store a live browser's scene of the Y document as the whiteboard content. `covered` when its
     * state vector is covered by the stored one (an older or concurrent snapshot: the leader saves the
     * merged scene), `unchanged` when elements, images and library are what's stored.
     *
     * @param  array{elements: list<array<string, mixed>>, appState: array<string, mixed>, files: array<string, array<string, mixed>>, libraryItems: array<int, mixed>|null}  $scene
     * @param  array<int, int>  $vector
     *                                   `$baseline`: the browser that wrote the stored elements into an empty live document reports the
     *                                   vector of that load; nothing is written to the page. `$checkpoint`: Save / Save & exit — record
     *                                   a version even when nothing changed. `$previewImageUrl`: the new preview (Save & exit).
     * @return array{status: 'saved'|'unchanged'|'covered'|'baseline', page: Page, version: PageVersion|null}|array{status: 'stale', page: Page, epoch: int}
     */
    public static function save(Page $page, User $user, int $epoch, array $scene, array $vector, bool $baseline = false, bool $checkpoint = false, ?string $previewImageUrl = null): array
    {
        PageCollab::ensureState($page);

        return DB::transaction(function () use ($page, $user, $epoch, $scene, $vector, $baseline, $checkpoint, $previewImageUrl) {
            // same order as PageCollab::save / ReviewGate (page row first)
            $locked = Page::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $state = PageCollabState::where('page_id', $page->id)->lockForUpdate()
                ->firstOrFail(['id', 'epoch', 'html_vector']);

            if ($state->epoch !== $epoch || ! self::supports($locked)) {
                return ['status' => 'stale', 'page' => $locked, 'epoch' => $state->epoch];
            }
            if ($baseline) {
                if ($state->html_vector === null) {
                    $state->update(['html_vector' => $vector]);
                }

                return ['status' => 'baseline', 'page' => $locked, 'version' => null];
            }

            $current = is_array($locked->content) ? $locked->content : [];
            // an empty vector is an empty live document (not loaded yet): never stored over the scene
            if ($vector === [] || ($state->html_vector !== null && (StateVector::covers($state->html_vector, $vector) || ! StateVector::covers($vector, $state->html_vector)))) {
                // the stored scene is at least as new: an explicit save still gets its checkpoint (and preview)
                $version = $checkpoint ? self::checkpoint($locked, $user, $current, $previewImageUrl) : null;

                return ['status' => 'covered', 'page' => $locked, 'version' => $version];
            }

            $content = [
                'type' => 'excalidraw',
                'elements' => $scene['elements'],
                'appState' => $scene['appState'],
                // images only grow: a browser sends the ones the server hasn't confirmed yet
                'files' => self::storedFiles($current) + $scene['files'],
                'libraryItems' => $scene['libraryItems'] ?? (is_array($current['libraryItems'] ?? null) ? $current['libraryItems'] : []),
            ];
            $preview = $previewImageUrl ?? ($current['previewImageUrl'] ?? null);
            if (is_string($preview) && $preview !== '') {
                $content['previewImageUrl'] = $preview;
            }

            // loose ==: the same scene read back from the database (key order, 10.0 vs 10) is unchanged
            $unchanged = $previewImageUrl === null
                && $content['elements'] == ($current['elements'] ?? null)
                && $content['files'] == self::storedFiles($current)
                && $content['libraryItems'] == ($current['libraryItems'] ?? []);

            if ($unchanged) {
                $version = $checkpoint ? $locked->recordVersion($user) : null;
                $status = 'unchanged';
            } else {
                $locked->collabWrite = true;
                try {
                    $version = $locked->saveWhiteboard($user, $content, $checkpoint, live: true);
                } finally {
                    $locked->collabWrite = false;
                }
                $status = 'saved';
            }
            $state->update(['html_vector' => $vector]);

            return ['status' => $status, 'page' => $locked, 'version' => $version];
        });
    }

    /**
     * Validate a browser's scene; null when it isn't one.
     *
     * @return array{elements: list<array<string, mixed>>, appState: array<string, mixed>, files: array<string, array<string, mixed>>, libraryItems: array<int, mixed>|null}|null
     */
    public static function normalizeScene(mixed $scene): ?array
    {
        if (! is_array($scene) || ! is_array($scene['elements'] ?? null) || ! array_is_list($scene['elements'])) {
            return null;
        }
        foreach ($scene['elements'] as $element) {
            if (! is_array($element) || ! is_string($element['id'] ?? null) || ! is_string($element['type'] ?? null)) {
                return null;
            }
        }
        $appState = $scene['appState'] ?? [];
        $library = $scene['libraryItems'] ?? null;
        if (! is_array($appState) || ($library !== null && (! is_array($library) || ! array_is_list($library)))) {
            return null;
        }

        $files = [];
        foreach (is_array($scene['files'] ?? null) ? $scene['files'] : [] as $id => $file) {
            if (! is_string($id) || strlen($id) > 200 || ! is_array($file)
                || ! is_string($file['dataURL'] ?? null) || strlen($file['dataURL']) > self::MAX_FILE_CHARS
                || ! preg_match('#^data:image/(png|jpeg|gif|webp|svg\+xml|bmp|x-icon|avif);base64,#', $file['dataURL'], $type)) {
                return null;
            }
            $files[$id] = [
                'id' => $id,
                'dataURL' => $file['dataURL'],
                'mimeType' => 'image/'.$type[1], // what the data URL is, not what the browser claims
                'created' => is_int($file['created'] ?? null) ? $file['created'] : now()->getTimestampMs(),
            ];
        }

        return ['elements' => $scene['elements'], 'appState' => $appState, 'files' => $files, 'libraryItems' => $library];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private static function storedFiles(array $content): array
    {
        return is_array($content['files'] ?? null) ? $content['files'] : [];
    }

    /**
     * An explicit save of a scene that is already stored: version (+ the new preview image).
     *
     * @param  array<string, mixed>  $current
     */
    private static function checkpoint(Page $page, User $user, array $current, ?string $previewImageUrl): PageVersion
    {
        if ($previewImageUrl !== null && $current !== []) {
            $page->collabWrite = true;
            try {
                $page->update(['content' => ['previewImageUrl' => $previewImageUrl] + $current]);
            } finally {
                $page->collabWrite = false;
            }
        }

        return $page->recordVersion($user);
    }
}
