import type { Created, Destinations, Me, SearchResults, WorkItem } from './types';

/** Gets a valid token: from `auth.ts` in the background, via a message elsewhere. */
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
        createIssue: (boardId: number, data: FormData) => post<Created & { item_key: string }>(`boards/${boardId}/items`, data),
        saveBookmark: (bookmarkId: number, data: { type: 'link' | 'note'; title?: string; url?: string; content?: string }) =>
            post<Created>(`bookmarks/${bookmarkId}/items`, data),
        createPage: (projectId: number, data: { title: string; markdown: string; publish: boolean }) => post<Created>(`projects/${projectId}/pages`, data),
        createSnippet: (collectionId: number, data: { title: string; content: string; language: string; description?: string }) =>
            post<Created>(`snippet-collections/${collectionId}/snippets`, data),
        createTask: (taskListId: number, data: { title: string; notes?: string; due_date?: string; assign_to_me: boolean }) =>
            post<Created>(`task-lists/${taskListId}/items`, data),
    };
}

export type Api = ReturnType<typeof createApi>;

function errorMessage(status: number, body: { message?: string; errors?: Record<string, string[]> }): string {
    if (status === 403) return 'You don’t have permission to add things there.';
    if (status === 404) return 'That place no longer exists. Pick another destination.';
    if (status === 413) return 'That’s too big to upload.';
    if (status === 429) return 'Too many requests. Wait a moment and try again.';
    if (body.errors) return Object.values(body.errors)[0]?.[0] ?? 'Please check the form.';
    return body.message || `Brainitive answered with an error (${status}).`;
}
