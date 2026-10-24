import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
    AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import type { HealthMetricKey, HealthReportDetail, HealthSection, Project, Workspace } from '@/types';
import { router } from '@inertiajs/react';
import { Sparkles, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { METRIC_LABELS, SourceChip, StatusBadge, formatDateTime, formatDay } from './HealthBits';

const SECTION_TITLES: Record<HealthSection, string> = { progress: 'Progress', risks: 'Risks', next_steps: 'Next steps' };

const SNAPSHOT_KEYS: HealthMetricKey[] = ['open', 'completed_in_period', 'overdue', 'due_soon', 'unassigned', 'blocked', 'milestones_upcoming', 'milestones_missed'];

interface Props {
    workspace: Workspace;
    project: Project;
    report: HealthReportDetail | null;
    /** The page's period + zone, kept after deleting */
    query: { tz: string; from: string; to: string };
    onClose: () => void;
}

/** A saved status update; every source is resolved for the reader (unavailable ones redacted). */
export function HealthReportSheet({ workspace, project, report, query, onClose }: Props) {
    const [confirming, setConfirming] = useState(false);

    const destroy = () => {
        if (!report) return;
        router.delete(route('projects.health.reports.destroy', { workspace: workspace.slug, project: project.slug, healthReport: report.id, ...query }), {
            preserveScroll: true,
            onSuccess: () => toast.success('Status update deleted.'),
            onError: () => toast.error('Could not delete the status update.'),
        });
    };

    const listed = (refs: string[] | undefined, title: string) => refs && refs.length > 0 && (
        <div>
            <h4 className="mb-1 text-xs font-medium text-muted-foreground">{title}</h4>
            <div className="flex flex-wrap gap-1">{refs.map((ref) => <SourceChip key={ref} refKey={ref} source={report?.sources[ref]} />)}</div>
        </div>
    );

    return (
        <Sheet open={report !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
                {report && (
                    <>
                        <SheetHeader className="space-y-2 pr-6 text-left">
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge status={report.status} />
                                {report.ai_assisted && <span className="inline-flex items-center gap-1 text-xs text-muted-foreground"><Sparkles className="size-3" /> AI-assisted</span>}
                            </div>
                            <SheetTitle>Status update · {formatDateTime(report.created_at, report.timezone)}</SheetTitle>
                            <SheetDescription>
                                {report.author ?? 'Former member'} · data as of {formatDateTime(report.as_of, report.timezone)} ({report.timezone}) · period {formatDay(report.period.from)} – {formatDay(report.period.to)}
                            </SheetDescription>
                        </SheetHeader>

                        <div className="mt-5 space-y-5">
                            {report.summary && <p className="whitespace-pre-line text-sm">{report.summary}</p>}

                            {(Object.keys(SECTION_TITLES) as HealthSection[]).map((section) => (
                                <section key={section} aria-label={SECTION_TITLES[section]}>
                                    <h3 className="mb-2 text-sm font-semibold">{SECTION_TITLES[section]}</h3>
                                    {report.sections[section].length === 0
                                        ? <p className="text-sm text-muted-foreground">Nothing noted.</p>
                                        : (
                                            <ul className="space-y-2.5">
                                                {report.sections[section].map((claim, i) => (
                                                    <li key={i} className="text-sm">
                                                        <p>{claim.text}</p>
                                                        {claim.sources.length > 0 && (
                                                            <div className="mt-1 flex flex-wrap gap-1">{claim.sources.map((ref) => <SourceChip key={ref} refKey={ref} source={report.sources[ref]} />)}</div>
                                                        )}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                </section>
                            ))}

                            <section aria-label="Numbers at the time" className="rounded-lg border p-3">
                                <h3 className="mb-2 text-sm font-semibold">Numbers at the time</h3>
                                <dl className="grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                                    {SNAPSHOT_KEYS.map((key) => (
                                        <div key={key} className="flex justify-between gap-2">
                                            <dt className="text-muted-foreground">{METRIC_LABELS[key]}</dt>
                                            <dd className="font-medium tabular-nums">{report.values[key] ?? 'Unknown'}</dd>
                                        </div>
                                    ))}
                                </dl>
                                <div className="mt-3 space-y-2">
                                    {listed(report.lists.overdue, 'Overdue issues')}
                                    {listed(report.lists.blocked?.map((b) => b.item), 'Blocked issues')}
                                </div>
                            </section>

                            {report.can_delete && (
                                <Button variant="outline" size="sm" className="text-destructive" onClick={() => setConfirming(true)}>
                                    <Trash2 className="size-4" /> Delete
                                </Button>
                            )}
                        </div>
                    </>
                )}
            </SheetContent>

            <AlertDialog open={confirming} onOpenChange={setConfirming}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete this status update?</AlertDialogTitle>
                        <AlertDialogDescription>It disappears from the project’s update history.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={destroy}>Delete</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </Sheet>
    );
}
