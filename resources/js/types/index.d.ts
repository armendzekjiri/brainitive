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
    /** Billing client (v1.38.0); set by workspace admins. */
    client_id?: number | null;
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
    settings?: {
        swimlanes?: import('@/lib/swimlanes').SwimlaneConfig;
        /** Days a done card stays on a Kanban board (BRAI-346); 0 / absent = all. See lib/doneRetention.ts. */
        done_retention_days?: number;
    } | null;
    created_at: string;
    updated_at: string;
}

/** A saved board view (BRAI-78) as BoardController::show sends it. */
export interface SavedBoardView {
    id: number;
    name: string;
    is_shared: boolean;
    is_own: boolean;
    owner: string | null;
    /** The board URL params the view stores (the open view: what is left of them on this board). */
    params: Record<string, string>;
    can_edit: boolean;
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
    /** Set when a recurring template created this issue (BRAI-123): the card's "Repeats weekly" badge. */
    recurring_occurrence?: { recurring_issue?: { id: number; badge_label: string } | null } | null;
    created_at: string;
    updated_at: string;
}

// ── Recurring issues (v1.40.0, BRAI-123) ──────────────────────────────────────
export type RecurrenceFrequency = 'daily' | 'weekly' | 'monthly' | 'yearly';

/** The schedule fields (App\Support\RecurringIssues\RecurrenceSchedule). */
export interface RecurrenceRule {
    frequency: RecurrenceFrequency;
    interval: number;
    /** ISO weekdays, 1 = Monday … 7 = Sunday (weekly). */
    weekdays: number[];
    monthly_mode: 'day' | 'weekday' | null;
    /** 1–31; 31 = the last day of shorter months. */
    month_day: number | null;
    /** 1–4, -1 = last. */
    month_week: number | null;
    /** 1–7, 0 = any weekday (Mon–Fri). */
    month_weekday: number | null;
    start_date: string;
    end_date: string | null;
    max_occurrences: number | null;
}

/** The issue a template creates. */
export interface RecurringIssueFields {
    title: string;
    description: string | null;
    item_type_id: number | null;
    priority_id: number | null;
    assigned_to: number | null;
    label_ids: number[];
    /** null = the board's first column. */
    column_id: number | null;
    /** Due date = occurrence + N days; null = none. */
    due_in_days: number | null;
}

export interface RecurringIssueTemplate extends RecurrenceRule, RecurringIssueFields {
    id: number;
    timezone: string;
    occurrences_count: number;
    last_occurrence_date: string | null;
    next_occurrence_date: string | null;
    last_error: string | null;
    paused: boolean;
    finished: boolean;
    summary: string;
    badge_label: string;
    creator: { id: number; name: string } | null;
}

export interface RecurringIssueOptions {
    columns: { id: number; name: string }[];
    types: { id: number; name: string; is_default: boolean }[];
    priorities: { id: number; name: string; is_default: boolean }[];
    members: { id: number; name: string }[];
    labels: { id: number; name: string; color: string }[];
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
    /** null = a default (instance-wide) type */
    project_id?: number | null;
    is_default?: boolean;
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
        warning?: string | null;
        /** A plan limit stopped the action (LimitReached) — opens the upgrade dialog. */
        limit_reached?: LimitReachedPayload | null;
    };
    /** Cloud plan, limits and usage of the current workspace (v1.46.0); null when unlimited (self-hosted). */
    entitlements?: WorkspaceEntitlements | null;
    /** Current user may open the current workspace's settings (admin). */
    canManageWorkspace?: boolean;
    /** The open-source repository + cached star count (GithubStars::shared) for the "Star on GitHub" buttons. */
    githubRepo?: { url: string; stars: number | null };
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
    priorities?: ItemPriority[];
    planTemplates?: { key: string; name: string; description: string }[];
    /** Unread notification count shared by HandleInertiaRequests middleware */
    unreadNotificationsCount?: number;
    /** Open ideas in the user's private capture inbox (BRAI-70) */
    captureInboxCount?: number;
    /** Browser push config shared by HandleInertiaRequests middleware */
    push?: {
        vapidPublicKey: string | null;
        subscribed: boolean;
    };
    /** Onboarding state + computed setup checklist progress (shared by HandleInertiaRequests) */
    onboarding?: OnboardingState | null;
    /** Customisable sidebar (BRAI-106), normalised by SidebarPreferences::forFrontend */
    sidebarPreferences?: SidebarPreferences | null;
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
    source: { type: 'board' | 'list' | 'external'; id: number; name: string; slug: string };
    /** done/open column ids: the circle in My Work / My Day completes or reopens the issue */
    board?: { id: number; name: string; slug: string; done_column_id?: number | null; open_column_id?: number | null };
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
    /** `workspace` = slug, for the activity picker of the stop dialog (v1.38.0). */
    item: { id: number; key: string; title: string; workspace?: string; url: string | null } | null;
}

// ── Time log & My timesheet (v1.38.0, BRAI-99) — member-facing: never a rate or amount ─────────

/** A billing activity as everyone who logs time sees it (`timesheet.activities`). */
export interface TimeLogActivity {
    id: number;
    name: string;
    color: string | null;
    pricing_type: 'hourly' | 'fixed';
    pricing_label: string;
    billable: boolean;
    /** Only on timesheet entries: the activity was archived after it was used. */
    archived?: boolean;
}

export interface TimeLogActivityOptions {
    activities: TimeLogActivity[];
    /** The workspace has activities: logging needs one. */
    required: boolean;
    /** Ticket type default, then the user's last-used activity. */
    default_activity_id: number | null;
}

export interface TimesheetEntry {
    id: number;
    spent_on: string;
    minutes: number;
    note: string | null;
    billable: boolean;
    source: 'manual' | 'timer' | 'legacy';
    /** On a finalized billing report: can't change. */
    locked: boolean;
    project: { id: number; name: string; key: string } | null;
    /** Live key / title while the issue exists, the snapshot after; url null when it can't be opened. */
    item: { id: number | null; key: string | null; title: string | null; url: string | null } | null;
    activity: TimeLogActivity | null;
}

/** A project you can log time on (timesheet). */
export interface TimesheetProject {
    id: number;
    name: string;
    key: string;
    slug: string;
}

/** An issue in the timesheet's issue picker (`timesheet.items`). */
export interface TimesheetIssueOption {
    id: number;
    key: string | null;
    title: string | null;
}

export interface TimesheetWeek {
    start: string;
    end: string;
    previous: string;
    next: string;
    current: string;
    number: number;
    today: string;
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

/** Workload planning (BRAI-75) — amounts are minutes; weeks are Mondays (Y-m-d). */
export type WorkloadCellState = 'unset' | 'unavailable' | 'ok' | 'over';
export type WorkloadFlag = 'done' | 'estimate_changed' | 'assignee_changed' | 'not_member';

export interface WorkloadWeek {
    start: string;
    end: string;
    week: number;
    year: number;
    label: string;
    is_current: boolean;
    is_past: boolean;
}

export interface WorkloadItemSummary {
    id: number;
    key: string;
    title: string;
    url: string | null;
    estimate_minutes: number | null;
    logged_minutes: number;
    remaining_minutes: number | null;
    completed: boolean;
}

export interface WorkloadCellAllocation {
    id: number;
    minutes: number;
    counts: boolean;
    flags: WorkloadFlag[];
    item: WorkloadItemSummary;
}

export interface WorkloadCell {
    week: string;
    capacity_minutes: number | null;
    capacity_source: 'default' | 'override' | null;
    override_note: string | null;
    allocated_minutes: number;
    unestimated_minutes: number;
    over_minutes: number;
    free_minutes: number | null;
    load_percent: number | null;
    state: WorkloadCellState;
    allocations: WorkloadCellAllocation[];
}

export interface WorkloadMember {
    id: number;
    name: string;
    avatar_display: string | null;
    capacity_minutes: number | null;
    work: { estimated_items: number; remaining_minutes: number; unplanned_minutes: number; unestimated_items: number };
    cells: WorkloadCell[];
}

export interface WorkloadTeamWeek {
    week: string;
    capacity_minutes: number;
    allocated_minutes: number;
    unset: number;
    over: number;
}

export interface WorkloadReviewEntry {
    kind: 'over_remaining' | WorkloadFlag;
    message: string;
    item: WorkloadItemSummary;
    allocation: { id: number; user_id: number; user_name: string; week: string; minutes: number } | null;
}

export interface WorkloadCandidate {
    id: number;
    key: string;
    title: string;
    is_subtask: boolean;
    assignee: { id: number; name: string } | null;
    estimate_minutes: number | null;
    logged_minutes: number;
    remaining_minutes: number | null;
    open_planned_minutes: number;
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
    /** SLA (BRAI-125): computed by TicketSla; null when the helpdesk has no target for the ticket. */
    first_responded_at?: string | null;
    sla_first_response_due_at?: string | null;
    sla_first_response_warn_at?: string | null;
    sla_resolution_due_at?: string | null;
    sla_resolution_warn_at?: string | null;
    sla_paused_at?: string | null;
    sla_paused_minutes?: number;
    sla_breached_at?: string | null;
    sla_first_response_breached?: boolean;
    sla_resolution_breached?: boolean;
}

/** Helpdesk SLA policy as the settings tab edits it (BRAI-125, HelpdeskSlaController). */
export type SlaUnit = 'minutes' | 'hours' | 'days';
export interface SlaTarget {
    value: number | string | null;
    unit: SlaUnit;
}
export interface SlaPolicy {
    exists: boolean;
    enabled: boolean;
    /** key: priority id, or "none" for tickets without a priority */
    targets: Record<string, { first_response: SlaTarget | null; resolution: SlaTarget | null }>;
    /** null = 24/7; ISO weekdays (1 = Monday); `to` before `from` = overnight */
    business_hours: { days: number[]; from: string; to: string } | null;
    timezone: string;
    pause_column_ids: number[];
    warn_percent: number | null;
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
    /** Sent / received by email (BRAI-79): Message-ID and, for agent replies, the delivery state. */
    email_message_id?: string | null;
    email_status?: 'sending' | 'sent' | 'failed' | null;
    email_error?: string | null;
    created_at: string;
    updated_at: string;
}

/** Helpdesk settings → Email → Connected mailbox (BRAI-79). Passwords are write-only. */
export interface HelpdeskMailboxSettings {
    enabled: boolean;
    email_address: string;
    from_name: string | null;
    imap_host: string;
    imap_port: number;
    imap_encryption: 'ssl' | 'starttls' | 'none';
    imap_username: string;
    has_imap_password: boolean;
    imap_folder: string;
    processed_folder: string | null;
    smtp_mode: 'app' | 'smtp';
    smtp_host: string | null;
    smtp_port: number | null;
    smtp_encryption: 'ssl' | 'starttls' | 'none' | null;
    smtp_username: string | null;
    has_smtp_password: boolean;
    last_checked_at: string | null;
    last_success_at: string | null;
    last_error: string | null;
}

/** One received email in the mailbox log (any transport). */
export interface HelpdeskInboundEmailRow {
    id: number;
    transport: 'imap' | 'forwarding';
    from_email: string | null;
    subject: string | null;
    status: 'ticket_created' | 'reply_added' | 'duplicate' | 'ignored' | 'rejected' | 'failed';
    reason: string | null;
    ticket_key: string | null;
    created_at: string | null;
}

/** GET helpdesks.mailbox.show */
export interface HelpdeskMailboxPayload {
    mailbox: HelpdeskMailboxSettings | null;
    recent: HelpdeskInboundEmailRow[];
    options: { imap_ports: number[]; smtp_ports: number[]; allow_unencrypted: boolean; app_from_address: string };
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
    /** A CalendarProviders key ('google', …). */
    provider: string;
    provider_label: string;
    provider_badge: string;
    kind: 'calendar' | 'tasks';
    account_email: string;
    account_name: string | null;
    external_calendar_id: string;
    external_calendar_name: string | null;
    external_calendar_color: string | null;
    server_url: string | null;
    is_active: boolean;
    /** Events of this calendar can't be created / moved / edited (ICS links, …). */
    read_only: boolean;
    /** Why loading this calendar failed last time (null when fine). */
    sync_error: string | null;
    sync_error_at: string | null;
}

export interface CalendarEvent {
    id: number | string;
    /** external_event = an event of any connected calendar provider; google_task = a Google Tasks task. */
    /** booking = a booking on one of your booking pages (BRAI-158), read-only */
    type: 'board_item' | 'task_list_item' | 'external_event' | 'google_task' | 'booking';
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
    source: { type: 'board' | 'list' | 'external'; id: number; name: string; slug: string };
    href_params: string[];
    item_type?: ItemType | null;
    priority?: ItemPriority | null;
    is_recurring_instance?: boolean;
    /** External events: the link to the event in its provider (e.g. calendar.google.com). */
    external_url?: string | null;
    /** External events / Google tasks: which connection (and therefore which calendar) it came from. */
    connection_id?: number | null;
    account_email?: string | null;
    /** External events / Google tasks: the provider key, its name and its chip badge. */
    provider?: string;
    provider_label?: string;
    provider_badge?: string;
    /** External events: can't be moved or edited here. */
    read_only?: boolean;
    all_day?: boolean;
    location?: string | null;
    /** External events: the event's video call (https), if it has one (BRAI-147). */
    conference_url?: string | null;
    conference_provider?: ConferenceProvider | null;
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
    sign_in_methods: ('password' | 'google' | 'github' | 'microsoft')[];
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

// ── Quick capture (BRAI-70) ─────────────────────────────────────────────────

/** Destination kinds: board issue, list task, doc, bookmark (group), snippet (collection). */
export type CaptureType = 'issue' | 'task' | 'page' | 'bookmark' | 'snippet';
export type CaptureState = 'inbox' | 'processed' | 'archived';

/** Where a converted capture's content lives (ResourceLocator; `available` = still exists). */
export interface CaptureTarget {
    type: CaptureType;
    title: string;
    breadcrumb: string;
    url: string | null;
    available: boolean;
}

/** One idea in the user's private capture inbox (CaptureInboxController::present). */
export interface Capture {
    id: string;
    text: string;
    url: string | null;
    intended_type: CaptureType | null;
    source: string | null;
    state: CaptureState;
    captured_at: string | null;
    processed_at: string | null;
    archived_at: string | null;
    target: CaptureTarget | null;
}

/** Writable destinations, grouped by workspace (CaptureDestinations, shared with the extension). */
export interface CaptureDestinationWorkspace {
    id: number;
    name: string;
    slug: string;
    projects: {
        id: number;
        name: string;
        key: string | null;
        can_create_pages: boolean;
        boards: { id: number; name: string; type: string; columns: { id: number; name: string }[] }[];
        task_lists: { id: number; name: string }[];
    }[];
    bookmarks: { id: number; name: string; icon: string | null }[];
    snippet_collections: { id: number; name: string }[];
}

/** Result of POST captures.convert. */
export interface CaptureConversionResult {
    replayed: boolean;
    /** `url` is null when the created content is gone or no longer accessible to you. */
    location: { type: string; title: string; breadcrumb: string; url: string | null };
    capture: Capture;
}

/** Linked content (BRAI-71): an endpoint type of a content reference. Whiteboards are pages. */
export type ContentReferenceType = 'item' | 'page' | 'ticket' | 'plan';

/** A resource as linked content shows it (ContentReferences::summary). */
export interface ContentReferenceSummary {
    /** `${type}:${id}` */
    key: string;
    type: ContentReferenceType;
    id: number;
    /** Issue / Doc / Whiteboard / Ticket / Plan */
    label: string;
    title: string;
    /** Issue or ticket key */
    subtitle: string | null;
    /** Canonical URL that opens it */
    url: string;
    status: 'draft' | 'archived' | null;
}

/** One entry of "Related content" / "Referenced by" (manual and derived links of a pair merged). */
export interface ContentReferenceEntry extends ContentReferenceSummary {
    manual: boolean;
    derived: boolean;
    /** "Plan row" / "Whiteboard card" when derived */
    derived_label: string | null;
    /** The manual reference's id (what removing deletes) */
    reference_id: number | null;
    can_remove: boolean;
}

export interface ContentReferenceList {
    related: ContentReferenceEntry[];
    referenced_by: ContentReferenceEntry[];
    can_link: boolean;
}

/** A picker search result. */
export interface ContentReferenceCandidate extends ContentReferenceSummary {
    linked: boolean;
}

/** Meeting notes → issues (BRAI-62): what the dialog needs (`meeting-notes.options`). */
export interface MeetingNotesOptions {
    ai_configured: boolean;
    can_configure_ai: boolean;
    settings_url: string;
    /** Boards of the project the user can add issues to. */
    boards: { id: number; name: string }[];
    /** People who can open the project — the only possible assignees. */
    members: { id: number; name: string }[];
    timezone: string;
    /** Today in the user's time zone: the default meeting date. */
    today: string;
    source: MeetingNotesSourceInfo | null;
    limits: { max_chars: number; max_proposals: number };
}

export interface MeetingNotesSourceInfo {
    uuid: string;
    title: string | null;
    /** Editors read their unpublished changes; everyone else the live doc. */
    uses_draft: boolean;
    length?: number;
    too_long?: boolean;
}

export interface MeetingNotesAssignee {
    /** The name as written in the notes. */
    mention: string;
    status: 'matched' | 'ambiguous' | 'unknown';
    user_id: number | null;
    /** Members the name could mean (ambiguous only). */
    candidates: { id: number; name: string }[];
}

/** One proposed action item — nothing exists until the user clicks Create. */
export interface MeetingNotesProposal {
    temp_id: string;
    title: string;
    description: string;
    /** The sentence in the notes this comes from (checked against the notes). */
    excerpt: string;
    assignee: MeetingNotesAssignee | null;
    due: { text: string; date: string | null; status: 'set' | 'needs_meeting_date' | 'unclear' } | null;
    /** temp_id of an earlier proposal with the same title / quote */
    duplicate_of: string | null;
    /** An open issue on the destination board with the same title */
    existing: { id: number; key: string; title: string; url: string } | null;
    selected: boolean;
}

export interface MeetingNotesExtraction {
    proposals: MeetingNotesProposal[];
    /** Suggestions whose quote wasn't found in the notes (dropped, never shown as tasks). */
    dropped: number;
    truncated: boolean;
    board: { id: number; name: string };
    source: MeetingNotesSourceInfo | null;
}

export interface MeetingNotesCreateResult {
    count: number;
    items: { id: number; key: string; title: string; url: string }[];
    board_url: string;
    /** A retry of a Create that already went through: nothing new was created. */
    replayed: boolean;
}

/** Project health (BRAI-64): metric keys (`metric:<key>` in sources). */
export type HealthMetricKey =
    | 'open' | 'completed' | 'completed_in_period' | 'created_in_period' | 'overdue' | 'due_soon'
    | 'no_due_date' | 'unassigned' | 'blocked' | 'milestones_upcoming' | 'milestones_missed'
    | 'plan_rows_open' | 'plan_rows_late';

/** An issue in a health list (live, for the viewer). */
export interface HealthItemRef {
    /** `item:<id>` */
    ref: string;
    id: number;
    key: string;
    title: string;
    due_date: string | null;
    assignee: string | null;
    url: string;
    /** Blockers only: the blocker lives in another project */
    external?: boolean;
}

export interface HealthMilestone {
    /** `plan:<id>` */
    ref: string;
    plan_id: number;
    plan: string;
    bar: string;
    name: string;
    date: string;
    state: 'upcoming' | 'missed';
    item_ref: string | null;
    url: string;
}

/** HealthMetrics::compute() — `null` values are Unknown (reason in `unknown`). */
export interface ProjectHealthData {
    as_of: string;
    timezone: string;
    today: string;
    period: { from: string; to: string };
    values: Record<HealthMetricKey, number | null>;
    unknown: Partial<Record<HealthMetricKey, string>>;
    lists: {
        overdue: HealthItemRef[];
        due_soon: HealthItemRef[];
        unassigned: HealthItemRef[];
        completed: HealthItemRef[];
        blocked: { item: HealthItemRef; blockers: HealthItemRef[] }[];
        milestones: HealthMilestone[];
    };
}

export type HealthStatus = 'on_track' | 'at_risk' | 'off_track';

export type HealthSection = 'progress' | 'risks' | 'next_steps';

export interface HealthClaim {
    text: string;
    /** `item:<id>`, `plan:<id>`, `metric:<key>` */
    sources: string[];
}

/** A source ref resolved for the reader; unavailable ones carry no title or link. */
export interface HealthSource {
    ref: string;
    available: boolean;
    type: 'item' | 'plan' | 'metric';
    label: string;
    url: string | null;
}

export interface HealthReportSummary {
    id: number;
    status: HealthStatus;
    summary: string | null;
    author: string | null;
    as_of: string | null;
    created_at: string | null;
    ai_assisted: boolean;
}

/** ReportSources::present() */
export interface HealthReportDetail extends HealthReportSummary {
    sections: Record<HealthSection, HealthClaim[]>;
    values: Partial<Record<HealthMetricKey, number | null>>;
    unknown: Partial<Record<HealthMetricKey, string>>;
    lists: {
        overdue?: string[];
        due_soon?: string[];
        unassigned?: string[];
        completed?: string[];
        blocked?: { item: string; blockers: string[] }[];
        milestones?: { plan: string; date: string; state: 'upcoming' | 'missed' }[];
    };
    sources: Record<string, HealthSource>;
    period: { from: string | null; to: string | null };
    timezone: string;
    can_delete: boolean;
}

// ── Client billing (v1.38.0): settings + documents (BRAI-101) ───────────────────────────────

/** billing_reports.status: draft → finalized → sent → paid; void unlocks the entries. */
export type BillingReportStatus = 'draft' | 'finalized' | 'sent' | 'paid' | 'void';

/** One workspace's billing settings as the settings page edits them (Billing\BillingSettingsController). */
export interface BillingSettings {
    currency: string;
    /** Percent with up to two decimals ("19.00"); stored as basis points. */
    default_tax_rate: string;
    prices_include_tax: boolean;
    /** 0 = exact; 5 | 15 | 30 minutes per entry, reports only. */
    rounding_minutes: number;
    invoice_mode: boolean;
    number_prefix: string;
    next_number: number | string;
    payment_terms_days: number | string | null;
    company_name: string;
    company_address: string;
    company_vat_id: string;
    company_email: string;
    company_phone: string;
    bank_name: string;
    iban: string;
    bic: string;
    footer: string;
}

// ── Billing reports (v1.38.0, BRAI-101 reports) ─────────────────────────────────
// Snapshot v1: docs/ai/tasks/release-v1.38.0-billing.md (built by App\Support\Billing\ReportBuilder).

export type BillingGroupBy = 'ticket' | 'activity' | 'person';

export interface BillingSnapshotLine {
    item_key: string | null;
    item_title: string | null;
    activity: { name: string; pricing_type: 'hourly' | 'fixed' };
    person: string | null;
    minutes: number;
    billed_minutes: number;
    rate_cents: number;
    amount_cents: number;
    fixed_already_billed: boolean;
    notes: string[];
    entry_ids: number[];
}

export interface BillingSnapshotEntry {
    id: number;
    date: string;
    person: string | null;
    project_key: string | null;
    item_key: string | null;
    item_title: string | null;
    activity: string;
    minutes: number;
    billed_minutes: number;
    billable: boolean;
    note: string | null;
}

export interface BillingReportSnapshot {
    version: 1;
    kind: 'report' | 'invoice';
    number: string | null;
    title: string;
    issued_on: string;
    period: { from: string; to: string };
    currency: string;
    tax_rate_bp: number;
    prices_include_tax: boolean;
    rounding_minutes: number;
    issuer: {
        company_name: string; address: string; vat_id: string; email: string; phone: string;
        bank_name: string; iban: string; bic: string; footer: string; logo_path: string | null; payment_terms_days: number;
    };
    client: { name: string; email: string; address: string; vat_id: string };
    options: { group_by: BillingGroupBy; show_people: boolean; show_notes: boolean };
    groups: {
        project: { id: number; name: string; key: string | null };
        lines: BillingSnapshotLine[];
        minutes: number;
        billed_minutes: number;
        net_cents: number;
    }[];
    totals: { minutes: number; billed_minutes: number; net_cents: number; tax_cents: number; gross_cents: number };
    entries: BillingSnapshotEntry[];
}

/** BillingReportController::summary() */
export interface BillingReportSummary {
    id: number;
    kind: 'report' | 'invoice';
    number: string | null;
    title: string | null;
    status: BillingReportStatus;
    client: { id: number; name: string } | null;
    period_from: string;
    period_to: string;
    currency: string;
    total_minutes: number;
    total_cents: number;
    created_at: string | null;
}

export interface BillingReportOptions {
    project_ids: number[] | null;
    group_by: BillingGroupBy;
    show_people: boolean;
    show_notes: boolean;
    excluded_entry_ids: number[];
}

export interface BillingReportDetail extends BillingReportSummary {
    options: BillingReportOptions;
    finalized_at: string | null;
    sent_at: string | null;
    sent_to: string | null;
    paid_at: string | null;
    voided_at: string | null;
    client_email: string | null;
}

/** An entry of a draft for the admin (always with person + note); left-out entries have no billed_minutes. */
export interface BillingReportRow extends Omit<BillingSnapshotEntry, 'billed_minutes'> {
    billed_minutes?: number;
}

export interface BillingReportWarnings {
    without_activity: number;
    non_billable: number;
    nothing_to_bill: boolean;
    settings_incomplete: boolean;
}

/** Billing → Activities (v1.38.0, BRAI-99): BillingActivityController@index, workspace admins only. */
export type BillingPricingType = 'hourly' | 'fixed';

export interface BillingActivity {
    id: number;
    name: string;
    pricing_type: BillingPricingType;
    /** hourly: per hour; fixed: the flat price — in cents of the workspace's currency */
    rate_cents: number;
    billable: boolean;
    color: string | null;
    archived_at: string | null;
    /** time was logged with it: it can only be archived, not deleted */
    used: boolean;
}

/** An item type usable in the workspace, with its default activity for logging time. */
export interface BillingActivityItemType {
    id: number;
    name: string;
    color: string | null;
    /** null = a global type */
    project: string | null;
    billing_activity_id: number | null;
}

// ── Billing: clients + team time overview (BRAI-100) ───────────────────────────────────────────

/** A client in a select (project settings, overview filter). */
export interface BillingClientOption {
    id: number;
    name: string;
    archived: boolean;
}

/** ClientController::index */
export interface BillingClient {
    id: number;
    name: string;
    email: string | null;
    address: string | null;
    vat_id: string | null;
    /** Percent (19 = 19 %); null = the workspace default. */
    tax_rate: number | null;
    notes: string | null;
    archived: boolean;
    projects: BillingClientProject[];
}

export type BillingClientProject = Pick<Project, 'id' | 'name' | 'slug' | 'key' | 'icon' | 'icon_display' | 'color'> & { client_id?: number | null };

export type TimeOverviewPeriod = 'last_month' | 'this_month' | 'custom';
export type TimeOverviewGroupBy = 'ticket' | 'person' | 'activity' | 'day';
export type TimeOverviewBillable = 'all' | 'billable' | 'non_billable';

/** TimeOverview::filters() */
export interface TimeOverviewFilters {
    period: TimeOverviewPeriod;
    from: string;
    to: string;
    projects: number[];
    client: number | null;
    person: number | null;
    /** An activity id, or 'none' for entries without an activity. */
    activity: number | 'none' | null;
    billable: TimeOverviewBillable;
    group: TimeOverviewGroupBy;
    detail: boolean;
}

export interface TimeOverviewEntry {
    id: number;
    date: string;
    person: string | null;
    project_key: string | null;
    ticket_key: string | null;
    ticket_title: string | null;
    activity: string | null;
    note: string | null;
    minutes: number;
    billable: boolean;
    /** Billed by a finalized report: no edits. */
    locked: boolean;
    report: string | null;
}

interface TimeOverviewTotals {
    minutes: number;
    billable_minutes: number;
    entries: number;
    /** Indicative, before rounding / VAT. */
    amount_cents: number;
    /** Any billable entry with an activity (otherwise there is no amount to show). */
    priced: boolean;
}

export interface TimeOverviewTicketRow extends TimeOverviewTotals {
    key: string;
    item_id: number | null;
    ticket_key: string | null;
    label: string | null;
    detail: TimeOverviewEntry[];
}

export interface TimeOverviewGroup extends TimeOverviewTotals {
    key: string;
    label: string;
    /** Project key (ticket mode) or pricing type (activity mode). */
    meta: string | null;
    /** Client name (ticket mode). */
    client?: string | null;
    /** Ticket rows (ticket mode only). */
    rows?: TimeOverviewTicketRow[];
    /** Entries (person / activity / day modes, with detail on). */
    detail?: TimeOverviewEntry[];
}

export interface TimeOverviewSummary {
    groups: TimeOverviewGroup[];
    totals: TimeOverviewTotals & { non_billable_minutes: number };
    detail_truncated: boolean;
}

export interface TimeOverviewOptions {
    projects: { id: number; name: string; key: string; client_id: number | null }[];
    clients: BillingClientOption[];
    people: { id: number; name: string }[];
    activities: { id: number; name: string; pricing_type: 'hourly' | 'fixed'; archived: boolean }[];
}

// ── Admin insights (BRAI-105) — app/Support/Insights/* ─────────────────────────
export type InsightsPeriod = 'this_month' | 'last_month' | 'last_30' | 'last_90' | 'custom';
export type InsightsTab = 'overview' | 'team' | 'projects' | 'helpdesk' | 'usage';

/** InsightsFilters::toArray() */
export interface InsightsFilters {
    period: InsightsPeriod;
    from: string;
    to: string;
    project: number | null;
    timezone: string;
}

/** Props every Insights page receives (InsightsController::tab). */
export interface InsightsPageProps<T> {
    workspace: { id: number; name: string; slug: string };
    filters: InsightsFilters;
    presets: Record<Exclude<InsightsPeriod, 'custom'>, { from: string; to: string }>;
    projectOptions: { id: number; name: string }[];
    /** Query string that reproduces the filters (CSV links). */
    exportQuery: Record<string, string | number>;
    insights: T;
}

export interface InsightsOverview {
    kpis: {
        created: number;
        completed: number;
        completion_rate: number | null;
        median_cycle_days: number | null;
        open: number;
        overdue: number;
        hours: number;
    };
    weeks: { week: string; created: number; completed: number }[];
    projects: { id: number; name: string; slug: string; color: string | null; open: number; overdue: number }[];
    types: { name: string | null; created: number; completed: number }[];
}

export interface InsightsTeamMember {
    id: number;
    name: string;
    avatar: string | null;
    role: string;
    completed: number;
    open: number;
    overdue: number;
    hours: number;
    comments: number;
    last_login_at: string | null;
    last_activity_at: string | null;
    last_active_at: string | null;
    profile_url: string;
}

export interface InsightsProjectRow {
    id: number;
    name: string;
    key: string | null;
    color: string | null;
    health: 'on_track' | 'at_risk' | 'off_track' | null;
    health_at: string | null;
    issues: number;
    open: number;
    overdue: number;
    done_in_range: number;
    hours: number;
    last_activity_at: string | null;
    url: string;
    health_url: string;
}

export interface InsightsHelpdesk {
    tickets: { created: number; resolved: number; open: number; median_resolution_hours: number | null };
    /** BRAI-125: breaches due in the range; met % of the tickets with an SLA resolved in the range */
    sla: { breaches: number; resolved: number; met: number; met_percent: number | null };
    helpdesks: {
        id: number;
        name: string;
        project: string;
        created: number;
        resolved: number;
        open: number;
        sla_breaches: number;
        sla_resolved: number;
        sla_met: number;
        sla_met_percent: number | null;
        url: string;
    }[];
    leads: { total: number; views: number; starts: number; submits: number };
    forms: {
        id: number;
        name: string;
        project: string;
        status: string;
        leads: number;
        closed: number;
        views: number;
        starts: number;
        submits: number;
        conversion: number | null;
        url: string;
    }[];
}

export interface InsightsUsage {
    members: number;
    roles: Record<string, number>;
    pending_invites: number;
    projects: number;
    storage: { id: number; name: string; color: string | null; files: number; issue_bytes: number; ticket_bytes: number; bytes: number }[];
    storage_bytes: number;
    webhooks: number;
}

// ── Notifications inbox (BRAI-102) ────────────────────────────────────────────

/** Catalog categories (app/Notifications/NotificationCatalog::CATEGORIES). */
export type NotificationCategoryKey =
    | 'mentions'
    | 'assignments'
    | 'comments'
    | 'status'
    | 'reminders'
    | 'pages'
    | 'sprints'
    | 'helpdesk'
    | 'leads'
    | 'workspace'
    | 'reviews'
    | 'feedback';

export type NotificationFilter = 'all' | 'unread' | 'mentions' | 'assigned';

/**
 * A notification's stored payload. New types follow the contract keys (type, title, body, url,
 * actor_name, actor_avatar, project_name, subject_key); older types keep their own keys.
 */
export interface NotificationData {
    type?: string;
    // payload contract (v1.39.0)
    title?: string;
    body?: string | null;
    url?: string;
    actor_name?: string | null;
    actor_avatar?: string | null;
    project_name?: string | null;
    subject_key?: string | null;
    // legacy keys
    item_key?: string;
    item_title?: string;
    item_url?: string;
    old_column?: string;
    new_column?: string;
    when_label?: string;
    due_today?: number;
    overdue?: number;
    titles?: string[];
    context_type?: string;
    context_title?: string;
    context_url?: string;
    page_name?: string;
    page_url?: string;
    ticket_key?: string;
    ticket_subject?: string;
    ticket_url?: string;
    reply_preview?: string;
    contact_name?: string;
    contact_email?: string;
    form_name?: string;
    lead_number?: number;
    lead_name?: string;
    lead_url?: string;
    [key: string]: unknown;
}

export interface AppNotification {
    id: string;
    type: string | null;
    /** From the catalog; null for a type the catalog doesn't know. */
    category: NotificationCategoryKey | null;
    data: NotificationData;
    read_at: string | null;
    archived_at: string | null;
    created_at: string;
}

export interface NotificationFeed {
    data: AppNotification[];
    next_cursor: string | null;
    unread_count: number;
}

export interface NotificationChannels {
    in_app: boolean;
    push: boolean;
    email: boolean;
}

/** One row of Profile → Notifications (NotificationCatalog::forUser). */
export interface NotificationCategoryPreference {
    key: NotificationCategoryKey;
    label: string;
    description: string;
    channels: NotificationChannels;
}

/** Outgoing webhook of a workspace (v1.39.0, BRAI-104). The secret is never part of it. */
export interface WorkspaceWebhook {
    id: number;
    name: string;
    url: string;
    /** DomainEvents names, or ["*"] for every event. */
    events: string[];
    active: boolean;
    failure_count: number;
    disabled_at: string | null;
    last_delivery_at: string | null;
    last_status: WebhookDeliveryStatus | null;
    created_by: string | null;
    created_at: string | null;
}

export type WebhookDeliveryStatus = 'pending' | 'success' | 'failed';

export interface WebhookDelivery {
    id: number;
    uuid: string;
    event: string;
    event_id: string;
    status: WebhookDeliveryStatus;
    attempts: number;
    response_code: number | null;
    duration_ms: number | null;
    error: string | null;
    /** The envelope that was sent: {id, event, occurred_at, workspace, actor, data}. */
    payload: Record<string, unknown>;
    response_body: string | null;
    created_at: string | null;
    delivered_at: string | null;
}

// ── Customisable sidebar (BRAI-106) ─────────────────────────────────────────
/** Top-level sidebar entries a user may hide (Home and Inbox are always shown). */
export type SidebarNavKey = 'my_work' | 'captures' | 'timesheet' | 'billing' | 'insights' | 'calendar' | 'booking_pages' | 'bookmarks' | 'snippets';
/** Project groups in the sidebar; health / workload / releases are single rows (BRAI-286). */
export type SidebarGroupKey = 'health' | 'workload' | 'releases' | 'boards' | 'lists' | 'plans' | 'helpdesks' | 'lead_forms' | 'pages' | 'whiteboards' | 'folders';
/** What can be pinned; "page" covers pages, whiteboards and folders. */
export type SidebarPinType = 'board' | 'list' | 'plan' | 'helpdesk' | 'lead_form' | 'page';

export interface SidebarPin {
    type: SidebarPinType;
    id: number;
}

export interface SidebarProjectPreferences {
    groups: Record<SidebarGroupKey, boolean>;
    pins: SidebarPin[];
    pinned_only: boolean;
}

/** `users.sidebar_preferences`; missing projects use the defaults (all groups, no pins). */
export interface SidebarPreferences {
    hidden_nav: SidebarNavKey[];
    projects: Record<string, SidebarProjectPreferences>;
}

/** One "Recently updated" row on the project page (RecentContent::for). */
export interface RecentContentEntry {
    type: SidebarPinType;
    id: number;
    name: string;
    url: string;
    updated_at: string;
    is_whiteboard?: boolean;
    icon?: string | null;
}

// ── Workflow automation (v1.40.0, BRAI-63; AutomationController) ────────────
export type AutomationSubject = 'item' | 'ticket';
export type AutomationValue = number | string | null;

/** A condition (BRAI-144): field × operator × value. Legacy {type, value} rules are sent normalised. */
export interface AutomationCondition {
    /** A CONDITION_FIELDS key, or "custom:<item field id>". */
    field: string;
    operator: string;
    value: AutomationConditionValue;
}

export interface AutomationAction {
    type: string;
    value: AutomationValue;
    text?: string | null;
}

export interface AutomationRule {
    id: number;
    name: string;
    enabled: boolean;
    version: number;
    trigger: string;
    trigger_config: { column_id?: number | null };
    condition_match: AutomationMatch;
    conditions: AutomationCondition[];
    actions: AutomationAction[];
    /** The rule in words ("Issue created" / "Board is Main Board" / "Assign to Max Member"). */
    summary: { when: string; match: AutomationMatch; if: string[]; then: string[] };
    /** The person the rule runs as (whoever saved it last). */
    actor: { id: number; name: string } | null;
    actor_problem: string | null;
    last_run_at: string | null;
    last_run_status: AutomationRunStatus | null;
}

export type AutomationRunStatus = 'queued' | 'running' | 'success' | 'skipped' | 'error';

export interface AutomationRunResult {
    action: string;
    label: string;
    status: 'ok' | 'skipped' | 'error';
    message: string;
}

export interface AutomationRun {
    id: number;
    rule: { id: number; name: string } | null;
    rule_version: number;
    trigger: string;
    subject: string | null;
    status: AutomationRunStatus;
    message: string | null;
    results: AutomationRunResult[];
    /** The conditions as checked for this run (null on runs from before BRAI-144). */
    conditions: AutomationRunConditions | null;
    created_at: string | null;
    finished_at: string | null;
}

/** Value kinds: board, helpdesk, column, item_type, priority, label, member, assignee, recipient, days, text. */
export interface AutomationCatalogEntry {
    key: string;
    label: string;
    value?: string | null;
    text?: 'optional' | 'required' | null;
    subjects?: AutomationSubject[];
    subject?: AutomationSubject;
    help?: string;
}

export interface AutomationCatalog {
    triggers: AutomationCatalogEntry[];
    conditionFields: AutomationConditionField[];
    operators: AutomationOperator[];
    /** Custom field type → operator keys. */
    customFieldOperators: Record<string, string[]>;
    actions: AutomationCatalogEntry[];
    maxConditions: number;
    maxList: number;
    maxActions: number;
}

export interface AutomationContainerOption {
    id: number;
    name: string;
    columns: { id: number; name: string }[];
}

export interface AutomationOptions {
    boards: AutomationContainerOption[];
    helpdesks: AutomationContainerOption[];
    itemTypes: { id: number; name: string }[];
    priorities: { id: number; name: string }[];
    labels: { id: number; name: string; color?: string | null }[];
    members: { id: number; name: string }[];
    /** Active custom fields of the project's issues (BRAI-144). */
    customFields: AutomationCustomField[];
}

/** "Test on an issue" (automations.preview). */
export interface AutomationPreview {
    subject: string;
    matches: boolean;
    match: AutomationMatch;
    conditions: { field: string; operator: string; label: string; passed: boolean }[];
    actions: { label: string; status: 'ok' | 'skipped' | 'error'; message: string }[];
}

// ── Bring your data: imports (v1.41.0) ────────────────────────────────────────
export type ImportSourceKey = 'trello' | 'jira' | 'notion' | 'brainitive' | string;
export type ImportStatus = 'uploaded' | 'previewed' | 'queued' | 'running' | 'completed' | 'failed' | 'cancelled';

export interface ImportSource {
    key: ImportSourceKey;
    label: string;
    description: string;
    accept: string;
    needs_project: boolean;
}

/** ImportRun::report — counts of what was created, warnings, and content that couldn't come over. */
export interface ImportReport {
    created?: Record<string, number>;
    warnings?: string[];
    unsupported?: Record<string, number>;
}

export interface ImportRunData {
    uuid: string;
    source: ImportSourceKey;
    status: ImportStatus;
    file_name: string | null;
    file_size: number;
    project: { id: number; name: string; slug: string } | null;
    user: { id: number; name: string } | null;
    progress: { done: number; total: number };
    error: string | null;
    created_at: string | null;
    finished_at: string | null;
    /** links to what was created, importer-defined: {project_id, board_id, page_id, url, …} */
    result: Record<string, unknown> | null;
    /** importer-defined dry-run summary (only on the detail payload) */
    preview?: Record<string, unknown> | null;
    options?: Record<string, unknown> | null;
    report?: ImportReport | null;
}

// ── Project export + restore (BRAI-81) ────────────────────────────────────────
export type ProjectExportStatus = 'queued' | 'running' | 'ready' | 'failed' | 'expired';

/** ProjectExport::present() */
export interface ProjectExportData {
    uuid: string;
    status: ProjectExportStatus;
    file_size: number;
    counts: Record<string, number> | null;
    error: string | null;
    created_at: string | null;
    finished_at: string | null;
    expires_at: string | null;
    /** signed, only for the exporter, until expires_at */
    download_url: string | null;
}

/** ProjectArchiveImporter::preview() */
export interface ProjectArchivePreview {
    format_version: number;
    app_version: string | null;
    exported_at: string | null;
    source: { name: string; key: string };
    suggested: { name: string; key: string };
    counts: Record<string, number>;
    people: { ref: string; name: string; email: string | null; member_id: number | null }[];
    members: { id: number; name: string; email: string | null }[];
    omitted: { what: string; count: number | null; why: string }[];
}

// ── Guest portals (v1.42.0 foundation) ────────────────────────────────────────

/** The viewer of a portal page (PortalIdentity::toPortalArray via PortalAuth::shared). */
export interface PortalIdentityData {
    uuid: string;
    name: string;
    email: string;
    /** a Brainitive account (signed in to the app) rather than an emailed guest */
    is_member: boolean;
}

// ── Calendar feed (BRAI-142) ──────────────────────────────────────────────────

/** What a calendar feed includes (CalendarFeed::DEFAULT_OPTIONS). */
export interface CalendarFeedOptions {
    assigned: boolean;
    created: boolean;
    tasks: boolean;
    my_day: boolean;
    focus: boolean;
    completed: boolean;
    descriptions: boolean;
    /** empty = every project the user can open */
    project_ids: number[];
}

/** JSON of calendar-feed.show / store / update / reset / destroy (CalendarFeedController::payload). */
export interface CalendarFeedPayload {
    feed: {
        url: string;
        links: { apple: string; google: string; outlook: string };
        options: CalendarFeedOptions;
        created_at: string | null;
        last_fetched_at: string | null;
        fetch_count: number;
    } | null;
    defaults: CalendarFeedOptions;
    projects: { id: number; name: string; workspace: string | null }[];
}

// ── Client portal (v1.42.0, BRAI-73) ──────────────────────────────────────────

/** awaiting | approved | changes_requested — plus superseded / withdrawn (displayStatus()) */
export type ClientDeliverableStatus = 'awaiting' | 'approved' | 'changes_requested' | 'superseded' | 'withdrawn';
export type ClientDecisionValue = 'approved' | 'changes_requested';

/** A frozen plan snapshot (Deliverables::planSnapshot): hidden columns and issue links removed. */
export interface ClientPlanSnapshot {
    description: string | null;
    data: import('@/components/plan/types').PlanData;
    settings: import('@/components/plan/types').PlanSettings;
}

/** ClientPortalController summary (client side). */
export interface ClientDeliverableSummary {
    uuid: string;
    kind: 'page' | 'plan';
    title: string;
    version: number;
    status: ClientDeliverableStatus;
    published_at: string | null;
    is_current: boolean;
    comments_count?: number;
}

export interface ClientPortalPublic {
    title: string;
    slug: string;
    welcome: string | null;
}

export interface ClientDecisionEntry {
    id: number;
    name: string;
    email?: string;
    decision: ClientDecisionValue;
    note: string | null;
    version: number;
    created_at: string | null;
    is_me?: boolean;
}

export interface ClientCommentEntry {
    id: number;
    author: string;
    from_team: boolean;
    /** team page only */
    visibility?: 'client' | 'internal';
    is_me?: boolean;
    body: string;
    created_at: string | null;
}

/** A version of the same doc / plan, client side (by uuid). */
export interface ClientDeliverableVersion {
    uuid: string;
    version: number;
    status: ClientDeliverableStatus;
    decision: ClientDecisionValue | 'awaiting';
    published_at: string | null;
}

/** A version of the same doc / plan, team side (by id). */
export interface ClientDeliverableTeamVersion {
    id: number;
    version: number;
    status: ClientDeliverableStatus;
    decision: ClientDecisionValue | 'awaiting';
    published_at: string | null;
}

/** ClientPortalTeamController summary (team side). */
export interface ClientDeliverableTeamSummary {
    id: number;
    uuid: string;
    kind: 'page' | 'plan';
    title: string;
    version: number;
    status: ClientDeliverableStatus;
    decision: ClientDecisionValue | 'awaiting';
    published_at: string | null;
    published_by: string | null;
    decisions_count: number | null;
    comments_count: number | null;
    last_decision_at: string | null;
}

export interface ClientPortalMemberRow {
    id: number;
    name: string | null;
    email: string | null;
    state: 'active' | 'expired' | 'revoked';
    invited_by: string | null;
    invited_at: string | null;
    expires_at: string | null;
    revoked_at: string | null;
    last_seen_at: string | null;
}

// ── GitHub App connect + merge transitions (v1.42.0, BRAI-61) ─────────────────

/** A repository of the project's GitHub App installation (ProjectGithubController::settingsFor). */
export interface GithubAvailableRepository {
    id: number;
    full_name: string;
    private: boolean;
    /** this project follows it */
    followed: boolean;
    /** another project follows it (a repository feeds one project) */
    taken: boolean;
    /** followed, but the installation no longer shares it */
    removed: boolean;
}

export interface GithubConnectionSettings {
    mode: 'webhook' | 'app';
    last_delivery_at: string | null;
    last_event: string | null;
    last_error: string | null;
    linked_issues: number;
    // mode = webhook (legacy)
    webhook_url?: string;
    secret?: string;
    repositories?: string[];
    // mode = app
    installation?: { account: string | null; account_type: string | null; suspended: boolean; removed: boolean; manage_url: string | null } | null;
    available_repositories?: GithubAvailableRepository[];
    last_refreshed_at?: string | null;
    rate_limited_until?: string | null;
}

export interface GithubBoardTransition {
    id: number;
    name: string;
    /** move the card here when its primary PR is merged; null = off */
    merge_column_id: number | null;
    columns: { id: number; name: string; is_done_column: boolean }[];
}

/** Project settings → Integrations → GitHub. */
export interface GithubSettings {
    /** the instance's GitHub App, when one is set up */
    app: { name: string; url: string; can_connect: boolean } | null;
    connection: GithubConnectionSettings | null;
    boards: GithubBoardTransition[];
}

/** Instance admin → GitHub App (Admin\GithubAppController::show). No secrets. */
export interface AdminGithubApp {
    source: 'env' | 'stored' | 'fake';
    app_id: number;
    slug: string;
    name: string | null;
    owner: string | null;
    url: string;
    can_verify_installations: boolean;
    created_at: string | null;
}

// ── Feedback portal (v1.42.0, BRAI-60) ────────────────────────────────────────
export type FeedbackStatus = 'open' | 'planned' | 'building' | 'released' | 'declined';

/** FeedbackPresenter::portal() — public */
export interface FeedbackPortalData {
    slug: string;
    title: string;
    intro: string | null;
    url: string;
    roadmap_url: string;
}

/** FeedbackPresenter::post() — public fields only (never the card, ids or emails) */
export interface FeedbackPostData {
    number: number;
    title: string;
    body: string | null;
    excerpt: string | null;
    status: FeedbackStatus;
    status_label: string;
    status_note: string | null;
    votes_count: number;
    author_name: string | null;
    created_at: string | null;
    url: string;
    is_pending: boolean;
    is_mine: boolean;
    has_voted: boolean;
    is_following: boolean;
}

/** FeedbackModerationController::presentPost() — team page */
export interface FeedbackModerationPost {
    id: number;
    number: number;
    title: string;
    body: string | null;
    moderation: 'pending' | 'approved' | 'rejected';
    status: FeedbackStatus;
    status_note: string | null;
    votes_count: number;
    author: { name: string; email: string } | null;
    created_at: string | null;
    public_url: string;
    merged_into: { number: number; title: string } | null;
    item: { key: string; title: string; url: string } | null;
}

/** FeedbackEvent (moderator audit) */
export interface FeedbackEventData {
    id: number;
    actor_name: string | null;
    action: string;
    label: string;
    post_number: number | null;
    changes: Record<string, unknown> | null;
    created_at: string | null;
}

// ── Document review (BRAI-80) ─────────────────────────────────────────────────

/** pending / approved / changes_requested are open; the rest are closed. */
export type PageReviewStatus = 'pending' | 'approved' | 'changes_requested' | 'cancelled' | 'superseded' | 'published';
export type PageReviewDecision = 'pending' | 'approved' | 'changes_requested';

/** ReviewPresenter::summary() → reviewers[] */
export interface PageReviewReviewerData {
    id: number;
    /** null when the account was deleted (the name stays on the history) */
    user_id: number | null;
    name: string;
    decision: PageReviewDecision;
    comment: string | null;
    decided_at: string | null;
    /** false: deleted or can't open the project any more — not counted in the status */
    active: boolean;
}

/** ReviewPresenter::summary() */
export interface PageReviewSummary {
    uuid: string;
    number: number;
    status: PageReviewStatus;
    /** the draft changed since the snapshot was taken */
    stale: boolean;
    content_hash: string;
    snapshot_name: string;
    message: string | null;
    requested_by: { id: number | null; name: string };
    created_at: string | null;
    closed_at: string | null;
    url: string;
    reviewers: PageReviewReviewerData[];
}

/** The `review` prop of Pages/Review. */
export interface PageReviewDetail extends PageReviewSummary {
    snapshot_html: string;
    my_decision: { decision: PageReviewDecision; comment: string | null } | null;
}

/** The `review` prop of Pages/Show (ReviewPresenter::forPage); null for folders / whiteboards. */
export interface PageReviewShowState {
    required: boolean;
    can_manage: boolean;
    can_request: boolean;
    /** sha256 of the current draft (editors only) — compared with latest.content_hash */
    draft_hash: string | null;
    latest: PageReviewSummary | null;
    /** open reviews waiting for this user's decision */
    assigned: { uuid: string; requested_by_name: string; url: string }[];
}

export interface PageReviewComment {
    uuid: string;
    author: { id: number | null; name: string };
    body: string;
    created_at: string | null;
}

/** ReviewPresenter::threads() — a root comment (comments[0]) and its replies. */
export interface PageReviewThread {
    uuid: string;
    review_uuid: string | null;
    review_number: number | null;
    /** text-quote anchor; null = page-level comment */
    quote: string | null;
    prefix: string | null;
    suffix: string | null;
    /** the quote can't be found (unambiguously) in the snapshot being viewed */
    outdated: boolean;
    resolved: boolean;
    resolved_at: string | null;
    resolved_by_name: string | null;
    comments: PageReviewComment[];
}

/** pages.reviews.candidates */
export interface PageReviewCandidate {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
}

/** Draft autosave / presence responses carry the draft hash since BRAI-80. */
export interface PageDraftHashState extends PageDraftState {
    draft_hash?: string;
}

// ── Calendar providers (BRAI-140) ──────────────────────────────────────────────
// Shape of App\Support\Calendar\CalendarProviders::catalog() and NormalizedEvent.

export interface CalendarProviderField {
    name: string;
    label: string;
    type: 'text' | 'url' | 'email' | 'password';
    help: string | null;
    placeholder: string | null;
    required: boolean;
}

export interface CalendarProviderInfo {
    key: string;
    label: string;
    badge: string;
    color: string;
    connect_mode: 'oauth' | 'form' | 'url';
    /** oauth: where "Connect" goes. */
    redirect_url: string | null;
    /** form / url: the fields of the generic connect dialog. */
    fields: CalendarProviderField[];
    capabilities: { write: boolean; tasks: boolean };
}

/** One event as every provider returns it (end_date inclusive for all-day events). */
export interface NormalizedCalendarEvent {
    id: string;
    title: string;
    description: string | null;
    location: string | null;
    all_day: boolean;
    start_date: string;
    start_time: string | null;
    end_date: string;
    end_time: string | null;
    timezone: string | null;
    external_url: string | null;
    /** Only present when the event has a video call (BRAI-147). */
    conference_url?: string;
    conference_provider?: ConferenceProvider;
}

/** The picker after connecting (calendar.connections.pending). */
export interface CalendarPickerData {
    provider: string;
    provider_label: string;
    account_email: string;
    account_name: string | null;
    calendars: { id: string; name: string; color: string | null; primary: boolean; read_only: boolean }[];
    task_lists: { id: string; title: string }[];
}

// ── Automation condition builder (BRAI-144; AutomationCatalog / AutomationConditions) ──
export type AutomationMatch = 'all' | 'any';

/** null (empty-type operators), an id / text / number, a list of ids, {days: n} or a Y-m-d date. */
export type AutomationConditionValue = number | string | number[] | { days: number } | null;

/** What an operator's value holds: none, one id, many ids, text, number, date (Y-m-d), days ({days: n}). */
export type AutomationOperatorValue = 'one' | 'many' | 'text' | 'number' | 'date' | 'days' | null;

export interface AutomationOperator {
    key: string;
    label: string;
    value: AutomationOperatorValue;
}

export interface AutomationConditionField {
    key: string;
    label: string;
    /** Where ids come from (board, helpdesk, column, item_type, priority, label, member), null = free value. */
    source: string | null;
    operators: string[];
    subjects: AutomationSubject[];
}

export interface AutomationCustomField {
    id: number;
    name: string;
    type: 'text' | 'number' | 'date' | 'checkbox' | 'select' | string;
    options: { id: number; name: string }[];
}

export interface AutomationRunConditions {
    match: AutomationMatch;
    items: { label: string; passed: boolean }[];
}

// ─── Smart links in docs and issue descriptions (BRAI-143) ───────────────────────

/** How a smart link shows in the editor. */
export type SmartLinkView = 'inline' | 'card';

/** Live data of one smart link (`smart-links.index`, state `ok`). */
export interface SmartLinkData {
    /** Canonical ref: `item:12`, `page:<uuid>`, `ticket:3`, `plan:4` */
    ref: string;
    type: ContentReferenceType;
    /** Issue / Doc / Whiteboard / Ticket / Plan */
    label: string;
    /** Issue or ticket key */
    key: string | null;
    title: string;
    url: string;
    icon?: string | null;
    status: { name: string; category: 'todo' | 'in_progress' | 'done' } | null;
    due_date: string | null;
    person: { role: string; name: string; avatar: string | null } | null;
    priority: { name: string; color: string | null } | null;
    item_type: { name: string; slug: string; icon: string | null; color: string | null } | null;
    project: { name: string; slug: string };
    updated_at: string | null;
}

/** One answer of `smart-links.index`: restricted / not found carry nothing else. */
export type SmartLinkResult = ({ state: 'ok' } & SmartLinkData) | { state: 'restricted' } | { state: 'not_found' };

/** A picker result (`smart-links.search`). */
export interface SmartLinkSearchResult extends ContentReferenceSummary {
    ref: string;
}

export interface SmartLinkSearchResponse {
    project: { slug: string; name: string };
    projects: { slug: string; name: string }[];
    results: SmartLinkSearchResult[];
}

// ── Online meetings from issues, docs and the calendar (v1.42.0, BRAI-147) ─────────

export type ConferenceProvider = 'google_meet' | 'teams' | 'other';

/** A calendar the user can schedule a meeting in (meetings.options / calendar.meetings.calendars). */
export interface MeetingCalendar {
    id: number;
    provider: string;
    provider_label: string;
    name: string;
    account_email: string;
    color: string | null;
    /** Video calls this calendar can add ([] = none, e.g. Teams on a personal Microsoft account). */
    conference_providers: Exclude<ConferenceProvider, 'other'>[];
}

export interface MeetingOptions {
    calendars: MeetingCalendar[];
    /** Project members (never their emails: the server resolves them). */
    members: { id: number; name: string; avatar: string | null }[];
    timezone: string;
    connect_url: string;
    max_attendees: number;
}

/** What a meeting is attached to: an issue (numeric id) or a doc (uuid). */
export interface MeetingSubject {
    type: 'item' | 'page';
    id: string;
    title: string;
    /** Absolute link back to the issue / doc (prefilled into the description). */
    url: string;
}

export interface Meeting {
    id: number;
    title: string;
    starts_at: string;
    ends_at: string;
    timezone: string;
    conference_provider: ConferenceProvider | null;
    conference_label: string | null;
    join_url: string | null;
    event_url: string | null;
    provider: string;
    provider_label: string;
    /** Emails only for the organiser. */
    attendees: { name: string | null; email: string | null }[];
    organizer: { id: number; name: string } | null;
    notes_page: { uuid: string; name: string; url: string } | null;
    can_remove: boolean;
    can_cancel: boolean;
    can_create_notes: boolean;
}

// ── Offline mode (BRAI-82) ─────────────────────────────────────────────────────
/** One change made on this device, queued until it reaches `offline.sync`. */
export type OfflineMutationType =
    | 'list_task.create' | 'list_task.update' | 'list_task.reorder'
    | 'issue.update' | 'issue.comment' | 'issue.create'
    | 'capture.create' | 'my_day.add' | 'my_day.remove';

export interface OfflineMutation {
    /** Client uuid = the server's idempotency key; also `target.ref` of later changes. */
    id: string;
    /** Outbox order (IndexedDB key). */
    seq?: number;
    type: OfflineMutationType;
    target?: { id?: number | null; ref?: string | null };
    /** The values the change was made against (per changed field). */
    base?: { updated_at?: string | null; fields?: Record<string, unknown> };
    data: Record<string, unknown>;
    /** What it is about: markers ("not synced yet"), the indicator and the conflicts panel. */
    entity: { kind: 'list_task' | 'issue' | 'capture' | 'my_day' | 'task_list'; key: string; label: string; parent_id?: number | null; url?: string | null };
    /** Sent at least once: never coalesced into again (the server may already have it). */
    sent?: boolean;
    created_at: string;
}

export type OfflineConflictStatus = 'conflict' | 'deleted' | 'forbidden' | 'invalid';

/** A change the server didn't take as is: shown in the Sync conflicts panel, never dropped silently. */
export interface OfflineConflict {
    id: string;
    mutation: OfflineMutation;
    status: OfflineConflictStatus;
    message: string;
    fields?: Record<string, { base: unknown; mine: unknown; theirs: unknown }>;
    entity?: Record<string, unknown> | null;
    created_at: string;
}

export interface OfflineTask {
    id: number | null;
    /** Uuid of the offline create, until the server answered with an id. */
    ref?: string | null;
    title: string;
    status: 'todo' | 'done';
    position: number;
    due_date?: string | null;
    updated_at?: string | null;
}

export interface OfflineTaskListSnapshot {
    id: number;
    name: string;
    url: string;
    workspace: { slug: string; name: string };
    project: { id: number; slug: string; name: string; key?: string | null };
    canManage: boolean;
    tasks: OfflineTask[];
}

export interface OfflineIssueSnapshot {
    id: number;
    key: string;
    title: string;
    url: string;
    board: { id: number; slug: string; name: string };
    project: { slug: string; name: string };
    workspace: { slug: string };
    column_id: number;
    priority_id: number | null;
    assigned_to: number | null;
    due_date: string | null;
    description_text: string;
    updated_at: string | null;
    columns: { id: number; name: string; is_done_column: boolean }[];
    priorities: { id: number; name: string }[];
    members: { id: number; name: string }[];
    comments: { id: number | null; ref?: string | null; author: string; text: string; created_at: string }[];
    canEdit: boolean;
}

export interface OfflineMyDayRow {
    work_type: 'board' | 'list';
    work_id: number | null;
    work_ref?: string | null;
    title: string;
    key: string;
    url: string | null;
    project_name: string;
    done: boolean;
    /** board issues: current / done / open column (the circle moves between them) */
    column_id?: number | null;
    done_column_id?: number | null;
    open_column_id?: number | null;
}

export interface OfflineMyDaySnapshot {
    date: string;
    rows: OfflineMyDayRow[];
}

export interface OfflineSnapshotRecord<T = unknown> {
    key: string;
    kind: 'list' | 'issue' | 'myday';
    data: T;
    viewedAt: number;
}

// ── Find anything (BRAI-55, v1.43.0) ─────────────────────────────────────────

/** A content type of the search index (`search_entries.type`). */
export type SearchType = 'item' | 'comment' | 'page' | 'ticket' | 'plan' | 'task' | 'snippet' | 'bookmark' | 'capture' | 'attachment' | 'ticket_attachment';

/** Filter group / tab of the search page (SearchTypes::GROUPS). */
export type SearchGroup = 'issues' | 'docs' | 'comments' | 'tickets' | 'tasks' | 'plans' | 'snippets' | 'bookmarks' | 'captures' | 'files';

export interface SearchResult {
    /** "type:id" */
    id: string;
    type: SearchType;
    group: SearchGroup;
    /** Issue, Doc, Whiteboard, Comment, Ticket, Plan, Task, Snippet, Bookmark, Capture, File */
    label: string;
    source_id: number;
    container_id: number | null;
    key: string | null;
    title: string;
    /** Escaped text; only <mark> tags around matches. */
    title_html: string;
    /** Escaped text; only <mark> tags around matches. */
    snippet_html: string;
    url: string;
    /** A bookmark's own link. */
    external_url: string | null;
    /** Where it lives, e.g. "Website · Main Board". */
    context: string | null;
    project: { id: number; name: string } | null;
    workspace: string | null;
    status: 'open' | 'done' | null;
    is_draft: boolean;
    updated_at: string | null;
}

export interface SearchResponse {
    results: SearchResult[];
    total: number;
    counts: Record<SearchGroup, number>;
    /** Typo fallback: titles similar to the words were added. */
    fuzzy: boolean;
    page: number;
    per_page: number;
    has_more: boolean;
}

export interface SearchFilters {
    q: string;
    type: SearchGroup | null;
    project: number[];
    status: 'open' | 'done' | null;
    assignee: string | null;
    updated: '7d' | '30d' | '90d' | '365d' | null;
    page: number;
}

export interface SearchOptions {
    projects: { id: number; name: string; workspace: string }[];
    people: { id: number; name: string }[];
}

// ── Live co-editing of documents (BRAI-54, v1.43.0) ─────────────────────────────────────────

/** `collab` prop of Pages/Show: null = the single-user editor. */
export interface PageCollabProps {
    epoch: number;
    /** wss URL of the realtime server, or a path on this host ("/collab"). */
    url: string;
}

/** POST pages.collab.session */
export interface PageCollabSession {
    url: string;
    /** `page.<uuid>.<epoch>` */
    document: string;
    epoch: number;
    token: string;
    expires_at: number;
    edit: boolean;
    /** State vector of the last saved draft HTML; null = nothing saved from this live document yet. */
    html_vector: Record<string, number> | null;
    user: { id: number; name: string };
}

/** PUT pages.collab.save */
export interface PageCollabSaveResponse extends PageDraftHashState {
    status: 'saved' | 'unchanged' | 'covered' | 'baseline';
}

/** POST pages.collab.bootstrap */
export type PageCollabBootstrap =
    | { status: 'granted'; html: string | null; name: string; icon: string | null }
    | { status: 'wait' }
    | { status: 'stale'; epoch: number; reset: true };

/** Presence heartbeat for editors: the live document's epoch (null = never opened live). */
export interface PagePresenceCollabState extends PageDraftState {
    collab_epoch?: number | null;
}

/** GET push-subscriptions.index — one registered browser (BRAI-154). Endpoints never reach the page. */
export interface PushDevice {
    id: number;
    /** SHA-256 hex of the endpoint: matches `endpointHash()` of this browser's subscription → "This device". */
    endpoint_hash: string;
    label: string;
    browser: string | null;
    os: string | null;
    platform: 'windows' | 'macos' | 'ios' | 'android' | 'linux' | 'chromeos' | 'other';
    /** "Chrome/Edge (Google push)", "Firefox", "Safari / iPhone", … */
    service: string;
    service_host: string;
    created_at: string | null;
    last_success_at: string | null;
    last_error: string | null;
    last_error_at: string | null;
    /** The push service answered 404 / 410: no more pushes go there. */
    expired_at: string | null;
}

/** POST push-subscriptions.test — what the push service answered. */
export interface PushTestResult {
    status: 'delivered' | 'expired' | 'rejected' | 'network' | 'unconfigured' | 'invalid';
    http_status: number | null;
    reason: string | null;
}

// ── Live channels (BRAI-151): resources/js/lib/live, docs/ai/tasks/feat-live-foundation.md ──────

/** Shared prop `live`: null = live channels are off (no socket, no token request). */
export interface LiveSharedConfig {
    /** e2e only: the client is exposed as `window.__brainitiveLive`. */
    debug: boolean;
}

/** State of one channel subscription in this tab. */
export type LiveChannelStatus = 'off' | 'connecting' | 'live' | 'offline' | 'forbidden';

/** Someone in a channel (presence). Several tabs of one person = one member. */
export interface LiveMember {
    id: number;
    name: string;
    avatar: string | null;
    /** Fixed per user (same palette as the live document carets). */
    color: string;
}

/** Who sent a signal — set by the realtime server from the sender's token, never by the sender. */
export interface LiveSender {
    id: number;
    name: string;
}

/** An event Brainitive published (`Live::publish`): ids and hints only, refetch the data. */
export interface LiveEvent<P extends Record<string, unknown> = Record<string, unknown>> {
    channel: string;
    event: string;
    payload: P;
}

/** POST live.token */
export interface LiveTokenResponse {
    url: string;
    token: string;
    expires_at: number;
    channels: string[];
    denied: string[];
    user: { id: number; name: string; avatar: string | null };
}

// ── Live whiteboards (BRAI-153, v1.44.0) ────────────────────────────────────────────────────

/** `whiteboardCollab` prop of Pages/Show: the preview watches the live whiteboard; null = static. */
export interface WhiteboardCollabProps {
    epoch: number;
    /** wss URL of the realtime server, or a path on this host ("/collab"). */
    url: string;
}

// ── Legal pages + public-page operator (BRAI-156, v1.44.0) ──────────────────────────────────

/** Shared prop `legalLinks` (LegalInfo::links): null = that document doesn't exist on this install. */
export interface LegalLinks {
    cloud: boolean;
    privacy: string;
    terms: string;
    cookies: string;
    imprint: string | null;
    subprocessors: string | null;
    dpa: string | null;
    refund: string | null;
}

/** `legal` prop of the legal pages (LegalInfo::pageProps). Empty operator values are null. */
export interface LegalPageData extends LegalLinks {
    document: { key: string; version: string; updated: string };
    operator: {
        name: string | null;
        description: string | null;
        address: string | null;
        email: string | null;
        phone: string | null;
        representative: string | null;
        register: string | null;
        vat_id: string | null;
        content_responsible: string | null;
        dpo: string | null;
        eu_representative: string | null;
        supervisory_authority: string | null;
        governing_law: string | null;
        jurisdiction: string | null;
    };
    providers: {
        hosting: string | null;
        hosting_location: string | null;
        email: string | null;
        email_location: string | null;
        backup: string | null;
        backup_location: string | null;
    };
    external: { privacy_url: string | null; terms_url: string | null; imprint_url: string | null };
    retention: { server_logs: string | null; backups: string | null; analytics: string | null };
    app_name: string;
    /** the session cookie's name (config session.cookie) */
    session_cookie: string;
    /** the operator's Umami runs on this install (Brainitive Cloud) */
    analytics: boolean;
    host: string | null;
}

/** Who runs a customer's public page (PublicOperator::for): the workspace, not the platform. */
export interface PublicOperatorData {
    name: string;
    /** false = no legal name set and the page mustn't reveal the workspace name: `name` is generic */
    named: boolean;
    privacy_url: string | null;
    imprint_url: string | null;
    configured: boolean;
    platform_name: string;
    platform_privacy_url: string;
}

/** Workspace settings → Legal (workspaces.legal_* columns). */
export interface WorkspaceLegalFields {
    legal_controller_name?: string | null;
    legal_privacy_url?: string | null;
    legal_imprint_url?: string | null;
}

/** "Download my data" (BRAI-156): DataExport::present() — status like ProjectExportData */
export interface DataExportData {
    uuid: string;
    status: ProjectExportStatus;
    file_size: number;
    counts: Record<string, number> | null;
    error: string | null;
    created_at: string | null;
    finished_at: string | null;
    expires_at: string | null;
    /** signed, only for the account owner, until expires_at */
    download_url: string | null;
}

/** GET/POST profile.data-export.index|store */
export interface DataExportState {
    exports: DataExportData[];
    /** seconds until a new request is allowed (0 = now) */
    available_in: number;
}

// ── Booking pages (v1.45.0, BRAI-158) ─────────────────────────────────────

export type BookingLocationKind = 'none' | 'google_meet' | 'teams' | 'phone' | 'in_person' | 'link';

export type BookingDay = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';

/** [start, end] as "HH:MM" wall-clock in the page's timezone ("24:00" = midnight) */
export type BookingRange = [string, string];

export interface BookingAvailability {
    weekly: Partial<Record<BookingDay, BookingRange[]>>;
    /** 'Y-m-d' → ranges; [] = day off */
    overrides: Record<string, BookingRange[]>;
}

export interface BookingRulesData {
    step_minutes: number;
    buffer_before: number;
    buffer_after: number;
    min_notice_minutes: number;
    horizon_days: number;
    daily_max: number | null;
}

export interface BookingQuestion {
    id: string;
    label: string;
    type: 'text' | 'textarea' | 'phone' | 'choice';
    required: boolean;
    options: string[];
}

export interface BookingTypeData {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    duration_minutes: number;
    location: { kind: BookingLocationKind; value: string | null };
    availability: BookingAvailability;
    rules: BookingRulesData;
    target_connection_id: number | null;
    questions: BookingQuestion[];
    enabled: boolean;
    position: number;
}

export interface BookingBusySources {
    connections: 'all' | number[];
    issues: boolean;
    tasks: boolean;
    all_day_blocks: boolean;
}

export interface BookingPageData {
    uuid: string;
    title: string;
    intro: string | null;
    timezone: string;
    enabled: boolean;
    public_slug: string;
    public_url: string;
    embed_url: string;
    workspace: string;
    busy_sources: BookingBusySources;
    card_board_id: number | null;
    card_column_id: number | null;
    consent: 'off' | 'optional' | 'required';
    consent_text: string | null;
}

export interface BookingPageListItem {
    uuid: string;
    title: string;
    enabled: boolean;
    public_url: string;
    workspace: string;
    types_count: number;
    upcoming_count: number;
}

export interface BookingTemplateData {
    key: string;
    name: string;
    description: string;
    /** "Intro call · 15 min" */
    types: string[];
}

export interface BookingConnectionData {
    id: number;
    name: string;
    account: string | null;
    provider: string;
    writable: boolean;
    conference_providers: ('google_meet' | 'teams')[];
    sync_error: string | null;
    blocks: boolean;
}

export interface BookingBoardData {
    id: number;
    name: string;
    project: string | null;
    columns: { id: number; name: string }[];
}

export interface BookingRow {
    uuid: string;
    type: string | null;
    starts_at: string;
    ends_at: string;
    guest_name: string;
    guest_email: string;
    guest_timezone: string | null;
    answers: [string, string][];
    status: 'confirmed' | 'cancelled';
    cancelled_by: 'host' | 'guest' | null;
    cancel_reason: string | null;
    conference_url: string | null;
    in_calendar: boolean;
    /** calendars that couldn't be checked when it was booked */
    unchecked: string[];
    card: { key: string; url: string } | null;
}

/** What the public page knows about a booking page (never anything about the host's events). */
export interface PublicBookingPageData {
    slug: string;
    title: string;
    intro: string | null;
    timezone: string;
    host: { name: string; avatar: string | null };
    consent: 'off' | 'optional' | 'required';
    consent_text: string | null;
    types: {
        slug: string;
        name: string;
        description: string | null;
        duration_minutes: number;
        location_kind: BookingLocationKind;
        location: string | null;
        questions: BookingQuestion[];
        horizon_days: number;
    }[];
}

export interface PublicBookingData {
    type: string;
    starts_at: string;
    ends_at: string;
    guest_name: string;
    guest_email: string;
    status: 'confirmed' | 'cancelled';
    location: string | null;
    conference_url: string | null;
}

/** Cloud plan limits (App\\Support\\Entitlements, v1.46.0). `null` = unlimited. */
export interface PlanLimits {
    plan: string;
    free: boolean;
    members: number | null;
    projects: number | null;
    contentBytes: number | null;
    fileBytes: number | null;
    versions: { keepLatest: number; keepDays: number | null };
    graceUntil: string | null;
}

export interface WorkspaceEntitlements {
    /** False until paid plans launch: limits are shown, nothing is blocked. */
    enforced: boolean;
    limits: PlanLimits;
    usage: { members: number; projects: number; contentBytes: number; fileBytes: number; measuredAt: string | null };
    /** "Plan & billing" page (brainitive/cloud); null hides every billing link. */
    billingUrl: string | null;
}

export type LimitKind = 'members' | 'projects' | 'workspaces' | 'content' | 'files';

/** LimitReached::payload() — the 402 JSON `limit_reached` and the `flash.limit_reached` prop. */
export interface LimitReachedPayload {
    kind: LimitKind;
    limit: number;
    usage: number;
    plan: string;
    workspace: string | null;
    billingUrl: string | null;
    message: string;
}
