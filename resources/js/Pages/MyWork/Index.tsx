import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { PageProps, MyWorkProject, MyWorkItem } from '@/types';
import { ProjectIcon } from '@/components/ProjectIcon';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import {
    ListTodo,
    Calendar,
    AlertCircle,
    CalendarDays,
    LayoutGrid,
    CheckCircle2,
    ArrowUpRight,
    Inbox,
} from 'lucide-react';

type FilterKey = 'all' | 'today' | 'overdue' | 'week';

interface Props extends PageProps {
    filter: string;
    projects: MyWorkProject[];
    counts: { all: number; today: number; overdue: number; week: number };
}

const filters: { key: FilterKey; label: string; icon: typeof Calendar }[] = [
    { key: 'all', label: 'All', icon: LayoutGrid },
    { key: 'today', label: 'Today', icon: Calendar },
    { key: 'overdue', label: 'Overdue', icon: AlertCircle },
    { key: 'week', label: 'This week', icon: CalendarDays },
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

function getDueBadgeVariant(item: MyWorkItem): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (item.completed_at) return 'secondary';
    if (!item.due_date) return 'outline';
    const d = new Date(item.due_date);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    d.setHours(0, 0, 0, 0);
    if (d < today) return 'destructive';
    if (d.getTime() === today.getTime()) return 'default';
    return 'secondary';
}

function ItemCard({
    item,
    project,
}: {
    item: MyWorkItem;
    project: MyWorkProject;
}) {
    const workspaceSlug = project.workspace?.slug;
    const projectSlug = project.slug;
    const boardSlug = item.board.slug;
    if (!workspaceSlug || !projectSlug) return null;

    const href = route('boards.items.show', [workspaceSlug, projectSlug, boardSlug, item.id]);
    const isDone = !!item.completed_at;

    return (
        <Link href={href} className="block group">
            <Card
                className={cn(
                    'transition-all duration-200 border hover:shadow-md hover:border-primary/30 hover:bg-accent/30',
                    isDone && 'opacity-75'
                )}
            >
                <CardContent className="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                            <span className="text-xs font-mono text-muted-foreground">
                                {item.key}
                            </span>
                            {item.priority && (
                                <Badge
                                    variant="outline"
                                    className="text-xs font-normal"
                                    style={
                                        item.priority.color
                                            ? { borderColor: item.priority.color, color: item.priority.color }
                                            : undefined
                                    }
                                >
                                    {item.priority.name}
                                </Badge>
                            )}
                            {item.column && (
                                <span className="text-xs text-muted-foreground truncate">
                                    {item.column.name}
                                </span>
                            )}
                        </div>
                        <h3
                            className={cn(
                                'font-medium mt-1 text-foreground group-hover:text-primary transition-colors line-clamp-2',
                                isDone && 'line-through text-muted-foreground'
                            )}
                        >
                            {item.title || 'Untitled'}
                        </h3>
                        {item.due_date && (
                            <Badge
                                variant={getDueBadgeVariant(item)}
                                className="mt-2 w-fit text-xs"
                            >
                                {formatDueDate(item.due_date)}
                            </Badge>
                        )}
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                        {isDone ? (
                            <CheckCircle2 className="size-5 text-green-600 dark:text-green-500" aria-hidden />
                        ) : null}
                        <ArrowUpRight className="size-4 text-muted-foreground group-hover:text-primary transition-colors" aria-hidden />
                    </div>
                </CardContent>
            </Card>
        </Link>
    );
}

export default function MyWorkIndex({ auth, filter, projects, counts }: Props) {
    const totalItems = projects.reduce((acc, p) => acc + p.items.length, 0);

    return (
        <AppLayout>
            <Head title="My Work" />

            <div className="p-6 md:p-8 max-w-5xl mx-auto">
                {/* Header */}
                <div className="mb-8">
                    <div className="flex items-center gap-3 mb-2">
                        <div className="size-12 rounded-xl bg-primary/10 flex items-center justify-center">
                            <ListTodo className="size-6 text-primary" />
                        </div>
                        <div>
                            <h1 className="text-2xl md:text-3xl font-bold tracking-tight">
                                My Work
                            </h1>
                            <p className="text-muted-foreground text-sm md:text-base">
                                Tasks assigned to you across all projects
                            </p>
                        </div>
                    </div>
                </div>

                {/* Filter tabs */}
                <div className="flex flex-wrap gap-2 mb-8">
                    {filters.map(({ key, label, icon: Icon }) => {
                        const count = counts[key];
                        const isActive = filter === key;
                        return (
                            <Button
                                key={key}
                                variant={isActive ? 'default' : 'outline'}
                                size="sm"
                                className="gap-2 rounded-lg"
                                onClick={() =>
                                    router.get(route('my-work.index'), { filter: key })
                                }
                            >
                                <Icon className="size-4" />
                                <span>{label}</span>
                                <Badge
                                    variant={isActive ? 'secondary' : 'outline'}
                                    className="ml-0.5 h-5 min-w-5 px-1.5 text-xs"
                                >
                                    {count}
                                </Badge>
                            </Button>
                        );
                    })}
                </div>

                {/* Content */}
                {totalItems === 0 ? (
                    <Card className="border-dashed border-2">
                        <CardContent className="flex flex-col items-center justify-center py-16 px-6">
                            <div className="size-20 rounded-full bg-muted flex items-center justify-center mb-6">
                                <Inbox className="size-10 text-muted-foreground" />
                            </div>
                            <h3 className="text-lg font-semibold mb-1">No tasks found</h3>
                            <p className="text-muted-foreground text-center max-w-sm text-sm">
                                {filter === 'all'
                                    ? "You don't have any tasks assigned to you yet. Assign items to yourself from any board."
                                    : `No tasks ${filter === 'today' ? 'due today' : filter === 'overdue' ? 'overdue' : 'due this week'}.`}
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-10">
                        {projects.map((project) => (
                            <section key={project.id} className="animate-fade-in">
                                <div className="flex items-center gap-3 mb-4">
                                    <ProjectIcon
                                        project={{
                                            key: project.key,
                                            icon: project.icon,
                                            icon_display: project.icon_display,
                                            color: project.color,
                                        }}
                                        size="md"
                                    />
                                    <div className="min-w-0">
                                        <h2 className="font-semibold text-lg truncate">
                                            {project.name}
                                        </h2>
                                        {project.workspace && (
                                            <p className="text-xs text-muted-foreground truncate">
                                                {project.workspace.name}
                                            </p>
                                        )}
                                    </div>
                                    <Badge variant="secondary" className="shrink-0 ml-auto">
                                        {project.items.length} task
                                        {project.items.length !== 1 ? 's' : ''}
                                    </Badge>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {project.items.map((item) => (
                                        <ItemCard
                                            key={item.id}
                                            item={item}
                                            project={project}
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
