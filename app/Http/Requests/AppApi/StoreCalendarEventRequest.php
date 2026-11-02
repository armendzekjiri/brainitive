<?php

namespace App\Http\Requests\AppApi;

use App\Models\Meeting;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/calendar/events`: a new event on one of the user's own writable connected calendars (the
 * web calendar's quick create). Dates and times are wall clock in `timezone` (default: the user's).
 */
class StoreCalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // a calendar of GET /calendar/connections (kind calendar, read_only false)
            'connection_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:500'],
            // first day, Y-m-d
            'date' => ['required', 'date_format:Y-m-d'],
            // start time H:i (24 h); none = an all-day event
            'time' => ['nullable', 'date_format:H:i'],
            // last day (inclusive), default `date`
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            // end time H:i, default 30 minutes after the start
            'end_time' => ['nullable', 'date_format:H:i'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:500'],
            // IANA zone of date / time, default the user's (effective_timezone of GET /me)
            'timezone' => ['nullable', 'string', 'timezone:all_with_bc'],
            // a video call: one of the calendar's conference_providers (GET /calendar/meeting-calendars)
            'conference' => ['nullable', 'in:google_meet,teams'],
            // guests by address; the calendar provider sends the invitations
            'attendees' => ['nullable', 'array', 'max:'.Meeting::MAX_ATTENDEES],
            'attendees.*' => ['string', 'max:254', 'email:rfc'],
            // retry key: the same id + input answers 200 with the event the first call created
            'request_id' => ['nullable', 'uuid'],
        ];
    }
}
