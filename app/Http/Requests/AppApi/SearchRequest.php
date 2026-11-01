<?php

namespace App\Http\Requests\AppApi;

use App\Support\Search\SearchQuery;
use App\Support\Search\SearchService;
use App\Support\Search\SearchTypes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/search` (BRAI-392): the filters of the web's search page (SearchService::queryFrom),
 * validated instead of silently ignored. Pages are numbered: `next_cursor` is the next page number.
 */
class SearchRequest extends FormRequest
{
    public const DEFAULT_LIMIT = 20;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // words to find: "exact phrase", -excluded, an issue key like WEB-12 ranks that issue first
            'q' => ['required', 'string', 'max:300'],
            // one group: issues, docs, comments, tickets, tasks, plans, snippets, bookmarks, captures, files
            'type' => ['sometimes', 'nullable', Rule::in(array_keys(SearchTypes::GROUPS))],
            // a workspace slug: only that workspace's content
            'workspace' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project' => ['sometimes', 'array', 'max:50'],
            'project.*' => ['integer', 'min:1'],
            'status' => ['sometimes', 'nullable', Rule::in(SearchQuery::STATUSES)],
            // "me" or a user id
            'assignee' => ['sometimes', 'nullable', 'regex:/^(me|[1-9][0-9]{0,18})$/'],
            'updated' => ['sometimes', 'nullable', Rule::in(array_keys(SearchService::UPDATED_PRESETS))],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.SearchQuery::MAX_PER_PAGE],
            // next_cursor of the previous page
            'cursor' => ['sometimes', 'nullable', 'regex:/^([1-9][0-9]?|100)$/'],
        ];
    }

    public function page(): int
    {
        return (int) ($this->validated('cursor') ?? 1);
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? self::DEFAULT_LIMIT);
    }

    /** No filter at all: the result also lists places (workspaces, projects, boards …) by name. */
    public function unfiltered(): bool
    {
        foreach (['type', 'workspace', 'project', 'status', 'assignee', 'updated'] as $filter) {
            if (! empty($this->validated($filter))) {
                return false;
            }
        }

        return true;
    }
}
