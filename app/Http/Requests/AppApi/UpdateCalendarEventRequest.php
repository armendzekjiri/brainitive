<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/v1/calendar/events/{id}`: change the sent fields of an event in one of the user's own writable
 * calendars (the web calendar's event dialog). Moving it: send `date` (+ `time`, `end_date`, `end_time`) —
 * without `time` it becomes an all-day event.
 */
class UpdateCalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:500'],
            // new first day, Y-m-d: replaces all of the event's dates and times
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            // IANA zone of date / time, default the user's
            'timezone' => ['nullable', 'string', 'timezone:all_with_bc'],
        ];
    }
}
