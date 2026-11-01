<?php

namespace App\Http\Requests\AppApi;

use App\Models\Item;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Workspace;
use App\Support\Billing\TimeEntries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A work log written through the App API (BRAI-400): the web timesheet's form (TimeEntries::rules) with
 * `issue_id` for the web's `item_id`. The workspace is the project's; a project the user can't log on is
 * a 422 on `project_id`, as on the web.
 */
abstract class WorkLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** The workspace of the entry being changed, or of the chosen project (null: not one of the user's). */
    abstract public function workspace(): ?Workspace;

    protected function entry(): ?TimeEntry
    {
        return null;
    }

    /** @return array<string, mixed> */
    protected function formRules(): array
    {
        $workspace = $this->route() !== null && $this->user() !== null ? $this->workspace() : null;
        if ($workspace === null) {
            $rules = [
                'project_id' => ['required', 'integer', Rule::in([])],
                'spent_on' => ['required', 'date_format:Y-m-d'],
                'time' => Item::durationRules(),
                'activity_id' => 'nullable',
                'note' => 'nullable|string|max:255',
                'billable' => 'nullable|boolean',
            ];
        } else {
            $rules = TimeEntries::rules($this->user(), $workspace, $this->entry());
            unset($rules['item_id']);
        }

        return [
            // a project you can log time on (GET /me/timesheet → projects)
            'project_id' => $rules['project_id'],
            // an issue of that project you may edit; null = on the project only
            'issue_id' => ['nullable', 'integer'],
            // Y-m-d, not in the future
            'spent_on' => $rules['spent_on'],
            // 45m, 1h 30m, 2h, 1d or plain minutes ("90"); max. 1w
            'time' => $rules['time'],
            // required while the workspace has activities (GET /me/timesheet → activity_required)
            'activity_id' => $rules['activity_id'],
            'note' => $rules['note'],
            // workspace admins only; everybody else gets the activity's default
            'billable' => $rules['billable'],
        ];
    }

    /** The project's workspace when the user is a member of it. */
    protected function projectWorkspace(mixed $projectId): ?Workspace
    {
        $project = filter_var($projectId, FILTER_VALIDATE_INT) !== false ? Project::with('workspace')->find((int) $projectId) : null;
        $workspace = $project?->workspace;

        return $workspace !== null && $this->user()->hasWorkspaceAccess($workspace) ? $workspace : null;
    }
}
