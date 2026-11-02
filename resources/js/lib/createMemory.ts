/** Remembers, per workspace, where the global Create modal last created something (project, board, type). */

export type RememberedCreateType = 'task' | 'page' | 'whiteboard' | 'folder';

export interface RememberedCreate {
    projectId?: number;
    boardId?: number;
    type?: RememberedCreateType;
}

const key = (workspaceId: number) => `create-last-${workspaceId}`;
const TYPES: RememberedCreateType[] = ['task', 'page', 'whiteboard', 'folder'];

export function readRememberedCreate(workspaceId: number | undefined): RememberedCreate {
    if (!workspaceId) return {};
    try {
        const raw = localStorage.getItem(key(workspaceId));
        const data = raw ? JSON.parse(raw) : null;
        if (!data || typeof data !== 'object') return {};
        return {
            projectId: Number.isInteger(data.projectId) ? data.projectId : undefined,
            boardId: Number.isInteger(data.boardId) ? data.boardId : undefined,
            type: TYPES.includes(data.type) ? data.type : undefined,
        };
    } catch {
        return {};
    }
}

export function rememberCreate(workspaceId: number | undefined, value: RememberedCreate): void {
    if (!workspaceId) return;
    try {
        localStorage.setItem(key(workspaceId), JSON.stringify(value));
    } catch {
        // storage unavailable (private window, blocked): the modal just won't remember
    }
}
