<?php

namespace App\Policies;

use App\Models\BookingPage;
use App\Models\User;

/** Booking pages are personal (BRAI-158): only their owner sees and changes them, while still in the workspace. */
class BookingPagePolicy
{
    public function view(User $user, BookingPage $page): bool
    {
        return $this->owns($user, $page);
    }

    public function update(User $user, BookingPage $page): bool
    {
        return $this->owns($user, $page);
    }

    public function delete(User $user, BookingPage $page): bool
    {
        return $this->owns($user, $page);
    }

    private function owns(User $user, BookingPage $page): bool
    {
        return $page->user_id === $user->id && $page->workspace && $user->hasWorkspaceAccess($page->workspace);
    }
}
