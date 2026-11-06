import { Input } from '@/components/ui/input';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
    activeFilterCount,
    type FilterOptions,
    hasAnyFilter,
    type MyWorkFilters,
    type MyWorkSort,
    type MyWorkSource,
    type MyWorkStatus,
    NO_PRIORITY,
} from '@/lib/myWorkFilters';
import { cn } from '@/lib/utils';
import { ArrowUpDown, ChevronDown, Filter, Search, X } from 'lucide-react';
import type { ReactNode } from 'react';

const STATUS_LABELS: Record<MyWorkStatus, string> = { open: 'Open', done: 'Done', all: 'All' };
const SOURCE_LABELS: Record<MyWorkSource, string> = { any: 'All sources', board: 'Boards', list: 'Task lists' };
const SORT_LABELS: Record<MyWorkSort, string> = { due: 'Due date', priority: 'Priority', updated: 'Recently updated', project: 'Project' };

// same trigger look as the board toolbar's Filter / Lanes buttons
const triggerClass = (active: boolean) =>
    cn(
        'inline-flex h-8 items-center gap-1.5 rounded-[6px] border px-2.5 text-xs font-semibold transition-colors whitespace-nowrap',
        active
            ? 'border-transparent bg-accent text-accent-foreground'
            : 'border-border bg-card text-muted-foreground hover:bg-secondary hover:text-foreground',
    );

const toggle = <T,>(values: T[], value: T): T[] =>
    values.includes(value) ? values.filter((v) => v !== value) : [...values, value];

interface Props {
    filters: MyWorkFilters;
    options: FilterOptions;
    onChange: (next: Partial<MyWorkFilters>) => void;
    onClear: () => void;
    shown: number;
    total: number;
}

/**
 * My Work filter bar: search · Project · Source · Priority · Labels · Status · Sort. From `md` up the
 * dropdowns sit inline; on a phone they collapse into one "Filters" popover (board-style chips).
 */
export default function MyWorkFilterBar({ filters, options, onChange, onClear, shown, total }: Props) {
    const active = activeFilterCount(filters);
    const anything = hasAnyFilter(filters);
    const projectName = (id: number) => options.projects.find((p) => p.id === id)?.name ?? 'Project';
    const summary = (values: unknown[], one: string, label: string) =>
        values.length === 0 ? label : values.length === 1 ? one : `${label} (${values.length})`;

    return (
        <div className="flex flex-wrap items-center gap-2" role="toolbar" aria-label="Filter my work">
            <div className="relative min-w-0 basis-full sm:basis-auto">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 size-[14px] -translate-y-1/2 text-muted-foreground" />
                <Input
                    type="search"
                    aria-label="Search my work"
                    placeholder="Search title or key"
                    value={filters.q}
                    onChange={(e) => onChange({ q: e.target.value })}
                    className="h-8 w-full bg-card pl-8 text-[13px] sm:w-56"
                />
            </div>

            {/* ── md+: inline dropdowns ── */}
            <div className="hidden flex-wrap items-center gap-2 md:flex">
                <MultiMenu
                    label={summary(filters.projects, projectName(filters.projects[0]), 'Project')}
                    active={filters.projects.length > 0}
                    title="Projects"
                >
                    {options.projects.map((p) => (
                        <DropdownMenuCheckboxItem
                            key={p.id}
                            checked={filters.projects.includes(p.id)}
                            onSelect={(e) => e.preventDefault()}
                            onCheckedChange={() => onChange({ projects: toggle(filters.projects, p.id) })}
                        >
                            <span className="mr-2 size-2 shrink-0 rounded-[2px]" style={{ backgroundColor: p.color || '#0E83DC' }} />
                            <span className="truncate">{p.name}</span>
                            <span className="ml-auto pl-3 text-[11px] text-muted-foreground">{p.count}</span>
                        </DropdownMenuCheckboxItem>
                    ))}
                </MultiMenu>

                <SingleMenu
                    label={filters.source === 'any' ? 'Source' : SOURCE_LABELS[filters.source]}
                    active={filters.source !== 'any'}
                    title="Source"
                    value={filters.source}
                    values={SOURCE_LABELS}
                    onValueChange={(source) => onChange({ source })}
                />

                <MultiMenu
                    label={summary(filters.priority, priorityName(options, filters.priority[0]), 'Priority')}
                    active={filters.priority.length > 0}
                    title="Priority"
                >
                    {options.priorities.map((p) => (
                        <DropdownMenuCheckboxItem
                            key={p.slug}
                            checked={filters.priority.includes(p.slug)}
                            onSelect={(e) => e.preventDefault()}
                            onCheckedChange={() => onChange({ priority: toggle(filters.priority, p.slug) })}
                        >
                            <span className="mr-2 size-2 shrink-0 rounded-full" style={{ backgroundColor: p.color || '#94A3B8' }} />
                            {p.name}
                        </DropdownMenuCheckboxItem>
                    ))}
                    <DropdownMenuCheckboxItem
                        checked={filters.priority.includes(NO_PRIORITY)}
                        onSelect={(e) => e.preventDefault()}
                        onCheckedChange={() => onChange({ priority: toggle(filters.priority, NO_PRIORITY) })}
                    >
                        <span className="mr-2 size-2 shrink-0 rounded-full bg-border" />
                        No priority
                    </DropdownMenuCheckboxItem>
                </MultiMenu>

                {options.labels.length > 0 && (
                    <MultiMenu
                        label={summary(filters.labels, labelName(options, filters.labels[0]), 'Labels')}
                        active={filters.labels.length > 0}
                        title="Labels"
                    >
                        {options.labels.map((l) => (
                            <DropdownMenuCheckboxItem
                                key={l.key}
                                checked={filters.labels.includes(l.key)}
                                onSelect={(e) => e.preventDefault()}
                                onCheckedChange={() => onChange({ labels: toggle(filters.labels, l.key) })}
                            >
                                <span className="mr-2 size-2 shrink-0 rounded-full" style={{ backgroundColor: l.color || '#94A3B8' }} />
                                {l.name}
                            </DropdownMenuCheckboxItem>
                        ))}
                    </MultiMenu>
                )}

                <SingleMenu
                    label={`Status: ${STATUS_LABELS[filters.status]}`}
                    active={filters.status !== 'open'}
                    title="Status"
                    value={filters.status}
                    values={STATUS_LABELS}
                    onValueChange={(status) => onChange({ status })}
                />

                <SingleMenu
                    label={`Sort: ${SORT_LABELS[filters.sort]}`}
                    active={false}
                    title="Sort by"
                    icon={<ArrowUpDown className="size-3.5" />}
                    value={filters.sort}
                    values={SORT_LABELS}
                    onValueChange={(sort) => onChange({ sort })}
                />
            </div>

            {/* ── phone: one Filters popover ── */}
            <Popover>
                <PopoverTrigger asChild>
                    <button type="button" className={cn(triggerClass(active > 0), 'md:hidden')}>
                        <Filter className="size-3.5" />
                        {active > 0 ? `Filters (${active})` : 'Filters'}
                    </button>
                </PopoverTrigger>
                <PopoverContent align="end" className="max-h-[75vh] w-[min(20rem,calc(100vw-2rem))] space-y-4 overflow-y-auto p-4">
                    <div className="flex items-center justify-between">
                        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Filters</p>
                        {anything && (
                            <button type="button" className="text-xs text-primary hover:underline" onClick={onClear}>
                                Clear all
                            </button>
                        )}
                    </div>
                    <ChipSection title="Status">
                        {(Object.keys(STATUS_LABELS) as MyWorkStatus[]).map((s) => (
                            <Chip key={s} active={filters.status === s} onClick={() => onChange({ status: s })}>{STATUS_LABELS[s]}</Chip>
                        ))}
                    </ChipSection>
                    <ChipSection title="Sort by">
                        {(Object.keys(SORT_LABELS) as MyWorkSort[]).map((s) => (
                            <Chip key={s} active={filters.sort === s} onClick={() => onChange({ sort: s })}>{SORT_LABELS[s]}</Chip>
                        ))}
                    </ChipSection>
                    <ChipSection title="Source">
                        {(Object.keys(SOURCE_LABELS) as MyWorkSource[]).map((s) => (
                            <Chip key={s} active={filters.source === s} onClick={() => onChange({ source: s })}>{SOURCE_LABELS[s]}</Chip>
                        ))}
                    </ChipSection>
                    <ChipSection title="Project">
                        {options.projects.map((p) => (
                            <Chip key={p.id} active={filters.projects.includes(p.id)} color={p.color} onClick={() => onChange({ projects: toggle(filters.projects, p.id) })}>
                                {p.name}
                            </Chip>
                        ))}
                    </ChipSection>
                    <ChipSection title="Priority">
                        {options.priorities.map((p) => (
                            <Chip key={p.slug} active={filters.priority.includes(p.slug)} color={p.color} onClick={() => onChange({ priority: toggle(filters.priority, p.slug) })}>
                                {p.name}
                            </Chip>
                        ))}
                        <Chip active={filters.priority.includes(NO_PRIORITY)} onClick={() => onChange({ priority: toggle(filters.priority, NO_PRIORITY) })}>
                            No priority
                        </Chip>
                    </ChipSection>
                    {options.labels.length > 0 && (
                        <ChipSection title="Labels">
                            {options.labels.map((l) => (
                                <Chip key={l.key} active={filters.labels.includes(l.key)} color={l.color ?? undefined} onClick={() => onChange({ labels: toggle(filters.labels, l.key) })}>
                                    {l.name}
                                </Chip>
                            ))}
                        </ChipSection>
                    )}
                </PopoverContent>
            </Popover>

            {anything && (
                <div className="flex items-center gap-2 sm:ml-auto">
                    <span className="text-xs text-muted-foreground" aria-live="polite" data-testid="my-work-result-count">
                        {shown} of {total} task{total === 1 ? '' : 's'}
                    </span>
                    <button
                        type="button"
                        onClick={onClear}
                        className="inline-flex h-8 items-center gap-1 rounded-[6px] px-2 text-xs font-semibold text-primary hover:bg-primary/10"
                    >
                        <X className="size-3.5" />
                        Clear filters
                    </button>
                </div>
            )}
        </div>
    );
}

function priorityName(options: FilterOptions, slug?: string): string {
    if (slug === NO_PRIORITY) return 'No priority';
    return options.priorities.find((p) => p.slug === slug)?.name ?? 'Priority';
}

function labelName(options: FilterOptions, key?: string): string {
    return options.labels.find((l) => l.key === key)?.name ?? 'Labels';
}

function MultiMenu({ label, active, title, children }: { label: string; active: boolean; title: string; children: ReactNode }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button type="button" className={triggerClass(active)} aria-label={`${title}: ${label}`}>
                    <span className="max-w-[160px] truncate">{label}</span>
                    <ChevronDown className="size-3 opacity-70" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-60">
                <DropdownMenuLabel className="text-[11px] uppercase tracking-wide text-muted-foreground">{title}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {children}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function SingleMenu<T extends string>({
    label,
    active,
    title,
    icon,
    value,
    values,
    onValueChange,
}: {
    label: string;
    active: boolean;
    title: string;
    icon?: ReactNode;
    value: T;
    values: Record<T, string>;
    onValueChange: (value: T) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button type="button" className={triggerClass(active)} aria-label={`${title}: ${values[value]}`}>
                    {icon}
                    {label}
                    <ChevronDown className="size-3 opacity-70" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-48">
                <DropdownMenuLabel className="text-[11px] uppercase tracking-wide text-muted-foreground">{title}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuRadioGroup value={value} onValueChange={(v) => onValueChange(v as T)}>
                    {(Object.keys(values) as T[]).map((key) => (
                        <DropdownMenuRadioItem key={key} value={key}>{values[key]}</DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function ChipSection({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="space-y-2">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{title}</p>
            <div className="flex flex-wrap gap-1.5">{children}</div>
        </div>
    );
}

/** Same pill as the board's Filter popover. */
function Chip({ active, color, onClick, children }: { active: boolean; color?: string; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={cn(
                'flex items-center gap-1.5 rounded-full border px-2.5 py-1.5 text-xs transition-all',
                active ? 'border-primary bg-primary/10 font-semibold text-primary' : 'border-transparent bg-muted/60 text-muted-foreground hover:bg-muted',
            )}
        >
            {color && <span className="size-2 rounded-full" style={{ backgroundColor: color }} />}
            {children}
        </button>
    );
}
