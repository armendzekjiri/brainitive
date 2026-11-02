import { Button } from '@/components/ui/button';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, router } from '@inertiajs/react';
import { UserRoundCheck } from 'lucide-react';
import { useState } from 'react';

type Person = { name: string; email: string };

type Props = {
    /** who the app's sign-in link signs in */
    user: Person;
    /** who is signed in in this browser right now, if anyone */
    current: Person | null;
};

/**
 * The app's webview link (/app/session/{code}) opened where it would replace another account or sign in a
 * plain browser (BRAI-377): nothing happens until Continue (POST + CSRF). The desktop webview with nobody
 * signed in never sees this page.
 */
export default function Session({ user, current }: Props) {
    const [busy, setBusy] = useState(false);

    const decide = (decision: 'continue' | 'cancel') => {
        router.post(route('app.session.confirm'), { decision }, { onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    };

    const title = current ? `Continue as ${user.email}?` : `Sign in as ${user.email} on this browser?`;

    return (
        <GuestLayout>
            <Head title={current ? 'Switch account' : 'Sign in'} />
            <div className="space-y-6 py-2">
                <div className="flex justify-center">
                    <div className="flex size-14 items-center justify-center rounded-2xl bg-primary/10 dark:bg-primary/20">
                        <UserRoundCheck className="size-7 text-primary" strokeWidth={2} />
                    </div>
                </div>

                <div className="text-center">
                    {current && (
                        <p className="mb-2 text-sm text-muted-foreground" data-testid="current-account">
                            You’re signed in as {current.email}.
                        </p>
                    )}
                    <h1 className="text-xl font-semibold tracking-tight break-words text-foreground">{title}</h1>
                    <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                        {current
                            ? `A Brainitive app asked to sign this browser in as ${user.name}. Continuing signs ${current.email} out here.`
                            : `A Brainitive app asked to sign this browser in as ${user.name}.`}
                    </p>
                </div>

                <p className="text-center text-xs text-muted-foreground">
                    Only continue if you opened this link from your own Brainitive app just now. If someone sent it to you, cancel.
                </p>

                <div className="flex gap-3">
                    <Button variant="outline" className="flex-1" disabled={busy} onClick={() => decide('cancel')}>
                        Cancel
                    </Button>
                    <Button className="flex-1" disabled={busy} onClick={() => decide('continue')}>
                        Continue
                    </Button>
                </div>
            </div>
        </GuestLayout>
    );
}
