<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Resources\AppApi\DeviceResource;
use App\Models\ApiKey;
use App\Models\InstanceSettings;
use App\Notifications\NotificationCatalog;
use App\Support\Admin\UserDeletion;
use App\Support\ApiKeys\ApiKeys;
use App\Support\Portal\PortalAuth;
use App\Support\Profile\Avatar;
use App\Support\Profile\ConnectedApps;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail && InstanceSettings::requiresEmailVerification(),
            'status' => session('status'),
            'connectedApps' => $this->connectedApps($request),
            'notificationCategories' => NotificationCatalog::forUser($request->user()),
            // signed-in desktop / mobile installs (App API v1, BRAI-385), same fields as GET /api/v1/devices
            'appDevices' => DeviceResource::collection($request->user()->appDevices()->active()->recentFirst()->get())->resolve($request),
            // personal API keys (Developer API, v1.52.0): listed under Connected apps; managed on /profile/api-keys
            'apiKeys' => $request->user()->apiKeys()->active()->orderByDesc('created_at')->orderByDesc('id')
                ->get(['id', 'name', 'prefix', 'last_used_at', 'expires_at', 'created_at'])
                ->map(fn (ApiKey $key) => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'prefix' => $key->prefix,
                    'last_used_at' => $key->last_used_at?->toIso8601String(),
                    'expires_at' => $key->expires_at?->toIso8601String(),
                    'created_at' => $key->created_at?->toIso8601String(),
                ])->values(),
            'apiKeysEnabled' => ApiKeys::enabled(),
        ]);
    }

    /**
     * Small personal settings: the browser's time zone (sent automatically) and the daily
     * task summary. JSON requests get 204, Inertia / form posts go back.
     */
    public function updatePreferences(Request $request)
    {
        $validated = $request->validate(ProfileUpdateRequest::preferenceRules());

        $request->user()->update($validated);

        return $request->header('X-Inertia') || ! $request->expectsJson() ? back() : response()->noContent();
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        unset($validated['avatar']);
        $user->fill($validated);

        $emailChanged = $user->isDirty('email');
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        if ($request->hasFile('avatar')) {
            Avatar::replace($user, $request->file('avatar'));
        }

        // a new address: the portal identity of the old one is no longer this account's (Codex web review O)
        DB::transaction(function () use ($user, $emailChanged) {
            $user->save();
            if ($emailChanged) {
                PortalAuth::unlink($user);
            }
        });

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): SymfonyResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Art. 17 GDPR without destroying other people's work (BRAI-156): a workspace other people
        // still work in must be handed over or deleted first; the user's own one-person workspaces go
        // with the account. Content created in other people's workspaces is handed to their owners.
        $shared = $user->ownedWorkspaces()
            ->whereHas('users', fn ($q) => $q->where('users.id', '!=', $user->id))
            ->pluck('name');
        if ($shared->isNotEmpty()) {
            throw ValidationException::withMessages([
                'password' => 'You own '.($shared->count() === 1 ? 'a workspace' : 'workspaces').' other people use ('.$shared->implode(', ').'). Transfer ownership or delete '.($shared->count() === 1 ? 'it' : 'them').' first.',
            ]);
        }

        Auth::logout();

        UserDeletion::delete($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location('/');
    }

    /**
     * Apps holding an active token for this account (MCP connectors, the browser extension, the CLI),
     * one row per app — shared with the App API (ConnectedApps, BRAI-394).
     *
     * @return list<array{client_id: string, name: string, scopes: list<string>, last_issued: ?string, expires_at: ?string, tokens: int}>
     */
    private function connectedApps(Request $request): array
    {
        return array_map(fn (array $app) => collect($app)->except('kind')->all(), ConnectedApps::for($request->user()));
    }

    /** Disconnect an app: revoke all of this account's tokens (and refresh tokens) for it. */
    public function disconnectApp(Request $request, string $clientId): RedirectResponse
    {
        ConnectedApps::disconnect($request->user(), $clientId);

        return back()->with('status', 'app-disconnected');
    }
}
