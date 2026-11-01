<?php

namespace App\Http\Requests\AppApi;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A list of the App API v1: cursor pagination (`?cursor=&limit=`, default 50, max 100) and
 * `updated_since` for sync (docs/ai/API.md → Lists). Lists with more filters extend it and merge
 * their rules with parent::rules().
 */
class ListRequest extends FormRequest
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // next_cursor of the previous page
            'cursor' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            // ISO-8601: only what changed since then
            'updated_since' => ['sometimes', 'date'],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? self::DEFAULT_LIMIT);
    }

    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function updatedSince(): ?CarbonImmutable
    {
        $since = $this->validated('updated_since');

        return is_string($since) ? CarbonImmutable::parse($since)->utc() : null;
    }
}
