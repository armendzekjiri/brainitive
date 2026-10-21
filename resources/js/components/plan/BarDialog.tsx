import { FormEvent, useEffect, useState } from 'react';
import { Diamond, Link2, Trash2, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { ColorSwatches } from './ColorSwatches';
import { formatDate, toDay, workingDays } from './dates';
import { findBar } from './ops';
import { PlanBar, PlanData } from './types';

interface Props {
    data: PlanData;
    barId: string | null;
    readOnly?: boolean;
    onClose: () => void;
    onSave: (bar: PlanBar) => void;
    onDelete: (barId: string) => void;
    onRemoveDependency: (dependencyId: string) => void;
}

/**
 * Edit one bar or milestone: label, dates, colour, progress and its dependencies.
 */
export function BarDialog({ data, barId, readOnly, onClose, onSave, onDelete, onRemoveDependency }: Props) {
    const found = barId ? findBar(data, barId) : null;
    const [draft, setDraft] = useState<PlanBar | null>(found?.bar ?? null);

    useEffect(() => {
        setDraft(barId ? findBar(data, barId)?.bar ?? null : null);
        // Only reset when another bar is opened, not on every document change
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [barId]);

    if (!found || !draft) return null;

    const isMilestone = draft.type === 'milestone';
    const valid = !!draft.start && (isMilestone || (!!draft.end && draft.end >= draft.start));
    const label = (id: string) => {
        const hit = findBar(data, id);
        return hit ? (hit.bar.label || hit.row.name || 'Untitled') : 'Unknown';
    };
    const before = data.dependencies.filter((d) => d.to === draft.id);
    const after = data.dependencies.filter((d) => d.from === draft.id);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!valid || readOnly) return;
        onSave({ ...draft, end: isMilestone ? draft.start : draft.end, label: draft.label?.trim() || undefined });
        onClose();
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            {isMilestone ? <Diamond className="size-4" style={{ color: draft.color }} /> : <span className="h-3 w-6 rounded-sm" style={{ background: draft.color }} />}
                            {found.row.name || 'Untitled row'}
                        </DialogTitle>
                        <DialogDescription>
                            {isMilestone
                                ? `Milestone on ${formatDate(draft.start)}`
                                : `${formatDate(draft.start)} – ${formatDate(draft.end)} · ${workingDays(toDay(draft.start), toDay(draft.end))} working days`}
                        </DialogDescription>
                    </DialogHeader>

                    <fieldset disabled={readOnly} className="mt-4 space-y-4">
                        <div className="inline-flex rounded-md border p-0.5 text-sm" role="radiogroup" aria-label="Type">
                            {(['bar', 'milestone'] as const).map((type) => (
                                <button
                                    key={type}
                                    type="button"
                                    role="radio"
                                    aria-checked={draft.type === type}
                                    onClick={() => setDraft({ ...draft, type })}
                                    className={cn('rounded px-3 py-1 transition-colors', draft.type === type ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground')}
                                >
                                    {type === 'bar' ? 'Bar' : 'Milestone'}
                                </button>
                            ))}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="bar-label">Label</Label>
                            <Input id="bar-label" value={draft.label ?? ''} placeholder="Shown next to the bar" maxLength={255} onChange={(e) => setDraft({ ...draft, label: e.target.value })} autoFocus />
                        </div>

                        <div className={cn('grid gap-3', isMilestone ? 'grid-cols-1' : 'grid-cols-2')}>
                            <div className="space-y-1.5">
                                <Label htmlFor="bar-start">{isMilestone ? 'Date' : 'Start'}</Label>
                                <Input id="bar-start" type="date" value={draft.start} required onChange={(e) => setDraft({ ...draft, start: e.target.value })} />
                            </div>
                            {!isMilestone && (
                                <div className="space-y-1.5">
                                    <Label htmlFor="bar-end">End</Label>
                                    <Input id="bar-end" type="date" value={draft.end} min={draft.start} required onChange={(e) => setDraft({ ...draft, end: e.target.value })} />
                                </div>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Colour</Label>
                            <ColorSwatches value={draft.color} onChange={(color) => setDraft({ ...draft, color })} />
                        </div>

                        {!isMilestone && (
                            <div className="space-y-1.5">
                                <Label htmlFor="bar-progress" className="flex justify-between"><span>Progress</span><span className="tabular-nums text-muted-foreground">{draft.progress ?? 0}%</span></Label>
                                <input
                                    id="bar-progress"
                                    type="range"
                                    min={0}
                                    max={100}
                                    step={5}
                                    value={draft.progress ?? 0}
                                    onChange={(e) => setDraft({ ...draft, progress: Number(e.target.value) })}
                                    className="w-full accent-primary"
                                />
                            </div>
                        )}

                        {(before.length > 0 || after.length > 0) && (
                            <div className="space-y-1.5">
                                <Label className="flex items-center gap-1.5"><Link2 className="size-3.5" />Dependencies</Label>
                                <ul className="space-y-1 text-sm">
                                    {before.map((d) => (
                                        <li key={d.id} className="flex items-center justify-between gap-2 rounded-md bg-muted/60 px-2 py-1">
                                            <span className="truncate"><span className="text-muted-foreground">Starts after</span> {label(d.from)}</span>
                                            {!readOnly && <button type="button" className="text-muted-foreground hover:text-destructive" title="Remove dependency" onClick={() => onRemoveDependency(d.id)}><X className="size-3.5" /></button>}
                                        </li>
                                    ))}
                                    {after.map((d) => (
                                        <li key={d.id} className="flex items-center justify-between gap-2 rounded-md bg-muted/60 px-2 py-1">
                                            <span className="truncate"><span className="text-muted-foreground">Comes before</span> {label(d.to)}</span>
                                            {!readOnly && <button type="button" className="text-muted-foreground hover:text-destructive" title="Remove dependency" onClick={() => onRemoveDependency(d.id)}><X className="size-3.5" /></button>}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </fieldset>

                    <DialogFooter className="mt-6 gap-2 sm:justify-between">
                        {!readOnly ? (
                            <Button type="button" variant="ghost" className="text-destructive hover:bg-destructive/10 hover:text-destructive" onClick={() => { onDelete(draft.id); onClose(); }}>
                                <Trash2 className="mr-1.5 size-4" />Delete
                            </Button>
                        ) : <span />}
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" onClick={onClose}>{readOnly ? 'Close' : 'Cancel'}</Button>
                            {!readOnly && <Button type="submit" disabled={!valid}>Save</Button>}
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
