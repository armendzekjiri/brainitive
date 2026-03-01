export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    avatar?: string | null;
    avatar_display?: string | null;
    bio?: string | null;
    phone?: string | null;
    is_instance_admin?: boolean;
    pivot?: {
        role?: 'admin' | 'editor' | 'viewer' | 'member';
        invited_at?: string;
        accepted_at?: string;
    };
}

export interface Workspace {
    id: number;
    uuid: string;
    name: string;
    slug: string;
    description?: string;
    logo?: string;
    header_image?: string;
    logo_display?: string | null;
    header_image_display?: string | null;
    website?: string;
    industry?: string;
    company_size?: string;
    timezone?: string;
    language?: string;
    currency?: string;
    is_public?: boolean;
    social_links?: {
        twitter?: string;
        linkedin?: string;
        github?: string;
    };
    branding?: {
        primary_color?: string;
        secondary_color?: string;
    };
    settings?: {
        ai_endpoint?: string;
        ai_api_key?: string;
        ai_model?: string;
        [key: string]: unknown;
    };
    owner_id: number;
    owner?: User;
    pivot?: {
        role: 'admin' | 'editor' | 'viewer' | 'member';
    };
    projects_count?: number;
    users_count?: number;
    created_at: string;
    updated_at: string;
}

export interface Project {
    id: number;
    uuid: string;
    workspace_id: number;
    name: string;
    slug: string;
    key: string;
    description?: string;
    icon?: string;
    icon_display?: string | null;
    color?: string;
    workspace?: Workspace;
    boards_count?: number;
    pages_count?: number;
    created_at: string;
    updated_at: string;
}

export interface Board {
    id: number;
    uuid: string;
    project_id: number;
    name: string;
    slug: string;
    description?: string;
    type: 'kanban' | 'scrum' | 'list';
    position: number;
    project?: Project;
    columns?: BoardColumn[];
    created_at: string;
    updated_at: string;
}

export interface Bookmark {
    id: number;
    uuid: string;
    workspace_id?: number;
    name: string;
    slug: string;
    description?: string;
    icon?: string;
    icon_display?: string; // emoji or full URL for stored image
    visibility: 'private' | 'public';
    collaborators?: Array<{ id: number; name: string; email?: string; pivot?: { role: 'viewer' | 'editor' } }>;
    creator?: User;
    position: number;
    created_by?: number;
    settings?: Record<string, unknown>;
    links_count?: number;
    files_count?: number;
    todos_count?: number;
    notes_count?: number;
    todos_completed_count?: number;
    items?: BookmarkItem[];
    created_at: string;
    updated_at: string;
}

export interface BookmarkItem {
    id: number;
    bookmark_id: number;
    type: 'link' | 'file' | 'todo' | 'note';
    title?: string;
    url?: string;
    file_path?: string;
    file_url?: string;
    content?: string;
    metadata?: Record<string, unknown> & { favicon_url?: string; description?: string };
    position: number;
    is_completed: boolean;
    created_by?: number;
    created_at: string;
    updated_at: string;
}

export interface BoardColumn {
    id: number;
    board_id: number;
    name: string;
    slug: string;
    color?: string;
    position: number;
    wip_limit?: number;
    is_done_column: boolean;
    items?: Item[];
    items_count?: number;
}

export interface Item {
    id: number;
    uuid: string;
    board_id: number;
    column_id: number;
    parent_id?: number | null;
    parent?: { id: number; key: string; title: string; board_id: number } | null;
    children_count?: number;
    item_type_id?: number;
    priority_id?: number;
    key: string;
    title: string;
    description?: string;
    created_by: number;
    assigned_to?: number;
    reporter_id?: number;
    position: number;
    story_points?: number;
    start_date?: string;
    due_date?: string;
    completed_at?: string;
    creator?: User;
    assignee?: User;
    reporter?: User;
    column?: BoardColumn;
    item_type?: ItemType;
    priority?: ItemPriority;
    labels?: Label[];
    watchers?: User[];
    outgoing_links?: ItemLink[];
    incoming_links?: ItemLink[];
    comments?: Comment[];
    comments_count?: number;
    attachments?: Attachment[];
    attachments_count?: number;
    created_at: string;
    updated_at: string;
}

export interface ItemType {
    id: number;
    name: string;
    slug: string;
    icon?: string;
    color?: string;
}

export interface ItemPriority {
    id: number;
    name: string;
    slug: string;
    icon?: string;
    color?: string;
    order: number;
}

export interface Label {
    id: number;
    project_id: number;
    name: string;
    slug: string;
    color?: string;
}

export interface ItemLink {
    id: number;
    source_item_id: number;
    target_item_id: number;
    link_type: 'blocks' | 'is_blocked_by' | 'duplicates' | 'is_duplicated_by' | 'relates_to' | 'clones' | 'is_cloned_by';
    source_item?: {
        id: number;
        key: string;
        title: string;
        board?: { id: number; name: string; slug: string };
    };
    target_item?: {
        id: number;
        key: string;
        title: string;
        board?: { id: number; name: string; slug: string };
    };
    created_at: string;
}

export interface Comment {
    id: number;
    item_id: number;
    user_id: number;
    parent_id?: number;
    body: string;
    user?: User;
    replies?: Comment[];
    created_at: string;
    updated_at: string;
}

export interface Attachment {
    id: number;
    item_id: number;
    user_id: number;
    filename: string;
    original_filename: string;
    path: string;
    mime_type: string;
    size: number;
    formatted_size?: string;
    url?: string;
    user?: User;
    created_at: string;
    updated_at: string;
}

export interface Page {
    id: number;
    uuid: string;
    project_id: number;
    parent_id?: number;
    created_by?: number;
    last_edited_by?: number;
    name: string;
    slug: string;
    icon?: string;
    type: 'page' | 'folder';
    status: 'draft' | 'published' | 'archived';
    content?: Record<string, unknown>;
    position: number;
    published_at?: string;
    is_whiteboard?: boolean;
    parent?: Page;
    children?: Page[];
    creator?: User;
    lastEditor?: User;
    created_at: string;
    updated_at: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash?: {
        success?: string;
        error?: string;
    };
    // Shared layout data
    workspaces?: Workspace[];
    currentWorkspace?: Workspace & {
        projects: (Project & { boards: (Board & { columns?: BoardColumn[] })[]; pages: Page[] })[];
    };
    /** When inside a project-scoped route (project, board, pages), the current project id for GlobalCreateModal pre-selection */
    currentProjectId?: number | null;
    /** When on a board page, the current board id for GlobalCreateModal pre-selection */
    currentBoardId?: number | null;
    currentPageUuid?: string | null;
    itemTypes?: ItemType[];
    priorities?: ItemPriority[];
    /** Unread notification count shared by HandleInertiaRequests middleware */
    unreadNotificationsCount?: number;
};

/** My Work page: assigned item summary */
export interface MyWorkItem {
    id: number;
    uuid: string;
    key: string;
    title: string;
    description?: string;
    due_date?: string;
    completed_at?: string;
    position: number;
    board: { id: number; name: string; slug: string };
    column?: { id: number; name: string; is_done_column: boolean };
    item_type?: ItemType;
    priority?: ItemPriority;
    assignee?: User;
}

/** My Work page: project with assigned items */
export interface MyWorkProject {
    id: number;
    name: string;
    slug: string;
    key: string;
    icon?: string;
    icon_display?: string | null;
    color?: string;
    workspace?: { id: number; name: string; slug: string };
    items: MyWorkItem[];
}

export interface SnippetCollection {
    id: number;
    uuid: string;
    workspace_id: number;
    slug: string;
    name: string;
    description?: string;
    icon?: string;
    visibility: 'private' | 'public';
    position: number;
    created_by: number;
    snippets_count?: number;
    snippets?: Snippet[];
    creator?: User;
    created_at: string;
    updated_at: string;
}

export interface Snippet {
    id: number;
    uuid: string;
    collection_id: number;
    slug: string;
    title: string;
    description?: string;
    language: string;
    content?: string;
    visibility: 'inherit' | 'private' | 'public';
    position: number;
    created_by: number;
    creator?: User;
    collection?: SnippetCollection;
    created_at: string;
    updated_at: string;
}

// ==================== HELPDESK TYPES ====================

export interface Helpdesk {
    id: number;
    uuid: string;
    project_id: number;
    name: string;
    slug: string;
    description?: string;
    settings?: {
        brand_color?: string;
        logo_url?: string;
        welcome_message?: string;
        button_text?: string;
        button_position?: 'bottom-right' | 'bottom-left';
        default_field_contact_name?: { label?: string; is_required?: boolean; hidden?: boolean };
        default_field_description?: { label?: string; is_required?: boolean; hidden?: boolean };
        default_field_attachments?: { hidden?: boolean };
    };
    position: number;
    created_by: number;
    columns?: HelpdeskColumn[];
    fields?: HelpdeskField[];
    tickets?: Ticket[];
    created_at: string;
    updated_at: string;
}

export interface HelpdeskColumn {
    id: number;
    helpdesk_id: number;
    name: string;
    slug: string;
    color?: string;
    position: number;
    wip_limit?: number;
    is_done_column: boolean;
    tickets?: Ticket[];
    tickets_count?: number;
}

export interface HelpdeskField {
    id: number;
    helpdesk_id: number;
    name: string;
    label: string;
    type: 'text' | 'rich_text' | 'select' | 'checkbox' | 'file' | 'number' | 'radio' | 'date' | 'checkboxes';
    options?: string[];
    is_required: boolean;
    position: number;
    is_visible_on_card: boolean;
    created_at: string;
    updated_at: string;
}

export interface Ticket {
    id: number;
    uuid: string;
    helpdesk_id: number;
    column_id: number;
    ticket_contact_id: number;
    key: string;
    subject: string;
    description?: string;
    assigned_to?: number;
    priority_id?: number;
    position: number;
    completed_at?: string;
    metadata?: Record<string, unknown>;
    contact?: TicketContact;
    assignee?: User;
    priority?: ItemPriority;
    column?: HelpdeskColumn;
    field_values?: TicketFieldValue[];
    replies?: TicketReply[];
    attachments?: TicketAttachment[];
    created_at: string;
    updated_at: string;
}

export interface TicketContact {
    id: number;
    uuid: string;
    email: string;
    name?: string;
}

export interface TicketFieldValue {
    id: number;
    ticket_id: number;
    helpdesk_field_id: number;
    value?: string;
    field?: HelpdeskField;
}

export interface TicketReply {
    id: number;
    ticket_id: number;
    user_id?: number;
    ticket_contact_id?: number;
    body: string;
    is_internal_note: boolean;
    user?: User;
    contact?: TicketContact;
    attachments?: TicketAttachment[];
    created_at: string;
    updated_at: string;
}

export interface TicketAttachment {
    id: number;
    ticket_id: number;
    ticket_reply_id?: number;
    filename: string;
    original_filename: string;
    path: string;
    mime_type?: string;
    size: number;
    uploaded_by_contact: boolean;
    formatted_size?: string;
    url?: string;
}
