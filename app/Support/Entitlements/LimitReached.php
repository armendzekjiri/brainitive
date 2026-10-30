<?php

namespace App\Support\Entitlements;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A plan limit stops an action (LimitGate). JSON callers get 402 with `limit_reached`; Inertia
 * visits go back with the `limit_reached` flash, which opens the upgrade dialog. Only people who may
 * manage the workspace get the billing link; everyone else is asked to contact an admin.
 */
final class LimitReached extends RuntimeException
{
    public const KINDS = ['members', 'projects', 'workspaces', 'content', 'files'];

    public function __construct(
        public readonly string $kind,
        public readonly int $limit,
        public readonly int $usage,
        public readonly string $plan,
        public readonly ?Workspace $workspace = null,
        public readonly ?string $billingUrl = null,
    ) {
        parent::__construct(self::sentence($kind, $limit, $plan));
    }

    /** @return array<string, mixed> */
    public function payload(?User $viewer = null): array
    {
        $canUpgrade = $this->workspace === null || ($viewer?->can('update', $this->workspace) ?? false);

        return [
            'kind' => $this->kind,
            'limit' => $this->limit,
            'usage' => $this->usage,
            'plan' => $this->plan,
            'workspace' => $this->workspace?->slug,
            'billingUrl' => $canUpgrade ? $this->billingUrl : null,
            'message' => $canUpgrade ? $this->getMessage() : $this->adminMessage(),
        ];
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $payload = $this->payload($request->user());

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $payload['message'], 'limit_reached' => $payload], 402);
        }

        return back()->with('error', $payload['message'])->with('limit_reached', $payload);
    }

    /** For members who can't upgrade: the same limit, but "ask an admin" instead of "upgrade". */
    private function adminMessage(): string
    {
        return preg_replace('/ Upgrade .*$/', '', $this->getMessage()).' Ask a workspace admin to upgrade the plan.';
    }

    private static function sentence(string $kind, int $limit, string $plan): string
    {
        return match ($kind) {
            'members' => "The {$plan} plan allows up to {$limit} members per workspace. Upgrade to invite more people.",
            'projects' => "The {$plan} plan allows up to {$limit} projects per workspace. Upgrade to create more.",
            'workspaces' => "You can own up to {$limit} workspaces on the {$plan} plan. Upgrade one of them to create another.",
            'content' => "This workspace has used all the content storage of the {$plan} plan. Upgrade to add more.",
            'files' => "This workspace has used all the file storage of the {$plan} plan. Upgrade to upload more.",
            default => "The {$plan} plan doesn't allow this. Upgrade to continue.",
        };
    }
}
