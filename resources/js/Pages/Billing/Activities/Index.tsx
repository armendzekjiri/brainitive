import { useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import type { Page } from '@inertiajs/core';
import { toast } from 'sonner';
import { Archive, ArchiveRestore, ArrowDown, ArrowUp, Check, Pencil, Plus, Tags, Trash2 } from 'lucide-react';
import BillingLayout from '@/Layouts/BillingLayout';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
    AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { BillingActivity, BillingActivityItemType, BillingPricingType, PageProps } from '@/types';

interface Props {
    workspace: { id: number; name: string; slug: string };
    /** ISO 4217 code from the workspace's billing settings */
    currency: string;
    activities: BillingActivity[];
    itemTypes: BillingActivityItemType[];
}

const COLORS: { value: string; name: string }[] = [
    { value: '#0E83DC', name: 'Blue' },
    { value: '#6366f1', name: 'Indigo' },
    { value: '#a855f7', name: 'Purple' },
    { value: '#ec4899', name: 'Pink' },
    { value: '#ef4444', name: 'Red' },
    { value: '#f97316', name: 'Orange' },
    { value: '#f59e0b', name: 'Amber' },
    { value: '#69A748', name: 'Green' },
    { value: '#14b8a6', name: 'Teal' },
    { value: '#64748b', name: 'Slate' },
];

const PRICING: Record<BillingPricingType, { label: string; hint: string }> = {
    hourly: { label: 'Per hour', hint: 'Amount = hours × price' },
    fixed: { label: 'Fixed price', hint: 'A flat price per ticket; hours are for information' },
};

/** €120.00 — the viewer's locale, the workspace's currency (falls back to "120.00 XYZ"). */
function formatMoney(cents: number, currency: string): string {
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(cents / 100);
    } catch {
        return `${(cents / 100).toFixed(2)} ${currency}`;
    }
}

function currencySymbol(currency: string): string {
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency }).formatToParts(0).find((p) => p.type === 'currency')?.value ?? currency;
    } catch {
        return currency;
    }
}

const priceLabel = (activity: Pick<BillingActivity, 'rate_cents' | 'pricing_type'>, currency: string) =>
    activity.pricing_type === 'hourly' ? `${formatMoney(activity.rate_cents, currency)} / h` : `${formatMoney(activity.rate_cents, currency)} flat`;

const successToast = (page: Page) => {
    const message = (page.props as unknown as PageProps).flash?.success;
    if (message) toast.success(message);
};
const errorToast = (errors: Record<string, string>) => toast.error(Object.values(errors)[0] ?? 'Something went wrong.');

/**
 * Billing → Activities (v1.38.0, BRAI-99, workspace admins): what the team bills, priced per hour
 * or as a fixed price, and which activity is preselected per issue type when someone logs time.
 */
export default function ActivitiesIndex({ workspace, currency, activities, itemTypes }: Props) {
    const [editing, setEditing] = useState<BillingActivity | 'new' | null>(null);
    const [deleting, setDeleting] = useState<BillingActivity | null>(null);
    const [reordering, setReordering] = useState(false);
    const active = activities.filter((a) => !a.archived_at);
    const archived = activities.filter((a) => a.archived_at);
    const visit = { preserveScroll: true, onSuccess: successToast, onError: errorToast };

    const move = (index: number, delta: number) => {
        const ids = active.map((a) => a.id);
        const target = index + delta;
        if (target < 0 || target >= ids.length) return;
        [ids[index], ids[target]] = [ids[target], ids[index]];
        router.post(route('billing.activities.reorder', workspace.slug), { ids }, {
            preserveScroll: true,
            onError: errorToast,
            onStart: () => setReordering(true),
            onFinish: () => setReordering(false),
        });
    };
    const archive = (activity: BillingActivity) => router.post(route('billing.activities.archive', [workspace.slug, activity.id]), {}, visit);
    const restore = (activity: BillingActivity) => router.post(route('billing.activities.restore', [workspace.slug, activity.id]), {}, visit);

    const newButton = (
        <Button className="gap-2" onClick={() => setEditing('new')}>
            <Plus className="size-4" />
            New activity
        </Button>
    );

    return (
        <BillingLayout
            title="Activities"
            description="What your team bills. Everyone picks an activity when they log time; prices are only visible to workspace admins."
            actions={active.length > 0 ? newButton : undefined}
        >
            <Card>
                <CardHeader>
                    <CardTitle className="text-lg">Activities</CardTitle>
                    <CardDescription>
                        Per hour: the amount is hours × price. Fixed price: billed once per ticket, whatever the hours. Prices in {currency}.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                    {active.length === 0 ? (
                        <div className="flex flex-col items-center rounded-xl border border-dashed px-6 py-10 text-center">
                            <Tags className="mb-3 size-8 text-muted-foreground/60" />
                            <p className="font-medium">{archived.length > 0 ? 'No active activities' : 'No activities yet'}</p>
                            <p className="mt-1 max-w-md text-sm text-muted-foreground">
                                {archived.length > 0 && 'Restore one below, or create a new one. '}
                                Create what your team bills, like <span className="font-medium text-foreground">Programming {formatMoney(12000, currency)}/h</span> or{' '}
                                <span className="font-medium text-foreground">SSM setup {formatMoney(45000, currency)} flat</span>.
                            </p>
                            <div className="mt-4">{newButton}</div>
                        </div>
                    ) : (
                        <ul className="space-y-2" aria-label="Activities">
                            {active.map((activity, index) => (
                                <li key={activity.id} className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border bg-muted/20 p-3 transition-colors hover:bg-muted/40">
                                    <ActivitySummary activity={activity} currency={currency} />
                                    <div className="ml-auto flex shrink-0 items-center gap-0.5">
                                        <Button variant="ghost" size="icon" className="size-8" disabled={reordering || index === 0} onClick={() => move(index, -1)} aria-label={`Move ${activity.name} up`}>
                                            <ArrowUp className="size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon" className="size-8" disabled={reordering || index === active.length - 1} onClick={() => move(index, 1)} aria-label={`Move ${activity.name} down`}>
                                            <ArrowDown className="size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon" className="size-8" onClick={() => setEditing(activity)} aria-label={`Edit ${activity.name}`}>
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon" className="size-8" onClick={() => archive(activity)} aria-label={`Archive ${activity.name}`} title="Archive: kept on logged time, can't be picked for new time">
                                            <Archive className="size-4" />
                                        </Button>
                                        {!activity.used && (
                                            <Button variant="ghost" size="icon" className="size-8 text-destructive hover:text-destructive" onClick={() => setDeleting(activity)} aria-label={`Delete ${activity.name}`}>
                                                <Trash2 className="size-4" />
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {archived.length > 0 && (
                        <div className="space-y-2">
                            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Archived</p>
                            <ul className="space-y-2" aria-label="Archived activities">
                                {archived.map((activity) => (
                                    <li key={activity.id} className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-dashed p-3 opacity-80">
                                        <ActivitySummary activity={activity} currency={currency} />
                                        <div className="ml-auto flex shrink-0 items-center gap-1">
                                            <Button variant="outline" size="sm" className="gap-1.5" onClick={() => restore(activity)} aria-label={`Restore ${activity.name}`}>
                                                <ArchiveRestore className="size-4" />
                                                Restore
                                            </Button>
                                            {!activity.used && (
                                                <Button variant="ghost" size="icon" className="size-8 text-destructive hover:text-destructive" onClick={() => setDeleting(activity)} aria-label={`Delete ${activity.name}`}>
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </CardContent>
            </Card>

            <DefaultsCard workspace={workspace} itemTypes={itemTypes} activities={active} hasArchived={archived.length > 0} />

            <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{editing === 'new' ? 'New activity' : 'Edit activity'}</DialogTitle>
                        <DialogDescription>
                            {editing !== 'new' && editing?.used
                                ? 'Time was already logged with this activity. A new price applies to reports you create from now on; finalized reports keep theirs.'
                                : 'Name it after the work, e.g. Programming, Testing or SSM setup.'}
                        </DialogDescription>
                    </DialogHeader>
                    {editing !== null && (
                        <ActivityForm
                            key={editing === 'new' ? 'new' : editing.id}
                            activity={editing === 'new' ? null : editing}
                            defaultColor={COLORS[activities.length % COLORS.length].value}
                            workspaceSlug={workspace.slug}
                            currency={currency}
                            onClose={() => setEditing(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <AlertDialog open={deleting !== null} onOpenChange={(open) => !open && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete “{deleting?.name}”?</AlertDialogTitle>
                        <AlertDialogDescription>No time was logged with this activity yet, so nothing else is lost.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                            onClick={() => deleting && router.delete(route('billing.activities.destroy', [workspace.slug, deleting.id]), visit)}
                        >
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </BillingLayout>
    );
}

function ColorDot({ color, className }: { color: string | null; className?: string }) {
    return <span aria-hidden className={cn('inline-block size-3 shrink-0 rounded-full border border-black/10 dark:border-white/20', !color && 'bg-muted', className)} style={color ? { backgroundColor: color } : undefined} />;
}

function ActivitySummary({ activity, currency }: { activity: BillingActivity; currency: string }) {
    return (
        <div className="flex min-w-0 flex-1 basis-56 items-center gap-3">
            <ColorDot color={activity.color} />
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <p className="truncate font-medium">{activity.name}</p>
                    <Badge variant="secondary" className="shrink-0 text-[10px]">{PRICING[activity.pricing_type].label}</Badge>
                    {!activity.billable && <Badge variant="outline" className="shrink-0 text-[10px]">Not billable</Badge>}
                </div>
                <p className="text-sm tabular-nums text-muted-foreground">
                    {priceLabel(activity, currency)}
                    {activity.used && <span className="text-xs"> · time logged</span>}
                </p>
            </div>
        </div>
    );
}

function ActivityForm({ activity, defaultColor, workspaceSlug, currency, onClose }: {
    activity: BillingActivity | null;
    /** a new activity gets the next palette colour, so the list doesn't turn all blue */
    defaultColor: string;
    workspaceSlug: string;
    currency: string;
    onClose: () => void;
}) {
    const [name, setName] = useState(activity?.name ?? '');
    const [pricingType, setPricingType] = useState<BillingPricingType>(activity?.pricing_type ?? 'hourly');
    const [price, setPrice] = useState(activity ? (activity.rate_cents / 100).toFixed(2).replace(/\.00$/, '') : '');
    const [billable, setBillable] = useState(activity?.billable ?? true);
    const [color, setColor] = useState<string | null>(activity ? activity.color : defaultColor);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // "120,50" → "120.50"; thousands separators are not expected in a price field
        const data = { name, pricing_type: pricingType, price: price.trim().replace(',', '.'), billable, color };
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: (page: Page) => {
                successToast(page);
                onClose();
            },
            onError: (errs: Record<string, string>) => setErrors(errs),
        };
        if (activity) router.put(route('billing.activities.update', [workspaceSlug, activity.id]), data, options);
        else router.post(route('billing.activities.store', workspaceSlug), data, options);
    };

    return (
        <form onSubmit={submit} className="space-y-5">
            <div className="space-y-2">
                <Label htmlFor="activity-name">Name</Label>
                <Input id="activity-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={80} placeholder="e.g. Programming" required autoFocus />
                {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
            </div>

            <div className="space-y-2">
                <Label id="activity-pricing-label">Pricing</Label>
                <div role="radiogroup" aria-labelledby="activity-pricing-label" className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {(Object.keys(PRICING) as BillingPricingType[]).map((type) => {
                        const selected = pricingType === type;
                        return (
                            <button
                                key={type}
                                type="button"
                                role="radio"
                                aria-checked={selected}
                                onClick={() => setPricingType(type)}
                                className={cn(
                                    'flex items-start gap-2 rounded-lg border p-3 text-left text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    selected ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:bg-muted/50',
                                )}
                            >
                                <span className={cn('mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border', selected ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/40')}>
                                    {selected && <Check className="size-3" />}
                                </span>
                                <span>
                                    <span className="block font-medium">{PRICING[type].label}</span>
                                    <span className="block text-xs text-muted-foreground">{PRICING[type].hint}</span>
                                </span>
                            </button>
                        );
                    })}
                </div>
                {errors.pricing_type && <p className="text-sm text-destructive">{errors.pricing_type}</p>}
            </div>

            <div className="space-y-2">
                <Label htmlFor="activity-price">{pricingType === 'hourly' ? 'Price per hour' : 'Fixed price'}</Label>
                <div className="flex items-center gap-2">
                    <div className="flex h-9 w-44 shrink-0 items-center rounded-md border border-input shadow-sm focus-within:ring-1 focus-within:ring-ring">
                        <span aria-hidden className="whitespace-nowrap pl-3 text-sm text-muted-foreground">{currencySymbol(currency)}</span>
                        <Input
                            id="activity-price"
                            inputMode="decimal"
                            value={price}
                            onChange={(e) => setPrice(e.target.value)}
                            placeholder={pricingType === 'hourly' ? '120' : '450'}
                            className="h-full min-w-0 border-0 pl-2 tabular-nums shadow-none focus-visible:ring-0"
                            required
                        />
                    </div>
                    <span className="text-sm text-muted-foreground">{pricingType === 'hourly' ? 'per hour' : 'flat, once per ticket'}</span>
                </div>
                {errors.price && <p className="text-sm text-destructive">{errors.price}</p>}
            </div>

            <label className="flex cursor-pointer items-start gap-3 rounded-lg border p-3">
                <input type="checkbox" className="mt-0.5 size-4 accent-primary" checked={billable} onChange={(e) => setBillable(e.target.checked)} />
                <span className="text-sm">
                    <span className="block font-medium">Billable</span>
                    <span className="block text-xs text-muted-foreground">Counts towards the client's bill. Turn it off for internal work like meetings.</span>
                </span>
            </label>

            <div className="space-y-2">
                <Label id="activity-color-label">Colour</Label>
                <div role="radiogroup" aria-labelledby="activity-color-label" className="flex flex-wrap gap-2">
                    {COLORS.map(({ value: c, name: colorName }) => (
                        <button
                            key={c}
                            type="button"
                            role="radio"
                            aria-checked={color === c}
                            aria-label={colorName}
                            title={colorName}
                            onClick={() => setColor(c)}
                            className={cn('size-7 rounded-full border border-black/10 transition-transform hover:scale-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 dark:border-white/20', color === c && 'ring-2 ring-foreground ring-offset-2 ring-offset-background')}
                            style={{ backgroundColor: c }}
                        />
                    ))}
                </div>
                {errors.color && <p className="text-sm text-destructive">{errors.color}</p>}
            </div>

            <DialogFooter className="gap-2 sm:gap-0">
                <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                <Button type="submit" disabled={processing}>{activity ? 'Save' : 'Create activity'}</Button>
            </DialogFooter>
        </form>
    );
}

const NONE = 'none';

function DefaultsCard({ workspace, itemTypes, activities, hasArchived }: {
    workspace: Props['workspace'];
    itemTypes: BillingActivityItemType[];
    /** the active ones: archived activities can't be a default */
    activities: BillingActivity[];
    hasArchived: boolean;
}) {
    const [saving, setSaving] = useState<number | null>(null);

    const save = (type: BillingActivityItemType, value: string) => {
        router.put(route('billing.activities.defaults.update', workspace.slug), {
            item_type_id: type.id,
            billing_activity_id: value === NONE ? null : Number(value),
        }, {
            preserveScroll: true,
            onStart: () => setSaving(type.id),
            onFinish: () => setSaving(null),
            onSuccess: successToast,
            onError: errorToast,
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-lg">Default activity per issue type</CardTitle>
                <CardDescription>
                    Preselected when someone logs time on an issue of this type. They can still pick another one.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {activities.length === 0 && (
                    <p className="mb-3 rounded-lg bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
                        {hasArchived ? 'Create or restore an activity first' : 'Create an activity first'}, then pick a default for each issue type.
                    </p>
                )}
                <ul className="divide-y rounded-lg border" aria-label="Default activity per issue type">
                    {itemTypes.map((type) => {
                        const label = type.project ? `${type.name} (${type.project})` : type.name;
                        return (
                            <li key={type.id} className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex min-w-0 items-center gap-2">
                                    <ColorDot color={type.color} />
                                    <span className="truncate text-sm font-medium">{type.name}</span>
                                    {type.project && <span className="truncate text-xs text-muted-foreground">{type.project}</span>}
                                </div>
                                <Select
                                    value={type.billing_activity_id ? String(type.billing_activity_id) : NONE}
                                    onValueChange={(value) => save(type, value)}
                                    disabled={activities.length === 0 || saving === type.id}
                                >
                                    <SelectTrigger className="w-full sm:w-64" aria-label={`Default activity for ${label}`}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>No default</SelectItem>
                                        {activities.map((activity) => (
                                            <SelectItem key={activity.id} value={String(activity.id)}>
                                                <span className="flex items-center gap-2">
                                                    <ColorDot color={activity.color} className="size-2.5" />
                                                    {activity.name}
                                                </span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </li>
                        );
                    })}
                </ul>
            </CardContent>
        </Card>
    );
}
