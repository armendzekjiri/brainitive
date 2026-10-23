import type { Item, ItemPriority, ItemType } from '@/types';

/**
 * Swimlanes: horizontal rows across the board. Custom lanes are ordered rules — a card goes
 * into the FIRST lane whose conditions all match (any selected value within a condition);
 * cards matching none go into the rest lane. Grouped modes make one lane per value.
 * Stored per board (BoardController::updateSwimlanes); each person may pick another mode.
 */
export type SwimlaneMode = 'none' | 'custom' | 'assignee' | 'priority' | 'type' | 'release';

export interface LaneRules {
    priority_ids?: number[];
    type_ids?: number[];
    label_ids?: number[];
    assignee_ids?: number[]; // 0 = unassigned
    release_ids?: number[]; // 0 = no fix version
    overdue?: boolean;
}

export interface CustomLane {
    id: string;
    name: string;
    color: string | null;
    rules: LaneRules;
}

export interface SwimlaneConfig {
    mode: SwimlaneMode;
    rest_name: string;
    lanes: CustomLane[];
}

export interface Lane {
    id: string;
    name: string;
    color: string | null;
    /** Field a drop into this lane sets (grouped modes only). */
    assign?: { field: 'assigned_to' | 'priority_id' | 'item_type_id' | 'release_id'; value: number | null };
}

export const MODE_LABELS: Record<SwimlaneMode, string> = {
    none: 'No lanes',
    custom: 'Custom lanes',
    assignee: 'By assignee',
    priority: 'By priority',
    type: 'By type',
    release: 'By fix version',
};

export const REST_LANE_ID = 'rest';

export const emptyConfig = (): SwimlaneConfig => ({ mode: 'none', rest_name: 'Everything else', lanes: [] });

export function isOverdue(item: Item, today = new Date()): boolean {
    if (!item.due_date || item.completed_at) return false;
    const due = new Date(item.due_date.slice(0, 10) + 'T23:59:59');
    return due < today;
}

export function matchesRules(item: Item, rules: LaneRules, today = new Date()): boolean {
    const has = (list: number[] | undefined, value: number) => !list || list.length === 0 || list.includes(value);
    if (!has(rules.priority_ids, item.priority_id ?? -1)) return false;
    if (!has(rules.type_ids, item.item_type_id ?? -1)) return false;
    if (!has(rules.assignee_ids, item.assigned_to ?? 0)) return false;
    if (!has(rules.release_ids, item.release_id ?? 0)) return false;
    if (rules.label_ids && rules.label_ids.length > 0 && !(item.labels ?? []).some((l) => rules.label_ids!.includes(l.id))) return false;
    if (rules.overdue && !isOverdue(item, today)) return false;
    return true;
}

export function describeRules(rules: LaneRules, names: { priorities: Map<number, string>; types: Map<number, string>; labels: Map<number, string>; users: Map<number, string>; releases: Map<number, string> }): string {
    const part = (label: string, ids: number[] | undefined, map: Map<number, string>, zero?: string) =>
        ids && ids.length ? `${label} ${ids.map((id) => (id === 0 && zero ? zero : map.get(id) ?? '?')).join(' or ')}` : null;
    const parts = [
        part('priority', rules.priority_ids, names.priorities),
        part('type', rules.type_ids, names.types),
        part('label', rules.label_ids, names.labels),
        part('assignee', rules.assignee_ids, names.users, 'unassigned'),
        part('fix version', rules.release_ids, names.releases, 'none'),
        rules.overdue ? 'overdue' : null,
    ].filter(Boolean);
    return parts.length ? parts.join(' · ') : 'every card';
}

/**
 * Lanes for the chosen mode plus a function placing a card. Grouped lanes only exist for
 * values that occur on the board (plus "none" lanes), in a stable, meaningful order.
 */
export function buildLanes(
    mode: SwimlaneMode,
    config: SwimlaneConfig | null | undefined,
    items: Item[],
    ctx: { priorities: ItemPriority[]; itemTypes: ItemType[] },
): { lanes: Lane[]; laneOf: (item: Item) => string } | null {
    if (mode === 'none') return null;
    const today = new Date();

    if (mode === 'custom') {
        const custom = config?.lanes ?? [];
        if (custom.length === 0) return null;
        return {
            lanes: [...custom.map((l) => ({ id: l.id, name: l.name, color: l.color })), { id: REST_LANE_ID, name: config?.rest_name || 'Everything else', color: null }],
            laneOf: (item) => custom.find((l) => matchesRules(item, l.rules, today))?.id ?? REST_LANE_ID,
        };
    }

    const unique = <T,>(list: T[], key: (t: T) => number) => [...new Map(list.map((t) => [key(t), t])).values()];

    if (mode === 'assignee') {
        const people = unique(items.flatMap((i) => (i.assignee ? [i.assignee] : [])), (u) => u.id).sort((a, b) => a.name.localeCompare(b.name));
        return {
            lanes: [...people.map((u) => ({ id: `u${u.id}`, name: u.name, color: null, assign: { field: 'assigned_to' as const, value: u.id } })), { id: 'u0', name: 'Unassigned', color: null, assign: { field: 'assigned_to' as const, value: null } }],
            laneOf: (item) => `u${item.assigned_to ?? 0}`,
        };
    }
    if (mode === 'priority') {
        return {
            lanes: [...ctx.priorities.map((p) => ({ id: `p${p.id}`, name: p.name, color: p.color ?? null, assign: { field: 'priority_id' as const, value: p.id } })), { id: 'p0', name: 'No priority', color: null, assign: { field: 'priority_id' as const, value: null } }],
            laneOf: (item) => `p${item.priority_id ?? 0}`,
        };
    }
    if (mode === 'type') {
        return {
            lanes: [...ctx.itemTypes.map((t) => ({ id: `t${t.id}`, name: t.name, color: t.color ?? null, assign: { field: 'item_type_id' as const, value: t.id } })), { id: 't0', name: 'No type', color: null }],
            laneOf: (item) => `t${item.item_type_id ?? 0}`,
        };
    }
    // release
    const releases = unique(items.flatMap((i) => (i.release ? [i.release] : [])), (r) => r.id)
        .sort((a, b) => Number(a.status !== 'unreleased') - Number(b.status !== 'unreleased') || (a.release_date ?? '9999').localeCompare(b.release_date ?? '9999'));
    return {
        lanes: [...releases.map((r) => ({ id: `r${r.id}`, name: r.name, color: null, assign: { field: 'release_id' as const, value: r.id } })), { id: 'r0', name: 'No fix version', color: null, assign: { field: 'release_id' as const, value: null } }],
        laneOf: (item) => `r${item.release_id ?? 0}`,
    };
}
