<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A smart link chip (SmartLinks::resolve, the web's chips): `ok` with live data, `restricted` (exists, the user
 * can't open it — no title) or `not_found`. `requested` is the ref or URL as asked.
 *
 * @property array<string, mixed> $resource
 */
class SmartLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $l = $this->resource;
        $ok = $l['state'] === 'ok';

        return [
            'requested' => (string) $l['requested'],
            /** @var 'ok'|'restricted'|'not_found' */
            'state' => (string) $l['state'],
            // the canonical ref (`plan:<id>` for a plan asked by slug); null unless ok
            'ref' => $ok ? (string) $l['ref'] : null,
            /** @var 'item'|'page'|'ticket'|'plan'|null */
            'type' => $ok ? (string) $l['type'] : null,
            'label' => $ok ? (string) $l['label'] : null,
            'key' => $ok && $l['key'] !== null ? (string) $l['key'] : null,
            'title' => $ok ? (string) $l['title'] : null,
            'url' => $ok ? (string) $l['url'] : null,
            'status' => $ok && is_array($l['status']) ? ['name' => (string) $l['status']['name'], 'category' => (string) $l['status']['category']] : null,
            'due_date' => $ok && $l['due_date'] !== null ? (string) $l['due_date'] : null,
            'person' => $ok && is_array($l['person']) ? ['role' => (string) $l['person']['role'], 'name' => (string) $l['person']['name']] : null,
            'priority' => $ok && is_array($l['priority']) ? ['name' => (string) $l['priority']['name'], 'color' => $l['priority']['color'] === null ? null : (string) $l['priority']['color']] : null,
            'project' => $ok ? ['name' => (string) $l['project']['name'], 'slug' => (string) $l['project']['slug']] : null,
        ];
    }
}
