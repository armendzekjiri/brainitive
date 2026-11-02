import { dataUrlToBlob } from './page';
import type { Created, Destinations, Me, SearchResults, WorkItem } from './types';

/** Gets a valid token: `getAccessToken` from `auth.ts` — background only. */
export type TokenProvider = (force?: boolean) => Promise<{ token: string; server: string }>;

export class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

/** Client for `/api/ext/v1`. Retries once with a fresh token on 401. */
export function createApi(getToken: TokenProvider) {
    async function request<T>(path: string, init: RequestInit = {}, retry = true): Promise<T> {
        const { token, server } = await getToken();
        const response = await fetch(`${server}/api/ext/v1/${path}`, {
            ...init,
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
                ...(init.body && !(init.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
                ...init.headers,
            },
            // Never follow a redirect with the bearer token or a body (Codex CLI/extension review 5).
            redirect: 'error',
        });

        if (response.status === 401 && retry) {
            await getToken(true);
            return request<T>(path, init, false);
        }

        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new ApiError(errorMessage(response.status, body), response.status, body.errors ?? {});
        }
        return body as T;
    }

    const post = <T>(path: string, data: Record<string, unknown> | FormData) =>
        request<T>(path, { method: 'POST', body: data instanceof FormData ? data : JSON.stringify(data) });

    return {
        me: () => request<Me>('me'),
        destinations: () => request<Destinations>('destinations'),
        search: (q: string) => request<SearchResults>(`search?q=${encodeURIComponent(q)}`),
        myWork: () => request<{ items: WorkItem[] }>('my-work'),
        createIssue: async (boardId: number, fields: IssueFields) => post<Created & { item_key: string }>(`boards/${boardId}/items`, await issueForm(fields)),
        saveBookmark: (bookmarkId: number, data: { type: 'link' | 'note'; title?: string; url?: string; content?: string }) =>
            post<Created>(`bookmarks/${bookmarkId}/items`, data),
        createPage: (projectId: number, data: { title: string; markdown: string; publish: boolean }) => post<Created>(`projects/${projectId}/pages`, data),
        createSnippet: (collectionId: number, data: { title: string; content: string; language: string; description?: string }) =>
            post<Created>(`snippet-collections/${collectionId}/snippets`, data),
        createTask: (taskListId: number, data: { title: string; notes?: string; due_date?: string; assign_to_me: boolean }) =>
            post<Created>(`task-lists/${taskListId}/items`, data),
        /** "Save to inbox" (BRAI-70): no destination, organized later in Brainitive → Captures. */
        saveToInbox: (data: { text: string; url?: string; source?: string }) => post<Created>('captures', data),
    };
}

export type Api = ReturnType<typeof createApi>;

/** A new issue as an extension page sends it to the background (JSON: the screenshot is a data URL). */
export interface IssueFields {
    title: string;
    description: string;
    column_id?: number | null;
    assign_to_me: boolean;
    /** PNG as a `data:` URL. */
    screenshot?: string | null;
}

async function issueForm(fields: IssueFields): Promise<FormData> {
    const data = new FormData();
    data.set('title', fields.title);
    data.set('description', fields.description);
    if (fields.column_id) data.set('column_id', String(fields.column_id));
    data.set('assign_to_me', fields.assign_to_me ? '1' : '0');
    if (fields.screenshot) {
        if (!fields.screenshot.startsWith('data:image/')) throw new Error('The screenshot isn’t an image.');
        data.set('screenshot', await dataUrlToBlob(fields.screenshot), 'screenshot.png');
    }
    return data;
}

function errorMessage(status: number, body: { message?: string; errors?: Record<string, string[]> }): string {
    if (status === 403) return 'You don’t have permission to add things there.';
    if (status === 404) return 'That place no longer exists. Pick another destination.';
    if (status === 413) return 'That’s too big to upload.';
    if (status === 429) return 'Too many requests. Wait a moment and try again.';
    if (body.errors) return Object.values(body.errors)[0]?.[0] ?? 'Please check the form.';
    return body.message || `Brainitive answered with an error (${status}).`;
}
