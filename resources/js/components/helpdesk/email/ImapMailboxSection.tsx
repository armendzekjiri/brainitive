import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
    AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import type { Helpdesk, HelpdeskInboundEmailRow, HelpdeskMailboxPayload, Project, Workspace } from '@/types';
import axios, { AxiosError } from 'axios';
import { CheckCircle2, Inbox, Loader2, PlugZap, Trash2, TriangleAlert } from 'lucide-react';
import { FormEvent, ReactNode, useEffect, useState } from 'react';
import { toast } from 'sonner';

type Encryption = 'ssl' | 'starttls' | 'none';

interface MailboxForm {
    enabled: boolean;
    email_address: string;
    from_name: string;
    imap_host: string;
    imap_port: number;
    imap_encryption: Encryption;
    imap_username: string;
    imap_password: string;
    imap_folder: string;
    processed_folder: string;
    smtp_mode: 'app' | 'smtp';
    smtp_host: string;
    smtp_port: number;
    smtp_encryption: Encryption;
    smtp_username: string;
    smtp_password: string;
}

const EMPTY: MailboxForm = {
    enabled: true,
    email_address: '',
    from_name: '',
    imap_host: '',
    imap_port: 993,
    imap_encryption: 'ssl',
    imap_username: '',
    imap_password: '',
    imap_folder: 'INBOX',
    processed_folder: '',
    smtp_mode: 'app',
    smtp_host: '',
    smtp_port: 587,
    smtp_encryption: 'starttls',
    smtp_username: '',
    smtp_password: '',
};

const SELECT = 'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';

const STATUS_LABELS: Record<HelpdeskInboundEmailRow['status'], string> = {
    ticket_created: 'New ticket',
    reply_added: 'Reply',
    duplicate: 'Duplicate',
    ignored: 'Ignored',
    rejected: 'Rejected',
    failed: 'Failed',
};

const REASON_LABELS: Record<string, string> = {
    auto_submitted: 'auto-reply',
    auto_reply: 'auto-reply',
    bulk: 'bulk mail',
    mailing_list: 'mailing list',
    bounce: 'bounce',
    own_message: 'our own mail',
    invalid_sender: 'no sender',
    rate_limited: 'too many from this sender',
    not_participant: 'sender is not on the ticket',
    dmarc_fail: 'sender failed DMARC',
    too_large: 'too large',
    error: 'error',
};

function toForm(payload: HelpdeskMailboxPayload): MailboxForm {
    const m = payload.mailbox;
    if (!m) return { ...EMPTY };
    return {
        enabled: m.enabled,
        email_address: m.email_address,
        from_name: m.from_name ?? '',
        imap_host: m.imap_host,
        imap_port: m.imap_port,
        imap_encryption: m.imap_encryption,
        imap_username: m.imap_username,
        imap_password: '',
        imap_folder: m.imap_folder,
        processed_folder: m.processed_folder ?? '',
        smtp_mode: m.smtp_mode,
        smtp_host: m.smtp_host ?? '',
        smtp_port: m.smtp_port ?? 587,
        smtp_encryption: m.smtp_encryption ?? 'starttls',
        smtp_username: m.smtp_username ?? '',
        smtp_password: '',
    };
}

function Field({ id, label, error, hint, children }: { id: string; label: string; error?: string; hint?: ReactNode; children: ReactNode }) {
    return (
        <div className="space-y-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : 'never');

/**
 * Helpdesk settings → Email → "Connected mailbox" (BRAI-79): an IMAP mailbox the app polls every
 * minute; replies go out through the app's mailer (Reply-To this address) or the mailbox's SMTP.
 * Loads and saves through its own JSON endpoints (HelpdeskMailboxController). Passwords are
 * write-only: the form shows whether one is saved and keeps it when the field stays empty.
 */
export default function ImapMailboxSection({ workspace, project, helpdesk }: { workspace: Workspace; project: Project; helpdesk: Helpdesk }) {
    const params = [workspace.slug, project.slug, helpdesk.slug];
    const [payload, setPayload] = useState<HelpdeskMailboxPayload | null>(null);
    const [form, setForm] = useState<MailboxForm>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [loadError, setLoadError] = useState(false);
    const [busy, setBusy] = useState<'save' | 'test' | 'delete' | null>(null);
    const [testResult, setTestResult] = useState<{ ok: boolean; message: string } | null>(null);

    const apply = (data: HelpdeskMailboxPayload) => {
        setPayload(data);
        setForm(toForm(data));
    };

    useEffect(() => {
        let alive = true;
        axios.get<HelpdeskMailboxPayload>(route('helpdesks.mailbox.show', params))
            .then(({ data }) => alive && apply(data))
            .catch(() => alive && setLoadError(true));
        return () => {
            alive = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [workspace.slug, project.slug, helpdesk.slug]);

    const set = <K extends keyof MailboxForm>(key: K, value: MailboxForm[K]) => setForm((f) => ({ ...f, [key]: value }));

    const body = () => ({
        ...form,
        from_name: form.from_name || null,
        imap_password: form.imap_password || null,
        processed_folder: form.processed_folder || null,
        smtp_host: form.smtp_mode === 'smtp' ? form.smtp_host : null,
        smtp_port: form.smtp_mode === 'smtp' ? form.smtp_port : null,
        smtp_encryption: form.smtp_mode === 'smtp' ? form.smtp_encryption : null,
        smtp_username: form.smtp_mode === 'smtp' ? form.smtp_username || null : null,
        smtp_password: form.smtp_mode === 'smtp' ? form.smtp_password || null : null,
    });

    const validationErrors = (e: unknown): boolean => {
        const err = e as AxiosError<{ errors?: Record<string, string[]> }>;
        if (err.response?.status === 422 && err.response.data?.errors) {
            setErrors(Object.fromEntries(Object.entries(err.response.data.errors).map(([k, v]) => [k, v[0]])));
            return true;
        }
        return false;
    };

    const save = async (e: FormEvent) => {
        e.preventDefault();
        setBusy('save');
        setErrors({});
        try {
            const { data } = await axios.put<HelpdeskMailboxPayload>(route('helpdesks.mailbox.update', params), body());
            apply(data);
            toast.success('Mailbox saved');
        } catch (err) {
            if (!validationErrors(err)) toast.error('The mailbox could not be saved.');
        } finally {
            setBusy(null);
        }
    };

    const test = async () => {
        setBusy('test');
        setErrors({});
        setTestResult(null);
        try {
            const { data } = await axios.post<{ ok: boolean; message: string }>(route('helpdesks.mailbox.test', params), body());
            setTestResult(data);
        } catch (err) {
            if (!validationErrors(err)) setTestResult({ ok: false, message: 'The test could not be run. Try again in a minute.' });
        } finally {
            setBusy(null);
        }
    };

    const disconnect = async () => {
        setBusy('delete');
        try {
            const { data } = await axios.delete<HelpdeskMailboxPayload>(route('helpdesks.mailbox.destroy', params));
            apply(data);
            setTestResult(null);
            toast.success('Mailbox disconnected');
        } catch {
            toast.error('The mailbox could not be disconnected.');
        } finally {
            setBusy(null);
        }
    };

    const mailbox = payload?.mailbox ?? null;
    const encryptions: { value: Encryption; label: string }[] = [
        { value: 'ssl', label: 'SSL/TLS' },
        { value: 'starttls', label: 'STARTTLS' },
        ...(payload?.options.allow_unencrypted ? [{ value: 'none' as Encryption, label: 'None (local only)' }] : []),
    ];

    return (
        <section aria-labelledby="imap-mailbox-heading" className="space-y-4">
            <div className="space-y-1">
                <h3 id="imap-mailbox-heading" className="flex items-center gap-2 text-base font-semibold">
                    <Inbox className="h-4 w-4" /> Connected mailbox
                    {mailbox && (mailbox.enabled ? <Badge variant="secondary">Active</Badge> : <Badge variant="outline">Paused</Badge>)}
                </h3>
                <p className="text-sm text-muted-foreground">
                    Brainitive checks this mailbox every minute: new email becomes a ticket, answers land in their ticket, and your replies go back by email in the same thread. Use a mailbox only for support — mail you read elsewhere first is skipped.
                </p>
            </div>

            {loadError && (
                <div className="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive" role="alert">
                    <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" /> The mailbox settings could not be loaded. Reload the page to try again.
                </div>
            )}

            {!payload && !loadError && <Skeleton className="h-40 w-full" />}

            {payload && (
                <form onSubmit={save} className="space-y-6" aria-label="Connected mailbox">
                    {mailbox && (
                        <div className="space-y-2 text-sm">
                            <p className="text-muted-foreground">
                                Last check: <span className="text-foreground">{when(mailbox.last_checked_at)}</span>
                                {mailbox.last_success_at && <> · last success: <span className="text-foreground">{when(mailbox.last_success_at)}</span></>}
                            </p>
                            {mailbox.last_error && (
                                <div className="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-destructive" role="alert">
                                    <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" /> <span>Last error: {mailbox.last_error}</span>
                                </div>
                            )}
                        </div>
                    )}

                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" className="h-4 w-4 rounded border-input" checked={form.enabled} onChange={(e) => set('enabled', e.target.checked)} />
                        Import email from this mailbox
                    </label>

                    <div className="grid gap-4 md:grid-cols-2">
                        <Field id="mailbox-address" label="Address customers write to" error={errors.email_address}>
                            <Input id="mailbox-address" type="email" value={form.email_address} onChange={(e) => set('email_address', e.target.value)} placeholder="support@example.com" required />
                        </Field>
                        <Field id="mailbox-from-name" label="Sender name (optional)" error={errors.from_name} hint={`Default: ${helpdesk.name}`}>
                            <Input id="mailbox-from-name" value={form.from_name} onChange={(e) => set('from_name', e.target.value)} />
                        </Field>
                    </div>

                    <fieldset className="space-y-4">
                        <legend className="text-sm font-medium">Incoming mail (IMAP)</legend>
                        <div className="grid gap-4 md:grid-cols-[1fr_8rem_10rem]">
                            <Field id="mailbox-imap-host" label="IMAP server" error={errors.imap_host}>
                                <Input id="mailbox-imap-host" value={form.imap_host} onChange={(e) => set('imap_host', e.target.value)} placeholder="imap.example.com" required />
                            </Field>
                            <Field id="mailbox-imap-port" label="IMAP port" error={errors.imap_port}>
                                <select id="mailbox-imap-port" className={SELECT} value={form.imap_port} onChange={(e) => set('imap_port', Number(e.target.value))}>
                                    {payload.options.imap_ports.map((p) => <option key={p} value={p}>{p}</option>)}
                                </select>
                            </Field>
                            <Field id="mailbox-imap-encryption" label="IMAP encryption" error={errors.imap_encryption}>
                                <select id="mailbox-imap-encryption" className={SELECT} value={form.imap_encryption} onChange={(e) => set('imap_encryption', e.target.value as Encryption)}>
                                    {encryptions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                                </select>
                            </Field>
                        </div>
                        <div className="grid gap-4 md:grid-cols-2">
                            <Field id="mailbox-imap-username" label="IMAP user name" error={errors.imap_username}>
                                <Input id="mailbox-imap-username" autoComplete="off" value={form.imap_username} onChange={(e) => set('imap_username', e.target.value)} required />
                            </Field>
                            <Field
                                id="mailbox-imap-password"
                                label="IMAP password"
                                error={errors.imap_password}
                                hint={mailbox?.has_imap_password ? 'Saved. Leave empty to keep it.' : 'An app password if your provider uses two-step sign-in.'}
                            >
                                <Input id="mailbox-imap-password" type="password" autoComplete="new-password" value={form.imap_password} onChange={(e) => set('imap_password', e.target.value)} placeholder={mailbox?.has_imap_password ? '••••••••' : ''} />
                            </Field>
                            <Field id="mailbox-imap-folder" label="Folder" error={errors.imap_folder}>
                                <Input id="mailbox-imap-folder" value={form.imap_folder} onChange={(e) => set('imap_folder', e.target.value)} required />
                            </Field>
                            <Field id="mailbox-processed-folder" label="Move imported mail to (optional)" error={errors.processed_folder} hint="Empty: imported mail is only marked as read.">
                                <Input id="mailbox-processed-folder" value={form.processed_folder} onChange={(e) => set('processed_folder', e.target.value)} placeholder="e.g. Imported" />
                            </Field>
                        </div>
                    </fieldset>

                    <fieldset className="space-y-3">
                        <legend className="text-sm font-medium">Replies</legend>
                        <label className="flex items-start gap-2 text-sm">
                            <input type="radio" name="smtp_mode" className="mt-0.5 h-4 w-4" checked={form.smtp_mode === 'app'} onChange={() => set('smtp_mode', 'app')} />
                            <span>
                                Send through the app’s mail server
                                <span className="block text-xs text-muted-foreground">
                                    From {payload.options.app_from_address || 'the app address'} with your helpdesk’s name; answers go to the address above.
                                </span>
                            </span>
                        </label>
                        <label className="flex items-start gap-2 text-sm">
                            <input type="radio" name="smtp_mode" className="mt-0.5 h-4 w-4" checked={form.smtp_mode === 'smtp'} onChange={() => set('smtp_mode', 'smtp')} />
                            <span>
                                Send through this mailbox’s SMTP server
                                <span className="block text-xs text-muted-foreground">Replies come from the address above itself.</span>
                            </span>
                        </label>

                        {form.smtp_mode === 'smtp' && (
                            <div className="space-y-4 rounded-md border p-4">
                                <div className="grid gap-4 md:grid-cols-[1fr_8rem_10rem]">
                                    <Field id="mailbox-smtp-host" label="SMTP server" error={errors.smtp_host}>
                                        <Input id="mailbox-smtp-host" value={form.smtp_host} onChange={(e) => set('smtp_host', e.target.value)} placeholder="smtp.example.com" />
                                    </Field>
                                    <Field id="mailbox-smtp-port" label="SMTP port" error={errors.smtp_port}>
                                        <select id="mailbox-smtp-port" className={SELECT} value={form.smtp_port} onChange={(e) => set('smtp_port', Number(e.target.value))}>
                                            {payload.options.smtp_ports.map((p) => <option key={p} value={p}>{p}</option>)}
                                        </select>
                                    </Field>
                                    <Field id="mailbox-smtp-encryption" label="SMTP encryption" error={errors.smtp_encryption}>
                                        <select id="mailbox-smtp-encryption" className={SELECT} value={form.smtp_encryption} onChange={(e) => set('smtp_encryption', e.target.value as Encryption)}>
                                            {encryptions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                                        </select>
                                    </Field>
                                </div>
                                <div className="grid gap-4 md:grid-cols-2">
                                    <Field id="mailbox-smtp-username" label="SMTP user name" error={errors.smtp_username} hint="Empty: the IMAP user name.">
                                        <Input id="mailbox-smtp-username" autoComplete="off" value={form.smtp_username} onChange={(e) => set('smtp_username', e.target.value)} />
                                    </Field>
                                    <Field
                                        id="mailbox-smtp-password"
                                        label="SMTP password"
                                        error={errors.smtp_password}
                                        hint={mailbox?.has_smtp_password ? 'Saved. Leave empty to keep it.' : 'Empty: the IMAP password.'}
                                    >
                                        <Input id="mailbox-smtp-password" type="password" autoComplete="new-password" value={form.smtp_password} onChange={(e) => set('smtp_password', e.target.value)} />
                                    </Field>
                                </div>
                            </div>
                        )}
                    </fieldset>

                    {testResult && (
                        <div
                            role="status"
                            className={`flex items-start gap-2 rounded-md border p-3 text-sm ${testResult.ok ? 'border-green-600/30 bg-green-600/5 text-green-700 dark:text-green-400' : 'border-destructive/30 bg-destructive/5 text-destructive'}`}
                        >
                            {testResult.ok ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" /> : <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" />}
                            <span>{testResult.message}</span>
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-2 border-t pt-4">
                        <Button type="submit" disabled={busy !== null}>
                            {busy === 'save' && <Loader2 className="h-4 w-4 animate-spin" />} Save mailbox
                        </Button>
                        <Button type="button" variant="outline" onClick={test} disabled={busy !== null}>
                            {busy === 'test' ? <Loader2 className="h-4 w-4 animate-spin" /> : <PlugZap className="h-4 w-4" />} Test connection
                        </Button>
                        {mailbox && (
                            <AlertDialog>
                                <AlertDialogTrigger asChild>
                                    <Button type="button" variant="ghost" className="text-destructive" disabled={busy !== null}>
                                        <Trash2 className="h-4 w-4" /> Disconnect
                                    </Button>
                                </AlertDialogTrigger>
                                <AlertDialogContent>
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>Disconnect this mailbox?</AlertDialogTitle>
                                        <AlertDialogDescription>
                                            Brainitive stops checking it and forgets the saved passwords. Tickets and their replies stay; replies to customers go out as notifications again.
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                                        <AlertDialogAction onClick={disconnect}>Disconnect</AlertDialogAction>
                                    </AlertDialogFooter>
                                </AlertDialogContent>
                            </AlertDialog>
                        )}
                    </div>
                </form>
            )}

            {payload && payload.recent.length > 0 && (
                <div className="space-y-2">
                    <h4 className="text-sm font-medium">Recently received</h4>
                    <ul className="divide-y rounded-md border text-sm" aria-label="Recently received email">
                        {payload.recent.map((row) => (
                            <li key={row.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                                <Badge variant={row.status === 'rejected' || row.status === 'failed' ? 'destructive' : row.status === 'ignored' || row.status === 'duplicate' ? 'outline' : 'secondary'}>
                                    {STATUS_LABELS[row.status] ?? row.status}
                                </Badge>
                                <span className="min-w-0 flex-1 truncate">
                                    {row.subject || '(no subject)'} <span className="text-muted-foreground">— {row.from_email ?? 'unknown sender'}</span>
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {row.ticket_key && <>{row.ticket_key} · </>}
                                    {row.reason && <>{REASON_LABELS[row.reason] ?? row.reason} · </>}
                                    {row.transport === 'imap' ? 'mailbox' : 'forwarding'} · {when(row.created_at)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
