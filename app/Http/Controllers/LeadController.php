<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\LeadForms\LeadFormDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        DB::transaction(function () use ($lead, $validated, $leadForm) {
            $lead->update(['column_id' => $validated['column_id']]);
            $ids = $validated['ids'] ?? [];
            if ($ids !== []) {
                $inColumn = Lead::where('lead_form_id', $leadForm->id)->where('column_id', $validated['column_id'])
                    ->whereIn('id', $ids)->pluck('id')->all();
                foreach (array_values(array_intersect($ids, $inColumn)) as $position => $id) {
                    Lead::whereKey($id)->update(['position' => $position]);
                }
            }
        });

        return back();
    }

    public function update(Request $request, Workspace $workspace, Project $project, LeadForm $leadForm, Lead $lead): RedirectResponse
    {
        $this->authorize('manageLeads', $leadForm);

        $validated = $request->validate([
            'name' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'sometimes|nullable|string|max:60',
            'company' => 'sometimes|nullable|string|max:255',
            'assigned_to' => 'sometimes|nullable|integer',
        ]);

        if (! empty($validated['assigned_to'])) {
            $assignee = User::find($validated['assigned_to']);
            if (! $assignee || ! $assignee->hasProjectAccess($project)) {
                throw ValidationException::withMessages(['assigned_to' => 'Pick a member of this project.']);
            }
        }

        $lead->update($validated);

        return back();
    }

    public function destroy(Workspace $workspace, Project $project, LeadForm $leadForm, Lead $lead): RedirectResponse
    {
        $this->authorize('manageLeads', $leadForm);
        $lead->delete();

        return back()->with('success', "Lead #{$lead->number} deleted.");
    }
}
