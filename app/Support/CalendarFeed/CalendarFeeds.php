<?php

namespace App\Support\CalendarFeed;

use App\Models\CalendarFeed;
use App\Models\User;

/**
 * The owner's side of the private calendar feed (BRAI-142), shared by the web's "Subscribe in other apps"
 * (CalendarFeedController) and the App API (`/api/v1/me/calendar-feed`, BRAI-418): the option rules and
 * how chosen options are cleaned up before they are stored.
 */
final class CalendarFeeds
{
    /**
     * The `options` input (every key optional; a missing one takes its default, CalendarFeed::DEFAULT_OPTIONS).
     *
     * @return array<string, list<string>|string>
     */
    public static function rules(): array
    {
        return [
            'options' => 'nullable|array',
            'options.assigned' => 'sometimes|boolean',
            'options.created' => 'sometimes|boolean',
            'options.tasks' => 'sometimes|boolean',
            'options.my_day' => 'sometimes|boolean',
            'options.focus' => 'sometimes|boolean',
            'options.completed' => 'sometimes|boolean',
            'options.descriptions' => 'sometimes|boolean',
            'options.project_ids' => 'sometimes|array|max:500',
            'options.project_ids.*' => 'integer',
        ];
    }

    /**
     * Known keys only, and only projects the user can open now (also re-checked on every fetch).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function options(User $user, array $options): array
    {
        $options = CalendarFeed::normalizeOptions($options);
        $options['project_ids'] = array_values(array_intersect($options['project_ids'], $user->accessibleProjectIds()));

        return $options;
    }

    /**
     * Turn the feed on with these options, or update the options of the active one.
     *
     * @param  array<string, mixed>  $options  options()
     * @return bool whether a new feed was created
     */
    public static function enable(User $user, array $options): bool
    {
        $feed = CalendarFeed::activeFor($user);
        $feed ? $feed->update(['options' => $options]) : CalendarFeed::issue($user, $options);

        return $feed === null;
    }

    /** Turn the feed off: the URL stops working. */
    public static function disable(User $user): void
    {
        CalendarFeed::active()->where('user_id', $user->id)->update(['revoked_at' => now()]);
    }
}
