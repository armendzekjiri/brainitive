<?php

namespace App\Models;

use App\Support\Events\DomainEvents;
use App\Support\Events\EventPayloads;
use App\Support\References\ContentReferences;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Page extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'parent_id',
        'created_by',
        'name',
        'slug',
        'uuid',
        'icon',
        'type',
        'content',
        'position',
        'status',
        'published_at',
        'last_edited_by',
        'draft_content',
        'draft_name',
        'draft_icon',
        'draft_updated_at',
        'draft_updated_by',
        'edit_revision',
        'current_version',
    ];

    protected $casts = [
        'content' => 'array',
        'draft_content' => 'array',
        'published_at' => 'datetime',
        'draft_updated_at' => 'datetime',
        'edit_revision' => 'integer',
        'current_version' => 'integer',
    ];

    /**
     * Mirror the column defaults so freshly created models carry them in memory.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'edit_revision' => 0,
        'current_version' => 0,
    ];

    /**
     * Unpublished changes are for editors only — PageController::show() reveals them.
     *
     * @var list<string>
     */
    protected $hidden = [
        'draft_content',
        'draft_name',
        'draft_icon',
    ];

    protected $appends = ['is_whiteboard'];

    /**
     * Whether this page is a whiteboard (Excalidraw) page.
     */
    public function getIsWhiteboardAttribute(): bool
    {
        $content = $this->content;
        if (! is_array($content)) {
            return false;
        }

        return ($content['type'] ?? '') === 'excalidraw'
            || (array_key_exists('elements', $content) && array_key_exists('appState', $content));
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($page) {
            // Generate UUID
            if (empty($page->uuid)) {
                $page->uuid = Str::uuid()->toString();
            }

            if (empty($page->slug)) {
                $page->slug = Str::slug($page->name);
            }

            // Make slug unique within the same parent
            $originalSlug = $page->slug;
            $count = 1;
            while (static::where('project_id', $page->project_id)
                ->where('parent_id', $page->parent_id)
                ->where('slug', $page->slug)
                ->exists()) {
                $page->slug = $originalSlug.'-'.$count++;
            }

            // Set position to last
            if ($page->position === 0) {
                $maxPosition = static::where('project_id', $page->project_id)
                    ->where('parent_id', $page->parent_id)
                    ->max('position') ?? -1;
                $page->position = $maxPosition + 1;
            }
        });

        // Auto-watch: add creator to watchers after page is created
        static::created(function (Page $page) {
            if ($page->created_by) {
                $page->watchers()->syncWithoutDetaching([$page->created_by]);
            }
        });

        // Issue cards on a whiteboard are derived references (BRAI-71)
        static::saved(function (Page $page) {
            if ($page->type === 'page' && ($page->wasRecentlyCreated || $page->wasChanged('content')) && $page->is_whiteboard) {
                ContentReferences::syncWhiteboard($page);
            }
        });
        // Pages are hard-deleted and their subpages go with them (FK cascade, no events)
        static::deleted(fn (Page $page) => ContentReferences::pruneMissing((int) $page->project_id));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Page::class, 'parent_id')->orderBy('position');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class, 'parent_id')
            ->where('type', 'page')
            ->orderBy('position');
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Page::class, 'parent_id')
            ->where('type', 'folder')
            ->orderBy('position');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all users watching this page.
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'page_watchers')
            ->withPivot('created_at');
    }

    /**
     * Add a user to the watch list (idempotent).
     */
    public function addWatcher(User $user): void
    {
        $this->watchers()->syncWithoutDetaching([$user->id]);
    }

    /**
     * Remove a user from the watch list.
     */
    public function removeWatcher(User $user): void
    {
        $this->watchers()->detach($user->id);
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    /**
     * User who last autosaved the unpublished changes of a published page.
     */
    public function draftEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'draft_updated_by');
    }

    /**
     * Published snapshots, newest first.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PageVersion::class)->orderByDesc('version');
    }

    /**
     * Limit to pages the user may see: unpublished pages are private to their author and
     * project/workspace admins; folders and published/archived pages follow project access.
     *
     * @param  Builder<Page>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $adminProjectIds = $user->adminProjectIds();

        $query->where(function (Builder $visible) use ($user, $adminProjectIds) {
            $visible->where('pages.type', 'folder')
                ->orWhere('pages.status', '!=', 'draft')
                ->orWhere('pages.created_by', $user->id)
                ->orWhereIn('pages.project_id', $adminProjectIds);
        });
    }

    public function isFolder(): bool
    {
        return $this->type === 'folder';
    }

    public function isPage(): bool
    {
        return $this->type === 'page';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Whether a published page has autosaved changes that readers don't see yet.
     */
    public function hasUnpublishedChanges(): bool
    {
        return $this->isPublished() && $this->draft_updated_at !== null;
    }

    /**
     * Autosave the editor state. Never-published pages are their own draft, so the change
     * lands in content directly; published pages collect changes in the draft columns.
     *
     * @param  array{content?: array<string, mixed>|null, name?: string, icon?: string|null}  $changes
     */
    public function saveDraft(User $user, array $changes): void
    {
        $attributes = ['edit_revision' => $this->edit_revision + 1];

        if ($this->isPublished()) {
            if (array_key_exists('content', $changes)) {
                $attributes['draft_content'] = $this->withExcalidrawEmbeds($changes['content'], $this->draft_content ?? $this->content);
            } elseif (! $this->hasUnpublishedChanges()) {
                $attributes['draft_content'] = $this->content;
            }
            $attributes['draft_name'] = $changes['name'] ?? ($this->hasUnpublishedChanges() ? $this->draft_name : $this->name);
            $attributes['draft_icon'] = array_key_exists('icon', $changes)
                ? $changes['icon']
                : ($this->hasUnpublishedChanges() ? $this->draft_icon : $this->icon);
            $attributes['draft_updated_at'] = now();
            $attributes['draft_updated_by'] = $user->id;
        } else {
            if (array_key_exists('content', $changes)) {
                $attributes['content'] = $this->withExcalidrawEmbeds($changes['content'], $this->content);
            }
            if (isset($changes['name'])) {
                $attributes['name'] = $changes['name'];
            }
            if (array_key_exists('icon', $changes)) {
                $attributes['icon'] = $changes['icon'];
            }
            $attributes['last_edited_by'] = $user->id;
        }

        $this->update($attributes);
    }

    /**
     * First publish (or re-publish after unpublishing): readers see the page and v1 is recorded.
     */
    public function publish(?User $user = null, ?string $message = null): PageVersion
    {
        $firstPublish = $this->getOriginal('status') !== 'published';
        $this->update([
            'status' => 'published',
            'published_at' => now(),
            'last_edited_by' => $user?->id ?? $this->last_edited_by,
        ]);

        $version = $this->recordVersion($user, $message);
        DomainEvents::emit(EventPayloads::projectWorkspaceId($this->project_id), 'page.published', fn () => EventPayloads::page($this, $firstPublish), $user); // webhooks (BRAI-104)

        return $version;
    }

    /**
     * Make the unpublished changes live and record them as a new version.
     */
    public function publishChanges(User $user, ?string $message = null): PageVersion
    {
        if ($this->hasUnpublishedChanges()) {
            $this->fill([
                'content' => $this->withExcalidrawEmbeds($this->draft_content, $this->content),
                'name' => $this->draft_name ?: $this->name,
                'icon' => $this->draft_icon,
            ]);
        }

        $this->fill($this->clearedDraftAttributes() + [
            'last_edited_by' => $user->id,
            'edit_revision' => $this->edit_revision + 1,
        ])->save();

        $version = $this->recordVersion($user, $message);
        DomainEvents::emit(EventPayloads::projectWorkspaceId($this->project_id), 'page.published', fn () => EventPayloads::page($this, false), $user); // webhooks (BRAI-104)

        return $version;
    }

    /**
     * Throw away the unpublished changes of a published page.
     */
    public function discardDraft(): void
    {
        $this->update($this->clearedDraftAttributes() + ['edit_revision' => $this->edit_revision + 1]);
    }

    /**
     * Take the page back to an author-only draft, keeping pending changes as its content.
     */
    public function unpublish(User $user): void
    {
        $attributes = ['status' => 'draft', 'last_edited_by' => $user->id];

        if ($this->hasUnpublishedChanges()) {
            $attributes += [
                'content' => $this->withExcalidrawEmbeds($this->draft_content, $this->content),
                'name' => $this->draft_name ?: $this->name,
                'icon' => $this->draft_icon,
            ];
        }

        $this->update($attributes + $this->clearedDraftAttributes() + ['edit_revision' => $this->edit_revision + 1]);
    }

    /**
     * Bring back an earlier version. On a published page this is itself a new version
     * (like Confluence), so nothing in the history is ever lost.
     */
    public function restoreVersion(PageVersion $version, User $user): ?PageVersion
    {
        $this->fill([
            'content' => $this->withExcalidrawEmbeds($version->content, $this->content),
            'name' => $version->name,
            'icon' => $version->icon,
            'last_edited_by' => $user->id,
            'edit_revision' => $this->edit_revision + 1,
        ] + $this->clearedDraftAttributes())->save();

        // Whiteboards have no draft step — their content is always live, so keep history too
        return $this->isPublished() || $this->is_whiteboard
            ? $this->recordVersion($user, "Restored from version {$version->version}")
            : null;
    }

    /** A whiteboard gets a new history entry at most this often while autosaving. */
    public const WHITEBOARD_VERSION_INTERVAL_MINUTES = 30;

    /**
     * Save a whiteboard scene (whiteboards are edited live, Miro-style) and keep the history
     * useful without a version per keystroke: a checkpoint is recorded on an explicit save,
     * when the newest version is older than the interval, or when someone else saved last.
     *
     * @param  array<string, mixed>  $content
     */
    public function saveWhiteboard(User $user, array $content, bool $checkpoint = false): ?PageVersion
    {
        $this->update([
            'content' => $content,
            'last_edited_by' => $user->id,
            'edit_revision' => $this->edit_revision + 1,
        ]);

        $latest = $this->versions()->first(['id', 'created_by', 'created_at']);
        $isStale = $latest === null
            || $latest->created_at->lt(now()->subMinutes(self::WHITEBOARD_VERSION_INTERVAL_MINUTES))
            || $latest->created_by !== $user->id;

        return $checkpoint || $isStale ? $this->recordVersion($user) : null;
    }

    /**
     * Snapshot the current live state as the next version.
     */
    public function recordVersion(?User $user = null, ?string $message = null): PageVersion
    {
        $this->increment('current_version');

        return $this->versions()->create([
            'version' => $this->current_version,
            'name' => $this->name,
            'icon' => $this->icon,
            'content' => $this->content,
            'message' => $message !== null && trim($message) !== '' ? trim($message) : null,
            'created_by' => $user?->id,
        ]);
    }

    /**
     * @return array<string, null>
     */
    private function clearedDraftAttributes(): array
    {
        return [
            'draft_content' => null,
            'draft_name' => null,
            'draft_icon' => null,
            'draft_updated_at' => null,
            'draft_updated_by' => null,
        ];
    }

    /**
     * Excalidraw embed scenes are saved straight into `content` by their own endpoint, so
     * keep them when the editor sends new HTML (which never carries them).
     *
     * @param  array<string, mixed>|null  $content
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>|null
     */
    private function withExcalidrawEmbeds(?array $content, ?array $existing): ?array
    {
        $embeds = array_merge($existing['excalidrawEmbeds'] ?? [], $this->content['excalidrawEmbeds'] ?? [], $content['excalidrawEmbeds'] ?? []);
        if ($content === null || $embeds === []) {
            return $content;
        }
        $content['excalidrawEmbeds'] = $embeds;

        return $content;
    }

    // Get all descendants recursively
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    // Get breadcrumb path
    public function getPathAttribute(): array
    {
        $path = [];
        $current = $this;

        while ($current) {
            array_unshift($path, [
                'id' => $current->id,
                'uuid' => $current->uuid,
                'name' => $current->name,
                'slug' => $current->slug,
                'type' => $current->type,
            ]);
            $current = $current->parent;
        }

        return $path;
    }
}
