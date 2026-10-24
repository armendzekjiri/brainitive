import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { TimeLogActivity, TimeLogActivityOptions } from '@/types';
import { useEffect, useState } from 'react';

const NONE = 'none';

/**
 * The activity options of a log form (v1.38.0, BRAI-99) from `timesheet.activities`, fetched
 * when the form opens: the workspace's active activities (no prices), whether one is required,
 * and the preselected one for the issue (ticket type default, then last used).
 */
export function useLogActivities(workspaceSlug: string | undefined, itemId: number | null | undefined, enabled: boolean) {
    // null while loading: never preselect from an earlier fetch (another issue, an older "last used")
    const [options, setOptions] = useState<TimeLogActivityOptions | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        setOptions(null);
        setFailed(false);
        if (!enabled || !workspaceSlug) return;
        let cancelled = false;
        window.axios
            .get<TimeLogActivityOptions>(route('timesheet.activities', { workspace: workspaceSlug, ...(itemId ? { item_id: itemId } : {}) }))
            .then(({ data }) => { if (!cancelled) setOptions(data); })
            // the server still decides: without options the form submits and shows its answer
            .catch(() => { if (!cancelled) { setFailed(true); setOptions({ activities: [], required: false, default_activity_id: null }); } });
        return () => { cancelled = true; };
    }, [workspaceSlug, itemId, enabled]);

    return { options, loading: enabled && options === null, failed };
}

export function ActivityDot({ color, className }: { color: string | null | undefined; className?: string }) {
    return (
        <span
            aria-hidden
            className={cn('inline-block size-2.5 shrink-0 rounded-full border border-black/10 dark:border-white/20', !color && 'bg-muted-foreground/40', className)}
            style={color ? { backgroundColor: color } : undefined}
        />
    );
}

interface PickerProps {
    id: string;
    activities: TimeLogActivity[];
    /** Activity id as a string, '' for none. */
    value: string;
    onChange: (value: string) => void;
    required: boolean;
    disabled?: boolean;
    invalid?: boolean;
    className?: string;
}

/** Activity select for logging time: colour, name and pricing type — never a rate. */
export function LogActivityPicker({ id, activities, value, onChange, required, disabled, invalid, className }: PickerProps) {
    return (
        <Select value={value || (required ? '' : NONE)} onValueChange={(v) => onChange(v === NONE ? '' : v)} disabled={disabled}>
            <SelectTrigger id={id} aria-invalid={invalid || undefined} className={cn('h-9', invalid && 'border-destructive', className)}>
                <SelectValue placeholder="Pick an activity" />
            </SelectTrigger>
            <SelectContent>
                {!required && <SelectItem value={NONE}><span className="text-muted-foreground">No activity</span></SelectItem>}
                {activities.map((activity) => (
                    <SelectItem key={activity.id} value={String(activity.id)}>
                        <span className="flex min-w-0 items-center gap-2">
                            <ActivityDot color={activity.color} />
                            <span className="truncate">{activity.name}</span>
                            <span className="shrink-0 text-xs text-muted-foreground">
                                {activity.pricing_label}{activity.billable ? '' : ' · not billable'}{activity.archived ? ' · archived' : ''}
                            </span>
                        </span>
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
