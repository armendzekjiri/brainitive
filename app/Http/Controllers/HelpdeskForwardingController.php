<?php

namespace App\Http\Controllers;

use App\Models\Helpdesk;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Helpdesks\Inbound\ForwardingAddress;
use Illuminate\Http\JsonResponse;

/**
 * Helpdesk settings → Email → "Forwarding address" (BRAI-124). JSON for
 * components/helpdesk/email/ForwardingAddressSection.tsx; whoever may edit the helpdesk.
 * While the instance has no inbound domain / provider secret, `enabled` is false and the section
 * shows the setup instead (docs/self-hosting.md).
 */
class HelpdeskForwardingController extends Controller
{
    public function show(Workspace $workspace, Project $project, Helpdesk $helpdesk): JsonResponse
    {
        $this->authorize('update', $helpdesk);

        return $this->payload(ForwardingAddress::for($helpdesk));
    }

    public function regenerate(Workspace $workspace, Project $project, Helpdesk $helpdesk): JsonResponse
    {
        $this->authorize('update', $helpdesk);
        abort_unless(ForwardingAddress::enabled(), 404);

        return $this->payload(ForwardingAddress::regenerate($helpdesk));
    }

    private function payload(?string $address): JsonResponse
    {
        return response()->json([
            'enabled' => $address !== null,
            'address' => $address,
            'provider' => ForwardingAddress::provider(),
        ])->header('Cache-Control', 'no-store');
    }
}
