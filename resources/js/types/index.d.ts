export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    avatar?: string | null;
    avatar_display?: string | null;
    bio?: string | null;
    phone?: string | null;
    calendar_view?: 'month' | 'week' | 'day' | null;
    timezone?: string | null;
    task_digest?: boolean;
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
    /** Lead generation forms (BRAI-93), in the sidebar next to the helpdesks */
    lead_forms?: LeadFormSummary[];
    created_at: string;
    updated_at: string;
}

export interface LeadFormSummary {
    id: number;
    project_id: number;
    name: string;
    slug: string;
    status: 'draft' | 'published';
    position: number;
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
    settings?: { swimlanes?: import('@/lib/swimlanes').SwimlaneConfig } | null;
    created_at: string;
    updated_at: string;
}

export interface TaskList {
    id: number;
    uuid: string;
    project_id: number;
    name: string;
    slug: string;
    description?: string;
    position: number;
    created_by?: number;
    creator?: User;
    items?: TaskListItem[];
    watchers?: User[];
    created_at: string;
    updated_at: string;
    /** Alias: Laravel serializes the taskLists relationship as task_lists */
    task_lists?: TaskList[];
}

/** A plan (Gantt planner) as listed in the sidebar / project page. */
export interface PlanSummary {
    id: number;
    project_id: number;
    name: string;
    slug: string;
    description?: string | null;
    position: number;
    updated_at?: string;
}

export interface TaskListItem {
    id: number;
    uuid: string;
    task_list_id: number;
    title: string;
    description?: Record<string, any> | null;
    status: 'todo' | 'done';
    assignee_id?: number | null;
    assignee?: User | null;
    due_date?: string | null;
    due_time?: string | null;
    start_date?: string | null;
    start_time?: string | null;
    recurrence?: 'daily' | 'weekly' | 'monthly' | 'yearly' | null;
    /** Minutes before the due time (0 = at due time), null = no reminder. */
    reminder_minutes?: number | null;
    position: number;
    created_by?: number;
    creator?: User;
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
    start_time?: string | null;
    due_date?: string;
    due_time?: string | null;
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
    /** Null = backlog (Scrum boards). */
    sprint_id?: number | null;
    release_id?: number | null;
    release?: { id: number; name: string; status: string; release_date?: string | null } | null;
    /** Rank in the backlog / sprint list. */
    backlog_position?: number;
    /** Minutes. */
    original_estimate?: number | null;
    /** Minutes. */
    time_spent?: number;
    /** Custom field values — one row per field that has a value (BRAI-77). */
    field_values?: ItemFieldValue[];
    created_at: string;
    updated_at: string;
}

export type ItemFieldType = 'text' | 'number' | 'date' | 'checkbox' | 'select';

export interface ItemFieldOption {
    id: number;
    item_field_id: number;
    label: string;
    color: string | null;
    position: number;
    /** Archived options stay on issues that have them but can't be picked anew. */
    archived_at: string | null;
}

/** A project's custom field for board issues (App\Models\ItemField). */
export interface ItemField {
    id: number;
    project_id: number;
    name: string;
    type: ItemFieldType;
    /** Null = every board of the project. */
    board_ids: number[] | null;
    position: number;
    archived_at: string | null;
    options: ItemFieldOption[];
    /** Project settings only: how many issues have a value. */
    values_count?: number;
}

/** Exactly one typed column is set, chosen by the field type; no row = no value. */
export interface ItemFieldValue {
    id: number;
    item_id: number;
    item_field_id: number;
    value_text: string | null;
    value_number: number | null;
    value_date: string | null;
    value_bool: boolean | null;
    option_id: number | null;
}

/** What a project template contains (App\Support\Templates\TemplateDocument::summary). */
export interface ProjectTemplateSummary {
    boards: { name: string; type: 'kanban' | 'scrum'; columns: string[]; issues: number }[];
    issues: number;
    docs: number;
    folders: number;
    task_lists: { name: string; tasks: number }[];
    plans: string[];
    labels: number;
    fields: string[];
    item_types: string[];
    /** Detail view of the gallery (BRAI-91). */
    issue_types?: string[];
    sample_issues?: { title: string; type: string; subtask: boolean }[];
    doc_names?: string[];
    label_names?: string[];
}

export interface ProjectTemplateCategory {
    key: string;
    name: string;
}

/** A template on the "New project" page: built-in or saved in the workspace (BRAI-72). */
export interface ProjectTemplateOption {
    /** "builtin:<key>" or "template:<uuid>" — sent as `template` when creating. */
    id: string;
    source: 'builtin' | 'workspace';
    /** Gallery category key (BuiltinTemplates::CATEGORIES, or "workspace"). */
    category: string;
    tags: string[];
    name: string;
    description: string | null;
    icon: string;
    summary: ProjectTemplateSummary;
    version?: number;
    updated_at?: string | null;
    created_by?: string | null;
    uuid?: string;
    can_delete?: boolean;
}

export type ProjectTemplateSection = 'issues' | 'pages' | 'task_lists' | 'plans';

/** A saved workspace template as listed in project settings (no document). */
export interface ProjectTemplate {
    uuid: string;
    name: string;
    description: string | null;
}

export interface ItemType {
    id: number;
    name: string;
    slug: string;
    icon?: string | null;
    icon_display?: string | null;
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
    last_editor?: User | null;
    /** Unpublished changes of a published page — only sent to users who can edit. */
    draft_content?: Record<string, unknown> | null;
    draft_name?: string | null;
    draft_icon?: string | null;
    draft_updated_at?: string | null;
    draft_editor?: Pick<User, 'id' | 'name'> | null;
    edit_revision?: number;
    current_version?: number;
    /** When the live version last changed (newest version), sent by pages.show. */
    last_published_at?: string | null;
    created_at: string;
    updated_at: string;
}

export interface PageVersionSummary {
    version: number;
    name: string;
    icon?: string | null;
    message?: string | null;
    author?: Pick<User, 'id' | 'name' | 'avatar'> | null;
    created_at: string;
}

export interface PageVersionDetail extends PageVersionSummary {
    content?: Record<string, unknown> | null;
}

/** Response of the draft autosave and presence endpoints. */
export interface PageDraftState {
    edit_revision: number;
    has_unpublished_changes: boolean;
    draft_updated_at: string | null;
    draft_updated_by: Pick<User, 'id' | 'name'> | null;
}

export interface PagePresenceUser {
    id: number;
    name: string;
    avatar?: string | null;
    editing: boolean;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
        isInstanceAdmin?: boolean;
    };
    flash?: {
        success?: string;
        error?: string;
    };
    /** Running focus timer (BRAI-74), null when none. */
    activeTimer?: ActiveTimer | null;
    // Shared layout data
    workspaces?: Workspace[];
    currentWorkspace?: Workspace & {
        projects: (Project & {
            boards: (Board & { columns?: BoardColumn[] })[];
            task_lists: TaskList[];
            plans?: PlanSummary[];
            pages: Page[];
            helpdesks?: Helpdesk[];
        })[];
    };
    /** When inside a project-scoped route (project, board, pages), the current project id for GlobalCreateModal pre-selection */
    currentProjectId?: number | null;
    /** When on a board page, the current board id for GlobalCreateModal pre-selection */
    currentBoardId?: number | null;
    currentPageUuid?: string | null;
    itemTypes?: ItemType[];
    priorities?: ItemPriority[];
    planTemplates?: { key: string; name: string; description: string }[];
    /** Unread notification count shared by HandleInertiaRequests middleware */
    unreadNotificationsCount?: number;
    /** Browser push config shared by HandleInertiaRequests middleware */
    push?: {
        vapidPublicKey: string | null;
        subscribed: boolean;
    };
    /** Onboarding state + computed setup checklist progress (shared by HandleInertiaRequests) */
    onboarding?: OnboardingState | null;
};

export type OnboardingFocus = 'project_mgmt' | 'docs' | 'support' | 'all';

export interface OnboardingState {
    welcome_seen: boolean;
    checklist_dismissed: boolean;
    focus: OnboardingFocus | null;
    checklist: {
        has_workspace: boolean;
        has_project: boolean;
        has_board: boolean;
        has_page: boolean;
        has_teammate: boolean;
    };
}

/** My Work page: assigned item summary */
export interface MyWorkItem {
    id: number;
    work_type: 'board' | 'list';
    uuid: string;
    key: string;
    title: string;
    description?: string | Record<string, any> | null;
    due_date?: string;
    completed_at?: string;
    status?: 'todo' | 'done';
    position: number;
    source: { type: 'board' | 'list'; id: number; name: string; slug: string };
    board?: { id: number; name: string; slug: string };
    task_list?: { id: number; name: string; slug: string };
    column?: { id: number; name: string; is_done_column: boolean };
    item_type?: ItemType | null;
    priority?: ItemPriority | null;
    assignee?: User | null;
}

/** The running focus timer (shared prop). `item` is null when the user can no longer open it. */
export interface ActiveTimer {
    id: number;
    started_at: string;
    elapsed_seconds: number;
    elapsed_minutes: number;
    item: { id: number; key: string; title: string; url: string | null } | null;
}

/** Today's hand-picked plan on My Work (BRAI-74). `keys` = "board:12" / "list:5". */
export interface MyDayPlan {
    date: string;
    rows: { item: MyWorkItem; project: Pick<MyWorkProject, 'id' | 'name' | 'slug' | 'color' | 'workspace'> }[];
    keys: string[];
    carryOverCount: number;
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
    /** Shareable support page: /support/{public_slug} */
    public_slug?: string | null;
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

export interface CalendarConnectionRow {
    id: number;
    provider: string;
    kind: 'calendar' | 'tasks';
    account_email: string;
    account_name: string | null;
    external_calendar_id: string;
    external_calendar_name: string | null;
    external_calendar_color: string | null;
    is_active: boolean;
}

export interface CalendarEvent {
    id: number | string;
    type: 'board_item' | 'task_list_item' | 'google_event' | 'google_task';
    key: string;
    title: string;
    start_date: string | null;
    start_time: string | null;
    due_date: string | null;
    due_time: string | null;
    is_completed: boolean;
    color: string | null;
    assignee: { id: number; name: string; avatar_display?: string | null } | null;
    project: {
        id: number;
        name: string;
        slug: string;
        key: string;
        color?: string | null;
        workspace: { id: number; name: string; slug: string };
    };
    source: { type: 'board' | 'list'; id: number; name: string; slug: string };
    href_params: string[];
    item_type?: ItemType | null;
    priority?: ItemPriority | null;
    is_recurring_instance?: boolean;
    /** Google events: the external link to the event on calendar.google.com. */
    external_url?: string | null;
    /** Google events: which connection (and therefore which Google calendar) the event came from. */
    connection_id?: number | null;
    account_email?: string | null;
}

/** One entry of an item's history (activity_logs), see App\Observers\ItemObserver. */
export interface ItemHistoryEntry {
    id: number;
    action: 'created' | 'updated' | 'moved' | 'assigned' | 'unassigned' | 'labels_changed' | 'commented'
        | 'linked' | 'unlinked' | 'attachment_added' | 'attachment_removed' | 'work_logged' | string;
    user?: Pick<User, 'id' | 'name' | 'avatar'> | null;
    changes?: Record<string, { from?: unknown; to?: unknown; changed?: boolean; added?: string[]; removed?: string[] }> | null;
    metadata?: Record<string, unknown> | null;
    created_at: string;
}

// ── Instance admin console (metadata only — never workspace content) ───────────

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface AdminUserRow {
    id: number;
    name: string;
    email: string;
    avatar_display: string | null;
    email_verified_at: string | null;
    suspended_at: string | null;
    last_login_at: string | null;
    created_at: string | null;
    sign_in_methods: ('password' | 'google' | 'github')[];
    is_instance_admin: boolean;
    workspaces_count: number;
    owned_workspaces_count: number;
    projects_count: number;
}

export interface AdminUserDetail {
    workspaces: {
        id: number;
        name: string;
        role: string;
        /** Workspace owners / admins can open every project in it. */
        all_projects: boolean;
        projects_count: number;
        members_count: number;
        project_memberships: number;
    }[];
    delete_blockers: string[];
    delete_impact: { reassigned: number; comments_deleted: number };
    has_api_tokens: boolean;
}

export interface AdminWorkspaceRow {
    id: number;
    name: string;
    owner: { id: number; name: string; email: string } | null;
    members_count: number;
    projects_count: number;
    created_at: string | null;
}

export interface AdminAuditEntry {
    id: number;
    actor_name: string;
    action: string;
    target_type: 'user' | 'workspace' | null;
    target_label: string | null;
    meta: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: string | null;
}
