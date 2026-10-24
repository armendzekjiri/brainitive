import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { hoursInput, parseHours } from '@/lib/workload';
import type { WorkloadMember } from '@/types';
import { useState } from 'react';
import { MAX_WEEK_MINUTES, workloadWrite } from './workloadActions';

interface Props {
    member: WorkloadMember;
    url: string;
    onOpenChange: (open: boolean) => void;
}

/** A person's default hours per week in this project (planners only). Empty = not set. Mount with key={member.id}. */
export function CapacityDialog({ member, url, onOpenChange }: Props) {
    const [value, setValue] = useState(() => hoursInput(member.capacity_minutes));
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const save = (minutes: number | null) => {
        if (minutes !== null && (minutes < 0 || minutes > MAX_WEEK_MINUTES)) {
            setError('Enter between 0 and 168 hours.');
            return;
        }
        setBusy(true);
        workloadWrite('put', url, { user_id: member.id, weekly_minutes: minutes }, { onDone: () => onOpenChange(false), onFinish: () => setBusy(false) });
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (busy) return;
        if (value.trim() === '') return save(null);
        const minutes = parseHours(value);
        if (minutes === null) {
            setError('Use hours, e.g. 32, 7.5 or 7h 30m.');
            return;
        }
        save(minutes);
    };

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Weekly capacity · {member.name}</DialogTitle>
                        <DialogDescription>
                            Hours {member.name} is available for this project in a normal week. Use 0 for people who don’t work on it; leave empty when you don’t know yet. Leave and part weeks are set per week.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-1.5">
                        <Label htmlFor="workload-capacity">Hours per week</Label>
                        <Input
                            id="workload-capacity"
                            inputMode="decimal"
                            autoFocus
                            placeholder="Not set"
                            value={value}
                            aria-invalid={error !== null}
                            aria-describedby={error ? 'workload-capacity-error' : undefined}
                            onChange={(e) => { setValue(e.target.value); setError(null); }}
                        />
                        {error && <p id="workload-capacity-error" role="alert" className="text-sm text-destructive">{error}</p>}
                    </div>
                    <DialogFooter className="gap-2 sm:gap-0">
                        {member.capacity_minutes !== null && (
                            <Button type="button" variant="ghost" disabled={busy} onClick={() => save(null)}>Clear</Button>
                        )}
                        <Button type="submit" disabled={busy}>Save capacity</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
