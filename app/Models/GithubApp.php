<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The instance's GitHub App, created once by an instance admin through GitHub's manifest flow
 * (BRAI-61). Secrets are encrypted at rest and never sent to the browser. GITHUB_APP_* env vars
 * override this row — read the effective app through App\Support\Github\GithubAppConfig.
 */
class GithubApp extends Model
{
    protected $fillable = ['app_id', 'slug', 'name', 'owner', 'html_url', 'client_id', 'client_secret', 'private_key', 'webhook_secret', 'created_by'];

    protected $hidden = ['client_secret', 'private_key', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'app_id' => 'integer',
            'client_secret' => 'encrypted',
            'private_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }
}
