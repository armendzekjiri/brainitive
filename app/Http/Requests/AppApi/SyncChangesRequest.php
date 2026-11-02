<?php

namespace App\Http\Requests\AppApi;

use App\Support\Sync\ChangeFeed;
use App\Support\Sync\SyncCursor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/sync/changes`: the feed's cursor + page size, and the scope of a client's offline copy.
 * `scopes` / `task_lists` / `captures` start (or change) a sync; with a cursor leave them out (the cursor
 * remembers them; different scopes or captures filter start a full run, different lists are additive).
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
            // ids of the task lists to keep offline and / or `mine` (the lists the user works in: pinned, with open
            // tasks assigned to them, recently worked in), comma separated (at most 50 lists; ones the user can't
            // open are skipped). With a cursor, other lists are additive: only the new ones come in full
            'task_lists' => ['sometimes', 'nullable', 'string', 'max:1000', 'regex:/^((mine|[1-9][0-9]{0,17})(,(mine|[1-9][0-9]{0,17})){0,50})?$/'],
            // all (default) | inbox: only captures in the inbox (one that leaves it comes as a delete)
            'captures' => ['sometimes', 'string', Rule::in(SyncCursor::CAPTURE_FILTERS)],
        ];
    }

    /** @return string|null null = not sent */
    public function captures(): ?string
    {
        $filter = $this->validated('captures');

        return is_string($filter) ? $filter : null;
    }

    /** @return list<string>|null null = not sent */
    public function scopes(): ?array
    {
        $scopes = $this->validated('scopes');

        return is_string($scopes) ? array_values(array_unique(explode(',', $scopes))) : null;
    }

    /** @return array{0: list<int>, 1: bool}|null [ids, mine]; null = not sent */
    public function taskLists(): ?array
    {
        if (! $this->has('task_lists')) {
            return null;
        }
        $parts = array_filter(explode(',', (string) $this->validated('task_lists')), fn (string $part) => $part !== '');
        $ids = array_values(array_unique(array_map('intval', array_filter($parts, fn (string $part) => $part !== 'mine'))));

        return [$ids, in_array('mine', $parts, true)];
    }
}
