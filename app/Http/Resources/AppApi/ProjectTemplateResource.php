<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A template to start a project from (`TemplateRuns::catalog`, the web's "New project" gallery):
 * built-in ones (`builtin:<key>`) and the workspace's saved ones (`template:<uuid>`). Pass `id` as
 * `template` to `POST /workspaces/{slug}/projects`.
 *
 * @property-read array{id: string, source: string, category: string, tags: list<string>, name: string, description: string|null, icon: string|null, summary: mixed, version?: int, updated_at?: string|null} $resource
 */
class ProjectTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $template = $this->resource;

        return [
            'id' => (string) $template['id'],
            // builtin | workspace
            'source' => (string) $template['source'],
            'category' => (string) $template['category'],
            'tags' => array_map('strval', array_values((array) $template['tags'])),
            'name' => (string) $template['name'],
            'description' => $template['description'] ?? null,
            'icon' => $template['icon'] ?? null,
            // what it sets up: counts per section (boards, docs, lists, plans …)
            'summary' => (array) ($template['summary'] ?? []),
            // the saved version of a workspace template; null for built-in ones
            'version' => isset($template['version']) ? (int) $template['version'] : null,
        ];
    }
}
