<?php

namespace App\Http\Requests\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Models\TaskList;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A write to the tasks of a list (BRAI-397). Authorized before validation: a list the user can't
 * open is a 404 (nothing leaks through 422s), one they may only read a 403
 * (TaskListPolicy::manageItems: project admins / editors and workspace admins).
 */
abstract class TaskRequest extends FormRequest
{
    use ResolvesVisible;

    private ?TaskList $list = null;

    public function authorize(): bool
    {
        return $this->user()->can('manageItems', $this->taskList());
    }

    public function taskList(): TaskList
    {
        return $this->list ??= $this->visibleTaskList($this, (string) $this->route('list'));
    }

    /** The list while a request is being served; null while the OpenAPI generator reads the rules. */
    protected function listForRules(): ?TaskList
    {
        return $this->route('list') !== null ? $this->taskList() : null;
    }
}
