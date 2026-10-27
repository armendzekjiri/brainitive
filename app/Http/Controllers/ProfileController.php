<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\InstanceSettings;
use App\Notifications\NotificationCatalog;
use App\Support\Admin\UserDeletion;
use App\Support\Uploads;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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
        ]);
    }

    /**
     * Small personal settings: the browser's time zone (sent automatically) and the daily
     * task summary. JSON requests get 204, Inertia / form posts go back.
     */
    public function updatePreferences(Request $request)
    {
        $validated = $request->validate([
            // all_with_bc: browsers still report old names such as "Asia/Calcutta".
            'timezone' => 'sometimes|required|string|timezone:all_with_bc',
            'task_digest' => 'sometimes|boolean',
        ]);

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

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
            $file = $request->file('avatar');
            $ext = Uploads::imageExtension($file);
            $path = $file->storeAs('profile-photos/'.$user->id, 'avatar.'.$ext, 'public');
            $user->avatar = $path;
        }

        $user->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
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

        return Redirect::to('/');
    }

    /**
     * Apps holding an active token for this account (MCP connectors, the browser extension, the CLI),
     * one row per app.
     *
     * @return list<array{client_id: string, name: string, scopes: list<string>, last_issued: ?string, expires_at: ?string, tokens: int}>
     */
    private function connectedApps(Request $request): array
    {
        return $request->user()->tokens()
            ->with('client:id,name')
            ->where('revoked', false)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->get()
            ->groupBy('client_id')
            ->map(fn ($tokens) => [
                'client_id' => (string) $tokens->first()->client_id,
                'name' => $tokens->first()->client?->name ?? 'Unknown app',
                'scopes' => $tokens->pluck('scopes')->flatten()->unique()->values()->all(),
                'last_issued' => $tokens->first()->created_at?->toIso8601String(),
                'expires_at' => $tokens->max('expires_at')?->toIso8601String(),
                'tokens' => $tokens->count(),
            ])
            ->values()
            ->all();
    }

    /** Disconnect an app: revoke all of this account's tokens (and refresh tokens) for it. */
    public function disconnectApp(Request $request, string $clientId): RedirectResponse
    {
        foreach ($request->user()->tokens()->where('client_id', $clientId)->where('revoked', false)->get() as $token) {
            $token->revoke();
            $token->refreshToken?->revoke();
        }

        return back()->with('status', 'app-disconnected');
    }
}
