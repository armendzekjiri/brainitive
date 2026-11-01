<?php

namespace App\Http\Requests\AppApi;

use App\Support\LeadForms\LeadFormAnalytics;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `GET /api/v1/lead-forms/{id}/analytics?days=7|30|90`: the form's funnel (the web's Analytics tab). */
class LeadFormAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // the last 7, 30 (default) or 90 days
            'days' => ['sometimes', 'integer', Rule::in(LeadFormAnalytics::RANGES)],
        ];
    }
}
