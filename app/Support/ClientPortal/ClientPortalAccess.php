<?php

namespace App\Support\ClientPortal;

use App\Models\ClientDeliverable;
use App\Models\ClientPortal;
use App\Models\ClientPortalMember;
use App\Models\PortalIdentity;

/**
 * Who may see what in a client portal (v1.42.0, BRAI-73). `portal.identity` only proves an email;
 * on top of it a client needs an ACTIVE membership of THIS portal (not revoked, not expired).
 *
 * - unknown or disabled portal, or its project is in the trash → 404 (it does not exist for outsiders);
 * - a deliverable of another portal, or withdrawn → 404 (ids can't be substituted);
 * - signed in but never invited → 404 too (signing in is open to any email); revoked / expired → 403.
 */
final class ClientPortalAccess
{
    public static function portal(string $slug): ClientPortal
    {
        // a portal of a trashed project / workspace is offline too
        $portal = ClientPortal::where('slug', $slug)->where('enabled', true)->whereHas('project.workspace')->first();
        abort_unless($portal, 404);

        return $portal;
    }

    public static function member(ClientPortal $portal, PortalIdentity $identity): ?ClientPortalMember
    {
        return $portal->members()->where('portal_identity_id', $identity->id)->first();
    }

    /** The active membership, or null (callers answer 403). Touches last_seen_at at most every 5 minutes. */
    public static function activeMember(ClientPortal $portal, PortalIdentity $identity): ?ClientPortalMember
    {
        $member = self::member($portal, $identity);
        if (! $member?->isActive()) {
            return null;
        }
        if (! $member->last_seen_at || $member->last_seen_at->lt(now()->subMinutes(5))) {
            $member->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $member;
    }

    /** Why a signed-in identity can't enter: not_invited | revoked | expired. */
    public static function denialReason(?ClientPortalMember $member): string
    {
        return $member ? $member->state() : 'not_invited';
    }

    /** A deliverable of this portal that clients may open (current or superseded, never withdrawn). */
    public static function deliverable(ClientPortal $portal, string $uuid): ClientDeliverable
    {
        $deliverable = $portal->deliverables()->visibleToClients()->where('uuid', $uuid)->first();
        abort_unless($deliverable, 404);

        return $deliverable;
    }
}
