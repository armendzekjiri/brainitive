import { AssigneeAvatar } from '@/components/board/ItemPrimitives';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { TaskListItem, User } from '@/types';
import { Bell, CalendarDays, Check, Repeat, UserRound, X } from 'lucide-react';
import { forwardRef, useState, type ComponentPropsWithoutRef, type ReactNode } from 'react';
import { QUICK_DATES, RECURRENCE_LABELS, REMINDER_OPTIONS, isRealDate, type Recurrence } from './taskDates';

/** Small rounded chip used as a picker trigger (composer and detail panel). */
export const PickerChip = forwardRef<HTMLButtonElement, ComponentPropsWithoutRef<'button'> & { icon: ReactNode; active?: boolean; tone?: string }>(
    ({ icon, active, tone, className, children, ...props }, ref) => (
        <button
            ref={ref}
            type="button"
            className={cn(
                'inline-flex h-7 items-center gap-1.5 rounded-md border px-2 text-xs font-medium transition-colors disabled:opacity-50',
                active ? cn('border-primary/30 bg-primary/5', tone ?? 'text-primary') : 'border-border text-muted-foreground hover:bg-muted hover:text-foreground',
                className,
            )}
            {...props}
        >
            {icon}
            {children}
        </button>
    ),
);
PickerChip.displayName = 'PickerChip';

function Option({ selected, onClick, children, hint }: { selected?: boolean; onClick: () => void; children: ReactNode; hint?: string }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn('flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted', selected && 'font-medium text-primary')}
        >
            <span className="flex-1 truncate">{children}</span>
            {hint && <span className="text-xs text-muted-foreground">{hint}</span>}
            {selected && <Check className="size-3.5" />}
        </button>
    );
}

export function DuePicker({ date, time, onChange, children }: {
    date?: string | null;
    time?: string | null;
    onChange: (date: string | null, time: string | null) => void;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    // The server stores "HH:MM:SS" on Postgres but only accepts "HH:MM".
    const hhmm = time?.slice(0, 5) ?? null;
    // Typing a year emits 0002-, 0020-, 0202-…: keep the draft here, save only real dates.
    const [draft, setDraft] = useState(date ?? '');
    const pick = (d: string | null, t: string | null = hhmm) => {
        onChange(d, d ? t : null);
        setOpen(false);
    };

    return (
        <Popover open={open} onOpenChange={(o) => { setOpen(o); if (o) setDraft(date ?? ''); }}>
            <PopoverTrigger asChild>{children}</PopoverTrigger>
            <PopoverContent align="start" className="w-60 p-1.5">
                {QUICK_DATES().map((q) => (
                    <Option key={q.label} selected={date === q.value} onClick={() => pick(q.value)} hint={q.hint}>
                        {q.label}
                    </Option>
                ))}
                <div className="my-1.5 border-t" />
                <div className="space-y-1.5 px-2 pb-1">
                    <label className="block text-xs text-muted-foreground">
                        Date
                        <input
                            type="date"
                            className="mt-0.5 h-8 w-full rounded-md border bg-background px-2 text-sm text-foreground"
                            value={draft}
                            onChange={(e) => {
                                setDraft(e.target.value);
                                if (!e.target.value) onChange(null, null);
                                else if (isRealDate(e.target.value)) onChange(e.target.value, hhmm);
                            }}
                        />
                    </label>
                    <label className="block text-xs text-muted-foreground">
                        Time <span className="opacity-70">(optional)</span>
                        <input
                            type="time"
                            className="mt-0.5 h-8 w-full rounded-md border bg-background px-2 text-sm text-foreground disabled:opacity-50"
                            value={hhmm ?? ''}
                            disabled={!date}
                            onChange={(e) => onChange(date ?? null, e.target.value || null)}
                        />
                    </label>
                </div>
                {date && (
                    <>
                        <div className="my-1.5 border-t" />
                        <Option onClick={() => pick(null, null)}>
                            <span className="inline-flex items-center gap-1.5 text-destructive"><X className="size-3.5" /> No date</span>
                        </Option>
                    </>
                )}
            </PopoverContent>
        </Popover>
    );
}

export function AssigneePicker({ value, members, onChange, children }: {
    value?: number | null;
    members: User[];
    onChange: (id: number | null) => void;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const shown = members.filter((m) => m.name.toLowerCase().includes(query.toLowerCase()));
    const pick = (id: number | null) => {
        onChange(id);
        setOpen(false);
        setQuery('');
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>{children}</PopoverTrigger>
            <PopoverContent align="start" className="w-60 p-1.5">
                {members.length > 6 && (
                    <input
                        autoFocus
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search people"
                        aria-label="Search people"
                        className="mb-1 h-8 w-full rounded-md border bg-background px-2 text-sm"
                    />
                )}
                <div className="max-h-64 overflow-y-auto">
                    <Option selected={!value} onClick={() => pick(null)}>
                        <span className="inline-flex items-center gap-2"><AssigneeAvatar user={null} size={20} /> Unassigned</span>
                    </Option>
                    {shown.map((m) => (
                        <Option key={m.id} selected={value === m.id} onClick={() => pick(m.id)}>
                            <span className="inline-flex items-center gap-2"><AssigneeAvatar user={m} size={20} /> {m.name}</span>
                        </Option>
                    ))}
                </div>
            </PopoverContent>
        </Popover>
    );
}

export function RepeatPicker({ value, hasDueDate, onChange, children }: {
    value?: TaskListItem['recurrence'];
    hasDueDate: boolean;
    onChange: (value: Recurrence | null) => void;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const pick = (v: Recurrence | null) => {
        onChange(v);
        setOpen(false);
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>{children}</PopoverTrigger>
            <PopoverContent align="start" className="w-52 p-1.5">
                {!hasDueDate && !value ? (
                    <p className="px-2 py-1.5 text-sm text-muted-foreground">Set a due date first — ticking it off moves that date to the next time.</p>
                ) : (
                    <>
                        <Option selected={!value} onClick={() => pick(null)}>Doesn’t repeat</Option>
                        {hasDueDate && (Object.keys(RECURRENCE_LABELS) as Recurrence[]).map((r) => (
                            <Option key={r} selected={value === r} onClick={() => pick(r)}>{RECURRENCE_LABELS[r]}</Option>
                        ))}
                        <p className="px-2 pt-1.5 text-[11px] leading-snug text-muted-foreground">Ticking it off moves the due date to the next time.</p>
                    </>
                )}
            </PopoverContent>
        </Popover>
    );
}

export function ReminderPicker({ value, hasDueDate, onChange, children }: {
    value?: number | null;
    hasDueDate: boolean;
    onChange: (minutes: number | null) => void;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const pick = (v: number | null) => {
        onChange(v);
        setOpen(false);
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>{children}</PopoverTrigger>
            <PopoverContent align="start" className="w-56 p-1.5">
                {!hasDueDate ? (
                    <p className="px-2 py-1.5 text-sm text-muted-foreground">Set a due date first — the reminder is counted back from it.</p>
                ) : (
                    <>
                        <Option selected={value == null} onClick={() => pick(null)}>No reminder</Option>
                        {REMINDER_OPTIONS.map((o) => (
                            <Option key={o.value} selected={value === o.value} onClick={() => pick(o.value)}>{o.label}</Option>
                        ))}
                        <p className="px-2 pt-1.5 text-[11px] leading-snug text-muted-foreground">
                            Sent to the assignee (or you, if nobody is assigned) in the bell and the browser. Without a time, the due time is 9:00.
                        </p>
                    </>
                )}
            </PopoverContent>
        </Popover>
    );
}

export const PickerIcons = { due: CalendarDays, assignee: UserRound, repeat: Repeat, reminder: Bell };
