<?php

namespace App\Support\Projects;

use App\Models\BillingActivity;
use App\Models\Board;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Move a project — with everything in it — to another workspace.
 *
 * Only `projects.workspace_id` points at the workspace; boards, items, pages, whiteboards,
 * plans, task lists, helpdesks + tickets, labels and project item types hang off the
 * project and come along. What has to be decided here:
 * - members: people already in the target workspace keep their project role; the others
 *   are removed (invite them to the workspace, then the project, by hand);
 * - issue links to issues outside the project are removed (they would show another
 *   workspace's issue titles);
 * - the project key must be free in the target (it's part of every issue / ticket key);
 *   a taken slug gets a suffix;
 * - logged time (v1.39.0): unbilled entries move with the project and take the target's activity
 *   of the same name (none → no activity, the admin picks one); entries already on a finalized
 *   report stay with the old workspace's billing.
 */
class ProjectTransfer
{
    public static function canManage(User $user, Workspace $workspace): bool
    {
        return $workspace->owner_id === $user->id || in_array($user->workspaceRole($workspace), ['owner', 'admin'], true);
    }

    /**
     * Workspaces this user may move the project into.
     *
     * @return Collection<int, Workspace>
     */
    public static function targets(User $user, Project $project)
    {
        return $user->workspaces()
            ->where('workspaces.id', '!=', $project->workspace_id)
            ->orderBy('name')
            ->get()
            ->filter(fn (Workspace $w) => self::canManage($user, $w))
            ->values();
    }

    /**
     * What will happen, for the confirmation step.
     *
     * @return array{key_taken: bool, new_slug: string, keep: list<array{id: int, name: string, email: string}>, remove: list<array{id: int, name: string, email: string}>, external_links: int, time: array{moving_minutes: int, billed_minutes: int, unmatched_activities: list<string>}}
     */
    public static function preview(Project $project, Workspace $target): array
    {
        $targetMemberIds = $target->users()->pluck('users.id')->all();
        $members = $project->users()->get(['users.id', 'name', 'email']);
        $person = fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email];

        return [
            'key_taken' => $target->projects()->withTrashed()->where('key', $project->key)->exists(),
            'new_slug' => self::freeSlug($project, $target),
            'keep' => $members->filter(fn (User $u) => in_array($u->id, $targetMemberIds))->map($person)->values()->all(),
            'remove' => $members->reject(fn (User $u) => in_array($u->id, $targetMemberIds))->map($person)->values()->all(),
            'external_links' => self::externalLinks($project)->count(),
            'time' => self::timePreview($project, $target),
        ];
    }

    public static function move(Project $project, Workspace $target): Project
    {
        if ($target->projects()->withTrashed()->where('key', $project->key)->exists()) {
            throw new \DomainException("“{$target->name}” already has a project with the key {$project->key}. Change this project's key first.");
        }

        DB::transaction(function () use ($project, $target) {
            $targetMemberIds = $target->users()->pluck('users.id')->all();
            $project->users()->wherePivotNotIn('user_id', $targetMemberIds)->detach();

            self::externalLinks($project)->delete();

            $project->forceFill([
                'workspace_id' => $target->id,
                'slug' => self::freeSlug($project, $target),
                'client_id' => null, // clients belong to the old workspace (billing, v1.38.0)
            ])->save();

            self::moveUnbilledTime($project, $target);
        });

        return $project->fresh('workspace');
    }

    /** Unbilled entries follow the project; activities are matched by name in the target. */
    private static function moveUnbilledTime(Project $project, Workspace $target): void
    {
        $unbilled = TimeEntry::query()->where('project_id', $project->id)->whereNull('billing_report_id');

        $targetIds = self::targetActivityIds($project, $target);
        foreach ($targetIds as $oldId => $newId) {
            (clone $unbilled)->where('billing_activity_id', $oldId)->update(['billing_activity_id' => $newId]);
        }

        // query updates: minutes don't change, so the issues' time_spent totals stay right
        (clone $unbilled)->update(['workspace_id' => $target->id]);
    }

    /**
     * Activities used by the project's unbilled entries → the target's activity of the same name (or null).
     *
     * @return array<int, int|null>
     */
    private static function targetActivityIds(Project $project, Workspace $target): array
    {
        $used = BillingActivity::query()
            ->whereIn('id', TimeEntry::query()->where('project_id', $project->id)->whereNull('billing_report_id')->whereNotNull('billing_activity_id')->select('billing_activity_id'))
            ->pluck('name', 'id');
        $byName = BillingActivity::query()->where('workspace_id', $target->id)->whereIn('name', $used->values())->pluck('id', 'name');

        return $used->map(fn (string $name) => $byName[$name] ?? null)->all();
    }

    /** @return array{moving_minutes: int, billed_minutes: int, unmatched_activities: list<string>} */
    private static function timePreview(Project $project, Workspace $target): array
    {
        $totals = TimeEntry::query()->where('project_id', $project->id)
            ->selectRaw('COALESCE(SUM(CASE WHEN billing_report_id IS NULL THEN minutes END), 0) AS moving, COALESCE(SUM(CASE WHEN billing_report_id IS NOT NULL THEN minutes END), 0) AS billed')
            ->first();
        $unmatched = collect(self::targetActivityIds($project, $target))->filter(fn ($id) => $id === null)->keys();

        return [
            'moving_minutes' => (int) $totals->moving,
            'billed_minutes' => (int) $totals->billed,
            'unmatched_activities' => BillingActivity::query()->whereIn('id', $unmatched)->orderBy('name')->pluck('name')->all(),
        ];
    }

    private static function freeSlug(Project $project, Workspace $target): string
    {
        $base = $project->slug;
        $slug = $base;
        for ($i = 2; $target->projects()->withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = Str::limit($base, 240, '')."-{$i}";
        }

        return $slug;
    }

    /** Links between an issue of this project and an issue elsewhere. */
    private static function externalLinks(Project $project)
    {
        $ids = Item::withTrashed()->whereIn('board_id', Board::withTrashed()->where('project_id', $project->id)->select('id'))->select('id');

        return ItemLink::query()->where(fn ($q) => $q
            ->where(fn ($q) => $q->whereIn('source_item_id', $ids)->whereNotIn('target_item_id', clone $ids))
            ->orWhere(fn ($q) => $q->whereIn('target_item_id', $ids)->whereNotIn('source_item_id', clone $ids)));
    }
}
