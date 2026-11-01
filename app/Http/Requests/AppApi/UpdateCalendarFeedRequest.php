<?php

namespace App\Http\Requests\AppApi;

use App\Support\CalendarFeed\CalendarFeeds;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /api/v1/me/calendar-feed`: turn the private feed on, or replace its options (a missing option takes
 * its default, as on the web). `options.project_ids` = only these projects (empty = all the user can open).
 */
class UpdateCalendarFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return CalendarFeeds::rules();
    }
}
