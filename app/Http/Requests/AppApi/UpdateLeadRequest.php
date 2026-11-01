<?php

namespace App\Http\Requests\AppApi;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/v1/leads/{id}`: move a lead to a pipeline column and / or change its contact details and assignee.
 * Authorized before validation: a lead of a project the user can't open is a 404, project viewers a 403
 * (LeadFormPolicy::manageLeads).
 */
class UpdateLeadRequest extends FormRequest
{
    private ?Lead $model = null;

    public function authorize(): bool
    {
        return $this->user()->can('manageLeads', $this->lead()->form);
    }

    public function lead(): Lead
    {
        if ($this->model !== null) {
            return $this->model;
        }
        $id = (string) $this->route('lead');
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1, 404);
        $lead = Lead::with('form.project.workspace')->find((int) $id);
        abort_unless($lead instanceof Lead && $lead->form?->project?->workspace !== null && $this->user()->can('view', $lead->form), 404);

        return $this->model = $lead;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $formId = $this->route('lead') !== null ? $this->lead()->lead_form_id : 0;

        return [
            // a column of the lead's form (its pipeline stage)
            'column_id' => ['sometimes', 'integer', Rule::exists('lead_form_columns', 'id')->where('lead_form_id', $formId)],
            // place in the column, 0 = top (default: keep it where it lands, the end)
            'position' => ['sometimes', 'integer', 'min:0'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:60'],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
            // a member of the project, or null
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
