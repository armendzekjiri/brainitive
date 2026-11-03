<?php

namespace App\Http\Resources\AppApi;

use App\Models\DataExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One "Download my data" export (BRAI-394, DataExport::present). The ZIP is downloaded through a
 * signed link that works for 24 h and **only in the owner's web session**: open `download_url` in the
 * system browser, or `download_path` in the app's webview after `POST /auth/web-session` (`?to=`).
 *
 * @mixin DataExport
 */
class DataExportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $presented = $this->resource->present();
        $url = $presented['download_url'];
        $path = is_string($url) ? (parse_url($url, PHP_URL_PATH) ?? '').(($query = parse_url($url, PHP_URL_QUERY)) ? '?'.$query : '') : null;

        return [
            'id' => (string) $this->uuid,
            // queued | running | ready | failed | expired
            'status' => (string) $presented['status'],
            'file_size' => $this->file_size,
            // what the ZIP holds, per kind (issues, docs, comments …)
            'counts' => self::counts($this->resource),
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'finished_at' => $this->finished_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            // null unless ready; needs the owner's web session
            'download_url' => is_string($url) ? $url : null,
            // the same link relative to the server, for /auth/web-session ?to=
            'download_path' => $path,
        ];
    }

    private static function counts(DataExport $export): ?object
    {
        return is_array($export->counts) ? (object) $export->counts : null;
    }
}
