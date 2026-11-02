<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use App\Support\Entitlements\LimitGate;
use App\Support\Entitlements\LimitReached;
use Illuminate\Support\Facades\Storage;

/**
 * Attachments (issue and ticket) reserve their bytes in the workspace's file storage when their row is created and
 * give them back when it's deleted (Codex web review C): one model hook, so every surface — the web, public widget
 * and portal uploads, inbound mail, the App API, the browser extension — is counted at once, not at the next
 * nightly measure. The reservation is atomic (LimitGate::reserveFiles, row lock): at the limit the row isn't
 * created, the just-stored file is removed and LimitReached (402) goes to the caller. The meter counts these
 * tables' `size` columns, so nothing is recorded twice.
 */
trait ReservesFileStorage
{
    public static function bootReservesFileStorage(): void
    {
        static::creating(function (self $model) {
            $workspace = $model->meteredWorkspace();
            if (! $workspace instanceof Workspace) {
                return;
            }
            try {
                app(LimitGate::class)->reserveFiles($workspace, (int) $model->size);
            } catch (LimitReached $e) {
                if ($model->path) {
                    Storage::disk('public')->delete($model->path);
                }

                throw $e;
            }
        });

        static::deleted(function (self $model) {
            $workspace = $model->meteredWorkspace();
            if ($workspace instanceof Workspace) {
                app(LimitGate::class)->releaseFiles($workspace, (int) $model->size);
            }
        });
    }

    abstract public function meteredWorkspace(): ?Workspace;
}
