<?php

namespace App\Support\Admin;

use App\Models\InstanceAdmin;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a user from the admin console without taking other people's data with them.
 *
 * Most `created_by` foreign keys cascade on delete, so a plain `$user->delete()` also wipes
 * every project, board, item, task list, helpdesk and snippet the user created — including
 * in workspaces they don't own. Before deleting, that content is handed to the owner of
 * the workspace it lives in, and other people's replies to the user's comments are kept as
 * top-level comments. The user's own comments and personal data (memberships, watchers,
 * calendar connections, sticky notes) are removed.
 */
class UserDeletion
{
    /** Tables whose author column isn't `created_by`. */
    private const AUTHOR_COLUMNS = ['attachments' => 'user_id'];

    /**
     * Why this user can't be deleted by $actor right now (empty = allowed).
     *
     * @return list<string>
     */
    public static function blockers(User $user, User $actor): array
    {
        $reasons = [];

        if ($user->is($actor)) {
            $reasons[] = 'You can’t delete your own account from the admin console.';
        }

        if ($user->isInstanceAdmin() && InstanceAdmin::count() <= 1) {
            $reasons[] = 'This is the last instance admin.';
        }

        $owned = $user->ownedWorkspaces()->count();
        if ($owned > 0) {
            $reasons[] = "They own {$owned} ".str('workspace')->plural($owned).'. Transfer or delete '.($owned === 1 ? 'it' : 'them').' first.';
        }

        return $reasons;
    }

    /**
     * What deleting the user changes — counts only, never content.
     *
     * @return array{reassigned: int, comments_deleted: int}
     */
    public static function impact(User $user): array
    {
        $reassigned = 0;
        foreach (static::ownedContent($user) as $query) {
            $reassigned += $query->count();
        }

        return [
            'reassigned' => $reassigned,
            'comments_deleted' => DB::table('comments')->where('user_id', $user->id)->count(),
        ];
    }

    public static function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            foreach (static::ownedContent($user) as $table => $query) {
                $query->get(['t.id', 'w.owner_id'])
                    ->groupBy('owner_id')
                    ->each(fn ($rows, $ownerId) => DB::table($table)
                        ->whereIn('id', $rows->pluck('id'))
                        ->update([self::AUTHOR_COLUMNS[$table] ?? 'created_by' => $ownerId]));
            }

            // Replies cascade with their parent: keep other people's replies.
            DB::table('comments')
                ->whereIn('parent_id', DB::table('comments')->select('id')->where('user_id', $user->id))
                ->where('user_id', '!=', $user->id)
                ->update(['parent_id' => null]);

            $user->tokens()->each(fn ($token) => $token->revoke());
            $user->delete();
        });
    }

    /**
     * Rows the user created whose `created_by` (or `user_id`) cascade-deletes with them,
     * each joined to its workspace as `w` and aliased `t`.
     *
     * @return array<string, Builder>
     */
    private static function ownedContent(User $user): array
    {
        $created = fn (string $table) => DB::table("{$table} as t")->where('t.created_by', $user->id);

        return [
            'projects' => $created('projects')
                ->join('workspaces as w', 'w.id', '=', 't.workspace_id'),
            'boards' => $created('boards')
                ->join('projects as p', 'p.id', '=', 't.project_id')
                ->join('workspaces as w', 'w.id', '=', 'p.workspace_id'),
            'items' => $created('items')
                ->join('boards as b', 'b.id', '=', 't.board_id')
                ->join('projects as p', 'p.id', '=', 'b.project_id')
                ->join('workspaces as w', 'w.id', '=', 'p.workspace_id'),
            'task_lists' => $created('task_lists')
                ->join('projects as p', 'p.id', '=', 't.project_id')
                ->join('workspaces as w', 'w.id', '=', 'p.workspace_id'),
            'task_list_items' => $created('task_list_items')
                ->join('task_lists as tl', 'tl.id', '=', 't.task_list_id')
                ->join('projects as p', 'p.id', '=', 'tl.project_id')
                ->join('workspaces as w', 'w.id', '=', 'p.workspace_id'),
            'helpdesks' => $created('helpdesks')
                ->join('projects as p', 'p.id', '=', 't.project_id')
                ->join('workspaces as w', 'w.id', '=', 'p.workspace_id'),
            'snippet_collections' => $created('snippet_collections')
                ->join('workspaces as w', 'w.id', '=', 't.workspace_id'),
            'snippets' => $created('snippets')
                ->join('snippet_collections as sc', 'sc.id', '=', 't.collection_id')
                ->join('workspaces as w', 'w.id', '=', 'sc.workspace_id'),
            'attachments' => DB::table('attachments as t')->where('t.user_id', $user->id)
                ->join('items as i', 'i.id', '=', 't.item_id')
                ->join('boards as b', 'b.id', '=', 'i.board_id')
                ->join('projects as p', 'p.id', '=', 'b.project_id')
                ->join('workspaces as w', 'w.id', '=', 'p.workspace_id'),
        ];
    }
}
