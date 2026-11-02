<?php

namespace App\Http\Requests\AppApi;

use App\Support\Sync\ChangeFeed;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/**
 * `GET /api/v1/sync/changes`: the feed's cursor + page size, and the scope of a client's offline copy.
 * `scopes` / `task_lists` start (or change) a sync; with a cursor leave them out (the cursor remembers
 * them; sending different ones starts a full run).
 */
class SyncChangesRequest extends ListRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $scope = '('.implode('|', ChangeFeed::SCOPES).')';

        return [
            // the feed has its own `since` inside the cursor
            ...Arr::except(parent::rules(), ['updated_since']),
            // work | task_lists | my_day | captures, comma separated (default: all four)
            'scopes' => ['sometimes', 'string', 'max:60', "regex:/^{$scope}(,{$scope})*$/"],
            // ids of the task lists to keep offline, comma separated (at most 50; lists the user can't open are skipped)
            'task_lists' => ['sometimes', 'nullable', 'string', 'max:1000', 'regex:/^([1-9][0-9]{0,17}(,[1-9][0-9]{0,17}){0,49})?$/'],
        ];
    }

    /** @return list<string>|null null = not sent */
    public function scopes(): ?array
    {
        $scopes = $this->validated('scopes');

        return is_string($scopes) ? array_values(array_unique(explode(',', $scopes))) : null;
    }

    /** @return list<int>|null null = not sent */
    public function taskLists(): ?array
    {
        if (! $this->has('task_lists')) {
            return null;
        }
        $lists = (string) $this->validated('task_lists');

        return $lists === '' ? [] : array_values(array_unique(array_map('intval', explode(',', $lists))));
    }
}
