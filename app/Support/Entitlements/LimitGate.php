<?php

namespace App\Support\Entitlements;

use App\Models\User;
use App\Models\Workspace;

/**
 * The only place that enforces plan limits (v1.46.0). Every entry point that adds members,
 * projects, workspaces, content or files calls one of the ensure*() methods before it writes.
 * Nothing is blocked while Entitlements::enforced() is false (self-hosted, before launch).
 * Members and projects are counted live; content and files come from the nightly usage:measure.
 */
final class LimitGate
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /** Members + open invitations, plus the ones about to be added. */
    public function ensureCanAddMembers(Workspace $workspace, int $adding = 1): void
    {
        $limits = $this->limitsWhenEnforced($workspace);
        if ($limits?->members === null) {
            return;
        }

        $usage = self::memberCount($workspace);
        if ($usage + $adding > $limits->members) {
            throw $this->reached('members', $limits->members, $usage, $limits, $workspace);
        }
    }

    public function ensureCanCreateProject(Workspace $workspace, int $adding = 1): void
    {
        $limits = $this->limitsWhenEnforced($workspace);
        if ($limits?->projects === null) {
            return;
        }

        $usage = $workspace->projects()->count();
        if ($usage + $adding > $limits->projects) {
            throw $this->reached('projects', $limits->projects, $usage, $limits, $workspace);
        }
    }

    /** A new workspace starts on the free plan: the owner may only own so many free ones. */
    public function ensureCanCreateWorkspace(User $owner): void
    {
        $max = $this->entitlements->enforced() ? $this->entitlements->freeWorkspacesPerOwner() : null;
        if ($max === null) {
            return;
        }

        $free = Workspace::where('owner_id', $owner->id)->get()
            ->filter(fn (Workspace $workspace) => $this->entitlements->limits($workspace)->free)
            ->count();
        if ($free >= $max) {
            throw new LimitReached('workspaces', $max, $free, 'Free');
        }
    }

    /** Blocks creating content (pages, issues …) once the measured content storage is used up. */
    public function ensureContentRoom(Workspace $workspace): void
    {
        $limits = $this->limitsWhenEnforced($workspace);
        if ($limits?->contentBytes === null) {
            return;
        }

        $usage = (int) $workspace->usage?->content_bytes;
        if ($usage >= $limits->contentBytes) {
            throw $this->reached('content', $limits->contentBytes, $usage, $limits, $workspace);
        }
    }

    public function ensureCanStoreFiles(Workspace $workspace, int $bytes): void
    {
        $limits = $this->limitsWhenEnforced($workspace);
        if ($limits?->fileBytes === null) {
            return;
        }

        $usage = (int) $workspace->usage?->file_bytes;
        if ($usage + $bytes > $limits->fileBytes) {
            throw $this->reached('files', $limits->fileBytes, $usage, $limits, $workspace);
        }
    }

    public static function memberCount(Workspace $workspace): int
    {
        return $workspace->users()->count() + $workspace->invitations()->valid()->count();
    }

    private function limitsWhenEnforced(Workspace $workspace): ?Limits
    {
        return $this->entitlements->enforced() ? $this->entitlements->limits($workspace) : null;
    }

    private function reached(string $kind, int $limit, int $usage, Limits $limits, Workspace $workspace): LimitReached
    {
        return new LimitReached($kind, $limit, $usage, $limits->plan, $workspace, $this->entitlements->billingUrl($workspace));
    }
}
