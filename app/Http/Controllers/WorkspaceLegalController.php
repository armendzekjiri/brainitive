<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Workspace settings → Legal (BRAI-156): the workspace runs its public pages (support pages, lead
 * forms, portals, share links) as the controller of its visitors' data. Name + links to its own
 * privacy policy and imprint, shown on those pages (PublicOperator).
 */
class WorkspaceLegalController extends Controller
{
    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('update', $workspace);

        $validated = $request->validate([
            'legal_controller_name' => ['nullable', 'string', 'max:200'],
            // https only: these are rendered as links on public pages
            'legal_privacy_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'legal_imprint_url' => ['nullable', 'string', 'max:2048', 'url:https'],
        ], [
            'legal_privacy_url.url' => 'Enter a full https:// address.',
            'legal_imprint_url.url' => 'Enter a full https:// address.',
        ]);

        $workspace->forceFill([
            'legal_controller_name' => filled($validated['legal_controller_name'] ?? null) ? trim($validated['legal_controller_name']) : null,
            'legal_privacy_url' => filled($validated['legal_privacy_url'] ?? null) ? $validated['legal_privacy_url'] : null,
            'legal_imprint_url' => filled($validated['legal_imprint_url'] ?? null) ? $validated['legal_imprint_url'] : null,
        ])->save();

        return back()->with('success', 'Legal details saved.');
    }
}
