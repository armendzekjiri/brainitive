<?php

namespace App\Policies;

use App\Models\CalendarConnection;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Online meetings (BRAI-147) follow their subject (issue → ItemPolicy, doc → PagePolicy).
 * Scheduling also needs the organiser's own, active calendar; only the organiser can cancel
 * the calendar event (it is in their calendar), while anyone who may edit the subject can
 * unlink a meeting from it.
 */
class MeetingPolicy
{
    public function view(User $user, Meeting $meeting): bool
    {
        $subject = $meeting->subject;

        return $subject !== null && $user->can('view', $subject);
    }

    /** $subject = the Item / Page; $connection = the calendar the event goes to. */
    public function create(User $user, Model $subject, CalendarConnection $connection): bool
    {
        return $user->can('update', $subject)
            && $connection->user_id === $user->id
            && $connection->kind === 'calendar'
            && $connection->is_active;
    }

    /** Unlink the meeting from its subject (the calendar event stays). */
    public function delete(User $user, Meeting $meeting): bool
    {
        if (! $this->view($user, $meeting)) {
            return false;
        }

        return $meeting->created_by === $user->id || $user->can('update', $meeting->subject);
    }

    /** Cancel the calendar event (attendees get the provider's cancellation) and unlink. */
    public function cancel(User $user, Meeting $meeting): bool
    {
        $connection = $meeting->connection;

        return $this->view($user, $meeting)
            && $meeting->created_by === $user->id
            && $connection !== null
            && $connection->user_id === $user->id;
    }
}
