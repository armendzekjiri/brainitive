<?php

namespace App\Http\Controllers;

use App\Support\Portal\PortalAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guest sign-in for the portals (v1.42.0): email → one-time link → confirm → signed in.
 * Contract: docs/ai/tasks/release-v1.42.0.md.
 */
class PortalLoginController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $returnTo = PortalAuth::safeReturnTo($request->query('return_to'));

        if (PortalAuth::current($request) && $returnTo !== '/') {
            return redirect($returnTo);
        }

        return Inertia::render('Portal/Login', [
            'mode' => session('portal_link_sent') ? 'sent' : 'form',
            'returnTo' => $returnTo,
            'email' => session('portal_link_email'),
            'identity' => PortalAuth::shared($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'return_to' => ['nullable', 'string', 'max:500'],
        ]);

        PortalAuth::sendLink($data['email'], $data['name'] ?? null, $data['return_to'] ?? null);

        return redirect()->route('guest.login', ['return_to' => PortalAuth::safeReturnTo($data['return_to'] ?? null)])
            ->with('portal_link_sent', true)
            ->with('portal_link_email', $data['email']);
    }

    /** The link in the mail: a page with a button, so link scanners don't spend it. */
    public function show(Request $request, string $token): Response
    {
        $row = PortalAuth::findToken($token);

        return Inertia::render('Portal/Login', [
            'mode' => $row ? 'confirm' : 'invalid',
            'token' => $row ? $token : null,
            'email' => $row?->identity->email,
            'returnTo' => $row?->return_to ?? '/',
            'identity' => PortalAuth::shared($request),
        ]);
    }

    public function consume(Request $request, string $token): RedirectResponse
    {
        $next = PortalAuth::consume($request, $token);

        if ($next === null) {
            return redirect()->route('guest.login.show', ['token' => $token]);
        }

        return redirect($next);
    }

    public function destroy(Request $request): RedirectResponse
    {
        PortalAuth::logout($request);

        return redirect(PortalAuth::safeReturnTo($request->input('return_to')));
    }
}
