/** Shapes returned by the Brainitive extension API (`/api/ext/v1`). */

export interface Location {
    type: string;
    title: string;
    workspace: string;
    project: string;
    breadcrumb: string;
    url: string;
}

export interface Me {
    user: { id: number; name: string; email: string; avatar: string | null };
    instance: { name: string; url: string };
}

export interface Column {
    id: number;
    name: string;
}

export interface BoardTarget {
    id: number;
    name: string;
    type: string;
    columns: Column[];
}

export interface ProjectTarget {
    id: number;
    name: string;
    key: string;
    icon: string | null;
    color: string | null;
    can_create_pages: boolean;
    boards: BoardTarget[];
    task_lists: { id: number; name: string }[];
}

export interface WorkspaceTarget {
    id: number;
    name: string;
    slug: string;
    projects: ProjectTarget[];
    bookmarks: { id: number; name: string; icon: string | null }[];
    snippet_collections: { id: number; name: string }[];
}

export interface Destinations {
    workspaces: WorkspaceTarget[];
}

export interface Created {
    created: true;
    item_key?: string;
    location: Location;
}

export interface SearchHit {
    id: number;
    type: string;
    title: string;
    subtitle?: string;
    url: string;
    workspace?: string | null;
}

export type SearchResults = Record<'workspaces' | 'projects' | 'snippets' | 'bookmarks' | 'items' | 'pages', SearchHit[]>;

export interface WorkItem {
    type: 'issue' | 'task';
    id: number;
    key: string | null;
    title: string;
    status: string | null;
    due_date: string | null;
    project: string;
    url: string;
}

/** What a capture starts from: the tab, plus whatever the context menu / screenshot added. */
export interface PendingCapture {
    mode?: Mode;
    /** The tab the capture is about (the popup may open as its own window). */
    tabId?: number;
    /** Shown on top of the popup, e.g. a screenshot that couldn't be taken. */
    error?: string;
    pageUrl?: string;
    pageTitle?: string;
    selectionText?: string;
    linkUrl?: string;
    /** PNG data URL from a screenshot. */
    screenshot?: string;
    clipWholePage?: boolean;
}

export type Mode = 'issue' | 'bookmark' | 'doc' | 'task' | 'snippet';

export interface Session {
    server: string;
    tokenEndpoint: string;
    clientId: string;
    redirectUri: string;
    accessToken: string;
    refreshToken: string | null;
    expiresAt: number;
}
