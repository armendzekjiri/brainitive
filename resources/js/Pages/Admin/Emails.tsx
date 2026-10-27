import AdminShell from '@/components/admin/AdminShell';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Loader2, Monitor, Moon, Send, Smartphone, Sun } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface EmailEntry {
    key: string;
    label: string;
    group: string;
    recipient: string;
}

interface Props {
    emails: EmailEntry[];
    groups: Record<string, string>;
    adminEmail: string;
}

type Scheme = 'light' | 'dark';
type View = 'html' | 'text';

function Toggle<T extends string>({ label, value, options, onChange }: {
    label: string;
    value: T;
    options: { value: T; label: string; icon?: typeof Sun }[];
    onChange: (value: T) => void;
}) {
    return (
        <div className="inline-flex items-center rounded-md border bg-background p-0.5" role="group" aria-label={label}>
            {options.map(({ value: v, label: optionLabel, icon: Icon }) => (
                <button
                    key={v}
                    type="button"
                    aria-pressed={value === v}
                    onClick={() => onChange(v)}
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded px-2.5 py-1 text-xs font-medium transition-colors',
                        value === v ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {Icon && <Icon className="size-3.5" aria-hidden="true" />}
                    {optionLabel}
                </button>
            ))}
        </div>
    );
}

/**
 * Instance admin → Emails (BRAI-155): every email the app sends, rendered from sample data, in light
 * or dark and at phone or desktop width, plus a test send to the admin's own address.
 */
export default function Emails({ emails, groups, adminEmail }: Props) {
    const [selected, setSelected] = useState(emails[0]?.key ?? '');
    const [scheme, setScheme] = useState<Scheme>('light');
    const [width, setWidth] = useState<'600' | '375'>('600');
    const [view, setView] = useState<View>('html');
    const [meta, setMeta] = useState<{ subject: string; text: string } | null>(null);
    const [sending, setSending] = useState(false);

    const byGroup = useMemo(
        () => Object.entries(groups).map(([key, label]) => ({ key, label, items: emails.filter((e) => e.group === key) })),
        [emails, groups],
    );
    const current = emails.find((e) => e.key === selected);

    useEffect(() => {
        if (!selected) return;
        let cancelled = false;
        setMeta(null);
        window.axios
            .get<{ subject: string; text: string }>(route('admin.emails.show', { email: selected, format: 'json' }))
            .then(({ data }) => !cancelled && setMeta(data))
            .catch(() => !cancelled && setMeta({ subject: '(could not render this email)', text: '' }));
        return () => {
            cancelled = true;
        };
    }, [selected]);

    const sendTest = () => {
        router.post(route('admin.emails.send', { email: selected }), {}, {
            preserveScroll: true,
            onStart: () => setSending(true),
            onFinish: () => setSending(false),
        });
    };

    return (
        <AdminShell title="Emails">
            <div className="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
                <nav aria-label="Emails" className="max-h-72 space-y-5 overflow-y-auto rounded-lg border p-2 lg:max-h-[calc(100vh-14rem)] lg:rounded-none lg:border-0 lg:p-0 lg:pr-2">
                    {byGroup.map((group) => (
                        <div key={group.key}>
                            <h2 className="mb-1.5 px-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{group.label}</h2>
                            <ul className="space-y-0.5">
                                {group.items.map((email) => (
                                    <li key={email.key}>
                                        <button
                                            type="button"
                                            onClick={() => setSelected(email.key)}
                                            aria-current={email.key === selected ? 'true' : undefined}
                                            data-email={email.key}
                                            className={cn(
                                                'w-full rounded-md px-2 py-1.5 text-left text-sm transition-colors',
                                                email.key === selected ? 'bg-accent font-medium text-accent-foreground' : 'hover:bg-muted',
                                            )}
                                        >
                                            {email.label}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </nav>

                {current && (
                    <section aria-label="Preview" className="min-w-0 space-y-3">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div className="min-w-0">
                                <p className="text-xs text-muted-foreground">To: {current.recipient} · sample data only</p>
                                <p className="truncate font-semibold" data-testid="email-subject" title={meta?.subject}>
                                    {meta ? meta.subject : 'Loading…'}
                                </p>
                            </div>
                            <Button size="sm" onClick={sendTest} disabled={sending} className="shrink-0">
                                {sending ? <Loader2 className="animate-spin" /> : <Send />}
                                Send test to me
                            </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">The test goes to {adminEmail}, with “[Test]” in the subject.</p>

                        <div className="flex flex-wrap gap-2">
                            <Toggle label="Format" value={view} onChange={setView} options={[{ value: 'html', label: 'HTML' }, { value: 'text', label: 'Text' }]} />
                            <Toggle label="Color scheme" value={scheme} onChange={setScheme} options={[{ value: 'light', label: 'Light', icon: Sun }, { value: 'dark', label: 'Dark', icon: Moon }]} />
                            <Toggle label="Width" value={width} onChange={setWidth} options={[{ value: '600', label: 'Desktop', icon: Monitor }, { value: '375', label: 'Phone', icon: Smartphone }]} />
                        </div>

                        <div className="overflow-x-auto rounded-lg border bg-muted/40 p-3">
                            {view === 'html' ? (
                                <iframe
                                    key={selected}
                                    title={`Preview: ${current.label}`}
                                    src={route('admin.emails.show', { email: selected, scheme })}
                                    sandbox="allow-popups allow-popups-to-escape-sandbox"
                                    // ?scheme=dark forces the email's dark rules (any browser); color-scheme matches the frame to it
                                    style={{ colorScheme: scheme, width: `${width}px` }}
                                    className="mx-auto block h-[70vh] rounded-md border"
                                />
                            ) : (
                                <pre className="mx-auto max-w-[600px] whitespace-pre-wrap break-words rounded-md border bg-background p-4 font-mono text-xs leading-relaxed">
                                    {meta ? meta.text : 'Loading…'}
                                </pre>
                            )}
                        </div>
                    </section>
                )}
            </div>
        </AdminShell>
    );
}
