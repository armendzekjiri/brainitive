<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\LeadForms\LeadActions;
use App\Support\LeadForms\LeadFormDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Leads on a lead form's board: move between columns, edit contact / assignee, delete.
 */
class LeadController extends Controller
{
    /**
     * A lead as the board and the lead sheet show it (answers in form order, with labels).
     */
    public static function present(Lead $lead): array
    {
        $form = $lead->form;
        $answers = $lead->answers ?? [];
        $labels = $lead->meta['labels'] ?? [];
        $rows = [];
        foreach (LeadFormDefinition::fields($form?->definition ?? []) as $field) {
            if (array_key_exists($field['id'], $answers)) {
                $rows[] = ['id' => $field['id'], 'label' => $field['label'], 'type' => $field['type'], 'display' => LeadFormDefinition::display($field, $answers[$field['id']])];
                unset($answers[$field['id']]);
            }
        }
        // questions removed from the form since
        foreach ($answers as $id => $value) {
            $rows[] = ['id' => (string) $id, 'label' => $labels[$id] ?? 'Removed question', 'type' => 'removed', 'display' => is_array($value) ? implode(', ', array_filter($value, 'is_scalar')) : (is_scalar($value) ? (string) $value : '')];
        }

        return [
            'id' => $lead->id,
            'uuid' => $lead->uuid,
            'number' => $lead->number,
            'column_id' => $lead->column_id,
            'name' => $lead->name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'company' => $lead->company,
            'source' => $lead->source,
            'answers' => $rows,
            'utm' => $lead->meta['utm'] ?? null,
            'referrer' => $lead->meta['referrer'] ?? null,
            // proof of the form's consent checkbox (BRAI-156)
            'consent_at' => $lead->consent_at?->toIso8601String(),
            'consent_text' => $lead->consent_text,
            'assignee' => $lead->assignee ? $lead->assignee->only(['id', 'name', 'email', 'avatar']) : null,
            'created_at' => $lead->created_at?->toIso8601String(),
        ];
    }

    /** Drop a lead in a column; `ids` is the column's new order. */
    public function move(Request $request, Workspace $workspace, Project $project, LeadForm $leadForm, Lead $lead): RedirectResponse
    {
        $this->authorize('manageLeads', $leadForm);

        $validated = $request->validate([
            'column_id' => ['required', 'integer', Rule::exists('lead_form_columns', 'id')->where('lead_form_id', $leadForm->id)],
            'ids' => 'sometimes|array|max:2000',
            'ids.*' => 'integer',
        ]);

        LeadActions::move($lead, $leadForm, (int) $validated['column_id'], array_map('intval', $validated['ids'] ?? []));

        return back();
    }

    public function update(Request $request, Workspace $workspace, Project $project, LeadForm $leadForm, Lead $lead): RedirectResponse
    {
        $this->authorize('manageLeads', $leadForm);

        LeadActions::update($lead, $project, $request->validate(LeadActions::rules()));

        return back();
    }

    public function destroy(Workspace $workspace, Project $project, LeadForm $leadForm, Lead $lead): RedirectResponse
    {
        $this->authorize('manageLeads', $leadForm);
        $lead->delete();

        return back()->with('success', "Lead #{$lead->number} deleted.");
    }
}
