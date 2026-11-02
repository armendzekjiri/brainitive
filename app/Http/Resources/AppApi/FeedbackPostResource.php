<?php

namespace App\Http\Resources\AppApi;

use App\Models\FeedbackPost;
use App\Support\Feedback\FeedbackModeration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An idea of a feedback portal as the project admins' moderation page shows it (FeedbackModeration::row): the
 * author's name and address, votes, moderation state, public status and the linked card.
 *
 * @mixin FeedbackPost
 */
class FeedbackPostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $portal = $this->portal;
        $row = FeedbackModeration::row($portal->project, $portal, $this->resource);

        return [
            'id' => (int) $row['id'],
            // #number on the public portal
            'number' => (int) $row['number'],
            'title' => (string) $row['title'],
            // plain text
            'body' => $row['body'],
            // pending | approved | rejected
            'moderation' => (string) $row['moderation'],
            // open | planned | building | released | declined (the public roadmap)
            'status' => (string) $row['status'],
            'status_note' => $row['status_note'],
            'votes_count' => (int) $row['votes_count'],
            // the portal user who posted it (name + address: admins only, as on the web)
            'author' => $row['author'] === null ? null : ['name' => (string) $row['author']['name'], 'email' => $row['author']['email']],
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'public_url' => (string) $row['public_url'],
            // merged into another idea: {number, title}
            'merged_into' => $row['merged_into'] === null ? null : ['number' => (int) $row['merged_into']['number'], 'title' => (string) $row['merged_into']['title']],
            // the linked issue
            'item' => $row['item'] === null ? null : [
                'id' => (int) $this->item_id,
                'key' => (string) $row['item']['key'],
                'title' => (string) $row['item']['title'],
                'url' => (string) $row['item']['url'],
            ],
        ];
    }
}
