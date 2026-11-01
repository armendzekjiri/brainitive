<?php

namespace App\Http\Requests\AppApi;

use App\Support\MyWork\AssignedWork;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/** `GET /api/v1/me/work`: due filter (one or several, comma separated) and status on top of the list rules. */
class ListWorkRequest extends ListRequest
{
    public const DUE = ['overdue', 'today', 'upcoming'];

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // overdue | today | upcoming, or several comma separated (overdue,today); by the user's local date
            'due' => ['sometimes', 'string', 'max:40', 'regex:/^(overdue|today|upcoming)(,(overdue|today|upcoming))*$/'],
            // open (default) | done | all
            'status' => ['sometimes', Rule::in([AssignedWork::OPEN, AssignedWork::DONE, AssignedWork::ALL])],
        ];
    }

    /** @return list<string> */
    public function due(): array
    {
        $due = $this->validated('due');

        return is_string($due) ? array_values(array_unique(explode(',', $due))) : [];
    }

    public function status(): string
    {
        return (string) ($this->validated('status') ?? AssignedWork::OPEN);
    }
}
