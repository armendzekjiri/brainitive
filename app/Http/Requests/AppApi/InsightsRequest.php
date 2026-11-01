<?php

namespace App\Http\Requests\AppApi;

use App\Support\Insights\InsightsFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `GET /api/v1/workspaces/{slug}/insights/{tab}`: the web's Insights filters. */
class InsightsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // this_month | last_month | last_30 (default) | last_90 | custom (with from + to)
            'period' => ['sometimes', 'string', Rule::in(InsightsFilters::PERIODS)],
            // custom: first / last local day, Y-m-d (at most a year)
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            // only this project of the workspace (id)
            'project' => ['sometimes', 'integer'],
        ];
    }
}
