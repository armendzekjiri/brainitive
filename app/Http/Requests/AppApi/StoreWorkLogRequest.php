<?php

namespace App\Http\Requests\AppApi;

use App\Models\Workspace;

/** `POST /api/v1/work-logs`: log time on an issue or a project. */
class StoreWorkLogRequest extends WorkLogRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->formRules(),
            // a uuid: a retry with the same one answers 200 with the work log the first call created
            'request_id' => ['nullable', 'uuid'],
        ];
    }

    public function workspace(): ?Workspace
    {
        return $this->projectWorkspace($this->input('project_id'));
    }
}
