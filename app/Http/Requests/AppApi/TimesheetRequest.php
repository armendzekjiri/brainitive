<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Foundation\Http\FormRequest;

/** `GET /api/v1/me/timesheet`: one workspace, one week. */
class TimesheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // the workspace slug (a timesheet is per workspace, as on the web)
            'workspace' => ['required', 'string', 'max:255'],
            // any day of the week (Y-m-d); default: the user's current week
            'week' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }
}
