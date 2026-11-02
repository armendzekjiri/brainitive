import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Tv, XCircle } from 'lucide-react';
import { FormEvent, useState } from 'react';

type Props = {
    stage: 'enter' | 'confirm' | 'approved' | 'denied';
    email: string;
    /** confirm: the code, XXXX-XXXX */
    code?: string;
    /** enter: what was typed before an error */
    typed?: string;
    client?: { name: string };
    device?: { name: string; platform: string | null };
    /** confirm: where and when the device asked — the coarse IP (`203.0.113.x`) and the request's age */
    origin?: { ip: string | null; minutes_ago: number | null };
    error?: string;
};

function startedAgo(minutes: number): string {
    if (minutes < 1) return 'Started less than a minute ago';
    return `Started ${minutes} minute${minutes === 1 ? '' : 's'} ago`;
}

function Icon({ tone, children }: { tone: 'primary' | 'destructive' | 'success'; children: React.ReactNode }) {
    const bg = tone === 'destructive' ? 'bg-destructive/10' : tone === 'success' ? 'bg-brand-green/10' : 'bg-primary/10 dark:bg-primary/20';
    return (
        <div className="flex justify-center">
            <div className={`flex size-14 items-center justify-center rounded-2xl ${bg}`}>{children}</div>
        </div>
    );
}

/**
 * Device-code sign-in (BRAI-438): confirm the code a TV shows. Opened from the TV's QR the code is
 * filled in, but nothing happens until Approve is clicked — the same consent look as AppAuth/Authorize.
 */
export default function Device({ stage, email, code, typed, client, device, origin, error }: Props) {
    const [value, setValue] = useState(typed ?? '');
    const [busy, setBusy] = useState(false);

    const lookUp = (e: FormEvent) => {
        e.preventDefault();
        if (!value.trim()) return;
        router.get(route('app.device'), { code: value.trim() }, { onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    };

    const decide = (decision: 'approve' | 'deny') => {
        router.post(
            route('app.device.store'),
            { user_code: code, decision },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );
    };

    if (stage === 'approved' || stage === 'denied') {
        const approved = stage === 'approved';
        return (
            <GuestLayout>
                <Head title={approved ? 'Device signed in' : 'Sign-in denied'} />
                <div className="space-y-4 py-2 text-center">
                    <Icon tone={approved ? 'success' : 'destructive'}>
                        {approved ? <CheckCircle2 className="size-7 text-brand-green" strokeWidth={2} /> : <XCircle className="size-7 text-destructive" strokeWidth={2} />}
                    </Icon>
                    <h1 className="text-xl font-semibold tracking-tight text-foreground">
                        {approved ? `${client?.name ?? 'The app'} is signing in` : 'Sign-in denied'}
                    </h1>
                    <p role="status" className="text-sm leading-relaxed text-muted-foreground">
                        {approved
                            ? `${device?.name ?? 'Your device'} is now signed in as ${email}. You can close this page.`
                            : `${device?.name ?? 'The device'} was not signed in. You can close this page.`}
                    </p>
                </div>
            </GuestLayout>
        );
    }

    if (stage === 'confirm' && code && client && device) {
        return (
            <GuestLayout>
                <Head title={`Sign in to ${client.name}`} />
                <div className="space-y-6 py-2">
                    <Icon tone="primary">
                        <Tv className="size-7 text-primary" strokeWidth={2} />
                    </Icon>

                    <div className="text-center">
                        <h1 className="text-xl font-semibold tracking-tight text-foreground">
                            Sign in to {client.name} as {email}?
                        </h1>
                        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                            {client.name} will be able to show your day, your work, your calendar and your notifications on this device, and mark
                            work as done. It can't change your account or settings.
                        </p>
                    </div>

                    <div className="rounded-lg border border-border px-4 py-3 text-center">
                        <p className="text-xs text-muted-foreground">Code on the screen</p>
                        <p className="font-mono text-2xl font-semibold tracking-[0.2em] text-foreground" data-testid="device-code">
                            {code}
                        </p>
                    </div>

                    <div className="space-y-3 rounded-lg border border-border px-4 py-3">
                        <div>
                            <p className="text-xs text-muted-foreground">Name reported by the device</p>
                            <p className="text-sm font-semibold break-words text-foreground">{device.name}</p>
                            {device.platform && <p className="text-xs text-muted-foreground">{device.platform}</p>}
                        </div>
                        {origin && (origin.ip || origin.minutes_ago !== null) && (
                            <div data-testid="device-origin">
                                <p className="text-xs text-muted-foreground">Request</p>
                                {origin.ip && <p className="text-sm text-foreground">From network {origin.ip}</p>}
                                {origin.minutes_ago !== null && <p className="text-xs text-muted-foreground">{startedAgo(origin.minutes_ago)}</p>}
                            </div>
                        )}
                    </div>

                    <p className="text-center text-xs text-muted-foreground">
                        Only approve if this is the code on your own TV right now, and you started it just now. Nobody from Brainitive will
                        ever ask you for it.
                    </p>

                    <div className="flex gap-3">
                        <Button variant="outline" className="flex-1" disabled={busy} onClick={() => decide('deny')}>
                            Deny
                        </Button>
                        <Button className="flex-1" disabled={busy} onClick={() => decide('approve')}>
                            Approve
                        </Button>
                    </div>
                </div>
            </GuestLayout>
        );
    }

    return (
        <GuestLayout>
            <Head title="Connect a device" />
            <form onSubmit={lookUp} className="space-y-6 py-2">
                <Icon tone={error ? 'destructive' : 'primary'}>
                    {error ? <AlertTriangle className="size-7 text-destructive" strokeWidth={2} /> : <Tv className="size-7 text-primary" strokeWidth={2} />}
                </Icon>

                <div className="text-center">
                    <h1 className="text-xl font-semibold tracking-tight text-foreground">Connect a device</h1>
                    <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                        Enter the code shown on your TV to sign it in as {email}.
                    </p>
                </div>

                <div className="space-y-2">
                    <Label htmlFor="user_code">Code</Label>
                    <Input
                        id="user_code"
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                        placeholder="XXXX-XXXX"
                        autoComplete="off"
                        autoCapitalize="characters"
                        spellCheck={false}
                        maxLength={32}
                        autoFocus
                        className="text-center font-mono text-lg tracking-[0.2em] uppercase"
                        aria-invalid={!!error}
                        aria-describedby={error ? 'user_code_error' : undefined}
                    />
                    {error && (
                        <p id="user_code_error" role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                </div>

                <Button type="submit" className="w-full" disabled={busy || !value.trim()}>
                    Continue
                </Button>
            </form>
        </GuestLayout>
    );
}
