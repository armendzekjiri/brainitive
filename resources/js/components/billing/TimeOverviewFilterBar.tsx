import { useEffect, useState } from 'react';
import { CalendarRange, ChevronDown, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { TimeOverviewBillable, TimeOverviewFilters, TimeOverviewGroupBy, TimeOverviewOptions, TimeOverviewPeriod } from '@/types';

const PERIODS: { value: TimeOverviewPeriod; label: string }[] = [
    { value: 'last_month', label: 'Last month' },
    { value: 'this_month', label: 'This month' },
    { value: 'custom', label: 'Custom' },
];

const GROUPS: { value: TimeOverviewGroupBy; label: string }[] = [
    { value: 'ticket', label: 'Project → ticket' },
    { value: 'person', label: 'Person' },
    { value: 'activity', label: 'Activity' },
    { value: 'day', label: 'Day' },
];

const BILLABLE: { value: TimeOverviewBillable; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'billable', label: 'Billable' },
    { value: 'non_billable', label: 'Non-billable' },
];

const ALL = 'all';

/** Filters of Billing → Time (BRAI-100). Every change reloads the page with the new query string. */
export function TimeOverviewFilterBar({ filters, presets, options, onChange }: {
    filters: TimeOverviewFilters;
    presets: Record<'last_month' | 'this_month', { from: string; to: string }>;
    options: TimeOverviewOptions;
    onChange: (next: Partial<TimeOverviewFilters>) => void;
}) {
    const [range, setRange] = useState({ from: filters.from, to: filters.to });
    useEffect(() => setRange({ from: filters.from, to: filters.to }), [filters.from, filters.to]);
    const [detail, setDetail] = useState(filters.detail);
    useEffect(() => setDetail(filters.detail), [filters.detail]);

    const pickPeriod = (period: TimeOverviewPeriod) => {
        if (period === 'custom') onChange({ period, from: filters.from, to: filters.to });
        else onChange({ period, ...presets[period] });
    };

    const active = filters.projects.length > 0 || filters.client !== null || filters.person !== null || filters.activity !== null || filters.billable !== 'all';

    return (
        <div className="space-y-4 rounded-xl border bg-card p-4 text-card-foreground shadow-sm">
            <div className="flex flex-wrap items-end gap-3">
                <div className="space-y-1.5">
                    <span className="block text-xs font-medium text-muted-foreground">Period</span>
                    <div role="group" aria-label="Period" className="inline-flex rounded-lg border bg-muted/40 p-0.5">
                        {PERIODS.map((p) => (
                            <button
                                key={p.value}
                                type="button"
                                aria-pressed={filters.period === p.value}
                                onClick={() => pickPeriod(p.value)}
                                className={cn(
                                    'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                    filters.period === p.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {p.label}
                            </button>
                        ))}
                    </div>
                </div>
                {filters.period === 'custom' && (
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            onChange({ period: 'custom', ...range });
                        }}
                    >
                        <div className="space-y-1.5">
                            <Label htmlFor="time-from" className="text-xs text-muted-foreground">From</Label>
                            <Input id="time-from" type="date" value={range.from} onChange={(e) => setRange((r) => ({ ...r, from: e.target.value }))} className="h-9 w-[10.5rem]" required />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="time-to" className="text-xs text-muted-foreground">To</Label>
                            <Input id="time-to" type="date" value={range.to} onChange={(e) => setRange((r) => ({ ...r, to: e.target.value }))} className="h-9 w-[10.5rem]" required />
                        </div>
                        <Button type="submit" size="sm" variant="secondary" className="h-9">
                            <CalendarRange className="size-4" />
                            Apply
                        </Button>
                    </form>
                )}
            </div>

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
                <FilterField label="Projects">
                    <ProjectPicker options={options.projects} selected={filters.projects} onChange={(projects) => onChange({ projects })} />
                </FilterField>
                <FilterField label="Client">
                    <Select value={filters.client ? String(filters.client) : ALL} onValueChange={(v) => onChange({ client: v === ALL ? null : Number(v) })}>
                        <SelectTrigger aria-label="Client" className="h-9"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All clients</SelectItem>
                            {options.clients.map((c) => (
                                <SelectItem key={c.id} value={String(c.id)}>{c.name}{c.archived ? ' (archived)' : ''}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FilterField>
                <FilterField label="Person">
                    <Select value={filters.person ? String(filters.person) : ALL} onValueChange={(v) => onChange({ person: v === ALL ? null : Number(v) })}>
                        <SelectTrigger aria-label="Person" className="h-9"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Everyone</SelectItem>
                            {options.people.map((p) => (
                                <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FilterField>
                <FilterField label="Activity">
                    <Select value={filters.activity === null ? ALL : String(filters.activity)} onValueChange={(v) => onChange({ activity: v === ALL ? null : v === 'none' ? 'none' : Number(v) })}>
                        <SelectTrigger aria-label="Activity" className="h-9"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All activities</SelectItem>
                            {options.activities.map((a) => (
                                <SelectItem key={a.id} value={String(a.id)}>{a.name}{a.archived ? ' (archived)' : ''}</SelectItem>
                            ))}
                            <SelectItem value="none">No activity</SelectItem>
                        </SelectContent>
                    </Select>
                </FilterField>
                <FilterField label="Billable">
                    <Select value={filters.billable} onValueChange={(v) => onChange({ billable: v as TimeOverviewBillable })}>
                        <SelectTrigger aria-label="Billable" className="h-9"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            {BILLABLE.map((b) => <SelectItem key={b.value} value={b.value}>{b.label}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </FilterField>
                <FilterField label="Group by">
                    <Select value={filters.group} onValueChange={(v) => onChange({ group: v as TimeOverviewGroupBy })}>
                        <SelectTrigger aria-label="Group by" className="h-9"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            {GROUPS.map((g) => <SelectItem key={g.value} value={g.value}>{g.label}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </FilterField>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <label className="flex cursor-pointer items-center gap-2 text-sm">
                    <input type="checkbox" className="size-4 rounded border-border bg-background text-primary focus:ring-primary focus:ring-offset-background" checked={detail} onChange={(e) => {
                            setDetail(e.target.checked);
                            onChange({ detail: e.target.checked });
                        }} />
                    Show entries
                </label>
                {active && (
                    <Button type="button" variant="ghost" size="sm" onClick={() => onChange({ projects: [], client: null, person: null, activity: null, billable: 'all' })}>
                        <X className="size-4" />
                        Clear filters
                    </Button>
                )}
            </div>
        </div>
    );
}

function FilterField({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="min-w-0 space-y-1.5">
            <span className="block text-xs font-medium text-muted-foreground">{label}</span>
            {children}
        </div>
    );
}

function ProjectPicker({ options, selected, onChange }: {
    options: TimeOverviewOptions['projects'];
    selected: number[];
    onChange: (ids: number[]) => void;
}) {
    // ticks show at once; the page reloads with the new filter behind them
    const [ticked, setTicked] = useState(selected);
    useEffect(() => setTicked(selected), [selected]);
    const label = ticked.length === 0
        ? 'All projects'
        : ticked.length === 1
            ? options.find((p) => p.id === ticked[0])?.name ?? '1 project'
            : `${ticked.length} projects`;
    const update = (ids: number[]) => {
        setTicked(ids);
        onChange(ids);
    };
    const toggle = (id: number) => update(ticked.includes(id) ? ticked.filter((x) => x !== id) : [...ticked, id]);

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button type="button" variant="outline" aria-label="Projects" className="h-9 w-full justify-between px-3 font-normal">
                    <span className="truncate">{label}</span>
                    <ChevronDown className="size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-72 p-1">
                <ul className="max-h-72 overflow-y-auto" aria-label="Filter by project">
                    {options.map((p) => (
                        <li key={p.id}>
                            <label className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent">
                                <input type="checkbox" className="size-4 rounded border-border bg-background text-primary focus:ring-primary focus:ring-offset-background" checked={ticked.includes(p.id)} onChange={() => toggle(p.id)} />
                                <span className="min-w-0 flex-1 truncate">{p.name}</span>
                                {p.key && <span className="font-mono text-xs text-muted-foreground">{p.key}</span>}
                            </label>
                        </li>
                    ))}
                    {options.length === 0 && <li className="px-2 py-3 text-sm text-muted-foreground">No projects.</li>}
                </ul>
                {ticked.length > 0 && (
                    <div className="border-t p-1">
                        <Button type="button" variant="ghost" size="sm" className="w-full" onClick={() => update([])}>All projects</Button>
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}
