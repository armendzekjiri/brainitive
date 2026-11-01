<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Foundation\Http\FormRequest;

/** `GET /api/v1/projects/{id}/health`: the period of the health metrics (default the last 14 days). */
class ProjectHealthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // IANA zone of the dates, default the user's
            'tz' => ['sometimes', 'string', 'timezone:all_with_bc'],
            // first / last local day, Y-m-d (at most a year; default the last 14 days up to today)
            'from' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:2100-12-31'],
        ];
    }
}
