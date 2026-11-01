import { Button } from '@/components/ui/button';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, MonitorSmartphone } from 'lucide-react';
import { useState } from 'react';

/** The authorize parameters the app sent, posted back unchanged with the decision. */
type AuthorizeRequest = {
    client: string;
    code_challenge: string;
    code_challenge_method: string;
    state: string;
    device_name?: string | null;
};

type Props = {
    request?: AuthorizeRequest;
    client?: { name: string };
    email?: string;
    error?: string;
};

/**
 * Native sign-in consent (BRAI-355): "Sign in to Brainitive Desktop as …?". Continue / Cancel
 * answer with Inertia::location, so the browser itself opens brainitive://auth/callback.
 */
export default function Authorize({ request, client, email, error }: Props) {
    const [sent, setSent] = useState<'approve' | 'deny' | null>(null);

    if (error || !request || !client) {
        return (
            <GuestLayout>
                <Head title="Sign-in link not valid" />
                <div className="space-y-4 py-2 text-center">
                    <div className="flex justify-center">
                        <div className="flex size-14 items-center justify-center rounded-2xl bg-destructive/10">
                            <AlertTriangle className="size-7 text-destructive" strokeWidth={2} />
                        </div>
                    </div>
                    <h1 className="text-xl font-semibold tracking-tight text-foreground">This sign-in link is not valid</h1>
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        Go back to the app and start signing in again.
                    </p>
                    {error && <p className="text-xs text-muted-foreground">{error}</p>}
                </div>
            </GuestLayout>
        );
    }

    const decide = (decision: 'approve' | 'deny') => {
        setSent(decision);
        router.post(route('app.authorize.store'), { ...request, decision }, { preserveScroll: true });
    };

    return (
        <GuestLayout>
            <Head title={`Sign in to ${client.name}`} />

            <div className="space-y-6 py-2">
                <div className="flex justify-center">
                    <div className="flex size-14 items-center justify-center rounded-2xl bg-primary/10 dark:bg-primary/20">
                        <MonitorSmartphone className="size-7 text-primary" strokeWidth={2} />
                    </div>
                </div>

                <div className="text-center">
                    <h1 className="text-xl font-semibold tracking-tight text-foreground">
                        Sign in to {client.name} as {email}?
                    </h1>
                    <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                        {client.name} will be able to use your Brainitive account on this device, with the same access you
                        have in the browser.
                    </p>
                </div>

                {request.device_name && (
                    <div className="rounded-lg border border-border px-4 py-3">
                        <p className="text-xs text-muted-foreground">Device</p>
                        <p className="text-sm font-semibold break-words text-foreground">{request.device_name}</p>
                    </div>
                )}

                <p className="text-center text-xs text-muted-foreground">
                    Only continue if you started signing in from the app yourself just now.
                </p>

                {sent ? (
                    <p role="status" className="text-center text-sm font-medium text-foreground">
                        {sent === 'approve'
                            ? `Opening ${client.name}… You can close this tab.`
                            : `Sign-in cancelled. You can close this tab.`}
                    </p>
                ) : (
                    <div className="flex gap-3">
                        <Button variant="outline" className="flex-1" onClick={() => decide('deny')}>
                            Cancel
                        </Button>
                        <Button className="flex-1" onClick={() => decide('approve')}>
                            Continue
                        </Button>
                    </div>
                )}
            </div>
        </GuestLayout>
    );
}
