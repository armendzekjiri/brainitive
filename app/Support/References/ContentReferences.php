<?php

namespace App\Support\References;

use App\Models\Board;
use App\Models\ContentReference;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Plans\PlanIssueSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Linked content and backlinks (BRAI-71): references between issues, docs / whiteboards,
 * helpdesk tickets and plans of one project.
 *
 * - **Manual** references are picked by people ("Link related content").
 * - **Derived** references mirror existing domain links — plan rows and whiteboard cards linked
 *   to issues, and smart links in a doc or an issue description (BRAI-143, SmartLinks). Model
 *   hooks call syncDerived() / syncDerivedTargets() whenever the plan / whiteboard / doc / issue
 *   is saved, so removing the row link, the card or the smart link removes the backlink. They
 *   are read-only here.
 *
 * Visibility is decided in batch, with the rules the policies apply inside a project the user
 * can already open: pages through Page::visibleTo() (drafts: author + admins), everything else
 * when it and its board / helpdesk aren't trashed. Hidden endpoints are dropped before anything
 * is counted, so a private draft never shows up as a title, a count or a search result.
 */
class ContentReferences
{
    /** Results per type in the picker. */
    public const PICKER_LIMIT = 8;

    /** Label of a derived reference, by the type of its source (a doc's smart links: see derivedLabel()). */
    private const DERIVED_LABELS = ['plan' => 'Plan row', 'page' => 'Whiteboard card', 'item' => 'Smart link'];

    /** Target types a derived reference can point to. */
    public const DERIVED_TARGET_TYPES = ['item', 'page', 'ticket', 'plan'];

    private const PAGE_SUMMARY_COLUMNS = ['id', 'project_id', 'uuid', 'name', 'icon', 'type', 'status', 'created_by', 'updated_at'];

    /**
     * A live endpoint of the project by type key + id; null when the type is unsupported, the
     * id belongs to another project, the record (or its board / helpdesk) is trashed, or it's
     * a folder. Doesn't check permissions.
     */
    public static function resolve(Project $project, string $type, int $id): ?Model
    {
        if (! isset(ContentReference::TYPES[$type])) {
            return null;
        }
        $model = self::summaryQuery($project, $type)->whereKey($id)->first();
        if ($model instanceof Page || $model instanceof Plan) {
            $model->setRelation('project', $project);
        }

        return $model;
    }

    /**
     * Add a manual reference; a repeated link returns the existing row (`wasRecentlyCreated`
     * false), never a second one.
     *
     * @throws \DomainException for self-links and issue → issue (that's an issue link)
     */
    public static function link(Project $project, User $user, Model $source, Model $target): ContentReference
    {
        $sourceType = ContentReference::typeOf($source);
        $targetType = ContentReference::typeOf($target);

        if ($sourceType === $targetType && $source->getKey() === $target->getKey()) {
            throw new \DomainException('Content can’t be linked to itself.');
        }
        if ($sourceType === 'item' && $targetType === 'item') {
            throw new \DomainException('Link issues to each other with “Linked issues”.');
        }

        // createOrFirst runs the insert in a savepoint: a concurrent duplicate can't abort the transaction (Postgres)
        return ContentReference::createOrFirst([
            'source_type' => $sourceType,
            'source_id' => $source->getKey(),
            'target_type' => $targetType,
            'target_id' => $target->getKey(),
            'origin' => ContentReference::ORIGIN_MANUAL,
        ], [
            'project_id' => $project->id,
            'created_by' => $user->id,
        ]);
    }

    /**
     * What the open resource links to (`related`) and what links to it (`referenced_by`), only
     * with endpoints the user can see. A manual + derived pair of the same endpoints is one entry.
     * The query count doesn't grow with the number of references.
     *
     * @return array{related: list<array<string, mixed>>, referenced_by: list<array<string, mixed>>, can_link: bool}
     */
    public static function forEndpoint(Workspace $workspace, Project $project, User $user, Model $model): array
    {
        $type = ContentReference::typeOf($model);
        $id = (int) $model->getKey();

        $references = ContentReference::query()
            ->where('project_id', $project->id)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('source_type', $type)->where('source_id', $id))
                ->orWhere(fn (Builder $q) => $q->where('target_type', $type)->where('target_id', $id)))
            ->orderBy('id')
            ->get();

        $wanted = [];
        foreach ($references as $reference) {
            [$otherType, $otherId] = self::otherEnd($reference, $type, $id);
            $wanted[$otherType][$otherId] = true;
        }

        $visible = self::visibleEndpoints($project, $user, array_map('array_keys', $wanted));
        $canLink = $user->can('update', $model);
        $canEditType = [];

        $groups = ['related' => [], 'referenced_by' => []];
        foreach ($references as $reference) {
            [$otherType, $otherId] = self::otherEnd($reference, $type, $id);
            $other = $visible[$otherType][$otherId] ?? null;
            if ($other === null) {
                continue;
            }
            $outgoing = $reference->source_type === $type && $reference->source_id === $id;
            $bucket = $outgoing ? 'related' : 'referenced_by';
            $key = "{$otherType}:{$otherId}";

            $entry = $groups[$bucket][$key] ?? self::summary($workspace, $project, $other) + [
                'manual' => false,
                'derived' => false,
                'derived_label' => null,
                'reference_id' => null,
                'can_remove' => false,
            ];

            if ($reference->isDerived()) {
                $entry['derived'] = true;
                $entry['derived_label'] = self::derivedLabel($reference->source_type, $outgoing ? $model : $other);
            } else {
                $entry['manual'] = true;
                $entry['reference_id'] = $reference->id;
                // removing needs edit rights on the source (the target, this resource, is visible)
                $entry['can_remove'] = $outgoing
                    ? $canLink
                    : ($canEditType[$otherType] ??= $user->can('update', $other));
            }
            $groups[$bucket][$key] = $entry;
        }

        return [
            'related' => array_values($groups['related']),
            'referenced_by' => array_values($groups['referenced_by']),
            'can_link' => $canLink,
        ];
    }

    /**
     * Picker search: content of the project the user can see, minus the source itself and (for
     * an issue) other issues. `linked` marks what the source already links to.
     *
     * @return list<array<string, mixed>>
     */
    public static function candidates(Workspace $workspace, Project $project, User $user, Model $source, ?string $search): array
    {
        $sourceType = ContentReference::typeOf($source);

        $linked = ContentReference::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $source->getKey())
            ->where('origin', ContentReference::ORIGIN_MANUAL)
            ->get(['target_type', 'target_id'])
            ->mapWithKeys(fn (ContentReference $r) => ["{$r->target_type}:{$r->target_id}" => true])
            ->all();

        $types = array_values(array_filter(
            array_keys(ContentReference::TYPES),
            fn (string $type) => ! ($sourceType === 'item' && $type === 'item'),
        ));

        return array_map(function (Model $model) use ($workspace, $project, $linked) {
            $summary = self::summary($workspace, $project, $model);

            return $summary + ['linked' => isset($linked[$summary['key']])];
        }, self::search($project, $user, $search, $types, $source));
    }

    /**
     * Content of the project the user can see, newest first, up to PICKER_LIMIT per type,
     * matching the title (or the key of issues / tickets), loaded with what summary() needs.
     * Used by the "Link related content" picker and the smart link picker (BRAI-143).
     *
     * @param  list<string>  $types
     * @return list<Model>
     */
    public static function search(Project $project, User $user, ?string $search, array $types, ?Model $exclude = null): array
    {
        $search = trim((string) $search);
        $excludeType = $exclude ? ContentReference::typeOf($exclude) : null;

        $results = [];
        foreach ($types as $type) {
            if (! isset(ContentReference::TYPES[$type])) {
                continue;
            }
            $query = self::visibleQuery($project, $user, $type);
            if ($exclude !== null && $type === $excludeType) {
                $query->whereKeyNot($exclude->getKey());
            }
            if ($search !== '') {
                $column = self::titleColumn($type);
                $query->where(fn (Builder $q) => match ($type) {
                    'item', 'ticket' => $q->whereLike($column, "%{$search}%")->orWhereLike('key', "%{$search}%"),
                    default => $q->whereLike($column, "%{$search}%"),
                });
            }
            array_push($results, ...$query->orderByDesc('updated_at')->orderByDesc('id')->limit(self::PICKER_LIMIT)->get()->all());
        }

        return $results;
    }

    /**
     * Make the derived references of a plan / whiteboard match the issues it links to now.
     * Issues outside the source's project are ignored; trashed issues keep their row (hidden
     * while trashed, back on restore).
     *
     * @param  list<int|string>  $itemIds
     */
    public static function syncDerived(Model $source, array $itemIds): void
    {
        $projectId = (int) $source->getAttribute('project_id');
        if (ContentReference::typeOf($source) === null || ! $projectId) {
            return;
        }

        $itemIds = array_values(array_unique(array_map('intval', array_filter($itemIds, 'is_numeric'))));
        $valid = $itemIds === [] ? [] : Item::withTrashed()
            ->whereIn('id', $itemIds)
            ->whereIn('board_id', Board::withTrashed()->where('project_id', $projectId)->select('id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        self::syncDerivedTargets($source, $projectId, ['item' => $valid]);
    }

    /**
     * Make the derived references of a source match the given targets, for the target types
     * given (other target types are left alone). The ids must already be endpoints of the
     * source's project — callers check that.
     *
     * @param  array<string, list<int>>  $targetsByType
     */
    public static function syncDerivedTargets(Model $source, int $projectId, array $targetsByType): void
    {
        $sourceType = ContentReference::typeOf($source);
        if ($sourceType === null || ! $projectId || $targetsByType === []) {
            return;
        }

        $existing = ContentReference::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $source->getKey())
            ->where('origin', ContentReference::ORIGIN_DERIVED)
            ->whereIn('target_type', array_keys($targetsByType))
            ->get(['id', 'target_type', 'target_id']);

        $wanted = [];
        foreach ($targetsByType as $type => $ids) {
            foreach ($ids as $id) {
                if (! ($type === $sourceType && (int) $id === (int) $source->getKey())) {
                    $wanted["{$type}:".(int) $id] = [$type, (int) $id];
                }
            }
        }

        $have = [];
        $gone = [];
        foreach ($existing as $reference) {
            $key = "{$reference->target_type}:{$reference->target_id}";
            if (isset($wanted[$key])) {
                $have[$key] = true;
            } else {
                $gone[] = $reference->id;
            }
        }
        if ($gone !== []) {
            ContentReference::query()->whereKey($gone)->delete();
        }

        $new = array_diff_key($wanted, $have);
        if ($new !== []) {
            $now = now();
            // ON CONFLICT DO NOTHING (pgsql) / INSERT OR IGNORE (sqlite): a parallel save can't fail or abort a transaction
            ContentReference::insertOrIgnore(array_values(array_map(fn (array $target) => [
                'project_id' => $projectId,
                'source_type' => $sourceType,
                'source_id' => $source->getKey(),
                'target_type' => $target[0],
                'target_id' => $target[1],
                'origin' => ContentReference::ORIGIN_DERIVED,
                'created_by' => Auth::id(),
                'created_at' => $now,
                'updated_at' => $now,
            ], $new)));
        }
    }

    /** Derived references of a plan: its rows linked to issues. */
    public static function syncPlan(Plan $plan): void
    {
        self::syncDerived($plan, PlanIssueSync::linkedIds($plan->data ?? []));
    }

    /** Derived references of a whiteboard: its issue cards. */
    public static function syncWhiteboard(Page $page): void
    {
        self::syncDerived($page, self::whiteboardItemIds($page->content));
    }

    /**
     * Issue ids of the issue cards on a whiteboard scene (deleted elements don't count).
     *
     * @return list<int>
     */
    public static function whiteboardItemIds(mixed $content): array
    {
        $ids = [];
        foreach (is_array($content) && is_array($content['elements'] ?? null) ? $content['elements'] : [] as $element) {
            if (! is_array($element) || ! empty($element['isDeleted'])) {
                continue;
            }
            $data = $element['customData'] ?? null;
            if (is_array($data) && ($data['brainitive'] ?? null) === 'issue' && is_numeric($data['itemId'] ?? null)) {
                $ids[(int) $data['itemId']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Remove references (manual and derived) whose endpoint no longer exists in the project:
     * hard-deleted, cascaded by the database (child pages, subtasks), or moved to another
     * project. Soft-deleted endpoints keep their references so a restore brings them back.
     */
    public static function pruneMissing(int $projectId): void
    {
        $existing = [
            'item' => Item::withTrashed()->whereIn('board_id', Board::withTrashed()->where('project_id', $projectId)->select('id'))->select('id'),
            'page' => Page::query()->where('project_id', $projectId)->select('id'),
            'ticket' => Ticket::withTrashed()->whereIn('helpdesk_id', Helpdesk::withTrashed()->where('project_id', $projectId)->select('id'))->select('id'),
            'plan' => Plan::withTrashed()->where('project_id', $projectId)->select('id'),
        ];

        foreach ($existing as $type => $ids) {
            ContentReference::query()
                ->where('project_id', $projectId)
                ->where(fn (Builder $q) => $q
                    ->where(fn (Builder $q) => $q->where('source_type', $type)->whereNotIn('source_id', clone $ids))
                    ->orWhere(fn (Builder $q) => $q->where('target_type', $type)->whereNotIn('target_id', clone $ids)))
                ->delete();
        }
    }

    /**
     * Canonical description of an endpoint: type, title, subtitle and the URL that opens it
     * (the same URL ResourceLocator returns).
     *
     * @return array{key: string, type: string, id: int, label: string, title: string, subtitle: string|null, url: string, status: string|null}
     */
    public static function summary(Workspace $workspace, Project $project, Model $model): array
    {
        $params = [$workspace->slug, $project->slug];
        $type = ContentReference::typeOf($model);

        [$label, $title, $subtitle, $url, $status] = match (true) {
            $model instanceof Item => [
                'Issue', $model->title ?: (string) $model->key, $model->key,
                route('boards.show', [...$params, $model->board->slug]).'#item-'.$model->id, null,
            ],
            $model instanceof Page => [
                self::pageIsWhiteboard($model) ? 'Whiteboard' : 'Doc', $model->name, null,
                route('pages.show', [...$params, $model->uuid]), in_array($model->status, ['draft', 'archived'], true) ? $model->status : null,
            ],
            $model instanceof Ticket => [
                'Ticket', $model->subject ?: (string) $model->key, $model->key,
                route('tickets.show', [...$params, $model->helpdesk->slug, $model->id]), null,
            ],
            $model instanceof Plan => [
                'Plan', $model->name, null, route('plans.show', [...$params, $model->slug]), null,
            ],
        };

        return [
            'key' => "{$type}:{$model->getKey()}",
            'type' => $type,
            'id' => (int) $model->getKey(),
            'label' => $label,
            'title' => (string) $title,
            'subtitle' => $subtitle,
            'url' => $url,
            'status' => $status,
        ];
    }

    /**
     * Visible endpoints by type and id, one query per type (plus its eager load).
     *
     * @param  array<string, list<int>>  $idsByType
     * @return array<string, array<int, Model>>
     */
    private static function visibleEndpoints(Project $project, User $user, array $idsByType): array
    {
        $visible = [];
        foreach ($idsByType as $type => $ids) {
            if ($ids === [] || ! isset(ContentReference::TYPES[$type])) {
                continue;
            }
            $visible[$type] = self::visibleQuery($project, $user, $type)
                ->whereKey($ids)
                ->get()
                ->keyBy(fn (Model $m) => (int) $m->getKey())
                ->all();
        }

        return $visible;
    }

    /**
     * Endpoints of one type the user can see, with what summary() needs. Mirrors the view
     * policies for a user who can open the project (callers have authorized one resource of it).
     */
    private static function visibleQuery(Project $project, User $user, string $type): Builder
    {
        $query = self::summaryQuery($project, $type);

        return $type === 'page' ? $query->visibleTo($user) : $query;
    }

    /**
     * Live endpoints of a type with only the columns summary() and the policies read: whiteboard
     * scenes and plan documents can be megabytes, and none of it is needed here.
     */
    private static function summaryQuery(Project $project, string $type): Builder
    {
        $query = self::endpointQuery($project, $type);

        return match ($type) {
            'item' => $query->select(['id', 'board_id', 'project_id', 'key', 'title', 'created_by', 'updated_at'])->with('board:id,slug,project_id'),
            'page' => $query->select(self::PAGE_SUMMARY_COLUMNS)
                // whiteboards are saved as {"type":"excalidraw",…}; don't load whole scenes for a label
                ->selectRaw('case when content like ? then 1 else 0 end as is_board', ['{"type":"excalidraw"%']),
            'ticket' => $query->select(['id', 'helpdesk_id', 'key', 'subject', 'assigned_to', 'updated_at'])->with('helpdesk:id,slug,project_id'),
            'plan' => $query->select(['id', 'project_id', 'name', 'slug', 'created_by', 'updated_at']),
        };
    }

    /**
     * Live endpoints of a type in the project: not trashed, board / helpdesk not trashed, pages
     * of type `page` (docs and whiteboards; folders aren't content).
     */
    private static function endpointQuery(Project $project, string $type): Builder
    {
        return match ($type) {
            'item' => Item::query()->whereIn('board_id', Board::query()->where('project_id', $project->id)->select('id')),
            'page' => Page::query()->where('project_id', $project->id)->where('type', 'page'),
            'ticket' => Ticket::query()->whereIn('helpdesk_id', Helpdesk::query()->where('project_id', $project->id)->select('id')),
            'plan' => Plan::query()->where('project_id', $project->id),
        };
    }

    private static function titleColumn(string $type): string
    {
        return match ($type) {
            'item' => 'title',
            'ticket' => 'subject',
            default => 'name',
        };
    }

    /** "Plan row", "Whiteboard card" or "Smart link" (a doc's or issue description's smart link). */
    private static function derivedLabel(string $sourceType, ?Model $source): string
    {
        if ($sourceType === 'page' && $source instanceof Page && ! self::pageIsWhiteboard($source)) {
            return 'Smart link';
        }

        return self::DERIVED_LABELS[$sourceType] ?? 'Linked';
    }

    private static function pageIsWhiteboard(Page $page): bool
    {
        $flag = $page->getAttributes()['is_board'] ?? null;

        return $flag === null ? $page->is_whiteboard : (bool) (int) $flag;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function otherEnd(ContentReference $reference, string $type, int $id): array
    {
        return $reference->source_type === $type && $reference->source_id === $id
            ? [$reference->target_type, $reference->target_id]
            : [$reference->source_type, $reference->source_id];
    }
}
