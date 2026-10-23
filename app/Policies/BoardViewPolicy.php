<?php

namespace App\Policies;

use App\Models\BoardView;
use App\Models\User;

/**
 * Saved board views: a personal view belongs to its owner alone; a shared view is visible to
 * everyone who can open the board and changed only by people who manage the board. A view never
 * grants access to its board — every check starts with BoardPolicy::view.
 */
class BoardViewPolicy
{
    public function view(User $user, BoardView $view): bool
    {
        if (! $user->can('view', $view->board)) {
            return false;
        }

        return $view->is_shared || $view->user_id === $user->id;
    }

    public function update(User $user, BoardView $view): bool
    {
        if (! $user->can('view', $view->board)) {
            return false;
        }

        return $view->is_shared ? $user->can('update', $view->board) : $view->user_id === $user->id;
    }

    public function delete(User $user, BoardView $view): bool
    {
        return $this->update($user, $view);
    }
}
