<?php

namespace App\Support\Entitlements;

use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A plan limit stops an action (LimitGate). JSON callers get 402 with `limit_reached`; Inertia
 * visits go back with the `limit_reached` flash, which opens the upgrade dialog.
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
    public function payload(): array
    {
        return [
            'kind' => $this->kind,
            'limit' => $this->limit,
            'usage' => $this->usage,
            'plan' => $this->plan,
            'workspace' => $this->workspace?->slug,
            'billingUrl' => $this->billingUrl,
            'message' => $this->getMessage(),
        ];
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $this->getMessage(), 'limit_reached' => $this->payload()], 402);
        }

        return back()->with('error', $this->getMessage())->with('limit_reached', $this->payload());
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
