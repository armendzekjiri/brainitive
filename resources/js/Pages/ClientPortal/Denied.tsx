import PortalLayout from '@/Layouts/PortalLayout';
import type { PortalIdentityData } from '@/types';
import { ShieldX } from 'lucide-react';

interface Props {
    portal: { title: string; slug: string };
    identity: PortalIdentityData | null;
    reason: 'not_invited' | 'revoked' | 'expired';
}

const MESSAGES: Record<Props['reason'], { title: string; body: string }> = {
    not_invited: { title: 'This portal is by invitation only', body: 'The email you signed in with has not been invited. Ask your contact on the team to invite you, or sign in with the address they used.' },
    revoked: { title: 'Your access was removed', body: 'The team removed your access to this portal. Ask your contact on the team if you think this is a mistake.' },
    expired: { title: 'Your invitation has expired', body: 'Your access to this portal ended. Ask your contact on the team to invite you again.' },
};

/** 403 of the client portal (ClientPortalController::denied, v1.42.0 BRAI-73). */
export default function ClientPortalDenied({ portal, identity, reason }: Props) {
    const m = MESSAGES[reason] ?? MESSAGES.not_invited;

    return (
        <PortalLayout title={portal.title} brand={portal.title} subtitle="Client portal" identity={identity} returnTo={route('client-portal.show', portal.slug, false)} width="max-w-lg">
            <div className="flex flex-col items-center gap-3 rounded-xl border bg-card p-8 text-center shadow-sm" data-testid="client-portal-denied">
                <ShieldX className="size-10 text-muted-foreground" aria-hidden="true" />
                <h1 className="text-xl font-semibold">{m.title}</h1>
                <p className="text-sm text-muted-foreground">{m.body}</p>
                {identity && <p className="text-xs text-muted-foreground">Signed in as {identity.email}</p>}
            </div>
        </PortalLayout>
    );
}
