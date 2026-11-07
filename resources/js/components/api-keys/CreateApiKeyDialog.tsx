import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { ApiKeyArea, ApiKeyLevel, ApiKeyOptions, ApiKeyScopeType } from '@/types';
import { ApiKeyScopePicker } from './ApiKeyScopePicker';
import { expiryLabel } from './format';

const SCOPES: { value: ApiKeyScopeType; label: string }[] = [
    { value: 'all', label: 'All my workspaces' },
    { value: 'workspaces', label: 'Chosen workspaces' },
    { value: 'projects', label: 'Chosen projects' },
    { value: 'boards', label: 'Chosen boards' },
];

const LEVELS: { value: ApiKeyLevel; label: string; width: string }[] = [
    { value: 'none', label: 'None', width: 'sm:w-16' },
    { value: 'read', label: 'Read', width: 'sm:w-16' },
    { value: 'write', label: 'Read & write', width: 'sm:w-28' },
];

type CreateForm = {
    name: string;
    scope_type: ApiKeyScopeType;
    scope_ids: number[];
    abilities: Record<string, ApiKeyLevel>;
    can_delete: boolean;
    expires_in_days: number | null;
};

function defaultAbilities(areas: ApiKeyArea[]): Record<string, ApiKeyLevel> {
    return Object.fromEntries(areas.map((a) => [a.key, a.key === 'issues' ? 'read' : 'none']));
}

/** "Create API key": name, where (all / chosen workspaces / projects / boards), what (per area), delete, expiry. */
export function CreateApiKeyDialog({
    open,
    onOpenChange,
    options,
    areas,
    expiryDays,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    options: ApiKeyOptions;
    areas: ApiKeyArea[];
    expiryDays: (number | null)[];
}) {
    const form = useForm<CreateForm>({
        name: '',
        scope_type: 'all',
        scope_ids: [],
        abilities: defaultAbilities(areas),
        can_delete: false,
        expires_in_days: expiryDays.includes(90) ? 90 : (expiryDays[0] ?? null),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const scopeError = errors.scope_ids ?? Object.entries(errors).find(([k]) => k.startsWith('scope_ids.'))?.[1];
    const abilitiesError = errors.abilities ?? Object.entries(errors).find(([k]) => k.startsWith('abilities.'))?.[1];

    const setScopeType = (type: ApiKeyScopeType) => {
        form.setData((d) => ({ ...d, scope_type: type, scope_ids: [] }));
    };

    const setLevel = (area: string, level: ApiKeyLevel) => {
        form.setData('abilities', { ...form.data.abilities, [area]: level });
    };

    const setAll = (level: ApiKeyLevel) => {
        form.setData('abilities', Object.fromEntries(areas.map((a) => [a.key, level])));
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => (d.scope_type === 'all' ? { ...d, scope_ids: [] } : d));
        form.post(route('profile.api-keys.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.clearErrors();
                onOpenChange(false);
            },
        });
    };

    const close = (next: boolean) => {
        if (!next && form.processing) return;
        onOpenChange(next);
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Create API key</DialogTitle>
                    <DialogDescription>The key acts as you, but only where and for what you allow here. You can't change it later; create a new one instead.</DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-6" data-testid="create-api-key-form">
                    <div className="space-y-1.5">
                        <Label htmlFor="api-key-name">Name</Label>
                        <Input
                            id="api-key-name"
                            data-testid="api-key-name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder="e.g. Nightly report script"
                            maxLength={100}
                            required
                            autoFocus
                        />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>

                    <fieldset className="space-y-3">
                        <legend className="text-sm font-medium">Where</legend>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {SCOPES.map((s) => (
                                <label key={s.value} className="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                    <input
                                        type="radio"
                                        name="api-key-scope"
                                        className="accent-primary"
                                        value={s.value}
                                        checked={form.data.scope_type === s.value}
                                        onChange={() => setScopeType(s.value)}
                                    />
                                    {s.label}
                                </label>
                            ))}
                        </div>
                        {form.data.scope_type !== 'all' && (
                            <ApiKeyScopePicker
                                type={form.data.scope_type}
                                options={options}
                                value={form.data.scope_ids}
                                onChange={(ids) => form.setData('scope_ids', ids)}
                            />
                        )}
                        {form.data.scope_type === 'all' && (
                            <p className="text-xs text-muted-foreground">Every workspace you belong to now and later, except those whose admins turned API keys off.</p>
                        )}
                        {form.data.scope_type === 'boards' && (
                            <p className="text-xs text-muted-foreground">Issues &amp; boards only see these boards; other areas you grant see their whole projects.</p>
                        )}
                        {(errors.scope_type || scopeError) && <p className="text-sm text-destructive">{errors.scope_type ?? scopeError}</p>}
                    </fieldset>

                    <fieldset className="space-y-2">
                        <legend className="sr-only">What</legend>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-sm font-medium" aria-hidden>
                                What
                            </p>
                            <div className="flex gap-1">
                                <Button type="button" variant="ghost" size="sm" onClick={() => setAll('read')}>
                                    Read everything
                                </Button>
                                <Button type="button" variant="ghost" size="sm" onClick={() => setAll('none')}>
                                    Clear
                                </Button>
                            </div>
                        </div>
                        <ul className="divide-y rounded-md border">
                            {areas.map((area) => (
                                <li key={area.key} className="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center sm:gap-4">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium">{area.label}</p>
                                        <p className="text-xs text-muted-foreground">{area.description}</p>
                                    </div>
                                    <div role="radiogroup" aria-label={area.label} className="flex shrink-0 flex-wrap gap-x-4 gap-y-1 sm:flex-nowrap sm:gap-0">
                                        {LEVELS.map((level) =>
                                            level.value === 'write' && area.read_only ? (
                                                <span key={level.value} className={`text-sm text-muted-foreground ${level.width}`} aria-hidden>
                                                    —
                                                </span>
                                            ) : (
                                                <label key={level.value} className={`inline-flex cursor-pointer items-center gap-1.5 text-sm ${level.width}`}>
                                                    <input
                                                        type="radio"
                                                        name={`api-key-ability-${area.key}`}
                                                        className="accent-primary"
                                                        aria-label={`${area.label}: ${level.label}`}
                                                        checked={(form.data.abilities[area.key] ?? 'none') === level.value}
                                                        onChange={() => setLevel(area.key, level.value)}
                                                    />
                                                    {level.label}
                                                </label>
                                            ),
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                        {abilitiesError && <p className="text-sm text-destructive">{abilitiesError}</p>}
                    </fieldset>

                    <div className="space-y-1">
                        <label className="flex cursor-pointer items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="mt-0.5 size-4 shrink-0 accent-primary"
                                aria-label="Allow deleting"
                                checked={form.data.can_delete}
                                onChange={(e) => form.setData('can_delete', e.target.checked)}
                            />
                            <span>Allow deleting (issues, docs, comments, files, tasks …)</span>
                        </label>
                        <p className="pl-6 text-xs text-muted-foreground">Write alone can create, update and move — not delete.</p>
                        {errors.can_delete && <p className="text-sm text-destructive">{errors.can_delete}</p>}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="api-key-expires">Expires</Label>
                        <Select
                            value={form.data.expires_in_days === null ? 'never' : String(form.data.expires_in_days)}
                            onValueChange={(v) => form.setData('expires_in_days', v === 'never' ? null : Number(v))}
                        >
                            <SelectTrigger id="api-key-expires" className="w-full sm:w-48">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {expiryDays.map((days) => (
                                    <SelectItem key={days ?? 'never'} value={days === null ? 'never' : String(days)}>
                                        {days === 90 ? '90 days (recommended)' : expiryLabel(days)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.expires_in_days && <p className="text-sm text-destructive">{errors.expires_in_days}</p>}
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={() => close(false)} disabled={form.processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Create key
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
