<?php

namespace App\Http\Requests\AppApi;

use App\Support\Calendar\CalendarEvents;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** `GET /api/v1/calendar/events`: a range of the user's local days and the web calendar's filters. */
class CalendarEventsRequest extends FormRequest
{
    /** The longest range one call may ask for. */
    public const MAX_DAYS = 62;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // first day, Y-m-d in the user's time zone (default: today)
            'from' => ['sometimes', 'date_format:Y-m-d'],
            // last day, inclusive (default: from + 7 days); at most 62 days after from
            'to' => ['sometimes', 'date_format:Y-m-d'],
            // board (dated issues), list (dated tasks), external (connected calendars + Google Tasks), booking; default all
            'sources' => ['sometimes', 'array'],
            'sources.*' => ['string', Rule::in(CalendarEvents::SOURCES)],
            // only issues / tasks assigned to the user (connected calendars are the user's own anyway)
            'me_only' => ['sometimes', 'boolean'],
            // false: leave out completed issues / tasks (default true, as the web)
            'show_completed' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $days = Carbon::parse($this->from())->diffInDays(Carbon::parse($this->to()), false);
            if ($days < 0) {
                $validator->errors()->add('to', 'The last day must not be before the first day.');
            } elseif ($days > self::MAX_DAYS) {
                $validator->errors()->add('to', 'Ask for at most '.self::MAX_DAYS.' days at a time.');
            }
        }];
    }

    public function from(): string
    {
        $from = $this->input('from');

        return is_string($from) && $from !== '' ? $from : now($this->user()->localTimezone())->toDateString();
    }

    public function to(): string
    {
        $to = $this->input('to');

        return is_string($to) && $to !== '' ? $to : Carbon::parse($this->from())->addDays(7)->toDateString();
    }

    /** @return list<string> */
    public function sources(): array
    {
        $sources = $this->validated('sources');

        return is_array($sources) && $sources !== [] ? array_values(array_unique($sources)) : CalendarEvents::SOURCES;
    }
}
