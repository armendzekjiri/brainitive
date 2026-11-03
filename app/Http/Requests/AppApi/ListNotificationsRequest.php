<?php

namespace App\Http\Requests\AppApi;

use App\Support\Notifications\NotificationInbox;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/** `GET /api/v1/notifications`: the inbox tabs of the web (all, unread, mentions, assigned) and the archive. */
class ListNotificationsRequest extends ListRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        // newest first: a sync starts again from the first page instead of updated_since
        return [
            ...Arr::except(parent::rules(), 'updated_since'),
            'filter' => ['sometimes', Rule::in(NotificationInbox::FILTERS)],
            // true: the archived notifications instead of the inbox
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    public function filter(): string
    {
        return (string) ($this->validated('filter') ?? 'all');
    }

    public function archived(): bool
    {
        return $this->boolean('archived');
    }
}
