<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * A refresh token an app device already used (its sha256), kept so presenting it again counts as reuse however many
 * rotations ago it was (Codex review, finding 7; AppTokens). Pruned after the refresh TTL — by then the token had
 * expired anyway — and deleted with the device or when it is revoked.
 */
class AppDeviceRefreshUse extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = ['app_device_id', 'token_hash'];

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subSeconds((int) config('app-api.refresh_ttl')));
    }
}
