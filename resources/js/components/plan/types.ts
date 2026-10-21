export type PlanZoom = 'day' | 'week' | 'month' | 'quarter';
export type PlanStatus = 'not_started' | 'in_progress' | 'done' | 'on_hold' | 'at_risk';
export type PlanColumn = 'who' | 'status' | 'start' | 'end' | 'duration' | 'progress' | 'notes';

export interface PlanBar {
    id: string;
    /** Y-m-d, inclusive */
    start: string;
    /** Y-m-d, inclusive; equals start for milestones */
    end: string;
    type: 'bar' | 'milestone';
    color: string;
    label?: string;
    progress?: number;
}

export interface PlanRow {
    id: string;
    name: string;
    /** Outline depth; a row is a group when the next row is deeper */
    level: number;
    who?: string;
    status?: PlanStatus;
    notes?: string;
    collapsed?: boolean;
    /** Linked board issue (the issue is the source of truth) */
    itemId?: number;
    bars: PlanBar[];
}

/** A board issue as plans see it (see app/Support/Boards/ProjectIssues.php). */
export interface IssueSummary {
    id: number;
    key: string;
    title: string;
    status: string | null;
    status_category: 'todo' | 'in_progress' | 'done';
    assignee: { id: number; name: string } | null;
    board: string | null;
    url: string;
    start_date: string | null;
    due_date: string | null;
    progress: number | null;
}

export type IssueAction = 'create' | 'link' | 'add' | 'unlink' | 'refresh';

/** Finish-to-start: `to` starts after `from` ends. */
export interface PlanDependency {
    id: string;
    from: string;
    to: string;
}

export interface PlanData {
    rows: PlanRow[];
    dependencies: PlanDependency[];
}

export interface PlanSettings {
    zoom: PlanZoom;
    columns: Record<PlanColumn, boolean>;
    gridWidth: number;
    showWeekends: boolean;
}

export interface PlanDocument {
    data: PlanData;
    settings: PlanSettings;
}

export interface PlanPayload extends PlanDocument {
    id: number;
    uuid: string;
    name: string;
    slug: string;
    description: string | null;
    current_version: number;
    share_url: string | null;
    edit_revision: number;
    updated_at: string | null;
    last_edited_by: { id: number; name: string } | null;
    created_by: { id: number; name: string } | null;
    created_at: string | null;
}

export const PLAN_COLORS: { name: string; value: string }[] = [
    { name: 'Blue', value: '#0E83DC' },
    { name: 'Teal', value: '#14B8A6' },
    { name: 'Green', value: '#22C55E' },
    { name: 'Lime', value: '#84CC16' },
    { name: 'Amber', value: '#F59E0B' },
    { name: 'Orange', value: '#F97316' },
    { name: 'Red', value: '#EF4444' },
    { name: 'Pink', value: '#EC4899' },
    { name: 'Violet', value: '#8B5CF6' },
    { name: 'Slate', value: '#64748B' },
];

export const DEFAULT_COLOR = PLAN_COLORS[0].value;

export const STATUS_META: Record<PlanStatus, { label: string; className: string; dot: string }> = {
    not_started: { label: 'Not started', className: 'bg-muted text-muted-foreground', dot: '#94A3B8' },
    in_progress: { label: 'In progress', className: 'bg-primary/10 text-primary', dot: '#0E83DC' },
    done: { label: 'Done', className: 'bg-brand-green/10 text-brand-green', dot: '#22C55E' },
    on_hold: { label: 'On hold', className: 'bg-amber-500/10 text-amber-600 dark:text-amber-400', dot: '#F59E0B' },
    at_risk: { label: 'At risk', className: 'bg-destructive/10 text-red-600 dark:text-red-400', dot: '#EF4444' },
};

export const COLUMN_META: Record<PlanColumn, { label: string; width: number; align?: 'right' }> = {
    who: { label: 'Who', width: 110 },
    status: { label: 'Status', width: 112 },
    start: { label: 'Start', width: 92 },
    end: { label: 'End', width: 92 },
    duration: { label: 'Days', width: 56, align: 'right' },
    progress: { label: '%', width: 52, align: 'right' },
    notes: { label: 'Notes', width: 150 },
};

export const COLUMN_ORDER: PlanColumn[] = ['who', 'status', 'start', 'end', 'duration', 'progress', 'notes'];

export function newId(prefix: string): string {
    return `${prefix}-${Math.random().toString(36).slice(2, 12)}`;
}
