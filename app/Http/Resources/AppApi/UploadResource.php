<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A stored upload (`POST /uploads`): its public address and a ready Markdown snippet to put into a doc, an issue
 * description or a comment.
 *
 * @property array{url: string, name: string, size: int, mime_type: string|null, kind: string} $resource
 */
class UploadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $url = url($r['url']);
        $label = str_replace(['[', ']', "\n"], ['(', ')', ' '], $r['name']);

        return [
            // public, no token needed (like issue attachments)
            'url' => $url,
            'name' => (string) $r['name'],
            // bytes
            'size' => (int) $r['size'],
            'mime_type' => $r['mime_type'] === null ? null : (string) $r['mime_type'],
            /** @var 'image'|'file' */
            'kind' => (string) $r['kind'],
            // `![name](url)` for images, `[name](url)` for files
            'markdown' => ($r['kind'] === 'image' ? '!' : '').'['.$label.']('.$url.')',
        ];
    }
}
