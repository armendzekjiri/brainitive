import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { differenceInCalendarDays, format, parseISO } from 'date-fns';
import { FormEvent, useEffect } from 'react';

export interface ReleaseProgress {
    total: number;
    done: number;
    in_progress: number;
    todo: number;
}

export interface ReleaseSummary {
    id: number;
    name: string;
    status: 'unreleased' | 'released' | 'archived';
    start_date: string | null;
    release_date: string | null;
    released_at: string | null;
    overdue: boolean;
    published: boolean;
    progress: ReleaseProgress;
    description?: string | null;
}

export function formatDay(date: string | null) {
    return date ? format(parseISO(date), 'd MMM yyyy') : null;
}

const STATUS_STYLE: Record<ReleaseSummary['status'], string> = {
    unreleased: 'bg-blue-500/15 text-blue-700 dark:text-blue-400',
    released: 'bg-green-500/15 text-green-700 dark:text-green-400',
    archived: 'bg-muted text-muted-foreground',
};

export function ReleaseStatusBadge({ release }: { release: Pick<ReleaseSummary, 'status' | 'overdue'> }) {
    return (
        <span className="inline-flex items-center gap-1">
            <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${STATUS_STYLE[release.status]}`}>{release.status}</span>
            {release.overdue && <span className="rounded bg-red-500/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-700 dark:text-red-400">Overdue</span>}
        </span>
    );
}

/** Done / in progress / to do as one segmented bar (Jira style). */
export function ReleaseProgressBar({ progress, className = '' }: { progress: ReleaseProgress; className?: string }) {
    const pct = (n: number) => (progress.total ? (n / progress.total) * 100 : 0);
    const label = `${progress.done} done, ${progress.in_progress} in progress, ${progress.todo} to do`;
    return (
        <div className={className}>
            <div className="flex h-2 w-full overflow-hidden rounded-full bg-muted" role="img" aria-label={progress.total ? label : 'No issues yet'}>
                <div className="bg-green-500" style={{ width: `${pct(progress.done)}%` }} />
                <div className="bg-blue-500" style={{ width: `${pct(progress.in_progress)}%` }} />
                <div className="bg-slate-300 dark:bg-slate-600" style={{ width: `${pct(progress.todo)}%` }} />
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
                {progress.total ? <>{progress.done} of {progress.total} done · {progress.in_progress} in progress · {progress.todo} to do</> : 'No issues yet'}
            </p>
        </div>
    );
}

export function dueText(release: ReleaseSummary) {
    if (release.status !== 'unreleased' || !release.release_date) return null;
    const days = differenceInCalendarDays(parseISO(release.release_date), new Date());
    if (days < 0) return `${-days} day${days === -1 ? '' : 's'} overdue`;
    if (days === 0) return 'Due today';
    return `Due in ${days} day${days === 1 ? '' : 's'}`;
}

/** Create or edit a release (name, dates, description). */
export function ReleaseFormDialog({ open, onOpenChange, url, method, release }: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    url: string;
    method: 'post' | 'put';
    release?: ReleaseSummary | null;
}) {
    const form = useForm({
        name: release?.name ?? '',
        description: release?.description ?? '',
        start_date: release?.start_date ?? '',
        release_date: release?.release_date ?? '',
    });

    useEffect(() => {
        if (open) {
            form.setData({ name: release?.name ?? '', description: release?.description ?? '', start_date: release?.start_date ?? '', release_date: release?.release_date ?? '' });
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, start_date: d.start_date || null, release_date: d.release_date || null }));
        form[method](url, { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{release ? `Edit ${release.name}` : 'Create release'}</DialogTitle>
                    <DialogDescription>A version you plan to ship, e.g. “v1.4” or “October launch”. Issues get it as their fix version.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="release-name">Name</Label>
                        <Input id="release-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={100} placeholder="v1.4.0" autoFocus required />
                        {form.errors.name && <p className="text-sm text-destructive">{form.errors.name}</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="release-start">Start date</Label>
                            <Input id="release-start" type="date" value={form.data.start_date} onChange={(e) => form.setData('start_date', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="release-date">Release date</Label>
                            <Input id="release-date" type="date" value={form.data.release_date} onChange={(e) => form.setData('release_date', e.target.value)} />
                        </div>
                    </div>
                    {form.errors.release_date && <p className="text-sm text-destructive">{form.errors.release_date}</p>}
                    <div className="space-y-1.5">
                        <Label htmlFor="release-description">Description</Label>
                        <Textarea id="release-description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} maxLength={5000} rows={3} placeholder="Goal of this release (optional)" />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={form.processing || !form.data.name.trim()}>{release ? 'Save' : 'Create release'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
