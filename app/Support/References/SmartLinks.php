<?php

namespace App\Support\References;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Helpdesk;
use App\Models\HelpdeskColumn;
use App\Models\Item;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Smart links (BRAI-143): issues, docs / whiteboards, tickets and plans linked inside a doc or
 * an issue description, shown as a live chip or card. They are saved as a normal anchor, so
 * everything else (share page, client portal, MCP, exports, search) sees a plain link:
 *
 *     <a href="<canonical url>" data-smart-link="item:12" data-smart-link-view="inline">WEB-12 Fix login</a>
 *
 * Refs: `item:<id>`, `page:<uuid>`, `ticket:<id>`, `plan:<id>`, plus `plan:<project-slug>/<plan-slug>`
 * (what a pasted plan URL gives; resolve() answers with the canonical `plan:<id>`).
 *
 * resolve() applies the visibility rules of ContentReferences / the view policies across the
 * workspace: the project must be one the user can open, pages follow Page::visibleTo (drafts:
 * author + admins), nothing trashed (nor its board / helpdesk / project). What exists in the
 * workspace but isn't visible answers `restricted`, everything else `not_found` — never a title.
 *
 * Derived references: a smart link in a doc (its published `content`) or in an issue description
 * to content of the same project is a `derived` content reference (synced by Page::saved and
 * Item::saved), so the target lists the doc / issue under "Referenced by". Cross-project smart
 * links render, but make no reference.
 */
final class SmartLinks
{
    public const TYPES = ['item', 'page', 'ticket', 'plan'];

    /** Refs per request of the batched endpoint. */
    public const MAX_REFS = 100;

    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * A ref split into its parts, or null when it isn't one.
     *
     * @return array{type: string, id?: int, uuid?: string, project?: string, slug?: string}|null
     */
    public static function parse(string $ref): ?array
    {
        $ref = trim($ref);
        if (preg_match('/^(item|ticket|plan):([1-9]\d{0,17})$/', $ref, $m)) {
            return ['type' => $m[1], 'id' => (int) $m[2]];
        }
        if (preg_match('/^page:('.self::UUID.')$/i', $ref, $m)) {
            return ['type' => 'page', 'uuid' => strtolower($m[1])];
        }
        if (preg_match('/^plan:([a-z0-9][a-z0-9-]{0,99})\/([a-z0-9][a-z0-9-]{0,199})$/', $ref, $m)) {
            return ['type' => 'plan', 'project' => $m[1], 'slug' => $m[2]];
        }

        return null;
    }

    /**
     * The smart link refs in saved HTML (valid ones, once each, in order).
     *
     * @return list<string>
     */
    public static function refsInHtml(?string $html): array
    {
        if ($html === null || ! str_contains($html, 'data-smart-link')) {
            return [];
        }
        // data-smart-link="…" but not data-smart-link-view="…"
        preg_match_all('/\sdata-smart-link\s*=\s*(["\'])(.{1,300}?)\1/i', $html, $matches);

        $refs = [];
        foreach ($matches[2] as $ref) {
            $ref = html_entity_decode($ref, ENT_QUOTES | ENT_HTML5);
            if (self::parse($ref) !== null) {
                $refs[$ref] = true;
            }
        }

        return array_keys($refs);
    }

    /** The HTML of a doc's content (`{type: lexical, html}`), or null for other content. */
    public static function pageHtml(mixed $content): ?string
    {
        if (is_array($content)) {
            return is_string($content['html'] ?? null) ? $content['html'] : null;
        }

        return is_string($content) ? $content : null;
    }

    /**
     * Live data for a batch of refs, keyed by the requested ref. Query count doesn't grow with
     * the number of refs.
     *
     * @param  list<string>  $refs
     * @return array<string, array<string, mixed>>
     */
    public static function resolve(Workspace $workspace, User $user, array $refs): array
    {
        $out = [];
        $any = false;
        $wanted = ['item' => [], 'page' => [], 'ticket' => [], 'plan' => [], 'plan_alias' => []];
        foreach (array_unique($refs) as $ref) {
            $parsed = self::parse((string) $ref);
            $out[$ref] = ['state' => 'not_found'];
            if ($parsed === null) {
                continue;
            }
            $any = true;
            match (true) {
                $parsed['type'] === 'page' => $wanted['page'][$parsed['uuid']][] = $ref,
                isset($parsed['slug']) => $wanted['plan_alias'][$parsed['project'].'/'.$parsed['slug']][] = $ref,
                default => $wanted[$parsed['type']][$parsed['id']][] = $ref,
            };
        }
        if (! $any) {
            return $out;
        }

        $projects = Project::query()->where('workspace_id', $workspace->id)->get(['id', 'workspace_id', 'name', 'slug'])->keyBy('id');
        $accessible = self::accessibleProjectIds($workspace, $user, $projects);
        $context = new SmartLinkContext($workspace, $user, $projects, $accessible);

        foreach (self::items($context, array_keys($wanted['item'])) as $item) {
            self::answer($out, $wanted['item'][$item->id] ?? [], $context, $item, fn () => self::itemData($context, $item));
        }
        foreach (self::pages($context, array_keys($wanted['page'])) as $page) {
            self::answer($out, $wanted['page'][strtolower((string) $page->uuid)] ?? [], $context, $page, fn () => self::pageData($context, $page), fn () => $context->canSeePage($page));
        }
        foreach (self::tickets($context, array_keys($wanted['ticket'])) as $ticket) {
            self::answer($out, $wanted['ticket'][$ticket->id] ?? [], $context, $ticket, fn () => self::ticketData($context, $ticket));
        }
        foreach (self::plans($context, array_keys($wanted['plan']), array_keys($wanted['plan_alias'])) as $plan) {
            $alias = ($projects[$plan->project_id]->slug ?? '').'/'.$plan->slug;
            $refsOfPlan = array_merge($wanted['plan'][$plan->id] ?? [], $wanted['plan_alias'][$alias] ?? []);
            self::answer($out, $refsOfPlan, $context, $plan, fn () => self::planData($context, $plan));
        }

        return $out;
    }

    /**
     * Picker search in one project (the user must be able to open it), as smart link payloads.
     *
     * @return list<array<string, mixed>>
     */
    public static function search(Workspace $workspace, User $user, Project $project, ?string $search): array
    {
        $models = ContentReferences::search($project, $user, $search, self::TYPES);

        return array_map(function (Model $model) use ($workspace, $project) {
            $summary = ContentReferences::summary($workspace, $project, $model);

            return $summary + ['ref' => self::refOf($model)];
        }, $models);
    }

    /** The canonical ref of a model. */
    public static function refOf(Model $model): string
    {
        return match (true) {
            $model instanceof Page => 'page:'.$model->uuid,
            $model instanceof Item => 'item:'.$model->id,
            $model instanceof Ticket => 'ticket:'.$model->id,
            $model instanceof Plan => 'plan:'.$model->id,
        };
    }

    /**
     * Projects of the workspace the user can open (ProjectPolicy::view: workspace admins all,
     * everyone else the projects they're a member of).
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, true>
     */
    public static function accessibleProjectIds(Workspace $workspace, User $user, Collection $projects): array
    {
        if ($user->workspaceRole($workspace) === 'admin') {
            return array_fill_keys($projects->keys()->all(), true);
        }

        $member = $user->projects()->whereIn('projects.id', $projects->keys())->pluck('projects.id')->all();

        return array_fill_keys(array_map('intval', $member), true);
    }

    // ── Derived references ──────────────────────────────────────────────────────────

    /** A doc's smart links (published `content`; never-published docs are their own draft). */
    public static function syncPage(Page $page): void
    {
        $html = self::pageHtml($page->content);
        if ($page->type !== 'page' || ! $page->project_id || ($page->wasRecentlyCreated && ! str_contains((string) $html, 'data-smart-link'))) {
            return;
        }
        self::syncSource($page, (int) $page->project_id, $html);
    }

    /** An issue description's smart links. */
    public static function syncItem(Item $item): void
    {
        $projectId = (int) Board::withTrashed()->whereKey($item->board_id)->value('project_id');
        if ($projectId) {
            self::syncSource($item, $projectId, $item->description);
        }
    }

    /**
     * Derived references from the smart links in the HTML to content of the same project.
     * Trashed targets keep their row (hidden while trashed), like plan rows.
     */
    private static function syncSource(Model $source, int $projectId, ?string $html): void
    {
        $byType = ['item' => [], 'page' => [], 'ticket' => [], 'plan' => []];
        $uuids = [];
        $planIds = [];
        $planSlugs = [];
        foreach (self::refsInHtml($html) as $ref) {
            $parsed = self::parse($ref);
            match (true) {
                $parsed['type'] === 'page' => $uuids[] = $parsed['uuid'],
                isset($parsed['slug']) => $planSlugs[$parsed['project']][] = $parsed['slug'],
                $parsed['type'] === 'plan' => $planIds[] = $parsed['id'],
                default => $byType[$parsed['type']][] = $parsed['id'],
            };
        }

        $toIds = fn ($ids) => array_map('intval', $ids->all());
        if ($byType['item'] !== []) {
            $byType['item'] = $toIds(Item::withTrashed()->whereKey($byType['item'])
                ->whereIn('board_id', Board::withTrashed()->where('project_id', $projectId)->select('id'))->pluck('id'));
        }
        if ($byType['ticket'] !== []) {
            $byType['ticket'] = $toIds(Ticket::withTrashed()->whereKey($byType['ticket'])
                ->whereIn('helpdesk_id', Helpdesk::withTrashed()->where('project_id', $projectId)->select('id'))->pluck('id'));
        }
        if ($uuids !== []) {
            $byType['page'] = $toIds(Page::query()->where('project_id', $projectId)->where('type', 'page')->whereIn('uuid', $uuids)->pluck('id'));
        }
        $projectSlug = $planSlugs === [] ? null : Project::withTrashed()->whereKey($projectId)->value('slug');
        $slugs = $projectSlug !== null ? ($planSlugs[$projectSlug] ?? []) : [];
        if ($planIds !== [] || $slugs !== []) {
            $byType['plan'] = $toIds(Plan::withTrashed()->where('project_id', $projectId)
                ->where(fn (Builder $q) => $q->whereKey($planIds)->orWhereIn('slug', $slugs))->pluck('id'));
        }

        ContentReferences::syncDerivedTargets($source, $projectId, $byType);
    }

    // ── Batch loading ───────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $refs
     * @param  array<string, array<string, mixed>>  $out
     */
    private static function answer(array &$out, array $refs, SmartLinkContext $context, Model $model, \Closure $data, ?\Closure $visible = null): void
    {
        if ($refs === []) {
            return;
        }
        $projectId = (int) ($model instanceof Item ? $model->board?->project_id
            : ($model instanceof Ticket ? $model->helpdesk?->project_id : $model->getAttribute('project_id')));
        $state = ! $context->canOpenProject($projectId) || ($visible !== null && ! $visible()) ? 'restricted' : 'ok';
        $payload = $state === 'ok' ? ['state' => 'ok'] + $data() : ['state' => 'restricted'];
        foreach ($refs as $ref) {
            $out[$ref] = $payload;
        }
    }

    /** @return Collection<int, Item> */
    private static function items(SmartLinkContext $context, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $items = Item::query()
            ->whereKey($ids)
            ->whereIn('board_id', Board::query()->whereIn('project_id', $context->projects->keys())->select('id'))
            ->with([
                'board:id,project_id,slug,name',
                'column:id,board_id,name,position,is_done_column',
                'assignee:id,name,avatar',
                'priority:id,name,color,icon',
                'itemType:id,name,slug,icon,color',
            ])
            ->get(['id', 'board_id', 'column_id', 'key', 'title', 'assigned_to', 'priority_id', 'item_type_id', 'due_date', 'completed_at', 'updated_at']);

        $context->firstColumns = $items->isEmpty() ? [] : BoardColumn::query()
            ->whereIn('board_id', $items->pluck('board_id')->unique())
            ->orderBy('position')->orderBy('id')
            ->get(['id', 'board_id'])
            ->unique('board_id')
            ->mapWithKeys(fn (BoardColumn $c) => [$c->board_id => $c->id])
            ->all();

        return $items;
    }

    /** @return Collection<int, Page> */
    private static function pages(SmartLinkContext $context, array $uuids): Collection
    {
        if ($uuids === []) {
            return new Collection;
        }

        return Page::query()
            ->whereIn('uuid', $uuids)
            ->where('type', 'page')
            ->whereIn('project_id', $context->projects->keys())
            ->with(['creator:id,name,avatar'])
            ->select(['id', 'project_id', 'uuid', 'name', 'icon', 'type', 'status', 'created_by', 'updated_at'])
            // whiteboards are saved as {"type":"excalidraw",…}; don't load whole scenes for a label
            ->selectRaw('case when content like ? then 1 else 0 end as is_board', ['{"type":"excalidraw"%'])
            ->get();
    }

    /** @return Collection<int, Ticket> */
    private static function tickets(SmartLinkContext $context, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $tickets = Ticket::query()
            ->whereKey($ids)
            ->whereIn('helpdesk_id', Helpdesk::query()->whereIn('project_id', $context->projects->keys())->select('id'))
            ->with([
                'helpdesk:id,project_id,slug,name',
                'column:id,helpdesk_id,name,position,is_done_column',
                'assignee:id,name,avatar',
                'priority:id,name,color,icon',
            ])
            ->get(['id', 'helpdesk_id', 'column_id', 'key', 'subject', 'assigned_to', 'priority_id', 'updated_at']);

        $context->firstHelpdeskColumns = $tickets->isEmpty() ? [] : HelpdeskColumn::query()
            ->whereIn('helpdesk_id', $tickets->pluck('helpdesk_id')->unique())
            ->orderBy('position')->orderBy('id')
            ->get(['id', 'helpdesk_id'])
            ->unique('helpdesk_id')
            ->mapWithKeys(fn (HelpdeskColumn $c) => [$c->helpdesk_id => $c->id])
            ->all();

        return $tickets;
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $aliases  `<project-slug>/<plan-slug>`
     * @return Collection<int, Plan>
     */
    private static function plans(SmartLinkContext $context, array $ids, array $aliases): Collection
    {
        $bySlug = [];
        $projectIdBySlug = $context->projects->mapWithKeys(fn (Project $p) => [$p->slug => $p->id])->all();
        foreach ($aliases as $alias) {
            [$projectSlug, $slug] = explode('/', $alias, 2);
            if (isset($projectIdBySlug[$projectSlug])) {
                $bySlug[] = [$projectIdBySlug[$projectSlug], $slug];
            }
        }
        if ($ids === [] && $bySlug === []) {
            return new Collection;
        }

        return Plan::query()
            ->whereIn('project_id', $context->projects->keys())
            ->where(function (Builder $q) use ($ids, $bySlug) {
                $q->whereKey($ids);
                foreach ($bySlug as [$projectId, $slug]) {
                    $q->orWhere(fn (Builder $q) => $q->where('project_id', $projectId)->where('slug', $slug));
                }
            })
            ->with(['creator:id,name,avatar'])
            ->get(['id', 'project_id', 'name', 'slug', 'created_by', 'updated_at']);
    }

    // ── Payloads ────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function itemData(SmartLinkContext $context, Item $item): array
    {
        $project = $context->projects[$item->board->project_id];
        $column = $item->column;
        $category = $column === null ? 'todo'
            : ($column->is_done_column ? 'done' : (($context->firstColumns[$item->board_id] ?? null) === $column->id ? 'todo' : 'in_progress'));

        return self::payload($context, $project, $item, [
            'label' => 'Issue',
            'key' => $item->key,
            'title' => $item->title ?: (string) $item->key,
            'url' => route('boards.show', [$context->workspace->slug, $project->slug, $item->board->slug]).'#item-'.$item->id,
            'status' => $column ? ['name' => $column->name, 'category' => $category] : null,
            'due_date' => $item->due_date?->toDateString(),
            'person' => self::person('Assignee', $item->assignee),
            'priority' => $item->priority ? ['name' => $item->priority->name, 'color' => $item->priority->color] : null,
            'item_type' => $item->itemType ? ['name' => $item->itemType->name, 'slug' => $item->itemType->slug, 'icon' => $item->itemType->icon, 'color' => $item->itemType->color] : null,
        ]);
    }

    /** @return array<string, mixed> */
    private static function pageData(SmartLinkContext $context, Page $page): array
    {
        $project = $context->projects[$page->project_id];
        $whiteboard = (bool) (int) ($page->getAttributes()['is_board'] ?? 0);

        return self::payload($context, $project, $page, [
            'label' => $whiteboard ? 'Whiteboard' : 'Doc',
            'key' => null,
            'title' => (string) $page->name,
            'icon' => $page->icon,
            'url' => route('pages.show', [$context->workspace->slug, $project->slug, $page->uuid]),
            'status' => match ($page->status) {
                'draft' => ['name' => 'Draft', 'category' => 'todo'],
                'archived' => ['name' => 'Archived', 'category' => 'done'],
                default => ['name' => 'Published', 'category' => 'done'],
            },
            'due_date' => null,
            'person' => self::person('Owner', $page->creator),
            'priority' => null,
            'item_type' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private static function ticketData(SmartLinkContext $context, Ticket $ticket): array
    {
        $project = $context->projects[$ticket->helpdesk->project_id];
        $column = $ticket->column;
        $category = $column === null ? 'todo'
            : ($column->is_done_column ? 'done' : (($context->firstHelpdeskColumns[$ticket->helpdesk_id] ?? null) === $column->id ? 'todo' : 'in_progress'));

        return self::payload($context, $project, $ticket, [
            'label' => 'Ticket',
            'key' => $ticket->key,
            'title' => $ticket->subject ?: (string) $ticket->key,
            'url' => route('tickets.show', [$context->workspace->slug, $project->slug, $ticket->helpdesk->slug, $ticket->id]),
            'status' => $column ? ['name' => $column->name, 'category' => $category] : null,
            'due_date' => null,
            'person' => self::person('Assignee', $ticket->assignee),
            'priority' => $ticket->priority ? ['name' => $ticket->priority->name, 'color' => $ticket->priority->color] : null,
            'item_type' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private static function planData(SmartLinkContext $context, Plan $plan): array
    {
        $project = $context->projects[$plan->project_id];

        return self::payload($context, $project, $plan, [
            'label' => 'Plan',
            'key' => null,
            'title' => (string) $plan->name,
            'url' => route('plans.show', [$context->workspace->slug, $project->slug, $plan->slug]),
            'status' => null,
            'due_date' => null,
            'person' => self::person('Owner', $plan->creator),
            'priority' => null,
            'item_type' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function payload(SmartLinkContext $context, Project $project, Model $model, array $data): array
    {
        return [
            'ref' => self::refOf($model),
            'type' => array_search($model::class, ['item' => Item::class, 'page' => Page::class, 'ticket' => Ticket::class, 'plan' => Plan::class], true),
            ...$data,
            'project' => ['name' => $project->name, 'slug' => $project->slug],
            'updated_at' => $model->updated_at?->toIso8601String(),
        ];
    }

    /** @return array{role: string, name: string, avatar: string|null}|null */
    private static function person(string $role, ?User $user): ?array
    {
        return $user ? ['role' => $role, 'name' => $user->name, 'avatar' => $user->avatar_display] : null;
    }
}
