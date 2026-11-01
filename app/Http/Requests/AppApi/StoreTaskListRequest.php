<?php

namespace App\Http\Requests\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Models\Project;
use App\Models\TaskList;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/projects/{id}/task-lists` (BRAI-397): the web form's fields. Authorized before
 * validation: a project the user can't open is a 404, one they may only read a 403
 * (TaskListPolicy::create: project admins / editors and workspace admins).
 */
class StoreTaskListRequest extends FormRequest
{
    use ResolvesVisible;

    private ?Project $project = null;

    public function authorize(): bool
    {
        return $this->user()->can('create', [TaskList::class, $this->project()]);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            // a uuid: a retry with the same one answers 200 with the list the first call created
            'request_id' => ['nullable', 'uuid'],
        ];
    }

    public function project(): Project
    {
        return $this->project ??= $this->visibleProject($this, (string) $this->route('project'));
    }
}
