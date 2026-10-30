import type { LimitKind, LimitReachedPayload, WorkspaceEntitlements } from '@/types';

/**
 * Cloud plan limits on the client (v1.46.0): the upgrade dialog's event, reading a 402
 * `limit_reached` answer, and the usage rows shared by the settings card and the 90 % banner.
 * Self-hosted installs have no `entitlements` prop and never get a 402, so none of this shows.
 */

const LIMIT_REACHED_EVENT = 'brainitive:limit-reached';

/** Open the upgrade dialog (mounted once in AppLayout) from anywhere, e.g. after a fetch() answered 402. */
export function showLimitReached(payload: LimitReachedPayload): void {
    window.dispatchEvent(new CustomEvent<LimitReachedPayload>(LIMIT_REACHED_EVENT, { detail: payload }));
}

export function onLimitReached(listener: (payload: LimitReachedPayload) => void): () => void {
    const handler = (event: Event) => listener((event as CustomEvent<LimitReachedPayload>).detail);
    window.addEventListener(LIMIT_REACHED_EVENT, handler);
    return () => window.removeEventListener(LIMIT_REACHED_EVENT, handler);
}

/** The `limit_reached` payload of a response body (LimitReached::render), or null. */
export function limitReachedOf(data: unknown): LimitReachedPayload | null {
    const payload = (data as { limit_reached?: unknown } | null)?.limit_reached as LimitReachedPayload | undefined;
    return payload && typeof payload === 'object' && typeof payload.kind === 'string' && typeof payload.limit === 'number' ? payload : null;
}

/** fetch() callers: opens the upgrade dialog when the body is a plan limit (402) and returns true. */
export function openIfLimitReached(data: unknown): boolean {
    const payload = limitReachedOf(data);
    if (payload) showLimitReached(payload);
    return payload !== null;
}

/** An axios error that is a plan limit (402): the upgrade dialog already shows it, skip the error toast. */
export function isLimitReachedError(error: unknown): boolean {
    const response = (error as { response?: { status?: number; data?: unknown } } | null)?.response;
    return response?.status === 402 && limitReachedOf(response.data) !== null;
}

/** 524288000 → "500 MB", 3221225472 → "3 GB", 1610612736 → "1.5 GB" (binary units, like the limits). */
export function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }
    const rounded = value >= 10 ? Math.round(value) : Math.round(value * 10) / 10;
    return `${rounded} ${units[unit]}`;
}

const BYTE_KINDS: LimitKind[] = ['content', 'files'];

/** "3", or "500 MB" for the storage kinds. */
export function formatAmount(kind: LimitKind, amount: number): string {
    return BYTE_KINDS.includes(kind) ? formatBytes(amount) : amount.toLocaleString();
}

/** What a limit counts, after "3 of 3 …". */
export const KIND_NOUNS: Record<LimitKind, string> = {
    members: 'members',
    projects: 'projects',
    workspaces: 'free workspaces',
    content: 'content storage',
    files: 'file storage',
};

export interface UsageRow {
    kind: LimitKind;
    label: string;
    used: number;
    /** null = unlimited */
    limit: number | null;
    /** used / limit (0 when unlimited), can exceed 1 after a downgrade */
    ratio: number;
}

/** Members, projects, content and files of the current workspace, in display order. */
export function usageRows(entitlements: WorkspaceEntitlements): UsageRow[] {
    const { limits, usage } = entitlements;
    const row = (kind: LimitKind, label: string, used: number, limit: number | null): UsageRow => ({
        kind,
        label,
        used,
        limit,
        ratio: limit === null ? 0 : limit <= 0 ? 1 : used / limit,
    });

    return [
        row('members', 'Members', usage.members, limits.members),
        row('projects', 'Projects', usage.projects, limits.projects),
        row('content', 'Content', usage.contentBytes, limits.contentBytes),
        row('files', 'Files', usage.fileBytes, limits.fileBytes),
    ];
}

/** From here on the workspace is warned (the server blocks adding at 100 %). */
export const WARN_RATIO = 0.9;

/** The limited rows at or above 90 %. */
export function nearLimitRows(entitlements: WorkspaceEntitlements): UsageRow[] {
    return usageRows(entitlements).filter((row) => row.limit !== null && row.ratio >= WARN_RATIO);
}
