import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PortalLayout from '@/Layouts/PortalLayout';
import type { PortalIdentityData } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { Link2Off, MailCheck, ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';

interface Props {
    mode: 'form' | 'sent' | 'confirm' | 'invalid';
    returnTo: string;
    email?: string | null;
    token?: string | null;
    identity: PortalIdentityData | null;
}

/** Guest sign-in for the portals (PortalLoginController, v1.42.0): email → link → confirm. */
export default function PortalLogin({ mode, returnTo, email, token, identity }: Props) {
    const form = useForm({ email: email ?? '', name: '', return_to: returnTo });
    const confirm = useForm({});

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('guest.login.store'));
    };

    return (
        <PortalLayout title="Sign in" identity={identity} returnTo={returnTo} width="max-w-md">
            <div className="rounded-xl border bg-card p-6 shadow-sm" data-testid="portal-login">
                {mode === 'form' && (
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <h1 className="text-xl font-semibold">Sign in with your email</h1>
                            <p className="mt-1 text-sm text-muted-foreground">
                                We'll email you a link. No password and no account needed.
                            </p>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="portal-email">Email</Label>
                            <Input
                                id="portal-email"
                                type="email"
                                autoComplete="email"
                                required
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                            />
                            {form.errors.email && <p className="text-sm text-destructive">{form.errors.email}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="portal-name">
                                Your name <span className="text-muted-foreground font-normal">(optional)</span>
                            </Label>
                            <Input
                                id="portal-name"
                                autoComplete="name"
                                maxLength={120}
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                        </div>
                        <Button type="submit" className="w-full" disabled={form.processing}>
                            Email me a sign-in link
                        </Button>
                        {/* Platform notice (BRAI-156): the guest sign-in itself is run by the platform */}
                        <p className="text-xs leading-relaxed text-muted-foreground" data-testid="guest-privacy-notice">
                            We store your email address (and name, if you give one) to sign you in to portals shared with you.
                            What you post in a portal is handled by the organisation that runs it.{' '}
                            <a href={route('privacy')} target="_blank" rel="noopener" className="underline underline-offset-2">
                                Privacy policy
                            </a>
                        </p>
                        <p className="text-center text-xs text-muted-foreground">
                            Part of the team?{' '}
                            <Link href={route('login')} className="underline underline-offset-2">
                                Sign in to Brainitive
                            </Link>
                        </p>
                    </form>
                )}

                {mode === 'sent' && (
                    <div className="text-center space-y-3" data-testid="portal-login-sent">
                        <MailCheck className="mx-auto size-10 text-primary" />
                        <h1 className="text-xl font-semibold">Check your email</h1>
                        <p className="text-sm text-muted-foreground">
                            If {email ? <strong className="text-foreground">{email}</strong> : 'the address'} can receive mail, a sign-in
                            link is on its way. It works once and for 30 minutes.
                        </p>
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={route('guest.login', { return_to: returnTo })}>Use another email</Link>
                        </Button>
                    </div>
                )}

                {mode === 'confirm' && token && (
                    <div className="text-center space-y-4">
                        <ShieldCheck className="mx-auto size-10 text-primary" />
                        <div>
                            <h1 className="text-xl font-semibold">Sign in</h1>
                            {email && <p className="mt-1 text-sm text-muted-foreground">as {email}</p>}
                        </div>
                        <Button
                            className="w-full"
                            disabled={confirm.processing}
                            onClick={() => confirm.post(route('guest.login.consume', token))}
                            data-testid="portal-login-confirm"
                        >
                            Continue
                        </Button>
                    </div>
                )}

                {mode === 'invalid' && (
                    <div className="text-center space-y-3" data-testid="portal-login-invalid">
                        <Link2Off className="mx-auto size-10 text-muted-foreground" />
                        <h1 className="text-xl font-semibold">This link no longer works</h1>
                        <p className="text-sm text-muted-foreground">Sign-in links work once and for 30 minutes. Ask for a new one.</p>
                        <Button asChild variant="outline">
                            <Link href={route('guest.login', { return_to: returnTo })}>Get a new link</Link>
                        </Button>
                    </div>
                )}
            </div>
        </PortalLayout>
    );
}
