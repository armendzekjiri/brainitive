<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/sync`: a batch of queued offline changes. Only the envelope is checked here; each change
 * is validated on its own (a bad one comes back as `invalid` in its result, the others still apply).
 */
class SyncBatchRequest extends FormRequest
{
    /** Most changes in one batch. */
    public const MAX = 50;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // the device's outbox in order: [{request_id, type, target?, base?, data}]
            'changes' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX],
            'changes.*' => ['array'],
            // a uuid per change, made once on the device: a retry with the same id answers the stored result
            'changes.*.request_id' => ['nullable'],
            // list_task.create|update|reorder, issue.update|comment|create, capture.create, my_day.add|remove, work.done,
            // timer.start|stop|discard, notification.read, notifications.read_all
            'changes.*.type' => ['nullable'],
            // {id} (a server id) or {ref} (the request_id of an earlier create in the outbox)
            'changes.*.target' => ['nullable'],
            // {fields: {field: value as the device saw it}} for every field an update changes
            'changes.*.base' => ['nullable'],
            // the change itself (per type)
            'changes.*.data' => ['nullable'],
        ];
    }
}
