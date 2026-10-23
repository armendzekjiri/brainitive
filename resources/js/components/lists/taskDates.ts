import { addDays, addMonths, addWeeks, addYears, differenceInCalendarDays, format, nextMonday } from 'date-fns';
import type { TaskListItem } from '@/types';

/**
 * Dates of list tasks are plain "Y-m-d" strings (+ optional "HH:MM" time) in the user's own
 * time zone. Parse at noon so no zone can shift the day.
 */
export function parseDay(value?: string | null): Date | null {
    if (!value) return null;
    const d = new Date(`${value.slice(0, 10)}T12:00:00`);
    return Number.isNaN(d.getTime()) ? null : d;
}

export const toDay = (d: Date) => format(d, 'yyyy-MM-dd');

/** A finished "Y-m-d" from a date input (not a half-typed year like 0202). */
export const isRealDate = (value: string) => /^\d{4}-\d{2}-\d{2}$/.test(value) && Number(value.slice(0, 4)) >= 1900;

export type DueTone = 'overdue' | 'today' | 'soon' | 'later';

/** "Today", "Tomorrow", "Friday", "Nov 12", plus the tone for its colour. */
export function dueInfo(item: Pick<TaskListItem, 'due_date' | 'due_time' | 'status'>, now = new Date()): { label: string; tone: DueTone } | null {
    const day = parseDay(item.due_date);
    if (!day) return null;
    const diff = differenceInCalendarDays(day, now);
    const time = item.due_time ? ` ${item.due_time.slice(0, 5)}` : '';
    const label =
        diff === 0 ? 'Today' : diff === 1 ? 'Tomorrow' : diff === -1 ? 'Yesterday' : diff > 1 && diff < 7 ? format(day, 'EEEE') : format(day, diff < -300 || diff > 300 ? 'MMM d, yyyy' : 'MMM d');
    const tone: DueTone = item.status === 'done' ? 'later' : diff < 0 ? 'overdue' : diff === 0 ? 'today' : diff < 7 ? 'soon' : 'later';
    return { label: label + time, tone };
}

export const DUE_TONE_CLASSES: Record<DueTone, string> = {
    overdue: 'text-destructive',
    today: 'text-amber-700 dark:text-amber-400',
    soon: 'text-primary',
    later: 'text-muted-foreground',
};

export const QUICK_DATES = (now = new Date()) => [
    { label: 'Today', value: toDay(now), hint: format(now, 'EEE') },
    { label: 'Tomorrow', value: toDay(addDays(now, 1)), hint: format(addDays(now, 1), 'EEE') },
    { label: 'Next week', value: toDay(nextMonday(now)), hint: format(nextMonday(now), 'EEE MMM d') },
];

export type Recurrence = NonNullable<TaskListItem['recurrence']>;

export const RECURRENCE_LABELS: Record<Recurrence, string> = {
    daily: 'Every day',
    weekly: 'Every week',
    monthly: 'Every month',
    yearly: 'Every year',
};

export const RECURRENCE_SHORT: Record<Recurrence, string> = { daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly', yearly: 'Yearly' };

/** Same rule as TaskListItem::nextDueDate() on the server. */
export function nextOccurrence(dueDate: string, recurrence: Recurrence): string {
    const day = parseDay(dueDate)!;
    const next = { daily: addDays, weekly: addWeeks, monthly: addMonths, yearly: addYears }[recurrence](day, 1);
    return toDay(next);
}

/** Mirrors TaskListItem::REMINDER_OPTIONS (minutes before the due time). */
export const REMINDER_OPTIONS: { value: number; label: string; short: string }[] = [
    { value: 0, label: 'At due time', short: 'At due time' },
    { value: 10, label: '10 minutes before', short: '10 min before' },
    { value: 60, label: '1 hour before', short: '1 h before' },
    { value: 1440, label: '1 day before', short: '1 day before' },
];

export const reminderLabel = (minutes?: number | null) => REMINDER_OPTIONS.find((o) => o.value === minutes)?.short ?? null;
