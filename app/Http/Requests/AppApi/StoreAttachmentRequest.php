<?php

namespace App\Http\Requests\AppApi;

use App\Support\Boards\ItemAttachments;
use Illuminate\Contracts\Validation\ValidationRule;

/** `POST /api/v1/items/{id}/attachments` (multipart): one file, ≤ 10 MB, as the issue dialog. */
class StoreAttachmentRequest extends IssueRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'file' => ItemAttachments::RULES,
            'request_id' => ['nullable', 'uuid'],
        ];
    }
}
