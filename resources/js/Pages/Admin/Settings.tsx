import AdminShell from '@/components/admin/AdminShell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { useForm, usePage } from '@inertiajs/react';
import { Loader2, Send } from 'lucide-react';
import { FormEvent, ReactNode } from 'react';

interface MailSettings {
    enabled: boolean;
    host: string;
    port: number | string;
    encryption: 'tls' | 'ssl' | 'none';
    username: string;
    has_password: boolean;
    from_address: string;
    from_name: string;
}

interface Props {
    settings: {
        allow_registration: boolean;
        require_email_verification: boolean;
        mail: MailSettings;
    };
    envMail: { mailer: string; host: string | null; from_address: string | null };
}

function Toggle({ id, checked, onChange, label, description }: { id: string; checked: boolean; onChange: (value: boolean) => void; label: string; description: ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-6 py-3">
            <div>
                <Label htmlFor={id} className="text-sm font-medium">{label}</Label>
                <p className="text-sm text-muted-foreground mt-0.5">{description}</p>
            </div>
            <button
                id={id}
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={cn(
                    'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                    checked ? 'bg-primary' : 'bg-input',
                )}
            >
                <span className={cn('inline-block size-5 rounded-full bg-background shadow transition-transform', checked ? 'translate-x-5' : 'translate-x-0.5')} />
            </button>
        </div>
    );
}

function Field({ id, label, error, hint, children }: { id: string; label: string; error?: string; hint?: string; children: ReactNode }) {
    return (
        <div className="space-y-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && !error && <p className="text-xs text-muted-foreground">{hint}</p>}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}

export default function Settings({ settings, envMail }: Props) {
    const me = usePage<PageProps>().props.auth.user;

    const form = useForm({
        allow_registration: settings.allow_registration,
        require_email_verification: settings.require_email_verification,
        mail: {
            enabled: settings.mail.enabled,
            host: settings.mail.host ?? '',
            port: settings.mail.port ?? 587,
            encryption: settings.mail.encryption,
            username: settings.mail.username ?? '',
            password: '',
            clear_password: false,
            from_address: settings.mail.from_address ?? '',
            from_name: settings.mail.from_name ?? '',
        },
    });
    const testForm = useForm({ to: me.email });

    const errors = form.errors as Record<string, string>;
    const setMail = <K extends keyof typeof form.data.mail>(key: K, value: (typeof form.data.mail)[K]) =>
        form.setData('mail', { ...form.data.mail, [key]: value });

    const save = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('admin.settings.update'), {
            preserveScroll: true,
            onSuccess: () => form.setData('mail', { ...form.data.mail, password: '', clear_password: false }),
        });
    };

    const sendTest = (e: FormEvent) => {
        e.preventDefault();
        testForm.post(route('admin.settings.test-email'), { preserveScroll: true });
    };

    const mail = form.data.mail;

    return (
        <AdminShell title="Settings">
            <form onSubmit={save} className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Sign-up & accounts</CardTitle>
                        <CardDescription>Who can create an account on this instance.</CardDescription>
                    </CardHeader>
                    <CardContent className="divide-y">
                        <Toggle
                            id="allow-registration"
                            checked={form.data.allow_registration}
                            onChange={(value) => form.setData('allow_registration', value)}
                            label="Anyone can sign up"
                            description="When off, only people invited to a workspace (or added by you under Users) can create an account. Applies to Google and GitHub sign-up too."
                        />
                        <Toggle
                            id="require-verification"
                            checked={form.data.require_email_verification}
                            onChange={(value) => form.setData('require_email_verification', value)}
                            label="Require email verification"
                            description="New accounts must confirm their email address before using the app. Needs working email delivery."
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Email delivery (SMTP)</CardTitle>
                        <CardDescription>
                            Used for password resets, invitations, verification and notifications. When off, the server’s
                            <code className="mx-1">.env</code> mail settings apply (currently <code>{envMail.mailer}</code>
                            {envMail.mailer === 'smtp' && envMail.host ? ` via ${envMail.host}` : ''}).
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <Toggle
                            id="smtp-enabled"
                            checked={mail.enabled}
                            onChange={(value) => setMail('enabled', value)}
                            label="Send email through this SMTP server"
                            description="Overrides the .env mail settings."
                        />

                        <fieldset disabled={!mail.enabled} className={cn('grid gap-4 sm:grid-cols-2', !mail.enabled && 'opacity-60')}>
                            <Field id="smtp-host" label="Host" error={errors['mail.host']}>
                                <Input id="smtp-host" value={mail.host} onChange={(e) => setMail('host', e.target.value)} placeholder="smtp.example.com" />
                            </Field>
                            <div className="grid grid-cols-2 gap-4">
                                <Field id="smtp-port" label="Port" error={errors['mail.port']}>
                                    <Input id="smtp-port" type="number" value={mail.port} onChange={(e) => setMail('port', e.target.value)} />
                                </Field>
                                <Field id="smtp-encryption" label="Encryption" error={errors['mail.encryption']}>
                                    <Select value={mail.encryption} onValueChange={(value) => setMail('encryption', value as MailSettings['encryption'])}>
                                        <SelectTrigger id="smtp-encryption">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="tls">STARTTLS (587)</SelectItem>
                                            <SelectItem value="ssl">SSL/TLS (465)</SelectItem>
                                            <SelectItem value="none">None</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </Field>
                            </div>
                            <Field id="smtp-username" label="Username" error={errors['mail.username']}>
                                <Input id="smtp-username" value={mail.username} onChange={(e) => setMail('username', e.target.value)} autoComplete="off" />
                            </Field>
                            <Field
                                id="smtp-password"
                                label="Password"
                                error={errors['mail.password']}
                                hint={settings.mail.has_password ? 'A password is saved. Leave empty to keep it.' : 'Stored encrypted. Never shown again.'}
                            >
                                <Input
                                    id="smtp-password"
                                    type="password"
                                    value={mail.password}
                                    onChange={(e) => setMail('password', e.target.value)}
                                    placeholder={settings.mail.has_password ? '••••••••' : ''}
                                    autoComplete="new-password"
                                />
                                {settings.mail.has_password && (
                                    <label className="flex items-center gap-2 text-xs text-muted-foreground">
                                        <input type="checkbox" checked={mail.clear_password} onChange={(e) => setMail('clear_password', e.target.checked)} />
                                        Remove the saved password
                                    </label>
                                )}
                            </Field>
                            <Field id="smtp-from-address" label="From address" error={errors['mail.from_address']}>
                                <Input id="smtp-from-address" type="email" value={mail.from_address} onChange={(e) => setMail('from_address', e.target.value)} placeholder="noreply@example.com" />
                            </Field>
                            <Field id="smtp-from-name" label="From name" error={errors['mail.from_name']}>
                                <Input id="smtp-from-name" value={mail.from_name} onChange={(e) => setMail('from_name', e.target.value)} placeholder="Brainitive" />
                            </Field>
                        </fieldset>
                    </CardContent>
                </Card>

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing} className="gap-2">
                        {form.processing && <Loader2 className="size-4 animate-spin" />} Save settings
                    </Button>
                </div>
            </form>

            <Card className="mt-6">
                <CardHeader>
                    <CardTitle>Send a test email</CardTitle>
                    <CardDescription>Uses the saved settings above. Errors from the mail server are shown right away.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={sendTest} className="flex flex-col sm:flex-row gap-3">
                        <Input type="email" value={testForm.data.to} onChange={(e) => testForm.setData('to', e.target.value)} aria-label="Recipient" required />
                        <Button type="submit" variant="outline" disabled={testForm.processing} className="gap-2 shrink-0">
                            {testForm.processing ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />} Send test
                        </Button>
                    </form>
                    {testForm.errors.to && <p className="mt-2 text-sm text-destructive">{testForm.errors.to}</p>}
                </CardContent>
            </Card>
        </AdminShell>
    );
}
