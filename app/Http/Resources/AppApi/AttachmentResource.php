<?php

namespace App\Http\Resources\AppApi;

use App\Models\Attachment;
use App\Support\Boards\ItemAttachments;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A file on an issue (BRAI-405). `url` is the file itself (the web's public storage link). Needs `user`.
 *
 * @mixin Attachment
 */
class AttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $uploader = $this->resource->user;

        return [
            'id' => (int) $this->id,
            'item_id' => (int) $this->item_id,
            'name' => (string) $this->original_filename,
            'mime_type' => $this->mime_type,
            // bytes
            'size' => (int) $this->size,
            'url' => ItemAttachments::url($this->resource),
            'uploaded_by' => $uploader ? new PersonResource($uploader) : null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
