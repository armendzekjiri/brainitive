<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A linked issue read from this issue's side (BRAI-406), from ContentLinkFormatter::issueLinks: "blocks",
 * "is_blocked_by", "relates_to" … An issue the user can't open stays as a link but `restricted: true`, with
 * no id / key / title / url (BRAI-435, as the web's "Restricted").
 *
 * @property-read array{link_id: int, link_type: string, label: string, direction: string, restricted: bool, id: int, key: string, title: string, url: string} $resource
 */
class IssueLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $link = $this->resource;
        $restricted = (bool) $link['restricted'];

        return [
            'id' => (int) $link['link_id'],
            // blocks | is_blocked_by | duplicates | is_duplicated_by | relates_to | clones | is_cloned_by
            'type' => (string) $link['link_type'],
            // "is blocked by", as the web shows it
            'label' => (string) $link['label'],
            // outgoing = added on this issue, incoming = added on the other one
            'direction' => (string) $link['direction'],
            'restricted' => $restricted,
            'issue' => $restricted ? null : [
                'id' => (int) $link['id'],
                'key' => (string) $link['key'],
                'title' => (string) $link['title'],
                'url' => (string) $link['url'],
            ],
        ];
    }
}
