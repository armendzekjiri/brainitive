import type { BoardColumn, ColumnWorkflow, Item, WorkflowRuleSettings } from '@/types';

/**
 * Board workflow rules (v1.52.0) on the client: hints only. The server (WorkflowGuard) decides every move and
 * answers a refused one with 422; these mirror its checks and messages so the board can dim columns a card can't
 * enter and the status select can disable them. Keep the wording in step with WorkflowGuard::violation().
 */

export function orList(parts: string[]): string {
    if (parts.length <= 1) return parts[0] ?? '';
    return `${parts.slice(0, -1).join(', ')} or ${parts[parts.length - 1]}`;
}

export function andList(parts: string[]): string {
    if (parts.length <= 1) return parts[0] ?? '';
    return `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}`;
}

type Movable = Pick<Item, 'column_id'> & Partial<Pick<Item, 'assigned_to' | 'due_date'>>;

/**
 * Why `item` can't enter `to` (null = the hints allow it). `openSubtasks`: open subtasks that would stay open
 * (only checked when given — drag & drop asks "Also complete the subtasks?" first).
 */
export function enterBlockReason(item: Movable, to: BoardColumn, columns: BoardColumn[], openSubtasks?: number): string | null {
    const rule: ColumnWorkflow | null | undefined = to.workflow;
    if (!rule || item.column_id === to.id) return null;
    if (!rule.actor_allowed) return rule.actor_reason ?? `You can't move cards to ${to.name}.`;
    if (rule.allowed_from.length > 0 && !rule.allowed_from.includes(item.column_id)) {
        const names = columns.filter((c) => rule.allowed_from.includes(c.id)).sort((a, b) => a.position - b.position).map((c) => c.name);
        return `Cards can only move to ${to.name} from ${orList(names)}.`;
    }
    if (rule.requires.assignee && !item.assigned_to) return `Cards need an assignee before they can move to ${to.name}.`;
    if (rule.requires.due_date && !item.due_date) return `Cards need a due date before they can move to ${to.name}.`;
    if (rule.requires.subtasks_done && (openSubtasks ?? 0) > 0) return `All subtasks must be done before a card can move to ${to.name}.`;
    return null;
}

/** The first message of an Inertia error bag (a refused move answers on `column_id`). */
export function firstError(errors: Record<string, string>, fallback: string): string {
    return errors.column_id ?? Object.values(errors)[0] ?? fallback;
}

export const EMPTY_RULE: WorkflowRuleSettings = {
    allowed_user_ids: [],
    allowed_roles: [],
    allowed_from_column_ids: [],
    require_assignee: false,
    require_subtasks_done: false,
    require_due_date: false,
};

export function isEmptyRule(rule: WorkflowRuleSettings): boolean {
    return !rule.restrict_who && rule.allowed_user_ids.length === 0 && rule.allowed_roles.length === 0 && rule.allowed_from_column_ids.length === 0
        && !rule.require_assignee && !rule.require_subtasks_done && !rule.require_due_date;
}

/** Canonical form for "unsaved changes" (order of ids doesn't matter). */
export function ruleKey(rule: WorkflowRuleSettings): string {
    const sorted = (ids: (number | string)[]) => [...ids].map(String).sort();
    return JSON.stringify([
        sorted(rule.allowed_user_ids), sorted(rule.allowed_roles), sorted(rule.allowed_from_column_ids),
        rule.require_assignee, rule.require_subtasks_done, rule.require_due_date, !!rule.restrict_who,
    ]);
}

/** Role key → label as the server words it in sentences (WorkflowRules::ROLES). */
const ROLE_PHRASES: Record<string, string> = {
    workspace_owner: 'the workspace owner',
    workspace_admin: 'workspace admins',
    project_admin: 'project admins',
    project_editor: 'project editors',
};
const ROLE_ORDER = Object.keys(ROLE_PHRASES);

/** "Armend Zekjiri, Jane or project admins" — members by name, then roles (WorkflowRules::whoList). */
export function whoList(rule: WorkflowRuleSettings, memberName: (id: number) => string): string {
    // restricted with nobody left = only project admins (WorkflowGuard::LOCKED_ROLES)
    if (rule.allowed_user_ids.length === 0 && rule.allowed_roles.length === 0) return ROLE_PHRASES.project_admin;
    const names = rule.allowed_user_ids.map(memberName).sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base', numeric: true }));
    const roles = ROLE_ORDER.filter((key) => rule.allowed_roles.includes(key)).map((key) => ROLE_PHRASES[key]);
    return orList([...names, ...roles]);
}

/** The rule as sentences, the same as the server's summary (WorkflowRules::summary). */
export function describeRule(rule: WorkflowRuleSettings, column: BoardColumn, columns: BoardColumn[], memberName: (id: number) => string): string[] {
    const lines: string[] = [];
    if (rule.allowed_from_column_ids.length > 0) {
        const names = columns.filter((c) => rule.allowed_from_column_ids.includes(c.id)).sort((a, b) => a.position - b.position).map((c) => c.name);
        lines.push(`Cards can enter ${column.name} only from ${orList(names)}.`);
    }
    if (rule.allowed_user_ids.length > 0 || rule.allowed_roles.length > 0 || rule.restrict_who) {
        lines.push(`Only ${whoList(rule, memberName)} can move cards here.`);
    }
    const requires = [
        rule.require_assignee && 'an assignee',
        rule.require_subtasks_done && 'all subtasks done',
        rule.require_due_date && 'a due date',
    ].filter((r): r is string => !!r);
    if (requires.length > 0) lines.push(`Requires ${andList(requires)}.`);
    return lines;
}
