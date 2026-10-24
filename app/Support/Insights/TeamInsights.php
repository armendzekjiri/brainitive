<?php

namespace App\Support\Insights;

use App\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * Insights → Team (BRAI-105): one row per workspace member. Definitions:
 * - completed = issues assigned to them with `completed_at` in the range;
 * - open / overdue = issues assigned to them that are open now / open and due before today;
 * - hours = their time entries with `spent_on` in the range;
 * - comments = their issue comments written in the range;
 * - last active = the later of their last sign-in and their latest issue activity in this
 *   workspace (`activity_logs` spans all workspaces, so it is scoped to this workspace's issues).
 *
 * Five queries, whatever the number of members.
 */
final class TeamInsights
{
    /** @return array{members: list<array<string, mixed>>} */
    public static function compute(InsightsFilters $filters): array
    {
        $start = $filters->startUtc();
        $end = $filters->endUtc();
        $workspaceId = $filters->workspace->id;

        $members = DB::table('workspace_user')
            ->join('users', 'users.id', '=', 'workspace_user.user_id')
            ->where('workspace_user.workspace_id', $workspaceId)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.avatar', 'users.last_login_at', 'workspace_user.role']);

        $open = InsightsQueries::openSql();
        $issues = InsightsQueries::issues($filters)
            ->whereNotNull('items.assigned_to')
            ->selectRaw(implode(', ', [
                'items.assigned_to as user_id',
                'sum(case when items.completed_at >= ? and items.completed_at < ? then 1 else 0 end) as completed',
                "sum(case when {$open} then 1 else 0 end) as open",
                "sum(case when {$open} and items.due_date < ? then 1 else 0 end) as overdue",
            ]), [$start, $end, $filters->today()])
            ->groupBy('items.assigned_to')
            ->get()->keyBy('user_id');

        $minutes = DB::table('time_entries')
            ->where('workspace_id', $workspaceId)
            ->whereIn('project_id', $filters->projectIds())
            ->whereBetween('spent_on', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(minutes) as minutes')
            ->pluck('minutes', 'user_id');

        $comments = InsightsQueries::issues($filters)
            ->join('comments', 'comments.item_id', '=', 'items.id')
            ->whereNull('comments.deleted_at')
            ->where('comments.created_at', '>=', $start)->where('comments.created_at', '<', $end)
            ->groupBy('comments.user_id')
            ->selectRaw('comments.user_id, count(*) as total')
            ->pluck('total', 'user_id');

        // latest issue activity in this workspace (any project, any time)
        $workspaceItems = DB::table('items')->join('boards', 'boards.id', '=', 'items.board_id')
            ->whereIn('boards.project_id', array_keys($filters->projects))->select('items.id');
        $activity = DB::table('activity_logs')
            ->where('loggable_type', Item::class)
            ->whereIn('loggable_id', $workspaceItems)
            ->whereIn('user_id', $members->pluck('id'))
            ->groupBy('user_id')
            ->selectRaw('user_id, max(created_at) as last_at')
            ->pluck('last_at', 'user_id');

        return [
            'members' => $members->map(function (object $member) use ($filters, $issues, $minutes, $comments, $activity) {
                $row = $issues->get($member->id);
                $lastLogin = InsightsQueries::iso($member->last_login_at);
                $lastActivity = InsightsQueries::iso($activity[$member->id] ?? null);
                $known = array_filter([$lastLogin, $lastActivity]);
                $lastActive = $known === [] ? null : max($known); // both ISO-8601 in UTC: string order = time order

                return [
                    'id' => (int) $member->id,
                    'name' => (string) $member->name,
                    'avatar' => $member->avatar ? asset('storage/'.$member->avatar) : null,
                    'role' => (string) $member->role,
                    'completed' => (int) ($row->completed ?? 0),
                    'open' => (int) ($row->open ?? 0),
                    'overdue' => (int) ($row->overdue ?? 0),
                    'hours' => InsightsQueries::hours($minutes[$member->id] ?? 0),
                    'comments' => (int) ($comments[$member->id] ?? 0),
                    'last_login_at' => $lastLogin,
                    'last_activity_at' => $lastActivity,
                    'last_active_at' => $lastActive,
                    'profile_url' => route('workspaces.members.profile', [$filters->workspace->slug, $member->id]),
                ];
            })->values()->all(),
        ];
    }
}
