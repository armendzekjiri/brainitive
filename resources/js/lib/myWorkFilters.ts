import type { MyWorkItem, MyWorkProject } from '@/types';

/**
 * My Work filter bar (v1.51.0). The controller returns every row of the scope + due chip (no
 * pagination), so these filters run in the browser. They live in the query string next to the
 * server's `scope` / `filter`, mirrored with history.replaceState like the board's filter bar.
 */
export type MyWorkStatus = 'open' | 'done' | 'all';
export type MyWorkSort = 'due' | 'priority' | 'updated' | 'project';
export type MyWorkSource = 'any' | 'board' | 'list';

export interface MyWorkFilters {
    q: string;
    projects: number[];
    source: MyWorkSource;
    /** priority slugs (priorities are per project: "High" everywhere is one option); 'none' = no priority */
    priority: string[];
    /** label names, lower case (labels are per project too) */
    labels: string[];
    status: MyWorkStatus;
    sort: MyWorkSort;
}

export const DEFAULT_MY_WORK_FILTERS: MyWorkFilters = {
    q: '',
    projects: [],
    source: 'any',
    priority: [],
    labels: [],
    status: 'open',
    sort: 'due',
};

export const NO_PRIORITY = 'none';

const STATUSES: MyWorkStatus[] = ['open', 'done', 'all'];
const SORTS: MyWorkSort[] = ['due', 'priority', 'updated', 'project'];
const SOURCES: MyWorkSource[] = ['any', 'board', 'list'];

const list = (value: string | null): string[] =>
    (value ?? '').split(',').map((v) => v.trim()).filter(Boolean);

export function readMyWorkFilters(search: string = typeof window === 'undefined' ? '' : window.location.search): MyWorkFilters {
    const params = new URLSearchParams(search);
    const pick = <T extends string>(value: string | null, allowed: T[], fallback: T): T =>
        allowed.includes(value as T) ? (value as T) : fallback;
    return {
        q: params.get('q') ?? '',
        projects: list(params.get('projects')).map(Number).filter((n) => Number.isInteger(n) && n > 0),
        source: pick(params.get('source'), SOURCES, 'any'),
        priority: list(params.get('priority')),
        labels: list(params.get('labels')).map((l) => l.toLowerCase()),
        status: pick(params.get('status'), STATUSES, 'open'),
        sort: pick(params.get('sort'), SORTS, 'due'),
    };
}

/** The client filters as query params; defaults are left out ('' = remove). */
export function myWorkFilterParams(filters: MyWorkFilters): Record<string, string> {
    return {
        q: filters.q.trim(),
        projects: filters.projects.join(','),
        source: filters.source === 'any' ? '' : filters.source,
        priority: filters.priority.join(','),
        labels: filters.labels.join(','),
        status: filters.status === 'open' ? '' : filters.status,
        sort: filters.sort === 'due' ? '' : filters.sort,
    };
}

/** Mirror the filters into the URL without an Inertia visit (keeps scope / filter and Inertia's history state). */
export function writeMyWorkFilters(filters: MyWorkFilters) {
    const url = new URL(window.location.href);
    for (const [key, value] of Object.entries(myWorkFilterParams(filters))) {
        if (value) url.searchParams.set(key, value);
        else url.searchParams.delete(key);
    }
    if (url.href !== window.location.href) window.history.replaceState(window.history.state, '', url.href);
}

/** How many filters narrow the list (search and sort not counted; status only when not the default). */
export function activeFilterCount(filters: MyWorkFilters): number {
    return (
        (filters.projects.length > 0 ? 1 : 0) +
        (filters.source !== 'any' ? 1 : 0) +
        (filters.priority.length > 0 ? 1 : 0) +
        (filters.labels.length > 0 ? 1 : 0) +
        (filters.status !== 'open' ? 1 : 0)
    );
}

export const hasAnyFilter = (filters: MyWorkFilters): boolean =>
    activeFilterCount(filters) > 0 || filters.q.trim() !== '' || filters.sort !== 'due';

export const workKey = (item: MyWorkItem) => `${item.work_type}:${item.id}`;
export const isWorkDone = (item: MyWorkItem) => !!item.completed_at || item.status === 'done';

export interface FilterOptions {
    projects: { id: number; name: string; color?: string; count: number }[];
    priorities: { slug: string; name: string; color?: string; order: number }[];
    labels: { key: string; name: string; color?: string | null }[];
    hasBoard: boolean;
    hasList: boolean;
}

/** The options the dropdowns offer: only what is present in the loaded rows. */
export function filterOptions(projects: MyWorkProject[]): FilterOptions {
    const priorities = new Map<string, FilterOptions['priorities'][number]>();
    const labels = new Map<string, FilterOptions['labels'][number]>();
    let hasBoard = false;
    let hasList = false;
    for (const project of projects) {
        for (const item of project.items) {
            if (item.work_type === 'list') hasList = true;
            else hasBoard = true;
            if (item.priority && !priorities.has(item.priority.slug)) {
                priorities.set(item.priority.slug, { slug: item.priority.slug, name: item.priority.name, color: item.priority.color, order: item.priority.order });
            }
            for (const label of item.labels ?? []) {
                const key = label.name.toLowerCase();
                if (!labels.has(key)) labels.set(key, { key, name: label.name, color: label.color });
            }
        }
    }
    return {
        projects: projects
            .map((p) => ({ id: p.id, name: p.name, color: p.color, count: p.items.length }))
            .sort((a, b) => a.name.localeCompare(b.name)),
        priorities: [...priorities.values()].sort((a, b) => a.order - b.order),
        labels: [...labels.values()].sort((a, b) => a.name.localeCompare(b.name)),
        hasBoard,
        hasList,
    };
}

/** Everything but status: the "Done here" counter counts done rows among these. */
export function matchesNarrowing(item: MyWorkItem, project: MyWorkProject, filters: MyWorkFilters): boolean {
    const q = filters.q.trim().toLowerCase();
    if (q && !(item.title ?? '').toLowerCase().includes(q) && !(item.key ?? '').toLowerCase().includes(q)) return false;
    if (filters.projects.length > 0 && !filters.projects.includes(project.id)) return false;
    if (filters.source !== 'any' && item.work_type !== filters.source) return false;
    if (filters.priority.length > 0 && !filters.priority.includes(item.priority?.slug ?? NO_PRIORITY)) return false;
    if (filters.labels.length > 0 && !(item.labels ?? []).some((l) => filters.labels.includes(l.name.toLowerCase()))) return false;
    return true;
}

/**
 * Apply filters + sort. `keepVisible` = rows that were open when the "Open" view was set up: a task
 * completed with the circle stays (struck through) instead of vanishing under the cursor.
 */
export function applyMyWorkFilters(
    projects: MyWorkProject[],
    filters: MyWorkFilters,
    keepVisible: Set<string> = new Set(),
): MyWorkProject[] {
    const sections = projects
        .map((project) => {
            const items = project.items
                .map((item, index) => ({ item, index }))
                .filter(({ item }) => matchesNarrowing(item, project, filters))
                .filter(({ item }) => {
                    const done = isWorkDone(item);
                    if (filters.status === 'open') return !done || keepVisible.has(workKey(item));
                    if (filters.status === 'done') return done;
                    return true;
                })
                .sort((a, b) => compareItems(a, b, filters.sort))
                .map(({ item }) => item);
            return { ...project, items };
        })
        .filter((project) => project.items.length > 0);

    if (filters.sort === 'project') return sections.sort((a, b) => a.name.localeCompare(b.name));
    if (filters.sort === 'updated') {
        // the project with the most recently touched task first
        const latest = (p: MyWorkProject) => Math.max(0, ...p.items.map((i) => (i.updated_at ? Date.parse(i.updated_at) : 0)));
        return sections.sort((a, b) => latest(b) - latest(a));
    }
    return sections;
}

type Indexed = { item: MyWorkItem; index: number };

/** Done rows stay at the bottom whatever the sort (as the server orders them); ties keep the server order. */
function compareItems(a: Indexed, b: Indexed, sort: MyWorkSort): number {
    const done = Number(isWorkDone(a.item)) - Number(isWorkDone(b.item));
    if (done !== 0) return done;
    if (sort === 'priority') {
        const pa = a.item.priority?.order ?? Number.MAX_SAFE_INTEGER;
        const pb = b.item.priority?.order ?? Number.MAX_SAFE_INTEGER;
        if (pa !== pb) return pa - pb;
    }
    if (sort === 'updated') {
        const ua = a.item.updated_at ? Date.parse(a.item.updated_at) : 0;
        const ub = b.item.updated_at ? Date.parse(b.item.updated_at) : 0;
        if (ua !== ub) return ub - ua;
    }
    return a.index - b.index;
}
