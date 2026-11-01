<?php

namespace App\Http\Requests\AppApi;

use App\Models\TimeEntry;
use App\Models\Workspace;

/**
 * `PATCH /api/v1/work-logs/{id}`: only the sent fields change — the rest is taken from the entry and the
 * whole form is validated as on the web. Only the user's own entries (404 otherwise).
 */
class UpdateWorkLogRequest extends WorkLogRequest
{
    private ?TimeEntry $entry = null;

    public function authorize(): bool
    {
        $this->ownEntry();

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = $this->formRules();
        foreach ($rules as $field => $rule) {
            $rules[$field] = ['sometimes', ...(is_array($rule) ? $rule : explode('|', $rule))];
        }

        return $rules;
    }

    public function workspace(): ?Workspace
    {
        return $this->ownEntry()->workspace;
    }

    protected function entry(): ?TimeEntry
    {
        return $this->ownEntry();
    }

    /**
     * The entry's current values with the sent fields on top: the web's whole form.
     *
     * @return array<string, mixed>
     */
    public function form(): array
    {
        $entry = $this->ownEntry();
        $sent = $this->validated();

        return [
            'project_id' => $sent['project_id'] ?? $entry->project_id,
            'item_id' => array_key_exists('issue_id', $sent) ? $sent['issue_id'] : $entry->item_id,
            'spent_on' => $sent['spent_on'] ?? $entry->spent_on->toDateString(),
            'time' => $sent['time'] ?? (string) $entry->minutes,
            'activity_id' => array_key_exists('activity_id', $sent) ? $sent['activity_id'] : $entry->billing_activity_id,
            'note' => array_key_exists('note', $sent) ? $sent['note'] : $entry->note,
            'billable' => $sent['billable'] ?? null,
        ];
    }

    public function ownEntry(): TimeEntry
    {
        if ($this->entry === null) {
            $id = (string) $this->route('log');
            $entry = preg_match('/^[1-9][0-9]{0,17}$/', $id) ? TimeEntry::with('workspace')->where('user_id', $this->user()->id)->find((int) $id) : null;
            abort_unless($entry instanceof TimeEntry && $entry->workspace !== null && $this->user()->hasWorkspaceAccess($entry->workspace), 404);
            $this->entry = $entry;
        }

        return $this->entry;
    }
}
