<?php

namespace App\Http\Requests\AppApi;

use App\Support\Workload\WorkloadWeeks;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `GET /api/v1/projects/{id}/workload`: which weeks (default: this week and the next five). */
class WorkloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // any day of the first week, Y-m-d (moved to its Monday); default this week
            'from' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2099-12-31'],
            // 4 | 6 (default) | 8 | 12 weeks
            'weeks' => ['sometimes', 'integer', Rule::in(WorkloadWeeks::SPANS)],
        ];
    }
}
