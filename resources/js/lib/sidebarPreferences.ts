import axios from 'axios';
import { usePage } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
import { toast } from 'sonner';
import type {
    PageProps,
    SidebarGroupKey,
    SidebarNavKey,
    SidebarPin,
    SidebarPinType,
    SidebarPreferences,
    SidebarProjectPreferences,
} from '@/types';

/**
 * Customisable sidebar (BRAI-106). One module-level store, hydrated from the shared
 * `sidebarPreferences` prop and written optimistically to `PATCH sidebar.preferences`. No React
 * provider: pages that render AppLayout themselves (Projects/Show) read and write the same store.
 */

/**
 * Project groups in sidebar order, with the labels the sidebar shows. "tools" are the project
 * views Health / Workload / Releases, one top-level row each (BRAI-286); "content" groups list
 * the project's boards, lists, pages, ….
 */
export const SIDEBAR_GROUPS: { key: SidebarGroupKey; label: string; section: 'tools' | 'content' }[] = [
    { key: 'health', label: 'Health', section: 'tools' },
    { key: 'workload', label: 'Workload', section: 'tools' },
    { key: 'releases', label: 'Releases', section: 'tools' },
    { key: 'boards', label: 'Boards', section: 'content' },
    { key: 'lists', label: 'Lists', section: 'content' },
    { key: 'plans', label: 'Plans', section: 'content' },
    { key: 'helpdesks', label: 'Helpdesk', section: 'content' },
    { key: 'lead_forms', label: 'Lead forms', section: 'content' },
    { key: 'pages', label: 'Pages', section: 'content' },
    { key: 'whiteboards', label: 'Whiteboards', section: 'content' },
    { key: 'folders', label: 'Folders', section: 'content' },
];

const DEFAULT_GROUPS = Object.fromEntries(SIDEBAR_GROUPS.map((g) => [g.key, true])) as Record<SidebarGroupKey, boolean>;

export const DEFAULT_PROJECT_PREFERENCES: SidebarProjectPreferences = { groups: DEFAULT_GROUPS, pins: [], pinned_only: false };

const EMPTY: SidebarPreferences = { hidden_nav: [], projects: {} };

function normalize(raw: SidebarPreferences | null | undefined): SidebarPreferences {
    if (!raw) return EMPTY;
    // PHP encodes an empty map as a list
    const projects = raw.projects && !Array.isArray(raw.projects) ? raw.projects : {};
    return { hidden_nav: Array.isArray(raw.hidden_nav) ? raw.hidden_nav : [], projects };
}

/** One project's preferences, with the defaults filled in. */
export function projectPreferences(prefs: SidebarPreferences, projectId: number): SidebarProjectPreferences {
    const stored = prefs.projects[String(projectId)];
    if (!stored) return DEFAULT_PROJECT_PREFERENCES;
    return { groups: { ...DEFAULT_GROUPS, ...stored.groups }, pins: stored.pins ?? [], pinned_only: !!stored.pinned_only };
}

export function isPinned(prefs: SidebarPreferences, projectId: number, type: SidebarPinType, id: number): boolean {
    return projectPreferences(prefs, projectId).pins.some((p) => p.type === type && p.id === id);
}

// ── store ───────────────────────────────────────────────────────────────────
let state: SidebarPreferences = EMPTY;
/** The last state the server confirmed (rollback target). */
let confirmed: SidebarPreferences = EMPTY;
let hydratedFrom: unknown = undefined;
let pending = 0;
let queue: Promise<unknown> = Promise.resolve();
const listeners = new Set<() => void>();

function setState(next: SidebarPreferences) {
    state = next;
    listeners.forEach((listener) => listener());
}

const subscribe = (listener: () => void) => {
    listeners.add(listener);
    return () => listeners.delete(listener);
};
const getSnapshot = () => state;

/**
 * Take a new server value (a new page visit). Silent on purpose: every reader re-renders with the
 * new page props anyway, and notifying here would update other components during render. While a
 * write is in flight the local (optimistic) state wins.
 */
function syncFromServer(server: SidebarPreferences | null | undefined) {
    if (server === hydratedFrom) return;
    hydratedFrom = server;
    if (pending > 0) return;
    state = confirmed = normalize(server);
}

/** The user's sidebar preferences (reactive). */
export function useSidebarPreferences(): SidebarPreferences {
    const server = usePage<PageProps>().props.sidebarPreferences;
    syncFromServer(server);
    return useSyncExternalStore(subscribe, getSnapshot, getSnapshot);
}

/** Apply `optimistic` now and send `payload`; writes go out one after another (last one wins). */
function save(payload: Record<string, unknown>, optimistic: SidebarPreferences) {
    setState(optimistic);
    pending++;
    queue = queue
        .then(() => axios.patch<{ sidebarPreferences: SidebarPreferences }>(route('sidebar.preferences'), payload))
        .then(({ data }) => {
            confirmed = normalize(data.sidebarPreferences);
            if (--pending === 0) setState(confirmed);
        })
        .catch(() => {
            if (--pending === 0) setState(confirmed);
            toast.error('Could not save your sidebar. Please try again.');
        });
}

function withProject(projectId: number, change: (current: SidebarProjectPreferences) => SidebarProjectPreferences): SidebarPreferences {
    return { ...state, projects: { ...state.projects, [String(projectId)]: change(projectPreferences(state, projectId)) } };
}

export function setNavHidden(key: SidebarNavKey, hidden: boolean) {
    const hiddenNav = hidden ? [...new Set([...state.hidden_nav, key])] : state.hidden_nav.filter((k) => k !== key);
    save({ hidden_nav: hiddenNav }, { ...state, hidden_nav: hiddenNav });
}

export function setGroupVisible(projectId: number, group: SidebarGroupKey, visible: boolean) {
    save(
        { project: { id: projectId, groups: { [group]: visible } } },
        withProject(projectId, (p) => ({ ...p, groups: { ...p.groups, [group]: visible } })),
    );
}

export function setPinnedOnly(projectId: number, pinnedOnly: boolean) {
    save({ project: { id: projectId, pinned_only: pinnedOnly } }, withProject(projectId, (p) => ({ ...p, pinned_only: pinnedOnly })));
}

export function togglePin(projectId: number, pin: SidebarPin) {
    const current = projectPreferences(state, projectId).pins;
    const pins = current.some((p) => p.type === pin.type && p.id === pin.id)
        ? current.filter((p) => !(p.type === pin.type && p.id === pin.id))
        : [...current, pin];
    save({ project: { id: projectId, pins } }, withProject(projectId, (p) => ({ ...p, pins })));
}

export function resetSidebar() {
    save({ reset: true }, EMPTY);
}

// ── "Customize sidebar" panel ───────────────────────────────────────────────
export const CUSTOMIZE_SIDEBAR_EVENT = 'sidebar:customize';

/** Open the "Customize sidebar" panel (mounted by AppLayout's sidebar). */
export function openCustomizeSidebar(): void {
    window.dispatchEvent(new CustomEvent(CUSTOMIZE_SIDEBAR_EVENT));
}
