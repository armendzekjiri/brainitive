<?php

namespace App\Support\Files;

use App\Models\Workspace;
use App\Support\Entitlements\LimitGate;
use App\Support\Entitlements\LimitReached;
use Illuminate\Http\UploadedFile;

/**
 * Cloud plans (v1.46.0): an upload needs room in the workspace's file storage. The web checks it per route
 * group (`file.room`, EnsureFileRoom); the App API calls this before storing anything (BRAI-416).
 */
final class FileRoom
{
    /** @throws LimitReached the files don't fit (402 limit_reached in the App API) */
    public static function ensure(Workspace $workspace, ?UploadedFile ...$files): void
    {
        $bytes = 0;
        foreach ($files as $file) {
            $bytes += (int) $file?->getSize();
        }
        if ($bytes > 0) {
            app(LimitGate::class)->ensureCanStoreFiles($workspace, $bytes);
        }
    }
}
