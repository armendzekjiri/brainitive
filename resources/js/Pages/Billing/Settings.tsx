import { useRef, useState, type FormEvent, type ReactNode } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import { Building2, FileText, ImageIcon, Info, Landmark, Loader2, Percent, Trash2, Upload } from 'lucide-react';
import BillingLayout from '@/Layouts/BillingLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { BillingSettings, PageProps } from '@/types';

/**
 * Billing → Settings (v1.38.0, BRAI-101), workspace admins: how time reports and invoices are
 * priced, numbered and printed. The logo lives on the private disk and is shown through an
 * admin-only route (logoUrl). Controller: Billing\BillingSettingsController.
 */
type BillingSettingsForm = BillingSettings;

interface Props {
    settings: BillingSettingsForm;
    logoUrl: string | null;
    currencies: string[];
    roundingOptions: number[];
}

const OTHER = '__other';
const MAX_LOGO_BYTES = 1024 * 1024;

function roundingLabel(minutes: number): string {
    return minutes === 0 ? 'Exact minutes' : `Round up to ${minutes} minutes per entry`;
}

function Field({ id, label, error, hint, className, children }: {
    id: string;
    label: string;
    error?: string;
    hint?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('space-y-1.5', className)}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && !error && <p className="text-xs text-muted-foreground">{hint}</p>}
            {error && <p className="text-xs text-destructive" id={`${id}-error`}>{error}</p>}
        </div>
    );
}

function Toggle({ id, checked, onChange, label, description }: {
    id: string;
    checked: boolean;
    onChange: (value: boolean) => void;
    label: string;
    description?: ReactNode;
}) {
    return (
        <label htmlFor={id} className="flex cursor-pointer items-start gap-3 rounded-xl border border-border p-3 transition-colors hover:bg-accent/40">
            <input
                id={id}
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                aria-labelledby={`${id}-label`}
                aria-describedby={description ? `${id}-description` : undefined}
                className="mt-0.5 size-4 shrink-0 rounded border-border text-primary focus:ring-primary"
            />
            <span className="min-w-0">
                <span id={`${id}-label`} className="block text-sm font-medium">{label}</span>
                {description && <span id={`${id}-description`} className="mt-0.5 block text-xs text-muted-foreground">{description}</span>}
            </span>
        </label>
    );
}

function Section({ icon, title, description, children }: { icon: ReactNode; title: string; description?: ReactNode; children: ReactNode }) {
    return (
        <Card>
            <CardHeader className="pb-4">
                <CardTitle className="flex items-center gap-2 text-base">
                    {icon}
                    {title}
                </CardTitle>
                {description && <CardDescription>{description}</CardDescription>}
            </CardHeader>
            <CardContent className="space-y-4">{children}</CardContent>
        </Card>
    );
}

function LogoCard({ logoUrl }: { logoUrl: string | null }) {
    const { currentWorkspace } = usePage<PageProps>().props;
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const slug = currentWorkspace?.slug ?? '';

    const upload = (file: File) => {
        setError(null);
        if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
            setError('The logo must be a PNG, JPG or WebP image.');
            return;
        }
        if (file.size > MAX_LOGO_BYTES) {
            setError('The logo may be at most 1 MB.');
            return;
        }
        setBusy(true);
        router.post(route('billing.settings.logo.store', slug), { logo: file }, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => toast.success('Logo uploaded.'),
            onError: (errors) => setError(errors.logo ?? 'The logo could not be uploaded.'),
            onFinish: () => {
                setBusy(false);
                if (input.current) input.current.value = '';
            },
        });
    };

    const remove = () => {
        setBusy(true);
        router.delete(route('billing.settings.logo.destroy', slug), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => toast.success('Logo removed.'),
            onError: () => toast.error('The logo could not be removed.'),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="space-y-2">
            <Label htmlFor="billing-logo">Logo</Label>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div className="flex h-20 w-full items-center justify-center overflow-hidden rounded-xl border border-dashed border-border bg-white p-2 sm:w-48">
                    {logoUrl
                        ? <img src={logoUrl} alt="Current logo" className="max-h-full max-w-full object-contain" />
                        : <ImageIcon className="size-6 text-muted-foreground" aria-hidden />}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <input
                        ref={input}
                        id="billing-logo"
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        className="sr-only"
                        tabIndex={-1}
                        onChange={(event) => {
                            const file = event.target.files?.[0];
                            if (file) upload(file);
                        }}
                    />
                    <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => input.current?.click()}>
                        {busy ? <Loader2 className="size-4 animate-spin" /> : <Upload className="size-4" />}
                        {logoUrl ? 'Replace logo' : 'Upload logo'}
                    </Button>
                    {logoUrl && (
                        <Button type="button" variant="ghost" size="sm" disabled={busy} onClick={remove}>
                            <Trash2 className="size-4" />
                            Remove logo
                        </Button>
                    )}
                </div>
            </div>
            {error
                ? <p className="text-xs text-destructive" role="alert">{error}</p>
                : <p className="text-xs text-muted-foreground">PNG, JPG or WebP, up to 1 MB. Printed at the top of every document; it is never public.</p>}
        </div>
    );
}

export default function BillingSettings({ settings, logoUrl, currencies, roundingOptions }: Props) {
    const { currentWorkspace } = usePage<PageProps>().props;
    const slug = currentWorkspace?.slug ?? '';
    const { data, setData, setDefaults, put, processing, errors, isDirty } = useForm<BillingSettingsForm>({
        ...settings,
        payment_terms_days: settings.payment_terms_days ?? '',
    });
    const [currencyChoice, setCurrencyChoice] = useState(currencies.includes(settings.currency) ? settings.currency : OTHER);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('billing.settings.update', slug), {
            preserveScroll: true,
            onSuccess: (page) => {
                // show what the server stored (IBAN without spaces, trimmed fields, "19.00")
                const saved = (page.props as unknown as Props).settings;
                const next = { ...saved, payment_terms_days: saved.payment_terms_days ?? '' };
                setData(next);
                setDefaults(next);
                toast.success('Billing settings saved.');
            },
            onError: () => toast.error('Please check the highlighted fields.'),
        });
    };

    return (
        <BillingLayout title="Settings" description="How your time reports and invoices are priced, numbered and printed.">
            <form onSubmit={submit} className="space-y-6" noValidate>
                <div className="grid gap-6 lg:grid-cols-2">
                    <Section icon={<Percent className="size-4 text-primary" />} title="Prices & tax" description="Used for new reports. Finalized documents keep the values they were issued with.">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field id="currency" label="Currency" error={currencyChoice === OTHER ? undefined : errors.currency}>
                                <Select
                                    value={currencyChoice}
                                    onValueChange={(value) => {
                                        setCurrencyChoice(value);
                                        setData('currency', value === OTHER ? '' : value);
                                    }}
                                >
                                    <SelectTrigger id="currency">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {currencies.map((code) => <SelectItem key={code} value={code}>{code}</SelectItem>)}
                                        <SelectItem value={OTHER}>Other…</SelectItem>
                                    </SelectContent>
                                </Select>
                            </Field>
                            {currencyChoice === OTHER && (
                                <Field id="currency_code" label="Currency code" error={errors.currency} hint="Three letters (ISO 4217), e.g. THB.">
                                    <Input
                                        id="currency_code"
                                        value={data.currency}
                                        maxLength={3}
                                        autoComplete="off"
                                        className="uppercase"
                                        onChange={(event) => setData('currency', event.target.value.toUpperCase().replace(/[^A-Z]/g, ''))}
                                    />
                                </Field>
                            )}
                            <Field id="default_tax_rate" label="Default VAT (%)" error={errors.default_tax_rate} hint="A client can have its own rate.">
                                <Input
                                    id="default_tax_rate"
                                    type="number"
                                    inputMode="decimal"
                                    min={0}
                                    max={100}
                                    step="0.01"
                                    value={data.default_tax_rate}
                                    onChange={(event) => setData('default_tax_rate', event.target.value)}
                                />
                            </Field>
                            <Field id="rounding_minutes" label="Rounding" error={errors.rounding_minutes} hint="Per time entry, on reports only. Logged time stays exact.">
                                <Select value={String(data.rounding_minutes)} onValueChange={(value) => setData('rounding_minutes', Number(value))}>
                                    <SelectTrigger id="rounding_minutes">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {roundingOptions.map((minutes) => <SelectItem key={minutes} value={String(minutes)}>{roundingLabel(minutes)}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field id="payment_terms_days" label="Payment terms (days)" error={errors.payment_terms_days} hint="Empty: no due date on documents.">
                                <Input
                                    id="payment_terms_days"
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    max={365}
                                    value={data.payment_terms_days ?? ''}
                                    onChange={(event) => setData('payment_terms_days', event.target.value)}
                                />
                            </Field>
                        </div>
                        <Toggle
                            id="prices_include_tax"
                            checked={data.prices_include_tax}
                            onChange={(value) => setData('prices_include_tax', value)}
                            label="Prices include VAT (gross)"
                            description="On: rates are gross, the total is the sum of the lines and the VAT is part of it. Off: VAT is added on top of the net sum."
                        />
                    </Section>

                    <Section icon={<FileText className="size-4 text-primary" />} title="Invoice mode" description="Documents are time reports by default. Turn this on to issue invoices.">
                        <Toggle
                            id="invoice_mode"
                            checked={data.invoice_mode}
                            onChange={(value) => setData('invoice_mode', value)}
                            label="Issue invoices with consecutive numbers"
                            description="Finalized documents are titled “Invoice” and take the next number below."
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field id="number_prefix" label="Number prefix" error={errors.number_prefix} hint="Optional, e.g. 2026- or INV-.">
                                <Input id="number_prefix" value={data.number_prefix} maxLength={20} onChange={(event) => setData('number_prefix', event.target.value)} />
                            </Field>
                            <Field id="next_number" label="Next number" error={errors.next_number}>
                                <Input
                                    id="next_number"
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    value={data.next_number}
                                    onChange={(event) => setData('next_number', event.target.value)}
                                />
                            </Field>
                        </div>
                        <div className="flex gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-900 dark:text-amber-200">
                            <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
                            <p>
                                Invoice mode makes finalized documents invoices with consecutive numbers. Brainitive doesn’t check
                                local invoicing rules: you are responsible for the legal requirements in your country (required
                                details, tax wording, numbering, archiving). Don’t lower the next number once invoices are issued.
                            </p>
                        </div>
                    </Section>

                    <Section icon={<Building2 className="size-4 text-primary" />} title="Your company" description="The issuer printed on every document.">
                        <LogoCard logoUrl={logoUrl} />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field id="company_name" label="Company name" error={errors.company_name} className="sm:col-span-2">
                                <Input id="company_name" value={data.company_name} maxLength={160} autoComplete="organization" onChange={(event) => setData('company_name', event.target.value)} />
                            </Field>
                            <Field id="company_address" label="Address" error={errors.company_address} className="sm:col-span-2">
                                <Textarea id="company_address" rows={3} value={data.company_address} maxLength={1000} onChange={(event) => setData('company_address', event.target.value)} />
                            </Field>
                            <Field id="company_vat_id" label="VAT ID" error={errors.company_vat_id}>
                                <Input id="company_vat_id" value={data.company_vat_id} maxLength={40} onChange={(event) => setData('company_vat_id', event.target.value)} />
                            </Field>
                            <Field id="company_phone" label="Phone" error={errors.company_phone}>
                                <Input id="company_phone" type="tel" value={data.company_phone} maxLength={40} onChange={(event) => setData('company_phone', event.target.value)} />
                            </Field>
                            <Field id="company_email" label="Email" error={errors.company_email} className="sm:col-span-2">
                                <Input id="company_email" type="email" value={data.company_email} maxLength={255} onChange={(event) => setData('company_email', event.target.value)} />
                            </Field>
                        </div>
                    </Section>

                    <Section icon={<Landmark className="size-4 text-primary" />} title="Bank details & footer" description="Shown under the totals, so clients know where to pay.">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field id="bank_name" label="Bank" error={errors.bank_name} className="sm:col-span-2">
                                <Input id="bank_name" value={data.bank_name} maxLength={120} onChange={(event) => setData('bank_name', event.target.value)} />
                            </Field>
                            <Field id="iban" label="IBAN" error={errors.iban}>
                                <Input id="iban" value={data.iban} maxLength={42} autoComplete="off" spellCheck={false} className="font-mono" onChange={(event) => setData('iban', event.target.value.toUpperCase())} />
                            </Field>
                            <Field id="bic" label="BIC" error={errors.bic}>
                                <Input id="bic" value={data.bic} maxLength={14} autoComplete="off" spellCheck={false} className="font-mono" onChange={(event) => setData('bic', event.target.value.toUpperCase())} />
                            </Field>
                            <Field id="footer" label="Footer" error={errors.footer} hint="Printed at the bottom of every page, e.g. registration or managing director." className="sm:col-span-2">
                                <Textarea id="footer" rows={3} value={data.footer} maxLength={2000} onChange={(event) => setData('footer', event.target.value)} />
                            </Field>
                        </div>
                    </Section>
                </div>

                <div className="sticky bottom-0 z-10 -mx-4 flex items-center justify-end gap-3 border-t border-border bg-background/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-xl sm:border">
                    {isDirty && <span className="text-xs text-muted-foreground">Unsaved changes</span>}
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="size-4 animate-spin" />}
                        Save settings
                    </Button>
                </div>
            </form>
        </BillingLayout>
    );
}
