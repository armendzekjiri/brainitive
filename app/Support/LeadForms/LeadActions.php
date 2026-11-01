<?php

namespace App\Support\LeadForms;

use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Project;
use App\Models\User;
use App\Support\Notifications\Assignments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Working the leads of a lead form's board (BRAI-152), shared by the web (LeadController) and the App API
 * (BRAI-421): moving a lead to a pipeline column and editing its contact details / assignee. Callers authorize
 * (LeadFormPolicy::manageLeads) first.
 */
final class LeadActions
{
    /** The editable contact fields and the assignee (the web's lead sheet). @return array<string, string> */
    public static function rules(): array
    {
        return [
            'name' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'sometimes|nullable|string|max:60',
            'company' => 'sometimes|nullable|string|max:255',
            'assigned_to' => 'sometimes|nullable|integer',
        ];
    }

    /**
     * Put the lead into a column; `$ids` = the column's new order (lead ids; others are ignored).
     *
     * @param  list<int>  $ids
     */
    public static function move(Lead $lead, LeadForm $form, int $columnId, array $ids = []): void
    {
        DB::transaction(function () use ($lead, $form, $columnId, $ids) {
            $lead->update(['column_id' => $columnId]);
            if ($ids !== []) {
                $inColumn = Lead::where('lead_form_id', $form->id)->where('column_id', $columnId)->whereIn('id', $ids)->pluck('id')->all();
                foreach (array_values(array_intersect($ids, $inColumn)) as $position => $id) {
                    Lead::whereKey($id)->update(['position' => $position]);
                }
            }
        });
    }

    /**
     * Change contact details / assignee (the given keys); the assignee must open the project, and is notified.
     *
     * @param  array<string, mixed>  $changes  rules()
     */
    public static function update(Lead $lead, Project $project, array $changes): void
    {
        if (! empty($changes['assigned_to'])) {
            $assignee = User::find($changes['assigned_to']);
            if (! $assignee || ! $assignee->hasProjectAccess($project)) {
                throw ValidationException::withMessages(['assigned_to' => 'Pick a member of this project.']);
            }
        }

        $previousAssigneeId = $lead->assigned_to;
        $lead->update($changes);
        Assignments::lead($lead, $previousAssigneeId);
    }
}
