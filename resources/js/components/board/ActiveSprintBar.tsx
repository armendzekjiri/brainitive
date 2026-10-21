import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { differenceInCalendarDays, format } from 'date-fns';
import { Flag, Loader2, Target } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Board, Project, Workspace } from '@/types';
import { cn } from '@/lib/utils';

export interface SprintSummary {
    id: number;
    name: string;
    goal?: string | null;
    start_date?: string | null;
    end_date?: string | null;
}

/** Sprint name, goal, time left and "Complete sprint" above a Scrum board. */
export function ActiveSprintBar({
    workspace,
    project,
    board,
    activeSprint,
    plannedSprints,
    canManage,
    openIssueCount,
}: {
    workspace: Workspace;
    project: Project;
    board: Board;
    activeSprint: SprintSummary | null;
    plannedSprints: { id: number; name: string }[];
    canManage: boolean;
    openIssueCount: number;
}) {
    const params = [workspace.slug, project.slug, board.slug];
    const [completeOpen, setCompleteOpen] = useState(false);
    const [moveTo, setMoveTo] = useState<string>(plannedSprints[0] ? String(plannedSprints[0].id) : 'backlog');
    const [completing, setCompleting] = useState(false);

    if (!activeSprint) {
        return (
            <div className="mx-6 mt-3 flex flex-wrap items-center gap-3 rounded-md border border-dashed border-border px-4 py-3 text-sm text-muted-foreground">
                <Flag className="size-4" />
                <span className="flex-1">No sprint is running. Plan one in the backlog and start it — its issues show up here.</span>
                <Link href={route('boards.backlog', params)}>
                    <Button size="sm" variant="secondary" className="h-8 rounded-[4px]">Go to backlog</Button>
                </Link>
            </div>
        );
    }

    const end = activeSprint.end_date ? new Date(activeSprint.end_date + 'T23:59:59') : null;
    const daysLeft = end ? differenceInCalendarDays(end, new Date()) : null;
    const complete = () => {
        setCompleting(true);
        router.post(route('boards.sprints.complete', [...params, activeSprint.id]), {
            move_open_to: moveTo === 'backlog' ? null : Number(moveTo),
        }, { onFinish: () => setCompleting(false) });
    };

    return (
        <>
            <div className="mx-6 mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                <span className="font-semibold text-foreground">{activeSprint.name}</span>
                {activeSprint.start_date && activeSprint.end_date && (
                    <span className="text-muted-foreground">
                        {format(new Date(activeSprint.start_date), 'MMM d')} – {format(new Date(activeSprint.end_date), 'MMM d')}
                    </span>
                )}
                {daysLeft !== null && (
                    <span className={cn('text-muted-foreground', daysLeft < 0 && 'text-red-600 dark:text-red-400 font-medium')}>
                        {daysLeft < 0 ? `${Math.abs(daysLeft)} day${Math.abs(daysLeft) === 1 ? '' : 's'} overdue` : daysLeft === 0 ? 'Ends today' : `${daysLeft} day${daysLeft === 1 ? '' : 's'} left`}
                    </span>
                )}
                {activeSprint.goal && (
                    <span className="inline-flex items-center gap-1.5 text-muted-foreground min-w-0">
                        <Target className="size-3.5 shrink-0" />
                        <span className="truncate max-w-[40ch]" title={activeSprint.goal}>{activeSprint.goal}</span>
                    </span>
                )}
                <span className="flex-1" />
                {canManage && (
                    <Button size="sm" variant="secondary" className="h-8 rounded-[4px]" onClick={() => setCompleteOpen(true)}>
                        Complete sprint
                    </Button>
                )}
            </div>

            <Dialog open={completeOpen} onOpenChange={setCompleteOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Complete {activeSprint.name}</DialogTitle>
                        <DialogDescription>
                            {openIssueCount === 0
                                ? 'All issues are done. Nice work!'
                                : `${openIssueCount} issue${openIssueCount === 1 ? ' is' : 's are'} not done yet. Where should ${openIssueCount === 1 ? 'it' : 'they'} go?`}
                        </DialogDescription>
                    </DialogHeader>
                    {openIssueCount > 0 && (
                        <div className="space-y-2">
                            <Label>Move open issues to</Label>
                            <Select value={moveTo} onValueChange={setMoveTo}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {plannedSprints.map((sprint) => (
                                        <SelectItem key={sprint.id} value={String(sprint.id)}>{sprint.name}</SelectItem>
                                    ))}
                                    <SelectItem value="backlog">Backlog</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setCompleteOpen(false)}>Cancel</Button>
                        <Button onClick={complete} disabled={completing}>
                            {completing && <Loader2 className="size-4 mr-1.5 animate-spin" />}
                            Complete sprint
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
