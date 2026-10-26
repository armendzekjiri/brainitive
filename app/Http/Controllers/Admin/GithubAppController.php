<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\GithubApp;
use App\Models\GithubConnection;
use App\Models\GithubInstallation;
use App\Support\Github\AppManifest;
use App\Support\Github\GithubApi;
use App\Support\Github\GithubApiException;
use App\Support\Github\GithubAppConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Instance admin → GitHub App (BRAI-61): create the instance's GitHub App in one click with
 * GitHub's manifest flow, see its status, or remove it. Secrets are stored encrypted and never
 * sent to the browser. GITHUB_APP_* env vars override (then this page is read-only).
 */
class GithubAppController extends Controller
{
    private const STATE_KEY = 'github_app_manifest_state';

    public function show(): Response
    {
        $app = GithubAppConfig::current();
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'self-hosted';

        return Inertia::render('Admin/GithubApp', [
            'app' => $app ? [
                'source' => $app->source,
                'app_id' => $app->appId,
                'slug' => $app->slug,
                'name' => $app->name,
                'owner' => $app->owner,
                'url' => $app->publicUrl(),
                'can_verify_installations' => $app->canVerifyInstallations(),
                'created_at' => $app->source === 'stored' ? GithubAppConfig::stored()?->created_at?->toIso8601String() : null,
            ] : null,
            'fromEnv' => GithubAppConfig::fromEnv(),
            'stats' => [
                'installations' => GithubInstallation::whereNull('removed_at')->count(),
                'app_connections' => GithubConnection::where('mode', GithubConnection::MODE_APP)->count(),
                'legacy_connections' => GithubConnection::where('mode', GithubConnection::MODE_WEBHOOK)->count(),
            ],
            'defaults' => [
                'name' => Str::limit('Brainitive '.$host, 34, ''),
                'webhook_url' => route('webhooks.github.app'),
                'setup_url' => route('github.app.setup'),
                'callback_url' => route('admin.github-app.callback'),
                'permissions' => AppManifest::PERMISSIONS,
                'events' => AppManifest::EVENTS,
            ],
        ]);
    }

    /** The manifest + GitHub URL the browser posts to (a form post: GitHub needs the admin signed in there). */
    public function manifest(Request $request): JsonResponse
    {
        abort_if(GithubAppConfig::fromEnv(), 409, 'The GitHub App is set by GITHUB_APP_* environment variables.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:34'],
            'organization' => ['nullable', 'string', 'max:39', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/'],
            'public' => ['boolean'],
        ]);

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        return response()->json([
            'url' => AppManifest::postUrl($validated['organization'] ?? null, $state),
            'manifest' => json_encode(AppManifest::build($validated['name'], $request->boolean('public', true)), JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** GitHub sends the admin back here with a one-time code for the new app's credentials. */
    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::STATE_KEY);
        $state = (string) $request->query('state', '');
        if (! is_string($expected) || $state === '' || ! hash_equals($expected, $state)) {
            return redirect()->route('admin.github-app.show')->with('error', 'This GitHub App link expired or was not started here. Try again.');
        }
        if (GithubAppConfig::fromEnv()) {
            return redirect()->route('admin.github-app.show')->with('error', 'The GitHub App is set by environment variables.');
        }
        $code = (string) $request->query('code', '');
        if ($code === '' || strlen($code) > 200) {
            return redirect()->route('admin.github-app.show')->with('error', 'GitHub did not return the new app.');
        }

        try {
            $data = GithubApi::convertManifest($code);
        } catch (GithubApiException $e) {
            return redirect()->route('admin.github-app.show')->with('error', $e->getMessage());
        }

        $pem = (string) data_get($data, 'pem', '');
        if ((int) data_get($data, 'id') < 1 || blank(data_get($data, 'slug')) || $pem === '' || openssl_pkey_get_private($pem) === false || blank(data_get($data, 'webhook_secret'))) {
            return redirect()->route('admin.github-app.show')->with('error', 'GitHub returned an incomplete app. Delete it on GitHub and try again.');
        }

        $app = DB::transaction(function () use ($data, $pem, $request) {
            GithubApp::query()->delete(); // one app per instance

            return GithubApp::create([
                'app_id' => (int) data_get($data, 'id'),
                'slug' => Str::limit((string) data_get($data, 'slug'), 100, ''),
                'name' => Str::limit((string) data_get($data, 'name'), 100, '') ?: null,
                'owner' => Str::limit((string) data_get($data, 'owner.login'), 100, '') ?: null,
                'html_url' => str_starts_with((string) data_get($data, 'html_url'), 'https://github.com/') ? Str::limit((string) data_get($data, 'html_url'), 300, '') : null,
                'client_id' => data_get($data, 'client_id') ?: null,
                'client_secret' => data_get($data, 'client_secret') ?: null,
                'private_key' => $pem,
                'webhook_secret' => (string) data_get($data, 'webhook_secret'),
                'created_by' => $request->user()->id,
            ]);
        });

        AdminAuditLog::record('github_app.created', null, ['app' => $app->slug, 'app_id' => $app->app_id]);

        return redirect()->route('admin.github-app.show')->with('success', "GitHub App “{$app->slug}” created. Projects can now connect GitHub.");
    }

    public function destroy(): RedirectResponse
    {
        if (GithubAppConfig::fromEnv()) {
            return back()->with('error', 'The GitHub App is set by environment variables; remove them to change it.');
        }

        $app = GithubAppConfig::stored();
        if ($app) {
            GithubApp::query()->delete();
            AdminAuditLog::record('github_app.removed', null, ['app' => $app->slug, 'app_id' => $app->app_id]);
        }

        return back()->with('success', 'GitHub App removed from this instance. Delete it on GitHub too.');
    }
}
