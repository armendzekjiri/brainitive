<?php

namespace App\Support\Entitlements;

use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * Fixed limits from config/entitlements.php (ENTITLEMENTS_DRIVER=config). Used by e2e and local
 * testing: the limits apply only to workspaces whose slug starts with `only_slug_prefix`, so the
 * seeded workspaces stay unlimited.
 */
final class ConfigEntitlements implements Entitlements
{
    /** @param  array<string, mixed>  $config */
    public function __construct(private readonly array $config) {}

    public function enforced(): bool
    {
        return (bool) ($this->config['enforced'] ?? true);
    }

    public function limits(Workspace $workspace): Limits
    {
        if (! $this->applies($workspace)) {
            return Limits::unlimited();
        }

        return new Limits(
            plan: (string) ($this->config['plan'] ?? 'Free'),
            free: true,
            members: $this->int('members'),
            projects: $this->int('projects'),
            contentBytes: $this->int('content_bytes'),
            fileBytes: $this->int('file_bytes'),
            versions: new VersionRetention((int) ($this->config['versions_keep_latest'] ?? 0), $this->int('versions_keep_days')),
        );
    }

    public function freeWorkspacesPerOwner(): ?int
    {
        return $this->int('free_workspaces_per_owner');
    }

    /** Only the matching workspaces have billing: the others stay like self-hosted (no plan UI). */
    public function billingUrl(Workspace $workspace): ?string
    {
        return $this->applies($workspace) ? ($this->config['billing_url'] ?? null) : null;
    }

    private function applies(Workspace $workspace): bool
    {
        $prefix = (string) ($this->config['only_slug_prefix'] ?? '');

        return $prefix === '' || Str::startsWith((string) $workspace->slug, $prefix);
    }

    private function int(string $key): ?int
    {
        $value = $this->config[$key] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }
}
