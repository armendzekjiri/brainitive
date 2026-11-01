<?php

namespace App\Http\Requests\AppApi;

use App\Models\Project;
use App\Models\Workspace;
use App\Support\Projects\ProjectCreator;
use App\Support\Templates\TemplateRuns;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/workspaces/{slug}/projects` (BRAI-389): the web form's rules (ProjectCreator).
 * Authorized before validation, so somebody outside the workspace gets a 404 and can't probe which
 * names or keys are taken; members who may not create projects get a 403. A retried template
 * run (same `request_id`) skips validation, which would fail on "name already taken", and the
 * controller answers with the project the first request created (as the web form does).
 */
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = $this->workspace();
        abort_unless($this->user()->can('view', $workspace), 404);

        return $this->user()->can('createProjects', $workspace);
    }

    private Project|false|null $existing = null;

    public function validateResolved(): void
    {
        if (! $this->passesAuthorization()) {
            $this->failedAuthorization();
        }
        if ($this->existingProject() !== null) {
            return;
        }

        parent::validateResolved();
    }

    /** The project an earlier, completed run with this request_id created (template runs only). */
    public function existingProject(): ?Project
    {
        if ($this->existing === null) {
            $this->existing = $this->filled('template')
                ? (TemplateRuns::alreadyCreated($this->workspace(), $this->user(), $this->input('request_id')) ?? false)
                : false;
        }

        return $this->existing ?: null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        // null while the OpenAPI generator reads the rules without a request
        return ProjectCreator::rules($this->route('workspace'));
    }

    private function workspace(): Workspace
    {
        /** @var Workspace */
        return $this->route('workspace');
    }
}
