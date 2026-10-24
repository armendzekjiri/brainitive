import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { PageProps, MyWorkProject, MyWorkItem, MyDayPlan } from '@/types';
import MyDaySection from '@/components/MyDaySection';
import WorkDoneToggle from '@/components/WorkDoneToggle';
import { toast } from 'sonner';
import { Sun } from 'lucide-react';
import { ProjectIcon } from '@/components/ProjectIcon';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import {
    ListTodo,
    Calendar,
    AlertCircle,
    CalendarDays,
    LayoutGrid,
    Inbox,
} from 'lucide-react';

type FilterKey = 'all' | 'today' | 'overdue' | 'week';
type ScopeKey = 'assigned' | 'created';

interface Props extends PageProps {
    scope: ScopeKey;
    filter: string;
    projects: MyWorkProject[];
    counts: { all: number; today: number; overdue: number; week: number };
    scopeCounts: { assigned: number; created: number };
    myDay: MyDayPlan;
}

const filters: { key: FilterKey; label: string; icon: typeof Calendar }[] = [
    { key: 'all', label: 'All', icon: LayoutGrid },
    { key: 'today', label: 'Today', icon: Calendar },
    { key: 'overdue', label: 'Overdue', icon: AlertCircle },
    { key: 'week', label: 'This week', icon: CalendarDays },
];

const scopes: { key: ScopeKey; label: string; description: string }[] = [
    { key: 'assigned', label: 'Assigned to me', description: 'Work you own' },
    { key: 'created', label: 'Created by me', description: 'Work you are tracking' },
];

function formatDueDate(dateStr: string): string {
    const d = new Date(dateStr);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);
    const endOfWeek = new Date(today);
    endOfWeek.setDate(today.getDate() + (7 - today.getDay()));
    endOfWeek.setHours(23, 59, 59, 999);
    d.setHours(0, 0, 0, 0);

    if (d.getTime() === today.getTime()) return 'Today';
    if (d.getTime() === tomorrow.getTime()) return 'Tomorrow';
    if (d >= today && d <= endOfWeek) return d.toLocaleDateString(undefined, { weekday: 'short' });
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: d.getFullYear() !== today.getFullYear() ? 'numeric' : undefined });
}

function getDueClass(item: MyWorkItem): string {
    if (item.completed_at || !item.due_date) return 'text-muted-foreground';
    const d = new Date(item.due_date);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    d.setHours(0, 0, 0, 0);
    if (d < today) return 'text-red-600 dark:text-red-400';
    if (d.getTime() === today.getTime()) return 'text-red-600 dark:text-red-400';
    return 'text-muted-foreground';
}

function getStatusPillClass(item: MyWorkItem): string {
    if (item.completed_at || item.column?.is_done_column) {
        return 'border-green-200 bg-green-50 text-green-700 dark:border-green-900/60 dark:bg-green-950/30 dark:text-green-300';
    }

    const status = item.column?.name?.toLowerCase() || '';
    if (status.includes('progress') || status.includes('doing')) {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300';
    }

    if (status.includes('todo') || status.includes('to do')) {
        return 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-300';
    }

    return 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-800 dark:bg-slate-900/40 dark:text-slate-300';
}

function PriorityBars({ color }: { color?: string }) {
    const activeColor = color || '#94A3B8';
    return (
        <span className="inline-flex h-3 items-end gap-[2px]" title="Priority">
            {[4, 7, 10].map((height, index) => (
                <span
                    key={height}
                    className="w-[3px] rounded-[1px]"
                    style={{
                        height,
                        background: index < (color ? 3 : 1) ? activeColor : 'hsl(var(--border))',
                    }}
                />
            ))}
        </span>
    );
}

const ROW_GRID = 'md:grid-cols-[24px_58px_minmax(220px,1fr)_180px_30px_110px_74px_34px_32px]';

/** ☀ — put a task on today's My Day or take it off. Never changes the task itself. */
function MyDayToggle({ item, planned }: { item: MyWorkItem; planned: boolean }) {
    const toggle = () => {
        const data = { work_type: item.work_type, work_id: item.id };
        const options = { preserveScroll: true, preserveState: true, onError: () => toast.error('Could not update My Day.') };
        if (planned) router.delete(route('my-day.destroy'), { data, ...options });
        else router.post(route('my-day.store'), data, options);
    };

    return (
        <button
            type="button"
            onClick={toggle}
            aria-pressed={planned}
            aria-label={planned ? `Remove “${item.title}” from My Day` : `Add “${item.title}” to My Day`}
            title={planned ? 'Remove from My Day' : 'Add to My Day'}
            className={cn(
                'grid size-7 place-items-center rounded-md transition-colors hover:bg-secondary',
                planned ? 'text-amber-500' : 'text-muted-foreground/40 hover:text-amber-500 md:opacity-0 md:group-hover:opacity-100 md:focus-visible:opacity-100',
            )}
        >
            <Sun className={cn('size-4', planned && 'fill-current')} />
        </button>
    );
}

function ItemRow({
    item,
    project,
    planned,
}: {
    item: MyWorkItem;
    project: MyWorkProject;
    planned: boolean;
}) {
    const workspaceSlug = project.workspace?.slug;
    const projectSlug = project.slug;
    if (!workspaceSlug || !projectSlug) return null;

    const href = item.work_type === 'list'
        ? route('task-lists.show', [workspaceSlug, projectSlug, item.source.slug])
        : route('boards.items.show', [workspaceSlug, projectSlug, item.source.slug, item.id]);
    const isDone = !!item.completed_at || item.status === 'done';
    const assigneeName = item.assignee?.name || 'Unassigned';
    const assigneeInitial = assigneeName.charAt(0).toUpperCase();
    const projectColor = project.color || '#0E83DC';
    const statusName = item.column?.name || 'No status';
    const sourceLabel = item.source.type === 'list' ? 'List' : 'Board';

    return (
        // The title link stretches over the whole row; the ☀ button sits above it (no button inside a link).
        <div
            className={cn(
                'group relative grid gap-2 border-b border-border/70 px-3.5 py-[9px] text-[12.5px] transition-colors last:border-b-0 hover:bg-background',
                ROW_GRID,
                'md:items-center md:gap-2.5',
                isDone && 'opacity-70',
            )}
        >
            {/* above the stretched title link, like the ☀ button */}
            <span className="relative z-10 hidden md:grid">
                <WorkDoneToggle item={item} workspaceSlug={workspaceSlug} projectSlug={projectSlug} />
            </span>

            <span className="font-mono text-[11px] font-medium tracking-normal text-muted-foreground">
                {item.key}
            </span>

            <span className="min-w-0">
                <Link
                    href={href}
                    className={cn(
                        'block truncate font-medium leading-5 text-foreground outline-none after:absolute after:inset-0 group-hover:text-primary focus-visible:after:rounded-sm focus-visible:after:ring-2 focus-visible:after:ring-ring',
                        isDone && 'line-through text-muted-foreground',
                    )}
                >
                    {item.title || 'Untitled'}
                </Link>
                <span className="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-muted-foreground md:hidden">
                    <span>{sourceLabel}: {item.source.name}</span>
                    <span>{statusName}</span>
                    {item.due_date && <span>{formatDueDate(item.due_date)}</span>}
                </span>
            </span>

            <span className="hidden min-w-0 items-center gap-1.5 text-xs text-muted-foreground md:inline-flex">
                <span
                    className="size-1.5 shrink-0 rounded-[2px]"
                    style={{ backgroundColor: projectColor }}
                />
                <span className="shrink-0 rounded-[4px] border border-border bg-card px-1.5 py-[1px] text-[10px] font-bold uppercase tracking-[0.04em] text-muted-foreground">
                    {sourceLabel}
                </span>
                <span className="truncate">{item.source.name}</span>
            </span>

            <span className="hidden md:block">
                <PriorityBars color={item.priority?.color} />
            </span>

            <span className="hidden min-w-0 md:block">
                <span className={cn('inline-flex max-w-full items-center gap-1.5 rounded-full border px-2 py-[2px] text-[11px] font-semibold', getStatusPillClass(item))}>
                    <span className="size-1.5 shrink-0 rounded-full bg-current opacity-70" />
                    <span className="truncate">{statusName}</span>
                </span>
            </span>

            <span className="hidden min-w-0 text-[11.5px] font-semibold md:block">
                <span className={cn(getDueClass(item))}>
                    {item.due_date ? formatDueDate(item.due_date) : 'No date'}
                </span>
            </span>

            <span className="hidden justify-end md:flex" title={assigneeName}>
                <span className="grid size-5 place-items-center rounded-full border-2 border-card bg-secondary text-[10px] font-bold text-foreground shadow-[0_1px_0_rgba(20,32,44,0.04)]">
                    {assigneeInitial}
                </span>
            </span>

            <span className="flex items-center gap-2 text-xs text-muted-foreground md:hidden">
                <PriorityBars color={item.priority?.color} />
                <span className="truncate">{item.priority?.name || 'No priority'}</span>
            </span>

            <span className="relative z-10 flex justify-end">
                <MyDayToggle item={item} planned={planned} />
            </span>
        </div>
    );
}

export default function MyWorkIndex({ auth, scope, filter, projects, counts, scopeCounts, myDay }: Props) {
    const plannedKeys = new Set(myDay.keys);
    const totalItems = projects.reduce((acc, p) => acc + p.items.length, 0);
    const visibleDone = projects.reduce((acc, p) => acc + p.items.filter((item) => item.completed_at).length, 0);
    const firstName = auth.user.name?.split(' ')[0] || 'there';
    const isCreatedScope = scope === 'created';
    const currentDate = new Date().toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
    });

    return (
        <AppLayout>
            <Head title="My Work" />

            <div className="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-5 md:px-6">
                <div className="border-b border-border/70 pb-5">
                    <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                        <div className="max-w-2xl">
                            <div className="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.08em] text-primary">
                                <ListTodo className="size-3.5" />
                                {isCreatedScope ? 'Created by me' : 'Assigned to me'}
                                <span className="font-medium normal-case tracking-normal text-muted-foreground">
                                    {currentDate}
                                </span>
                            </div>
                            <h1 className="m-0 text-[26px] font-bold leading-tight tracking-[-0.02em] text-foreground md:text-[30px]">
                                {counts.today > 0 ? (
                                    <>
                                        {firstName}, you have <span className="bg-primary/10 px-1 text-primary">{counts.today} task{counts.today === 1 ? '' : 's'} due today</span>.
                                    </>
                                ) : (
                                    <>
                                        {firstName}, nothing is due today.
                                    </>
                                )}
                            </h1>
                            <p className="mt-2 text-sm text-muted-foreground">
                                {isCreatedScope
                                    ? 'Track work you created, who owns it, and where it sits across boards.'
                                    : 'Track assigned work across your projects without jumping between boards.'}
                            </p>
                        </div>

                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:min-w-[480px]">
                            <div className="rounded-[8px] border border-border bg-card px-3 py-2.5 shadow-[0_1px_0_rgba(20,32,44,0.04)]">
                                <span className="text-[10.5px] font-bold uppercase tracking-[0.06em] text-muted-foreground">
                                    {isCreatedScope ? 'Created' : 'Assigned'}
                                </span>
                                <div className="mt-1 text-2xl font-bold leading-none tracking-[-0.02em]">{counts.all}</div>
                            </div>
                            <div className="rounded-[8px] border border-border bg-card px-3 py-2.5 shadow-[0_1px_0_rgba(20,32,44,0.04)]">
                                <span className="text-[10.5px] font-bold uppercase tracking-[0.06em] text-muted-foreground">Today</span>
                                <div className="mt-1 text-2xl font-bold leading-none tracking-[-0.02em] text-primary">{counts.today}</div>
                            </div>
                            <div className="rounded-[8px] border border-border bg-card px-3 py-2.5 shadow-[0_1px_0_rgba(20,32,44,0.04)]">
                                <span className="text-[10.5px] font-bold uppercase tracking-[0.06em] text-muted-foreground">Overdue</span>
                                <div className="mt-1 text-2xl font-bold leading-none tracking-[-0.02em] text-red-600">{counts.overdue}</div>
                            </div>
                            <div className="rounded-[8px] border border-border bg-card px-3 py-2.5 shadow-[0_1px_0_rgba(20,32,44,0.04)]">
                                <span className="text-[10.5px] font-bold uppercase tracking-[0.06em] text-muted-foreground">Done here</span>
                                <div className="mt-1 text-2xl font-bold leading-none tracking-[-0.02em] text-green-600">{visibleDone}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <MyDaySection plan={myDay} />

                <div className="flex flex-wrap items-center gap-2">
                    <div className="inline-flex rounded-[7px] border border-border bg-secondary p-[3px]">
                        {scopes.map((scopeOption) => {
                            const isActive = scope === scopeOption.key;
                            return (
                                <button
                                    key={scopeOption.key}
                                    type="button"
                                    className={cn(
                                        'inline-flex h-[26px] items-center gap-1.5 rounded-[5px] px-2.5 text-[11.5px] font-semibold transition-colors',
                                        isActive
                                            ? 'bg-card text-foreground shadow-[0_1px_0_rgba(20,32,44,0.04),0_1px_2px_rgba(0,0,0,0.04)]'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                    title={scopeOption.description}
                                    onClick={() =>
                                        router.get(route('my-work.index'), { scope: scopeOption.key, filter })
                                    }
                                >
                                    {scopeOption.label}
                                    <span className="rounded-full bg-secondary px-1.5 text-[10px] text-muted-foreground">
                                        {scopeCounts[scopeOption.key]}
                                    </span>
                                </button>
                            );
                        })}
                    </div>

                    {filters.map(({ key, label, icon: Icon }) => {
                        const count = counts[key];
                        const isActive = filter === key;
                        return (
                            <button
                                key={key}
                                type="button"
                                className={cn(
                                    'inline-flex h-8 items-center gap-1.5 rounded-[6px] border px-2.5 text-xs font-semibold transition-colors',
                                    isActive
                                        ? 'border-transparent bg-primary text-primary-foreground shadow-[0_1px_0_rgba(20,32,44,0.04)]'
                                        : 'border-border bg-card text-muted-foreground hover:bg-secondary hover:text-foreground',
                                )}
                                onClick={() =>
                                    router.get(route('my-work.index'), { scope, filter: key })
                                }
                            >
                                <Icon className="size-3.5" />
                                <span>{label}</span>
                                <span
                                    className={cn(
                                        'inline-grid min-w-5 place-items-center rounded-full px-1.5 text-[10.5px]',
                                        isActive ? 'bg-primary-foreground/20 text-primary-foreground' : 'bg-secondary text-muted-foreground',
                                    )}
                                >
                                    {count}
                                </span>
                            </button>
                        );
                    })}
                </div>

                {/* Content */}
                {totalItems === 0 ? (
                    <div className="flex min-h-[360px] flex-col items-center justify-center rounded-[10px] border border-dashed border-border bg-card px-6 text-center">
                        <div className="mb-5 grid size-16 place-items-center rounded-full bg-secondary">
                            <Inbox className="size-8 text-muted-foreground" />
                        </div>
                        <h3 className="mb-1 text-base font-semibold">No tasks found</h3>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            {filter === 'all'
                                ? isCreatedScope
                                    ? "You haven't created any tasks yet."
                                    : "You don't have any tasks assigned to you yet. Assign items to yourself from any board."
                                : `No tasks ${filter === 'today' ? 'due today' : filter === 'overdue' ? 'overdue' : 'due this week'}.`}
                        </p>
                    </div>
                ) : (
                    <div className="space-y-4">
                        {projects.map((project) => (
                            <section key={project.id} className="overflow-hidden rounded-[10px] border border-border bg-card shadow-[0_1px_0_rgba(20,32,44,0.04)]">
                                <header className="flex items-center gap-3 border-b border-border/70 bg-background px-3.5 py-[11px]">
                                    <ProjectIcon
                                        project={{
                                            key: project.key,
                                            icon: project.icon,
                                            icon_display: project.icon_display,
                                            color: project.color,
                                        }}
                                        size="sm"
                                    />
                                    <div className="min-w-0">
                                        <h2 className="truncate text-[13.5px] font-bold tracking-[-0.01em]">
                                            {project.name}
                                        </h2>
                                        {project.workspace && (
                                            <p className="truncate text-[11px] text-muted-foreground">
                                                {project.workspace.name}
                                            </p>
                                        )}
                                    </div>
                                    <Badge variant="secondary" className="ml-auto shrink-0 rounded-full text-[11px]">
                                        {project.items.length} task
                                        {project.items.length !== 1 ? 's' : ''}
                                    </Badge>
                                </header>
                                <div className={cn('hidden gap-2.5 border-b border-border/70 bg-muted/20 px-3.5 py-2 text-[10.5px] font-bold uppercase tracking-[0.06em] text-muted-foreground md:grid', ROW_GRID)}>
                                    <span />
                                    <span>Key</span>
                                    <span>Work</span>
                                    <span>Source</span>
                                    <span />
                                    <span>Status</span>
                                    <span>Due</span>
                                    <span />
                                    <span />
                                </div>
                                <div>
                                    {project.items.map((item) => (
                                        <ItemRow
                                            key={`${item.work_type}-${item.id}`}
                                            item={item}
                                            project={project}
                                            planned={plannedKeys.has(`${item.work_type}:${item.id}`)}
                                        />
                                    ))}
                                </div>
                            </section>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
