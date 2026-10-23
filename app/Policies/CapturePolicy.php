<?php

namespace App\Policies;

use App\Models\Capture;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Captures are private: only the owner may see or change one. Others get a 404, so a capture's
 * existence doesn't leak. Converting additionally needs the destination's own policy
 * (`ContentCapture::authorize`) — owning the capture grants nothing there.
 */
class CapturePolicy
{
    public function view(User $user, Capture $capture): Response
    {
        return $this->owner($user, $capture);
    }

    public function update(User $user, Capture $capture): Response
    {
        return $this->owner($user, $capture);
    }

    public function delete(User $user, Capture $capture): Response
    {
        return $this->owner($user, $capture);
    }

    public function convert(User $user, Capture $capture): Response
    {
        return $this->owner($user, $capture);
    }

    private function owner(User $user, Capture $capture): Response
    {
        return $capture->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
