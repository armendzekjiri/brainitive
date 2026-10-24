import { Link, router, usePage, useForm } from '@inertiajs/react';
import axios from 'axios';
import { Toaster } from 'sonner';
import React, { PropsWithChildren, useState, useRef, FormEvent, useEffect, useCallback, createContext, useContext } from 'react';
import { PageProps, Workspace, Project, Board, TaskList, Page as PageType, ItemType, ItemPriority, User as UserType, BoardColumn, Helpdesk, PlanSummary } from '@/types';
import { cn } from '@/lib/utils';
import { CommandPalette, CmdIcon, PaletteCommand, useRecentTracking } from '@/components/CommandPalette';
import { useTimezoneSync } from '@/hooks/useTimezoneSync';
import WorkTimerPill from '@/components/WorkTimerPill';
import { QuickCreateSnippetModal } from '@/components/QuickCreateSnippetModal';
import { QuickCreateBookmarkModal } from '@/components/QuickCreateBookmarkModal';
import { GlobalStickyNotes } from '@/components/GlobalStickyNotes';
import { PushPrompt } from '@/components/PushPrompt';
import { SidebarResizer, getInitialSidebarWidth } from '@/components/SidebarResizer';
import { HelpModal } from '@/components/HelpModal';
import { NotificationsDropdown } from '@/components/NotificationsDropdown';
import { ProjectIcon } from '@/components/ProjectIcon';
import ApplicationLogo from '@/Components/ApplicationLogo';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuItem,
    SidebarProvider,
    SidebarTrigger,
} from '@/components/ui/sidebar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Separator } from '@/components/ui/separator';
import {
    ChevronRight,
    ChevronsUpDown,
    Home,
    LayoutGrid,
    LogOut,
    Plus,
    Search,
    Settings,
    User,
    Bell,
    HelpCircle,
    FolderKanban,
    Check,
    CheckSquare,
    FileText,
    Folder,
    Kanban,
    List,
    ListTodo,
    Lightbulb,
    Clock,
    ReceiptText,
    Bookmark as BookmarkIcon,
    Code2,
    PencilRuler,
    StickyNote,
    GripVertical,
    Headphones,
    LifeBuoy,
    Magnet,
    Moon,
    Sun,
    CalendarDays,
    GanttChart,
    ShieldCheck,
    Rocket,
    HeartPulse,
    Gauge,
} from 'lucide-react';
import { useTheme } from '@/hooks/useTheme';
import { ThemeMenu } from '@/components/ThemeMenu';
import { WelcomeModal } from '@/components/Onboarding/WelcomeModal';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { flattenPagesForParentSelect } from '@/lib/pageTree';
import { GlobalCreateModal } from '@/components/GlobalCreateModal';
import CreateProjectDialog, { openCreateProject } from '@/components/projects/CreateProjectDialog';
import { QuickCaptureDialog, QuickCaptureFab, openQuickCapture } from '@/components/captures/QuickCaptureDialog';
import HelpdeskTemplatePicker from '@/components/helpdesk/HelpdeskTemplatePicker';
import LeadFormTemplatePicker from '@/components/leadForms/LeadFormTemplatePicker';

interface Props extends PropsWithChildren {
    title?: string;
}

// Context for global create modal
export type GlobalCreateType = 'task' | 'page' | 'whiteboard' | 'folder';

interface GlobalCreateModalContextType {
    openCreateTaskModal: (projectId?: number, boardId?: number, columnId?: number, type?: GlobalCreateType) => void;
    closeCreateTaskModal: () => void;
}

const GlobalCreateModalContext = createContext<GlobalCreateModalContextType | null>(null);

export function useGlobalCreateModal() {
    const context = useContext(GlobalCreateModalContext);
    if (!context) {
        throw new Error('useGlobalCreateModal must be used within a GlobalCreateModalProvider');
    }
    return context;
}

// Hook to persist expanded state in localStorage
function useExpandedState(key: string, defaultValue: boolean = false): [boolean, (value: boolean) => void] {
    const storageKey = `sidebar-expanded-${key}`;
    const [isOpen, setIsOpen] = useState<boolean>(() => {
        if (typeof window === 'undefined') return defaultValue;
        const saved = localStorage.getItem(storageKey);
        return saved !== null ? saved === 'true' : defaultValue;
    });

    const setIsOpenPersisted = useCallback((value: boolean) => {
        setIsOpen(value);
        localStorage.setItem(storageKey, String(value));
    }, [storageKey]);

    return [isOpen, setIsOpenPersisted];
}

// Check if a page (or any descendant) has the given uuid
function pageContainsUuid(page: PageType, uuid: string): boolean {
    if (page.uuid === uuid) return true;
    if (page.children && page.children.length > 0) {
        return page.children.some((c) => pageContainsUuid(c, uuid));
    }
    return false;
}

// Check if project's page tree contains the given page uuid
function projectContainsPageUuid(project: Project & { pages?: PageType[] }, uuid: string): boolean {
    if (!project.pages || project.pages.length === 0) return false;
    return project.pages.some((p) => pageContainsUuid(p, uuid));
}

// Workspace icon: logo image when available, otherwise first letter
function WorkspaceIcon({ name, logoUrl, size = 'md' }: { name: string; logoUrl?: string | null; size?: 'sm' | 'md' | 'lg' }) {
    const sizeClasses = {
        sm: 'size-6 text-xs',
        md: 'size-8 text-sm',
        lg: 'size-10 text-base',
    };

    const colors = [
        'bg-brand-blue',
        'bg-brand-slate',
        'bg-brand-green',
        'bg-primary',
        'bg-brand-blue',
        'bg-brand-slate',
        'bg-brand-green',
        'bg-primary',
    ];
    const colorIndex = name.charCodeAt(0) % colors.length;

    if (logoUrl) {
        return (
            <img
                src={logoUrl}
                alt=""
                className={`${sizeClasses[size]} rounded-md object-cover shrink-0`}
            />
        );
    }

    return (
        <div className={`${sizeClasses[size]} ${colors[colorIndex]} rounded-md flex items-center justify-center text-white font-semibold shrink-0`}>
            {name.charAt(0).toUpperCase()}
        </div>
    );
}

function WorkspaceSwitcher({ workspaces, currentWorkspace }: { workspaces: Workspace[]; currentWorkspace?: Workspace }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button className="flex items-center gap-3 w-full px-3 py-2.5 hover:bg-accent rounded-lg transition-colors text-left">
                    {currentWorkspace ? (
                        <WorkspaceIcon name={currentWorkspace.name} logoUrl={currentWorkspace.logo_display} size="md" />
                    ) : (
                        <div className="size-8 rounded-md bg-muted flex items-center justify-center">
                            <FolderKanban className="size-4 text-muted-foreground" />
                        </div>
                    )}
                    <div className="flex-1 min-w-0">
                        <p className="text-sm font-semibold truncate">
                            {currentWorkspace?.name || 'Select Workspace'}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {currentWorkspace ? 'Free plan' : 'No workspace'}
                        </p>
                    </div>
                    <ChevronsUpDown className="size-4 text-muted-foreground shrink-0" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                className="w-72 p-2"
                align="start"
                side="bottom"
                sideOffset={4}
            >
                <DropdownMenuLabel className="text-xs font-medium text-muted-foreground px-2 py-1.5">
                    Your workspaces
                </DropdownMenuLabel>
                {workspaces.map((workspace) => (
                    <DropdownMenuItem
                        key={workspace.id}
                        onClick={() => router.visit(route('workspaces.show', workspace.slug))}
                        className="flex items-center gap-3 p-2 cursor-pointer rounded-md"
                    >
                        <WorkspaceIcon name={workspace.name} logoUrl={workspace.logo_display} size="sm" />
                        <span className="flex-1 text-sm font-medium truncate">{workspace.name}</span>
                        {currentWorkspace?.id === workspace.id && (
                            <Check className="size-4 text-primary" />
                        )}
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator className="my-2" />
                <DropdownMenuItem
                    onClick={() => router.visit(route('workspaces.index'))}
                    className="flex items-center gap-3 p-2 cursor-pointer rounded-md"
                >
                    <div className="size-6 rounded-md bg-muted flex items-center justify-center">
                        <FolderKanban className="size-3.5 text-muted-foreground" />
                    </div>
                    <span className="text-sm font-medium">All Workspaces</span>
                </DropdownMenuItem>
                <DropdownMenuItem
                    onClick={() => router.visit(route('workspaces.create'))}
                    className="flex items-center gap-3 p-2 cursor-pointer rounded-md text-primary"
                >
                    <div className="size-6 rounded-md border-2 border-dashed border-primary/50 flex items-center justify-center">
                        <Plus className="size-3.5" />
                    </div>
                    <span className="text-sm font-medium">Create workspace</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

// Project Switcher (same UX pattern as WorkspaceSwitcher)
function ProjectSwitcher({
    projects,
    currentProject,
    onSelect,
    workspace,
}: {
    projects: (Project & { boards: Board[]; pages?: PageType[]; helpdesks?: Helpdesk[] })[];
    currentProject?: Project & { boards: Board[]; pages?: PageType[]; helpdesks?: Helpdesk[] };
    onSelect: (project: Project & { boards: Board[]; pages?: PageType[]; helpdesks?: Helpdesk[] }) => void;
    workspace: Workspace;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button className="flex items-center gap-3 w-full px-3 py-2.5 hover:bg-accent rounded-lg transition-colors text-left">
                    {currentProject ? (
                        <ProjectIcon project={currentProject} size="md" />
                    ) : (
                        <div className="size-8 rounded-md bg-muted flex items-center justify-center">
                            <FolderKanban className="size-4 text-muted-foreground" />
                        </div>
                    )}
                    <div className="flex-1 min-w-0">
                        <p className="text-xs text-muted-foreground uppercase tracking-wider font-semibold">Project</p>
                        <p className="text-sm font-semibold truncate">
                            {currentProject?.name || 'Select Project'}
                        </p>
                    </div>
                    <ChevronsUpDown className="size-4 text-muted-foreground shrink-0" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                className="w-72 p-2"
                align="start"
                side="bottom"
                sideOffset={4}
            >
                <DropdownMenuLabel className="text-xs font-medium text-muted-foreground px-2 py-1.5">
                    Your projects
                </DropdownMenuLabel>
                {projects.map((project) => (
                    <DropdownMenuItem
                        key={project.id}
                        onClick={() => onSelect(project)}
                        className="flex items-center gap-3 p-2 cursor-pointer rounded-md"
                    >
                        <ProjectIcon project={project} size="sm" />
                        <span className="flex-1 text-sm font-medium truncate">{project.name}</span>
                        {currentProject?.id === project.id && (
                            <Check className="size-4 text-primary" />
                        )}
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator className="my-2" />
                <DropdownMenuItem
                    onClick={() => openCreateProject()}
                    className="flex items-center gap-3 p-2 cursor-pointer rounded-md text-primary"
                >
                    <div className="size-6 rounded-md border-2 border-dashed border-primary/50 flex items-center justify-center">
                        <Plus className="size-3.5" />
                    </div>
                    <span className="text-sm font-medium">Create project</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

// Single item wrapper: drag only starts when grabbing the grip handle.
// This prevents the drag from accidentally collapsing/expanding the collapsible.
function DraggableItem({
    children,
    onDragStart,
    onDragEnter,
    onDragEnd,
}: {
    children: React.ReactNode;
    onDragStart: () => void;
    onDragEnter: () => void;
    onDragEnd: () => void;
}) {
    const divRef = useRef<HTMLDivElement>(null);
    const enableDrag = () => { if (divRef.current) divRef.current.draggable = true; };
    const disableDrag = () => { if (divRef.current) divRef.current.draggable = false; };

    return (
        <div
            ref={divRef}
            className="relative group/drag"
            onDragStart={(e) => { e.stopPropagation(); onDragStart(); }}
            onDragEnter={(e) => { e.preventDefault(); e.stopPropagation(); onDragEnter(); }}
            onDragEnd={() => { disableDrag(); onDragEnd(); }}
            onDragOver={(e) => e.preventDefault()}
        >
            {/* Grip handle - mousedown enables drag on this item only */}
            <div
                className="absolute left-0.5 top-1/2 -translate-y-1/2 opacity-0 group-hover/drag:opacity-100 cursor-grab z-10 select-none"
                onMouseDown={enableDrag}
                onMouseUp={disableDrag}
            >
                <GripVertical className="size-3 text-muted-foreground/40" />
            </div>
            {children}
        </div>
    );
}

// Draggable group of sidebar items with DB-persisted reorder
function DraggableSidebarGroup({
    items,
    workspace,
    project,
    level = 0,
    onAddItem,
    currentPageUuid,
}: {
    items: PageType[];
    workspace: Workspace;
    project: Project;
    level?: number;
    onAddItem: (parentId: number) => void;
    currentPageUuid?: string | null;
}) {
    const [orderedItems, setOrderedItems] = useState<PageType[]>(items);
    const dragIndexRef = useRef<number | null>(null);

    // Sync when Inertia provides updated items after navigation/mutations
    const itemKey = items.map((i) => i.id).join(',');
    useEffect(() => {
        setOrderedItems(items);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [itemKey]);

    const handleDragStart = (index: number) => {
        dragIndexRef.current = index;
    };

    const handleDragEnter = (index: number) => {
        if (dragIndexRef.current === null || dragIndexRef.current === index) return;
        const newItems = [...orderedItems];
        const [dragged] = newItems.splice(dragIndexRef.current, 1);
        newItems.splice(index, 0, dragged);
        dragIndexRef.current = index;
        setOrderedItems(newItems);
    };

    const handleDragEnd = () => {
        if (dragIndexRef.current === null) return;
        dragIndexRef.current = null;
        const pagesData = orderedItems.map((page, idx) => ({
            id: page.id,
            position: idx,
            parent_id: page.parent_id ?? null,
        }));
        axios.post(route('pages.reorder', [workspace.slug, project.slug]), { pages: pagesData });
    };

    return (
        <>
            {orderedItems.map((page, index) => (
                <DraggableItem
                    key={page.id}
                    onDragStart={() => handleDragStart(index)}
                    onDragEnter={() => handleDragEnter(index)}
                    onDragEnd={handleDragEnd}
                >
                    <PageTreeItem
                        page={page}
                        workspace={workspace}
                        project={project}
                        level={level}
                        onAddItem={onAddItem}
                        currentPageUuid={currentPageUuid}
                    />
                </DraggableItem>
            ))}
        </>
    );
}

function ProjectContent({
    project,
    workspace,
    onAddItem,
    currentPageUuid,
}: {
    project: Project & { boards: Board[]; task_lists?: TaskList[]; plans?: PlanSummary[]; pages?: PageType[]; helpdesks?: Helpdesk[] };
    workspace: Workspace;
    onAddItem: (projectId: number, parentId?: number) => void;
    currentPageUuid?: string | null;
}) {
    const hasContent = (project.boards && project.boards.length > 0) || (project.task_lists && project.task_lists.length > 0) || (project.plans && project.plans.length > 0) || (project.pages && project.pages.length > 0) || (project.helpdesks && project.helpdesks.length > 0) || (project.lead_forms?.length ?? 0) > 0;
    const [boardsOpen, setBoardsOpen] = useExpandedState(`project-${project.id}-boards`, true);
    const [listsOpen, setListsOpen] = useExpandedState(`project-${project.id}-lists`, true);
    const [plansOpen, setPlansOpen] = useExpandedState(`project-${project.id}-plans`, true);
    const [pagesOpen, setPagesOpen] = useExpandedState(`project-${project.id}-pages`, true);
    const [whiteboardsOpen, setWhiteboardsOpen] = useExpandedState(`project-${project.id}-whiteboards`, true);
    const [foldersOpen, setFoldersOpen] = useExpandedState(`project-${project.id}-folders`, true);
    const [helpdesksOpen, setHelpdesksOpen] = useExpandedState(`project-${project.id}-helpdesks`, true);
    const [leadFormsOpen, setLeadFormsOpen] = useExpandedState(`project-${project.id}-lead-forms`, true);

    if (!hasContent) {
        return (
            <div className="px-2 py-6 text-center">
                <p className="text-xs text-muted-foreground mb-3">No boards or pages yet</p>
                <Button
                    size="sm"
                    variant="outline"
                    className="w-full"
                    onClick={() => onAddItem(project.id)}
                >
                    <Plus className="size-4 mr-1.5" />
                    Add content
                </Button>
            </div>
        );
    }

    const rootDocs = project.pages?.filter((p) => p.type === 'page' && !p.is_whiteboard) || [];
    const rootWhiteboards = project.pages?.filter((p) => p.type === 'page' && p.is_whiteboard) || [];
    const rootFolders = project.pages?.filter((p) => p.type === 'folder') || [];

    return (
        <SidebarMenu className="space-y-0.5">
            {/* Boards */}
            {project.boards?.length > 0 && (
                <>
                    <button
                        onClick={() => setBoardsOpen(!boardsOpen)}
                        className="flex items-center gap-1.5 px-2 py-1 w-full hover:bg-accent/50 rounded-md transition-colors"
                    >
                        <ChevronRight className={`size-3 shrink-0 text-muted-foreground/60 transition-transform ${boardsOpen ? 'rotate-90' : ''}`} />
                        <span className="text-xs font-medium text-muted-foreground uppercase tracking-wider flex-1 text-left">Boards</span>
                    </button>
                    {boardsOpen && project.boards.map((board) => (
                        <SidebarMenuItem key={board.id} className="pl-5">
                            <Link
                                href={route('boards.show', [workspace.slug, project.slug, board.slug])}
                                className="flex min-w-0 items-center gap-2.5 px-2 py-1.5 hover:bg-accent rounded-md transition-colors text-sm text-muted-foreground hover:text-foreground"
                            >
                                <LayoutGrid className="size-4 shrink-0" />
                                <span className="min-w-0 truncate">{board.name}</span>
                            </Link>
                        </SidebarMenuItem>
                    ))}
                    {boardsOpen && (
                        <SidebarMenuItem className="pl-5">
                            <Link
                                href={route('releases.index', [workspace.slug, project.slug])}
                                className="flex min-w-0 items-center gap-2.5 px-2 py-1.5 hover:bg-accent rounded-md transition-colors text-sm text-muted-foreground hover:text-foreground"
                            >
                                <Rocket className="size-4 shrink-0" />
                                <span className="min-w-0 truncate">Releases</span>
                            </Link>
                        </SidebarMenuItem>
                    )}
                    {boardsOpen && (
                        <SidebarMenuItem className="pl-5">
                            <Link
                                href={route('projects.health', [workspace.slug, project.slug])}
                                className="flex min-w-0 items-center gap-2.5 px-2 py-1.5 hover:bg-accent rounded-md transition-colors text-sm text-muted-foreground hover:text-foreground"
                            >
                                <HeartPulse className="size-4 shrink-0" />
                                <span className="min-w-0 truncate">Health</span>
                            </Link>
                        </SidebarMenuItem>
                    )}
                    {boardsOpen && (
                        <SidebarMenuItem className="pl-5">
                            <Link
                                href={route('workload.index', [workspace.slug, project.slug])}
                                className="flex min-w-0 items-center gap-2.5 px-2 py-1.5 hover:bg-accent rounded-md transition-colors text-sm text-muted-foreground hover:text-foreground"
                            >
                                <Gauge className="size-4 shrink-0" />
                                <span className="min-w-0 truncate">Workload</span>
                            </Link>
                        </SidebarMenuItem>
                    )}
                </>
            )}

            {/* Lists */}
            {project.task_lists && project.task_lists.length > 0 && (
                <>
                    {(project.boards?.length ?? 0) > 0 && <div className="border-t border-border/50 my-1.5" />}
                    <button
                        onClick={() => setListsOpen(!listsOpen)}
                        className="flex items-center gap-1.5 px-2 py-1 w-full hover:bg-accent/50 rounded-md transition-colors"
                    >
                        <ChevronRight className={`size-3 shrink-0 text-muted-foreground/60 transition-transform ${listsOpen ? 'rotate-90' : ''}`} />
                        <span className="text-xs font-medium text-muted-foreground uppercase tracking-wider flex-1 text-left">Lists</span>
                    </button>
                    {listsOpen && project.task_lists.map((tl) => (
                        <SidebarMenuItem key={tl.id} className="pl-5">
                            <Link
                                href={route('task-lists.show', [workspace.slug, project.slug, tl.slug])}
                                className="flex min-w-0 items-center gap-2.5 px-2 py-1.5 hover:bg-accent rounded-md transition-colors text-sm text-muted-foreground hover:text-foreground"
                            >
                                <List className="size-4 shrink-0" />
                                <span className="min-w-0 truncate">{tl.name}</span>
                            </Link>
                        </SidebarMenuItem>
                    ))}
                </>
            )}

            {/* Plans */}
            {project.plans && project.plans.length > 0 && (
                <>
                    {((project.boards?.length ?? 0) > 0 || (project.task_lists?.length ?? 0) > 0) && <div className="border-t border-border/50 my-1.5" />}
                    <button
                        onClick={() => setPlansOpen(!plansOpen)}
                        className="flex items-center gap-1.5 px-2 py-1 w-full hover:bg-accent/50 rounded-md transition-colors"
                    >
                        <ChevronRight className={`size-3 shrink-0 text-muted-foreground/60 transition-transform ${plansOpen ? 'rotate-90' : ''}`} />
                        <span className="text-xs font-medium text-muted-foreground uppercase tracking-wider flex-1 text-left">Plans</span>
                    </button>
                    {plansOpen && project.plans.map((plan) => (
                        <SidebarMenuItem key={plan.id} className="pl-5">
                            <Link
                                href={route('plans.show', [workspace.slug, project.slug, plan.slug])}
                                className="flex min-w-0 items-center gap-2.5 px-2 py-1.5 hover:bg-accent rounded-md transition-colors text-sm text-muted-foreground hover:text-foreground"
                            >
                                <GanttChart className="size-4 shrink-0" />
                                <span className="min-w-0 truncate">{plan.name}</span>
                            </Link>
                        </SidebarMenuItem>
                    ))}
                </>
            )}

            {/* Helpdesk */}
            {project.helpdesks && project.helpdesks.length > 0 && (
                <>
                    {((project.boards?.length ?? 0) > 0 || (project.task_lists?.length ?? 0) > 0 || (project.plans?.length ?? 0) > 0) && <div className="border-t border-border/50 my-1.5" />}
                    <button
                        onClick={() => setHelpdesksOpen(!helpdesksOpen)}
                        className="flex items-center gap-1.5 px-2 py-1 w-full hover:bg-accent/50 rounded-md transition-colors"
                    >
                        <ChevronRight className={`size-3 shrink-0 text-muted-foreground/60 transition-transform ${helpdesksOpen ? 'rotate-90' : ''}`} />
                        <span className="text-xs font-medium text-muted-foreground uppercase tracking-wider flex-1 text-left">Helpdesk</span>
                    </button>
                    {helpdesksOpen && project.helpdesks.map((helpdesk) => (
                        <SidebarMenuItem key={helpdesk.id} className="pl-5">
                            <Link
                                href={route('helpdesks.show', [workspace.slug, project.slug, helpdesk.slug])}
                                className="flex min-w-0 items-center gap-2.5 px-2 py-1.5 hover:bg-accent rounded-md transition-colors text-sm text-muted-foreground hover:text-foreground"
                            >
                                <LifeBuoy className="size-4 shrink-0" />
                                <span className="min-w-0 truncate">{helpdesk.name}</span>
                            </Link>
                        </SidebarMenuItem>
                    ))}
                </>
            )}

            {/* Lead forms (BRAI-93) */}
            {(project.lead_forms?.length ?? 0) > 0 && (
                <>
                    {((project.boards?.length ?? 0) > 0 || (project.task_lists?.length ?? 0) > 0 || (project.plans?.length ?? 0) > 0 || (project.helpdesks?.length ?? 0) > 0) && <div className="border-t border-border/50 my-1.5" />}
                    <button
                        onClick={() => setLeadFormsOpen(!leadFormsOpen)}
                        className="flex items-center gap-1.5 px-2 py-1 w-full hover:bg-accent/50 rounded-md transition-colors"
                    >
                        <ChevronRight className={`size-3 shrink-0 text-muted-foreground/60 transition-transform ${leadFormsOpen ? 'rotate-90' : ''}`} />
                        <span className="text-xs font-medium text-muted-foreground uppercase tracking-wider flex-1 text-left">Lead forms</span>
                    </button>
                    {leadFormsOpen && project.lead_forms!.map((leadForm) => (
                        <SidebarMenuItem key={leadForm.id} className="pl-5">
                            <Link
                                href={route('lead-forms.show', [workspace.slug, project.slug, leadForm.slug])}
                                className="flex min-w-0 items-center gap-2.5 px-2 py-1.5 hover:bg-accent rounded-md transition-colors text-sm text-muted-foreground hover:text-foreground"
                            >
                                <Magnet className="size-4 shrink-0" />
                                <span className="min-w-0 flex-1 truncate">{leadForm.name}</span>
                                {leadForm.status === 'published' && <span className="size-1.5 shrink-0 rounded-full bg-emerald-500" title="Live" />}
                            </Link>
                        </SidebarMenuItem>
                    ))}
                </>
            )}

            {/* Pages */}
            {rootDocs.length > 0 && (
                <>
                    {((project.boards?.length ?? 0) > 0 || (project.helpdesks?.length ?? 0) > 0 || (project.lead_forms?.length ?? 0) > 0) && <div className="border-t border-border/50 my-1.5" />}
                    <button
                        onClick={() => setPagesOpen(!pagesOpen)}
                        className="flex items-center gap-1.5 px-2 py-1 w-full hover:bg-accent/50 rounded-md transition-colors"
                    >
                        <ChevronRight className={`size-3 shrink-0 text-muted-foreground/60 transition-transform ${pagesOpen ? 'rotate-90' : ''}`} />
                        <span className="text-xs font-medium text-muted-foreground uppercase tracking-wider flex-1 text-left">Pages</span>
                    </button>
                    {pagesOpen && (
                        <DraggableSidebarGroup
                            items={rootDocs}
                            workspace={workspace}
                            project={project}
                            onAddItem={(parentId) => onAddItem(project.id, parentId)}
                            currentPageUuid={currentPageUuid}
                        />
                    )}
                </>
            )}

            {/* Whiteboards */}
            {rootWhiteboards.length > 0 && (
                <>
                    {(project.boards?.length > 0 || rootDocs.length > 0) && <div className="border-t border-border/50 my-1.5" />}
                    <button
                        onClick={() => setWhiteboardsOpen(!whiteboardsOpen)}
                        className="flex items-center gap-1.5 px-2 py-1 w-full hover:bg-accent/50 rounded-md transition-colors"
                    >
                        <ChevronRight className={`size-3 shrink-0 text-muted-foreground/60 transition-transform ${whiteboardsOpen ? 'rotate-90' : ''}`} />
                        <span className="text-xs font-medium text-muted-foreground uppercase tracking-wider flex-1 text-left">Whiteboards</span>
                    </button>
                    {whiteboardsOpen && (
                        <DraggableSidebarGroup
                            items={rootWhiteboards}
                            workspace={workspace}
                            project={project}
                            onAddItem={(parentId) => onAddItem(project.id, parentId)}
                            currentPageUuid={currentPageUuid}
                        />
                    )}
                </>
            )}

            {/* Folders */}
            {rootFolders.length > 0 && (
                <>
                    {(project.boards?.length > 0 || rootDocs.length > 0 || rootWhiteboards.length > 0) && (
                        <div className="border-t border-border/50 my-1.5" />
                    )}
                    <button
                        onClick={() => setFoldersOpen(!foldersOpen)}
                        className="flex items-center gap-1.5 px-2 py-1 w-full hover:bg-accent/50 rounded-md transition-colors"
                    >
                        <ChevronRight className={`size-3 shrink-0 text-muted-foreground/60 transition-transform ${foldersOpen ? 'rotate-90' : ''}`} />
                        <span className="text-xs font-medium text-muted-foreground uppercase tracking-wider flex-1 text-left">Folders</span>
                    </button>
                    {foldersOpen && (
                        <DraggableSidebarGroup
                            items={rootFolders}
                            workspace={workspace}
                            project={project}
                            onAddItem={(parentId) => onAddItem(project.id, parentId)}
                            currentPageUuid={currentPageUuid}
                        />
                    )}
                </>
            )}

            {/* Add content button */}
            <SidebarMenuItem>
                <button
                    onClick={() => onAddItem(project.id)}
                    className="flex min-w-0 items-center gap-2.5 px-2 py-1.5 hover:bg-accent rounded-md transition-colors text-sm text-muted-foreground hover:text-foreground w-full text-left mt-1"
                >
                    <Plus className="size-4 shrink-0" />
                    <span className="min-w-0 truncate">Add content</span>
                </button>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}

// Create Item Modal (Page, Board, Folder)
interface CreateItemModalProps {
    open: boolean;
    onClose: () => void;
    workspace: Workspace;
    project: Project;
    parentId?: number;
    parentType?: 'project' | 'folder';
}

function CreateItemModal({ open, onClose, workspace, project, parentId, parentType = 'project' }: CreateItemModalProps) {
    const [itemType, setItemType] = useState<'page' | 'board' | 'folder' | 'excalidraw' | 'helpdesk' | 'leadform' | 'list' | 'plan' | null>(null);
    const { planTemplates = [] } = usePage<PageProps>().props;

    const pageForm = useForm({
        name: '',
        type: 'page' as 'page' | 'folder',
        parent_id: parentId ?? null,
        content_type: 'doc' as 'doc' | 'excalidraw',
    });

    // Sync parent when modal opens or parentId changes (e.g. "Add" on a folder)
    useEffect(() => {
        if (open) {
            pageForm.setData('parent_id', parentId ?? null);
        }
    }, [open, parentId]);

    const boardForm = useForm({
        name: '',
        type: 'kanban',
    });

    const helpdeskForm = useForm({
        name: '',
        description: '',
        template: '',
    });

    const listForm = useForm({
        name: '',
        description: '',
    });

    const planForm = useForm({
        name: '',
        template: 'blank',
    });

    const handleCreatePlan = (e: FormEvent) => {
        e.preventDefault();
        planForm.post(route('plans.store', [workspace.slug, project.slug]), {
            onSuccess: () => {
                planForm.reset();
                setItemType(null);
                onClose();
            },
        });
    };

    const handleCreateList = (e: FormEvent) => {
        e.preventDefault();
        listForm.post(route('task-lists.store', [workspace.slug, project.slug]), {
            onSuccess: () => {
                listForm.reset();
                setItemType(null);
                onClose();
            },
        });
    };

    const handleCreatePage = (e: FormEvent) => {
        e.preventDefault();
        pageForm.post(route('pages.store', [workspace.slug, project.slug]), {
            onSuccess: () => {
                pageForm.reset();
                setItemType(null);
                onClose();
            },
        });
    };

    const handleCreateBoard = (e: FormEvent) => {
        e.preventDefault();
        boardForm.post(route('boards.store', [workspace.slug, project.slug]), {
            onSuccess: () => {
                boardForm.reset();
                setItemType(null);
                onClose();
            },
        });
    };

    const handleCreateHelpdesk = (e: FormEvent) => {
        e.preventDefault();
        helpdeskForm.post(route('helpdesks.store', [workspace.slug, project.slug]), {
            onSuccess: () => {
                helpdeskForm.reset();
                setItemType(null);
                onClose();
            },
        });
    };

    const handleClose = () => {
        setItemType(null);
        pageForm.reset();
        boardForm.reset();
        helpdeskForm.reset();
        listForm.reset();
        planForm.reset();
        onClose();
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && handleClose()}>
            <DialogContent className={cn('sm:max-w-md', itemType === 'plan' && 'sm:max-w-lg', itemType === 'helpdesk' && 'sm:max-w-2xl max-h-[90vh] overflow-y-auto', itemType === 'leadform' && 'sm:max-w-4xl')}>
                <DialogHeader>
                    <DialogTitle>
                        {itemType === null ? 'Create New' : itemType === 'board' ? 'Create Board' : itemType === 'folder' ? 'Create Folder' : itemType === 'excalidraw' ? 'Create Whiteboard' : itemType === 'helpdesk' ? 'Create Helpdesk' : itemType === 'leadform' ? 'Create Lead Form' : itemType === 'list' ? 'Create List' : itemType === 'plan' ? 'Create Plan' : 'Create Page'}
                    </DialogTitle>
                    <DialogDescription>
                        {itemType === null
                            ? `Add a new item to ${parentType === 'folder' ? 'this folder' : project.name}`
                            : itemType === 'excalidraw'
                                ? 'Create a whiteboard to draw and save diagrams.'
                                : itemType === 'helpdesk'
                                    ? 'Create a helpdesk to manage customer support tickets.'
                                    : itemType === 'leadform'
                                    ? 'Start from a template — multi-step questions, icon cards and its own leads board. You can change everything later.'
                                    : itemType === 'list'
                                        ? 'Create a task list to track to-dos with due dates and assignees.'
                                        : itemType === 'plan'
                                            ? 'A Gantt plan for the big picture: phases, bars, milestones and who does what.'
                                            : `Enter a name for your new ${itemType}`}
                    </DialogDescription>
                </DialogHeader>

                {itemType === null ? (
                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 py-4">
                        <button
                            type="button"
                            onClick={() => {
                                setItemType('page');
                                pageForm.setData('content_type', 'doc');
                                pageForm.setData('type', 'page');
                            }}
                            className="flex flex-col items-center gap-2 p-4 rounded-lg border hover:border-primary hover:bg-primary/5 transition-colors"
                        >
                            <div className="size-12 rounded-lg bg-primary/10 text-primary flex items-center justify-center">
                                <FileText className="size-6" />
                            </div>
                            <span className="text-sm font-medium">Page</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                setItemType('excalidraw');
                                pageForm.setData('content_type', 'excalidraw');
                                pageForm.setData('type', 'page');
                            }}
                            className="flex flex-col items-center gap-2 p-4 rounded-lg border hover:border-primary hover:bg-primary/5 transition-colors"
                        >
                            <div className="size-12 rounded-lg bg-brand-slate/10 text-brand-slate flex items-center justify-center">
                                <svg className="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <path d="M12 19l7-7 3 3-7 7-3-3z" />
                                    <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z" />
                                    <path d="M2 2l7.586 7.586" />
                                    <circle cx="11" cy="11" r="2" />
                                </svg>
                            </div>
                            <span className="text-sm font-medium">Whiteboard</span>
                        </button>
                        {parentType === 'project' && (
                            <button
                                type="button"
                                onClick={() => setItemType('board')}
                                className="flex flex-col items-center gap-2 p-4 rounded-lg border hover:border-primary hover:bg-primary/5 transition-colors"
                            >
                                <div className="size-12 rounded-lg bg-brand-slate/10 text-brand-slate flex items-center justify-center">
                                    <Kanban className="size-6" />
                                </div>
                                <span className="text-sm font-medium">Board</span>
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => setItemType('folder')}
                            className="flex flex-col items-center gap-2 p-4 rounded-lg border hover:border-primary hover:bg-primary/5 transition-colors"
                        >
                            <div className="size-12 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center">
                                <Folder className="size-6" />
                            </div>
                            <span className="text-sm font-medium">Folder</span>
                        </button>
                        {parentType === 'project' && (
                            <button
                                type="button"
                                onClick={() => setItemType('helpdesk')}
                                className="flex flex-col items-center gap-2 p-4 rounded-lg border hover:border-primary hover:bg-primary/5 transition-colors"
                            >
                                <div className="size-12 rounded-lg bg-green-100 text-green-600 flex items-center justify-center">
                                    <Headphones className="size-6" />
                                </div>
                                <span className="text-sm font-medium">Helpdesk</span>
                            </button>
                        )}
                        {parentType === 'project' && (
                            <button
                                type="button"
                                onClick={() => setItemType('leadform')}
                                className="flex flex-col items-center gap-2 p-4 rounded-lg border hover:border-primary hover:bg-primary/5 transition-colors"
                            >
                                <div className="size-12 rounded-lg bg-rose-100 text-rose-600 dark:bg-rose-500/15 dark:text-rose-300 flex items-center justify-center">
                                    <Magnet className="size-6" />
                                </div>
                                <span className="text-sm font-medium">Lead form</span>
                            </button>
                        )}
                        {parentType === 'project' && (
                            <button
                                type="button"
                                onClick={() => setItemType('list')}
                                className="flex flex-col items-center gap-2 p-4 rounded-lg border hover:border-primary hover:bg-primary/5 transition-colors"
                            >
                                <div className="size-12 rounded-lg bg-[#0E83DC]/10 text-[#0E83DC] flex items-center justify-center">
                                    <List className="size-6" />
                                </div>
                                <span className="text-sm font-medium">List</span>
                            </button>
                        )}
                        {parentType === 'project' && (
                            <button
                                type="button"
                                onClick={() => setItemType('plan')}
                                className="flex flex-col items-center gap-2 p-4 rounded-lg border hover:border-primary hover:bg-primary/5 transition-colors"
                            >
                                <div className="size-12 rounded-lg bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300 flex items-center justify-center">
                                    <GanttChart className="size-6" />
                                </div>
                                <span className="text-sm font-medium">Plan</span>
                            </button>
                        )}
                    </div>
                ) : itemType === 'board' ? (
                    <form onSubmit={handleCreateBoard} className="space-y-4 py-2">
                        <div className="space-y-2">
                            <Label htmlFor="board-name">Board Name</Label>
                            <Input
                                id="board-name"
                                value={boardForm.data.name}
                                onChange={(e) => boardForm.setData('name', e.target.value)}
                                placeholder="e.g., Sprint Board, Backlog"
                                autoFocus
                                required
                            />
                        </div>
                        <div className="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="outline" onClick={() => setItemType(null)}>
                                Back
                            </Button>
                            <Button type="submit" disabled={boardForm.processing}>
                                {boardForm.processing ? 'Creating...' : 'Create Board'}
                            </Button>
                        </div>
                    </form>
                ) : itemType === 'leadform' ? (
                    <LeadFormTemplatePicker
                        workspaceSlug={workspace.slug}
                        projectSlug={project.slug}
                        onBack={() => setItemType(null)}
                        onCreated={() => {
                            setItemType(null);
                            onClose();
                        }}
                    />
                ) : itemType === 'helpdesk' ? (
                    <form onSubmit={handleCreateHelpdesk} className="space-y-4 py-2">
                        <div className="space-y-2">
                            <Label>Start from</Label>
                            <HelpdeskTemplatePicker
                                workspaceSlug={workspace.slug}
                                value={helpdeskForm.data.template}
                                onChange={(key, template) => helpdeskForm.setData((d) => ({ ...d, template: key, name: d.name || (template?.name ?? '') }))}
                            />
                            {helpdeskForm.errors.template && <p className="text-sm text-destructive">{helpdeskForm.errors.template}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="helpdesk-name">Helpdesk Name</Label>
                            <Input
                                id="helpdesk-name"
                                value={helpdeskForm.data.name}
                                onChange={(e) => helpdeskForm.setData('name', e.target.value)}
                                placeholder="e.g., Customer Support, Technical Help"
                                autoFocus
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="helpdesk-description">Description (optional)</Label>
                            <Textarea
                                id="helpdesk-description"
                                value={helpdeskForm.data.description}
                                onChange={(e) => helpdeskForm.setData('description', e.target.value)}
                                placeholder="Brief description of this helpdesk"
                                rows={3}
                                className="min-h-[80px]"
                            />
                        </div>
                        <div className="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="outline" onClick={() => setItemType(null)}>
                                Back
                            </Button>
                            <Button type="submit" disabled={helpdeskForm.processing}>
                                {helpdeskForm.processing ? 'Creating...' : 'Create Helpdesk'}
                            </Button>
                        </div>
                    </form>
                ) : itemType === 'list' ? (
                    <form onSubmit={handleCreateList} className="space-y-4 py-2">
                        <div className="space-y-2">
                            <Label htmlFor="list-name">List Name</Label>
                            <Input
                                id="list-name"
                                value={listForm.data.name}
                                onChange={(e) => listForm.setData('name', e.target.value)}
                                placeholder="e.g., Weekly Tasks, Sprint Backlog"
                                autoFocus
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="list-description">Description <span className="font-normal text-muted-foreground">(optional)</span></Label>
                            <Textarea
                                id="list-description"
                                value={listForm.data.description}
                                onChange={(e) => listForm.setData('description', e.target.value)}
                                placeholder="What is this list for?"
                                rows={2}
                                className="resize-none"
                            />
                        </div>
                        <div className="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="outline" onClick={() => setItemType(null)}>
                                Back
                            </Button>
                            <Button type="submit" disabled={listForm.processing}>
                                {listForm.processing ? 'Creating...' : 'Create List'}
                            </Button>
                        </div>
                    </form>
                ) : itemType === 'plan' ? (
                    <form onSubmit={handleCreatePlan} className="space-y-4 py-2">
                        <div className="space-y-2">
                            <Label htmlFor="plan-name">Plan Name</Label>
                            <Input
                                id="plan-name"
                                value={planForm.data.name}
                                onChange={(e) => planForm.setData('name', e.target.value)}
                                placeholder="e.g., Roadmap 2027, Workshop series"
                                autoFocus
                                required
                            />
                            {planForm.errors.name && <p className="text-sm text-destructive">{planForm.errors.name}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label>Start from</Label>
                            <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Template">
                                {planTemplates.map((template) => (
                                    <button
                                        key={template.key}
                                        type="button"
                                        role="radio"
                                        aria-checked={planForm.data.template === template.key}
                                        onClick={() => planForm.setData('template', template.key)}
                                        className={cn(
                                            'rounded-lg border px-3 py-2 text-left transition-colors hover:border-primary/60',
                                            planForm.data.template === template.key && 'border-primary bg-primary/5 ring-1 ring-primary',
                                        )}
                                    >
                                        <span className="block text-sm font-medium">{template.name}</span>
                                        <span className="block text-xs text-muted-foreground">{template.description}</span>
                                    </button>
                                ))}
                            </div>
                        </div>
                        <div className="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="outline" onClick={() => setItemType(null)}>
                                Back
                            </Button>
                            <Button type="submit" disabled={planForm.processing}>
                                {planForm.processing ? 'Creating...' : 'Create Plan'}
                            </Button>
                        </div>
                    </form>
                ) : (itemType === 'page' || itemType === 'excalidraw' || itemType === 'folder') ? (
                    <form onSubmit={handleCreatePage} className="space-y-4 py-2">
                        <div className="space-y-2">
                            <Label htmlFor="parent-select">Parent</Label>
                            <Select
                                value={pageForm.data.parent_id === null || pageForm.data.parent_id === undefined ? 'root' : String(pageForm.data.parent_id)}
                                onValueChange={(v) => pageForm.setData('parent_id', v === 'root' ? null : Number(v))}
                            >
                                <SelectTrigger id="parent-select">
                                    <SelectValue placeholder="Select parent" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="root">Root</SelectItem>
                                    {flattenPagesForParentSelect((project as Project & { pages?: PageType[] }).pages ?? [], {
                                        foldersOnly: itemType === 'folder',
                                    }).map((p) => (
                                        <SelectItem key={p.id} value={String(p.id)}>
                                            <span className="flex items-center gap-2" style={{ paddingLeft: `${p.depth * 14}px` }}>
                                                {p.type === 'folder' ? (
                                                    <Folder className="size-3.5 shrink-0 text-amber-600" />
                                                ) : p.is_whiteboard ? (
                                                    <PencilRuler className="size-3.5 shrink-0 text-brand-slate" />
                                                ) : (
                                                    <FileText className="size-3.5 shrink-0 text-blue-600" />
                                                )}
                                                {p.name}
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="page-name">
                                {itemType === 'folder' ? 'Folder Name' : itemType === 'excalidraw' ? 'Whiteboard Name' : 'Page Name'}
                            </Label>
                            <Input
                                id="page-name"
                                value={pageForm.data.name}
                                onChange={(e) => {
                                    pageForm.setData('name', e.target.value);
                                    pageForm.setData('type', itemType === 'folder' ? 'folder' : 'page');
                                }}
                                placeholder={itemType === 'folder' ? 'e.g., Documentation, Updates' : itemType === 'excalidraw' ? 'e.g., Diagram, Sketch' : 'e.g., Getting Started, API Reference'}
                                autoFocus
                                required
                            />
                        </div>
                        <div className="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="outline" onClick={() => setItemType(null)}>
                                Back
                            </Button>
                            <Button type="submit" disabled={pageForm.processing}>
                                {pageForm.processing ? 'Creating...' : `Create ${itemType === 'folder' ? 'Folder' : itemType === 'excalidraw' ? 'Whiteboard' : 'Page'}`}
                            </Button>
                        </div>
                    </form>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

// Page/Folder Tree Item
function PageTreeItem({
    page,
    workspace,
    project,
    level = 0,
    onAddItem,
    currentPageUuid,
}: {
    page: PageType;
    workspace: Workspace;
    project: Project;
    level?: number;
    onAddItem: (parentId: number) => void;
    currentPageUuid?: string | null;
}) {
    const hasChildren = page.children && page.children.length > 0;
    const defaultOpen = !!currentPageUuid && hasChildren && (page.uuid === currentPageUuid || pageContainsUuid(page, currentPageUuid));
    const [isOpen, setIsOpen] = useExpandedState(`page-${page.id}`, defaultOpen);

    // Auto-expand when we navigate to this page or a descendant (e.g. open sidebar to current location)
    useEffect(() => {
        if (defaultOpen) setIsOpen(true);
    }, [defaultOpen]);

    if (page.type === 'folder') {
        return (
            <Collapsible open={isOpen} onOpenChange={setIsOpen}>
                <div className="flex min-w-0 items-center group">
                    <CollapsibleTrigger asChild>
                        <button
                            className="flex min-w-0 flex-1 items-center gap-2 px-2 py-1.5 hover:bg-accent rounded-md transition-colors text-left text-sm"
                            style={{ paddingLeft: `${level * 12 + 8}px` }}
                        >
                            <ChevronRight className={`size-3.5 shrink-0 text-muted-foreground transition-transform ${isOpen ? 'rotate-90' : ''}`} />
                            {(page.icon?.startsWith('data:') || page.icon?.startsWith('http') || page.icon?.startsWith('/'))
                                ? <img src={page.icon} className="size-4 rounded object-cover shrink-0" alt="" />
                                : <span className="shrink-0 text-base">{page.icon || '📁'}</span>
                            }
                            <span className="min-w-0 truncate text-muted-foreground group-hover:text-foreground">{page.name}</span>
                        </button>
                    </CollapsibleTrigger>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-6 shrink-0 opacity-0 group-hover:opacity-100 mr-1"
                        onClick={(e) => {
                            e.stopPropagation();
                            onAddItem(page.id);
                        }}
                    >
                        <Plus className="size-3.5" />
                    </Button>
                </div>
                {hasChildren && (
                    <CollapsibleContent>
                        {(() => {
                            const children = page.children!;
                            const childDocs = children.filter((p) => p.type === 'page' && !p.is_whiteboard);
                            const childWhiteboards = children.filter((p) => p.type === 'page' && p.is_whiteboard);
                            const childFolders = children.filter((p) => p.type === 'folder');
                            return (
                                <>
                                    {childDocs.length > 0 && (
                                        <DraggableSidebarGroup
                                            items={childDocs}
                                            workspace={workspace}
                                            project={project}
                                            level={level + 1}
                                            onAddItem={onAddItem}
                                            currentPageUuid={currentPageUuid}
                                        />
                                    )}
                                    {childWhiteboards.length > 0 && (
                                        <DraggableSidebarGroup
                                            items={childWhiteboards}
                                            workspace={workspace}
                                            project={project}
                                            level={level + 1}
                                            onAddItem={onAddItem}
                                            currentPageUuid={currentPageUuid}
                                        />
                                    )}
                                    {childFolders.length > 0 && (
                                        <DraggableSidebarGroup
                                            items={childFolders}
                                            workspace={workspace}
                                            project={project}
                                            level={level + 1}
                                            onAddItem={onAddItem}
                                            currentPageUuid={currentPageUuid}
                                        />
                                    )}
                                </>
                            );
                        })()}
                    </CollapsibleContent>
                )}
            </Collapsible>
        );
    }

    // Page with children: collapsible with link to page
    if (page.type === 'page' && hasChildren) {
        const children = page.children!;
        const childDocs = children.filter((p) => p.type === 'page' && !p.is_whiteboard);
        const childWhiteboards = children.filter((p) => p.type === 'page' && p.is_whiteboard);
        const childFolders = children.filter((p) => p.type === 'folder');
        return (
            <Collapsible open={isOpen} onOpenChange={setIsOpen}>
                <div className="flex min-w-0 items-center group">
                    <CollapsibleTrigger asChild>
                        <button
                            className="shrink-0 p-1 hover:bg-accent rounded transition-colors text-muted-foreground"
                            style={{ marginLeft: `${level * 12 + 4}px` }}
                        >
                            <ChevronRight className={`size-3.5 transition-transform ${isOpen ? 'rotate-90' : ''}`} />
                        </button>
                    </CollapsibleTrigger>
                    <Link
                        href={route('pages.show', [workspace.slug, project.slug, page.uuid])}
                        className="flex min-w-0 flex-1 items-center gap-2 px-1 py-1.5 hover:bg-accent rounded-md transition-colors text-left text-sm text-muted-foreground hover:text-foreground"
                    >
                        {page.is_whiteboard ? (
                            <PencilRuler className="size-4 shrink-0 text-brand-slate" />
                        ) : (page.icon?.startsWith('data:') || page.icon?.startsWith('http') || page.icon?.startsWith('/'))
                            ? <img src={page.icon} className="size-4 rounded object-cover shrink-0" alt="" />
                            : (
                                <span className="shrink-0 text-base">{page.icon || '📄'}</span>
                            )}
                        <span className="min-w-0 truncate">{page.name}</span>
                    </Link>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-6 shrink-0 opacity-0 group-hover:opacity-100 mr-1"
                        onClick={(e) => {
                            e.stopPropagation();
                            onAddItem(page.id);
                        }}
                    >
                        <Plus className="size-3.5" />
                    </Button>
                </div>
                <CollapsibleContent>
                    <>
                        {childDocs.length > 0 && (
                            <DraggableSidebarGroup
                                items={childDocs}
                                workspace={workspace}
                                project={project}
                                level={level + 1}
                                onAddItem={onAddItem}
                                currentPageUuid={currentPageUuid}
                            />
                        )}
                        {childWhiteboards.length > 0 && (
                            <DraggableSidebarGroup
                                items={childWhiteboards}
                                workspace={workspace}
                                project={project}
                                level={level + 1}
                                onAddItem={onAddItem}
                                currentPageUuid={currentPageUuid}
                            />
                        )}
                        {childFolders.length > 0 && (
                            <DraggableSidebarGroup
                                items={childFolders}
                                workspace={workspace}
                                project={project}
                                level={level + 1}
                                onAddItem={onAddItem}
                                currentPageUuid={currentPageUuid}
                            />
                        )}
                    </>
                </CollapsibleContent>
            </Collapsible>
        );
    }

    return (
        <Link
            href={route('pages.show', [workspace.slug, project.slug, page.uuid])}
            className="flex min-w-0 items-center gap-2 px-2 py-1.5 hover:bg-accent rounded-md transition-colors text-sm text-muted-foreground hover:text-foreground"
            style={{ paddingLeft: `${level * 12 + 24}px` }}
        >
            {page.is_whiteboard ? (
                <PencilRuler className="size-4 shrink-0 text-brand-slate" />
            ) : (page.icon?.startsWith('data:') || page.icon?.startsWith('http') || page.icon?.startsWith('/'))
                ? <img src={page.icon} className="size-4 rounded object-cover shrink-0" alt="" />
                : (
                    <span className="shrink-0 text-base">{page.icon || '📄'}</span>
                )}
            <span className="min-w-0 truncate">{page.name}</span>
        </Link>
    );
}

// Collapsible group headers inside ProjectItem inline expanded view
function InlineProjectGroups({
    project,
    workspace,
    rootDocs,
    rootWhiteboards,
    rootFolders,
    onAddItem,
    currentPageUuid,
}: {
    project: Project & { boards: Board[]; pages?: PageType[]; helpdesks?: Helpdesk[] };
    workspace: Workspace;
    rootDocs: PageType[];
    rootWhiteboards: PageType[];
    rootFolders: PageType[];
    onAddItem: (projectId: number, parentId?: number) => void;
    currentPageUuid?: string | null;
}) {
    const [pagesOpen, setPagesOpen] = useExpandedState(`inline-project-${project.id}-pages`, true);
    const [whiteboardsOpen, setWhiteboardsOpen] = useExpandedState(`inline-project-${project.id}-whiteboards`, true);
    const [foldersOpen, setFoldersOpen] = useExpandedState(`inline-project-${project.id}-folders`, true);
    const hasBoardsOrHelpdesksBefore = (project.boards?.length ?? 0) > 0 || (project.helpdesks?.length ?? 0) > 0;

    return (
        <>
            {hasBoardsOrHelpdesksBefore && <div className="border-t border-border/50 my-1.5" />}
            {rootDocs.length > 0 && (
                <>
                    <button
                        onClick={() => setPagesOpen(!pagesOpen)}
                        className="flex items-center gap-1.5 px-2 py-1 w-full hover:bg-accent/50 rounded-md transition-colors"
                    >
                        <ChevronRight className={`size-3 shrink-0 text-muted-foreground/60 transition-transform ${pagesOpen ? 'rotate-90' : ''}`} />
                        <span className="text-xs font-medium text-muted-foreground uppercase tracking-wider flex-1 text-left">Pages</span>
                    </button>
                    {pagesOpen && (
                        <DraggableSidebarGroup
                            items={rootDocs}
                            workspace={workspace}
                            project={project}
                            onAddItem={(parentId) => onAddItem(project.id, parentId)}
                            currentPageUuid={currentPageUuid}
                        />
                    )}
                </>
            )}
            {rootWhiteboards.length > 0 && (
                <>
                    {rootDocs.length > 0 && <div className="border-t border-border/50 my-1.5" />}
                    <button
                        onClick={() => setWhiteboardsOpen(!whiteboardsOpen)}
                        className="flex items-center gap-1.5 px-2 py-1 w-full hover:bg-accent/50 rounded-md transition-colors"
                    >
                        <ChevronRight className={`size-3 shrink-0 text-muted-foreground/60 transition-transform ${whiteboardsOpen ? 'rotate-90' : ''}`} />
                        <span className="text-xs font-medium text-muted-foreground uppercase tracking-wider flex-1 text-left">Whiteboards</span>
                    </button>
                    {whiteboardsOpen && (
                        <DraggableSidebarGroup
                            items={rootWhiteboards}
                            workspace={workspace}
                            project={project}
                            onAddItem={(parentId) => onAddItem(project.id, parentId)}
                            currentPageUuid={currentPageUuid}
                        />
                    )}
                </>
            )}
            {rootFolders.length > 0 && (
                <>
                    {(rootDocs.length > 0 || rootWhiteboards.length > 0) && <div className="border-t border-border/50 my-1.5" />}
                    <button
                        onClick={() => setFoldersOpen(!foldersOpen)}
                        className="flex items-center gap-1.5 px-2 py-1 w-full hover:bg-accent/50 rounded-md transition-colors"
                    >
                        <ChevronRight className={`size-3 shrink-0 text-muted-foreground/60 transition-transform ${foldersOpen ? 'rotate-90' : ''}`} />
                        <span className="text-xs font-medium text-muted-foreground uppercase tracking-wider flex-1 text-left">Folders</span>
                    </button>
                    {foldersOpen && (
                        <DraggableSidebarGroup
                            items={rootFolders}
                            workspace={workspace}
                            project={project}
                            onAddItem={(parentId) => onAddItem(project.id, parentId)}
                            currentPageUuid={currentPageUuid}
                        />
                    )}
                </>
            )}
        </>
    );
}

// Project Item with boards and pages
function ProjectItem({
    project,
    workspace,
    isActive,
    onAddItem,
    currentPageUuid,
}: {
    project: Project & { boards: Board[]; pages?: PageType[]; helpdesks?: Helpdesk[] };
    workspace: Workspace;
    isActive: boolean;
    onAddItem: (projectId: number, parentId?: number) => void;
    currentPageUuid?: string | null;
}) {
    const isProjectActive = !!currentPageUuid && projectContainsPageUuid(project, currentPageUuid);
    const [isOpen, setIsOpen] = useExpandedState(`project-${project.id}`, isActive || isProjectActive);

    useEffect(() => {
        if (isProjectActive) setIsOpen(true);
    }, [isProjectActive]);

    const hasContent = (project.boards && project.boards.length > 0) || (project.pages && project.pages.length > 0) || (project.helpdesks && project.helpdesks.length > 0) || (project.lead_forms?.length ?? 0) > 0;

    return (
        <Collapsible open={isOpen} onOpenChange={setIsOpen} className="group/collapsible">
            <SidebarMenuItem className="min-w-0">
                <div className="flex min-w-0 items-center group">
                    <CollapsibleTrigger asChild>
                        <button className="flex min-w-0 flex-1 items-center gap-3 px-2 py-2 hover:bg-accent rounded-lg transition-colors text-left">
                            <ProjectIcon project={project} size="sm" />
                            <span className="min-w-0 flex-1 truncate text-sm font-medium">{project.name}</span>
                            {hasContent && (
                                <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-transform group-data-[state=open]/collapsible:rotate-90" />
                            )}
                        </button>
                    </CollapsibleTrigger>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-7 shrink-0 opacity-0 group-hover:opacity-100 mr-1"
                        onClick={(e) => {
                            e.stopPropagation();
                            onAddItem(project.id);
                        }}
                    >
                        <Plus className="size-4" />
                    </Button>
                </div>
                {hasContent && (
                    <CollapsibleContent>
                        <div className="ml-4 pl-5 border-l border-border space-y-0.5 py-1">
                            {/* Boards */}
                            {project.boards?.map((board) => (
                                <Link
                                    key={board.id}
                                    href={route('boards.show', [workspace.slug, project.slug, board.slug])}
                                    className="flex min-w-0 items-center gap-2.5 px-2 py-1.5 hover:bg-accent rounded-md transition-colors text-sm text-muted-foreground hover:text-foreground"
                                >
                                    <LayoutGrid className="size-4 shrink-0" />
                                    <span className="min-w-0 truncate">{board.name}</span>
                                </Link>
                            ))}

                            {/* Helpdesks */}
                            {project.helpdesks?.map((helpdesk) => (
                                <Link
                                    key={helpdesk.id}
                                    href={route('helpdesks.show', [workspace.slug, project.slug, helpdesk.slug])}
                                    className="flex min-w-0 items-center gap-2.5 px-2 py-1.5 hover:bg-accent rounded-md transition-colors text-sm text-muted-foreground hover:text-foreground"
                                >
                                    <LifeBuoy className="size-4 shrink-0" />
                                    <span className="min-w-0 truncate">{helpdesk.name}</span>
                                </Link>
                            ))}

                            {/* Lead forms */}
                            {project.lead_forms?.map((leadForm) => (
                                <Link
                                    key={leadForm.id}
                                    href={route('lead-forms.show', [workspace.slug, project.slug, leadForm.slug])}
                                    className="flex min-w-0 items-center gap-2.5 px-2 py-1.5 hover:bg-accent rounded-md transition-colors text-sm text-muted-foreground hover:text-foreground"
                                >
                                    <Magnet className="size-4 shrink-0" />
                                    <span className="min-w-0 truncate">{leadForm.name}</span>
                                </Link>
                            ))}

                            {/* Pages, Whiteboards, Folders - collapsible with drag */}
                            {project.pages && project.pages.length > 0 && (() => {
                                const rootDocs = project.pages.filter((p) => p.type === 'page' && !p.is_whiteboard);
                                const rootWhiteboards = project.pages.filter((p) => p.type === 'page' && p.is_whiteboard);
                                const rootFolders = project.pages.filter((p) => p.type === 'folder');
                                const hasAny = rootDocs.length > 0 || rootWhiteboards.length > 0 || rootFolders.length > 0;
                                if (!hasAny) return null;
                                return (
                                    <InlineProjectGroups
                                        project={project}
                                        workspace={workspace}
                                        rootDocs={rootDocs}
                                        rootWhiteboards={rootWhiteboards}
                                        rootFolders={rootFolders}
                                        onAddItem={onAddItem}
                                        currentPageUuid={currentPageUuid}
                                    />
                                );
                            })()}
                        </div>
                    </CollapsibleContent>
                )}
            </SidebarMenuItem>
        </Collapsible>
    );
}

function AppSidebar({
    workspaces,
    currentWorkspace,
    currentProjectId,
    onOpenSearch,
    onOpenHelp,
}: {
    workspaces: Workspace[];
    currentWorkspace?: Workspace & { projects: (Project & { boards: Board[]; pages?: PageType[]; helpdesks?: Helpdesk[] })[] };
    currentProjectId?: number | null;
    onOpenSearch?: () => void;
    onOpenHelp?: () => void;
}) {
    const { auth, currentPageUuid, canManageWorkspace = false, captureInboxCount = 0 } = usePage<PageProps>().props;
    const isInstanceAdmin = !!auth.isInstanceAdmin;
    const user = auth.user;
    const projects = currentWorkspace?.projects || [];

    // Determine active project from URL context
    const detectActiveProject = useCallback(() => {
        if (!projects.length) return null;
        // Check if current page uuid belongs to a project
        if (currentPageUuid) {
            const match = projects.find(p => projectContainsPageUuid(p, currentPageUuid));
            if (match) return match;
        }
        // Check URL for project slug
        const path = window.location.pathname;
        const projectMatch = path.match(/\/projects\/([^\/]+)/);
        if (projectMatch) {
            const slug = projectMatch[1];
            const match = projects.find(p => p.slug === slug);
            if (match) return match;
        }
        return null;
    }, [projects, currentPageUuid]);

    // Selected project state persisted in localStorage
    const [activeProjectId, setActiveProjectId] = useState<number | null>(() => {
        if (typeof window === 'undefined' || !currentWorkspace) return currentProjectId ?? null;
        const saved = localStorage.getItem(`sidebar-active-project-${currentWorkspace.id}`);
        return saved ? parseInt(saved, 10) : currentProjectId ?? null;
    });

    // Resolve the active project object
    const activeProject = projects.find(p => p.id === activeProjectId) || null;

    // Auto-detect project from URL on mount and navigation
    useEffect(() => {
        if (!currentWorkspace) return;

        const detected = detectActiveProject();
        const saved = localStorage.getItem(`sidebar-active-project-${currentWorkspace.id}`);
        const savedProjectId = saved ? parseInt(saved, 10) : null;
        const validProjectIds = new Set(projects.map((project) => project.id));
        const rememberedProjectId = currentProjectId && validProjectIds.has(currentProjectId) ? currentProjectId : null;
        const storedProjectId = savedProjectId && validProjectIds.has(savedProjectId) ? savedProjectId : null;
        const existingProjectId = activeProjectId && validProjectIds.has(activeProjectId) ? activeProjectId : null;
        const nextProjectId = detected?.id ?? rememberedProjectId ?? storedProjectId ?? existingProjectId ?? projects[0]?.id ?? null;

        if (nextProjectId) {
            if (nextProjectId !== activeProjectId) {
                setActiveProjectId(nextProjectId);
            }
            localStorage.setItem(`sidebar-active-project-${currentWorkspace.id}`, String(nextProjectId));
        }
    }, [activeProjectId, currentProjectId, currentWorkspace?.id, detectActiveProject, projects]);

    const handleSelectProject = (project: Project & { boards: Board[]; pages?: PageType[]; helpdesks?: Helpdesk[] }) => {
        setActiveProjectId(project.id);
        if (currentWorkspace) {
            localStorage.setItem(`sidebar-active-project-${currentWorkspace.id}`, String(project.id));
            router.visit(route('projects.show', [currentWorkspace.slug, project.slug]));
        }
    };

    // Modal state
    const [createModalOpen, setCreateModalOpen] = useState(false);
    const [selectedProject, setSelectedProject] = useState<Project | null>(null);
    const [selectedParentId, setSelectedParentId] = useState<number | undefined>(undefined);
    const [parentType, setParentType] = useState<'project' | 'folder'>('project');

    const handleAddItem = (projectId: number, parentId?: number) => {
        const project = currentWorkspace?.projects.find(p => p.id === projectId);
        if (project) {
            setSelectedProject(project);
            setSelectedParentId(parentId);
            setParentType(parentId ? 'folder' : 'project');
            setCreateModalOpen(true);
        }
    };

    return (
        <>
            <Sidebar className="border-r border-border/50 overflow-hidden min-w-0 flex flex-col">
                <SidebarHeader className="p-3 pb-2">
                    <Link href={route('workspaces.index')} className="flex items-center gap-2.5 px-1 py-1 rounded-lg hover:bg-accent/50 transition-colors">
                        <ApplicationLogo className="h-8 w-auto object-contain shrink-0" alt="Brainitive" />
                    </Link>
                </SidebarHeader>

                {/* Main Navigation */}
                <div className="px-3 pb-2 flex-shrink-0">
                    <SidebarMenu className="space-y-0.5">
                        <SidebarMenuItem>
                            <Link
                                href={currentWorkspace ? route('workspaces.show', currentWorkspace.slug) : route('workspaces.index')}
                                className={cn(
                                    'flex items-center gap-2.5 px-2 py-1.5 rounded-md text-[13px] font-medium transition-colors',
                                    route().current() === 'workspaces.show' || route().current() === 'workspaces.index' ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                                )}
                            >
                                <Home className="size-4" />
                                <span>Home</span>
                            </Link>
                        </SidebarMenuItem>
                        <SidebarMenuItem>
                            <Link
                                href={route('my-work.index')}
                                className={cn(
                                    'flex items-center gap-2.5 px-2 py-1.5 rounded-md text-[13px] font-medium transition-colors',
                                    route().current() === 'my-work.index' ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                                )}
                            >
                                <ListTodo className="size-4" />
                                <span>My Work</span>
                            </Link>
                        </SidebarMenuItem>
                        <SidebarMenuItem>
                            <Link
                                href={route('captures.index')}
                                className={cn(
                                    'flex items-center gap-2.5 px-2 py-1.5 rounded-md text-[13px] font-medium transition-colors',
                                    route().current() === 'captures.index' ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                                )}
                            >
                                <Lightbulb className="size-4" />
                                <span>Captures</span>
                                {captureInboxCount > 0 && (
                                    <span className="ml-auto rounded-full bg-muted px-1.5 text-[11px] tabular-nums text-muted-foreground" aria-label={`${captureInboxCount} in your capture inbox`}>
                                        {captureInboxCount}
                                    </span>
                                )}
                            </Link>
                        </SidebarMenuItem>
                        {/* Time tracking & billing (v1.38.0); the timesheet route comes with BRAI-99 */}
                        {currentWorkspace && route().has('timesheet.index') && (
                            <SidebarMenuItem>
                                <Link
                                    href={route('timesheet.index', currentWorkspace.slug)}
                                    className={cn(
                                        'flex items-center gap-2.5 px-2 py-1.5 rounded-md text-[13px] font-medium transition-colors',
                                        route().current() === 'timesheet.index' ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                                    )}
                                >
                                    <Clock className="size-4" />
                                    <span>Timesheet</span>
                                </Link>
                            </SidebarMenuItem>
                        )}
                        {currentWorkspace && canManageWorkspace && (
                            <SidebarMenuItem>
                                <Link
                                    href={route('billing.index', currentWorkspace.slug)}
                                    className={cn(
                                        'flex items-center gap-2.5 px-2 py-1.5 rounded-md text-[13px] font-medium transition-colors',
                                        route().current()?.startsWith('billing.') ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                                    )}
                                >
                                    <ReceiptText className="size-4" />
                                    <span>Billing</span>
                                </Link>
                            </SidebarMenuItem>
                        )}
                        <SidebarMenuItem>
                            <Link
                                href={route('calendar.index')}
                                className={cn(
                                    'flex items-center gap-2.5 px-2 py-1.5 rounded-md text-[13px] font-medium transition-colors',
                                    route().current() === 'calendar.index' ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                                )}
                            >
                                <CalendarDays className="size-4" />
                                <span>Calendar</span>
                            </Link>
                        </SidebarMenuItem>
                        <SidebarMenuItem>
                            <Link
                                href={route('notifications.page')}
                                className={cn(
                                    'flex items-center gap-2.5 px-2 py-1.5 rounded-md text-[13px] font-medium transition-colors',
                                    route().current() === 'notifications.page' ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                                )}
                            >
                                <Bell className="size-4" />
                                <span>Inbox</span>
                            </Link>
                        </SidebarMenuItem>
                        {currentWorkspace && (
                            <SidebarMenuItem>
                                <Link
                                    href={route('bookmarks.index', currentWorkspace.slug)}
                                    className={cn(
                                        'flex items-center gap-2.5 px-2 py-1.5 rounded-md text-[13px] font-medium transition-colors',
                                        route().current() === 'bookmarks.index' ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                                    )}
                                >
                                    <BookmarkIcon className="size-4" />
                                    <span>Bookmarks</span>
                                </Link>
                            </SidebarMenuItem>
                        )}
                        {currentWorkspace && (
                            <SidebarMenuItem>
                                <Link
                                    href={route('snippets.index', currentWorkspace.slug)}
                                    className={cn(
                                        'flex items-center gap-2.5 px-2 py-1.5 rounded-md text-[13px] font-medium transition-colors',
                                        route().current() === 'snippets.index' ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                                    )}
                                >
                                    <Code2 className="size-4" />
                                    <span>Snippets</span>
                                </Link>
                            </SidebarMenuItem>
                        )}
                    </SidebarMenu>
                </div>

                <Separator className="mx-3" />

                {/* Project Switcher */}
                {currentWorkspace && projects.length > 0 ? (
                    <div className="px-3 pt-2 flex-shrink-0">
                        <ProjectSwitcher
                            projects={projects}
                            currentProject={activeProject || undefined}
                            onSelect={handleSelectProject}
                            workspace={currentWorkspace}
                        />
                    </div>
                ) : currentWorkspace ? (
                    <div className="px-3 py-4 flex-shrink-0">
                        <div className="px-2 py-6 text-center">
                            <div className="size-12 rounded-full bg-muted mx-auto mb-3 flex items-center justify-center">
                                <FolderKanban className="size-6 text-muted-foreground" />
                            </div>
                            <p className="text-sm font-medium mb-1">No projects yet</p>
                            <p className="text-xs text-muted-foreground mb-4">Create your first project to get started</p>
                            <Button size="sm" className="w-full" onClick={() => openCreateProject()}>
                                <Plus className="size-4 mr-1.5" />
                                Create Project
                            </Button>
                        </div>
                    </div>
                ) : null}

                {/* Selected Project Content - Scrollable */}
                <SidebarContent className="px-3">
                    {activeProject && currentWorkspace && (
                        <SidebarGroup className="py-2">
                            <SidebarGroupContent>
                                <ProjectContent
                                    project={activeProject}
                                    workspace={currentWorkspace}
                                    onAddItem={handleAddItem}
                                    currentPageUuid={currentPageUuid}
                                />
                            </SidebarGroupContent>
                        </SidebarGroup>
                    )}
                </SidebarContent>

                <SidebarFooter className="p-3 pt-0">
                    <Separator className="mb-3" />
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button className="flex items-center gap-3 w-full px-2 py-2 hover:bg-accent rounded-lg transition-colors text-left">
                                <Avatar className="size-8">
                                    {user.avatar_display && <AvatarImage src={user.avatar_display} alt="" />}
                                    <AvatarFallback className="bg-gradient-to-br from-brand-blue to-brand-slate text-white text-sm font-semibold">
                                        {user.name.charAt(0).toUpperCase()}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm font-medium truncate">{user.name}</p>
                                    <p className="text-xs text-muted-foreground truncate">{currentWorkspace?.name || user.email}</p>
                                </div>
                                <ChevronsUpDown className="size-4 text-muted-foreground shrink-0" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            className="w-72 p-2"
                            side="top"
                            align="start"
                            sideOffset={4}
                        >
                            {/* User info */}
                            <div className="flex items-center gap-3 p-2 mb-1">
                                <Avatar className="size-10">
                                    {user.avatar_display && <AvatarImage src={user.avatar_display} alt="" />}
                                    <AvatarFallback className="bg-gradient-to-br from-brand-blue to-brand-slate text-white font-semibold">
                                        {user.name.charAt(0).toUpperCase()}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm font-semibold truncate">{user.name}</p>
                                    <p className="text-xs text-muted-foreground truncate">{user.email}</p>
                                </div>
                            </div>
                            <DropdownMenuSeparator />

                            {/* Workspace switcher section */}
                            <DropdownMenuLabel className="text-xs font-medium text-muted-foreground px-2 py-1.5 mt-1">
                                Workspace
                            </DropdownMenuLabel>
                            {workspaces.map((workspace) => (
                                <DropdownMenuItem
                                    key={workspace.id}
                                    onClick={() => router.visit(route('workspaces.show', workspace.slug))}
                                    className="flex items-center gap-3 p-2 cursor-pointer rounded-md"
                                >
                                    <WorkspaceIcon name={workspace.name} logoUrl={workspace.logo_display} size="sm" />
                                    <span className="flex-1 text-sm font-medium truncate">{workspace.name}</span>
                                    {currentWorkspace?.id === workspace.id && (
                                        <Check className="size-4 text-primary" />
                                    )}
                                </DropdownMenuItem>
                            ))}
                            <DropdownMenuItem
                                onClick={() => router.visit(route('workspaces.create'))}
                                className="flex items-center gap-3 p-2 cursor-pointer rounded-md text-primary"
                            >
                                <div className="size-6 rounded-md border-2 border-dashed border-primary/50 flex items-center justify-center">
                                    <Plus className="size-3.5" />
                                </div>
                                <span className="text-sm font-medium">Create workspace</span>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />

                            {/* Account actions */}
                            <DropdownMenuItem asChild className="cursor-pointer">
                                <Link href={route('profile.edit')} className="flex items-center gap-3 p-2">
                                    <User className="size-4" />
                                    <span className="text-sm">Profile settings</span>
                                </Link>
                            </DropdownMenuItem>
                            {currentWorkspace && canManageWorkspace && (
                                <DropdownMenuItem asChild className="cursor-pointer">
                                    <Link href={route('workspaces.edit', currentWorkspace.slug)} className="flex items-center gap-3 p-2">
                                        <Settings className="size-4" />
                                        <span className="text-sm">Workspace settings</span>
                                    </Link>
                                </DropdownMenuItem>
                            )}
                            {isInstanceAdmin && (
                                <DropdownMenuItem asChild className="cursor-pointer">
                                    <Link href={route('admin.overview')} className="flex items-center gap-3 p-2">
                                        <ShieldCheck className="size-4" />
                                        <span className="text-sm">Instance admin</span>
                                    </Link>
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuItem onClick={() => onOpenHelp?.()} className="flex items-center gap-3 p-2 cursor-pointer">
                                <HelpCircle className="size-4" />
                                <span className="text-sm">Help & support</span>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <ThemeMenu />
                            <DropdownMenuSeparator />
                            <DropdownMenuItem asChild className="cursor-pointer text-destructive focus:text-destructive">
                                <Link href={route('logout')} method="post" as="button" className="flex items-center gap-3 p-2 w-full">
                                    <LogOut className="size-4" />
                                    <span className="text-sm">Sign out</span>
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </SidebarFooter>
            </Sidebar>

            {/* Create Item Modal */}
            {selectedProject && currentWorkspace && (
                <CreateItemModal
                    open={createModalOpen}
                    onClose={() => {
                        setCreateModalOpen(false);
                        setSelectedProject(null);
                        setSelectedParentId(undefined);
                    }}
                    workspace={currentWorkspace}
                    project={selectedProject}
                    parentId={selectedParentId}
                    parentType={parentType}
                />
            )}
        </>
    );
}

function TopBar({ onCreateClick, onSearchClick, onOpenHelp }: { onCreateClick?: () => void; onSearchClick?: () => void; onOpenHelp?: () => void }) {
    const { resolvedTheme, toggleTheme } = useTheme();
    const { currentWorkspace } = usePage<PageProps>().props;

    return (
        <header className="flex h-12 shrink-0 items-center gap-3 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 px-4">
            <SidebarTrigger className="-ml-1 size-8" />

            {/* Breadcrumb */}
            <nav className="flex items-center gap-1.5 text-sm min-w-0">
                {currentWorkspace && (
                    <Link
                        href={route('workspaces.show', currentWorkspace.slug)}
                        className="font-medium text-muted-foreground hover:text-foreground transition-colors truncate"
                    >
                        {currentWorkspace.name}
                    </Link>
                )}
            </nav>

            <div className="flex-1" />

            {/* Search trigger */}
            <div className="w-full max-w-sm">
                <div className="relative">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                    <Input
                        type="search"
                        placeholder="Search..."
                        className="pl-9 h-8 bg-muted/50 border-0 focus-visible:ring-1 focus-visible:ring-primary/50 text-sm cursor-pointer"
                        readOnly
                        onFocus={() => onSearchClick?.()}
                        onClick={() => onSearchClick?.()}
                    />
                    <kbd className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 hidden h-5 select-none items-center gap-1 rounded border bg-background px-1.5 font-mono text-[10px] font-medium sm:flex">
                        ⌘K
                    </kbd>
                </div>
            </div>

            {/* Utilities */}
            <div className="flex items-center gap-1.5">
                <WorkTimerPill />
                <NotificationsDropdown />
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    onClick={toggleTheme}
                    title={resolvedTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
                >
                    {resolvedTheme === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
                </Button>
                <Button variant="ghost" size="icon" className="size-8" onClick={() => onOpenHelp?.()}>
                    <HelpCircle className="size-4" />
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    className="hidden h-8 gap-1.5 px-3 md:inline-flex"
                    onClick={openQuickCapture}
                    title="Quick capture (C)"
                >
                    <Lightbulb className="size-3.5" />
                    Capture
                </Button>
                <Button size="sm" className="h-8 px-3 gap-1.5" onClick={onCreateClick}>
                    <Plus className="size-3.5" />
                    <span className="hidden sm:inline">Create</span>
                </Button>
            </div>
        </header>
    );
}

// Global Create Task Modal
// GlobalCreateModal is now in @/components/GlobalCreateModal

export default function AppLayout({ children, title }: Props) {
    const { workspaces = [], currentWorkspace, currentProjectId, currentBoardId } = usePage<PageProps>().props;
    const [sidebarWidth, setSidebarWidth] = useState(getInitialSidebarWidth);
    const [createTaskModalOpen, setCreateTaskModalOpen] = useState(false);
    const [commandPaletteOpen, setCommandPaletteOpen] = useState(false);
    const [paletteQuery, setPaletteQuery] = useState('');
    const { resolvedTheme, toggleTheme } = useTheme();
    useRecentTracking();
    useTimezoneSync();
    const [quickCreateSnippetOpen, setQuickCreateSnippetOpen] = useState(false);
    const [quickCreateBookmarkOpen, setQuickCreateBookmarkOpen] = useState(false);
    const [helpModalOpen, setHelpModalOpen] = useState(false);
    const [preSelectedProjectId, setPreSelectedProjectId] = useState<number | undefined>();
    const [preSelectedBoardId, setPreSelectedBoardId] = useState<number | undefined>();
    const [preSelectedColumnId, setPreSelectedColumnId] = useState<number | undefined>();
    const [defaultCreateType, setDefaultCreateType] = useState<GlobalCreateType>('task');

    const openPalette = useCallback((initialQuery = '') => {
        setPaletteQuery(initialQuery);
        setCommandPaletteOpen(true);
    }, []);
    const onOpenSearch = useCallback(() => openPalette(), [openPalette]);

    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            // Leave shortcuts an editor already handled (e.g. Excalidraw's ⌘K link) and IME input alone.
            if (e.defaultPrevented || e.isComposing || !(e.metaKey || e.ctrlKey) || e.shiftKey || e.altKey) return;
            if ((e.target as HTMLElement | null)?.closest?.('.excalidraw')) return;
            const key = e.key.toLowerCase();
            if (key === 'k' || key === '.') {
                e.preventDefault();
                setPaletteQuery(key === '.' ? '>' : '');
                setCommandPaletteOpen((open) => !open);
            }
        };
        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, []);

    useEffect(() => {
        const handleMentionClick = (e: MouseEvent) => {
            const target = (e.target as HTMLElement).closest('[data-mention-user-id]') as HTMLElement | null;
            if (!target) return;
            const userId = target.getAttribute('data-mention-user-id');
            if (!userId || !currentWorkspace) return;
            e.preventDefault();
            e.stopPropagation();
            router.visit(route('workspaces.members.profile', [currentWorkspace.slug, userId]));
        };
        document.addEventListener('click', handleMentionClick);
        return () => document.removeEventListener('click', handleMentionClick);
    }, [currentWorkspace]);

    const openCreateTaskModal = useCallback((projectId?: number, boardId?: number, columnId?: number, type?: GlobalCreateType) => {
        setPreSelectedProjectId(projectId ?? currentProjectId ?? undefined);
        setPreSelectedBoardId(boardId ?? currentBoardId ?? undefined);
        setPreSelectedColumnId(columnId);
        if (type) setDefaultCreateType(type);
        setCreateTaskModalOpen(true);
    }, [currentProjectId, currentBoardId]);

    const closeCreateTaskModal = useCallback(() => {
        setCreateTaskModalOpen(false);
        setPreSelectedProjectId(undefined);
        setPreSelectedBoardId(undefined);
        setPreSelectedColumnId(undefined);
        setDefaultCreateType('task');
    }, []);

    const globalCreateModalValue = {
        openCreateTaskModal,
        closeCreateTaskModal,
    };

    const commandPaletteCommands: PaletteCommand[] = [
        // ── Navigation ──────────────────────────────────────────────────────
        ...(currentWorkspace ? [
            {
                id: 'nav-workspace',
                label: `Go to ${currentWorkspace.name}`,
                description: 'Projects of this workspace',
                keywords: 'home workspace projects',
                icon: <CmdIcon><Home className="size-4" /></CmdIcon>,
                group: 'Go to',
                action: () => router.visit(route('workspaces.show', currentWorkspace.slug)),
            },
        ] as PaletteCommand[] : []),
        {
            id: 'nav-my-work',
            label: 'Go to My Work',
            description: 'Issues assigned to you',
            keywords: 'assigned tasks todo',
            icon: <CmdIcon><FolderKanban className="size-4" /></CmdIcon>,
            group: 'Go to',
            action: () => router.visit(route('my-work.index')),
        },
        {
            id: 'nav-calendar',
            label: 'Go to Calendar',
            description: 'Due dates and connected calendars',
            keywords: 'schedule events google',
            icon: <CmdIcon><CalendarDays className="size-4" /></CmdIcon>,
            group: 'Go to',
            action: () => router.visit(route('calendar.index')),
        },
        {
            id: 'nav-inbox',
            label: 'Go to Inbox',
            description: 'All notifications',
            keywords: 'notifications mentions',
            icon: <CmdIcon><Bell className="size-4" /></CmdIcon>,
            group: 'Go to',
            action: () => router.visit(route('notifications.page')),
        },
        {
            id: 'nav-workspaces',
            label: 'Switch workspace',
            description: 'All your workspaces',
            keywords: 'workspaces change',
            icon: <CmdIcon><ChevronsUpDown className="size-4" /></CmdIcon>,
            group: 'Go to',
            action: () => router.visit(route('workspaces.index')),
        },
        ...(currentWorkspace ? [
            {
                id: 'nav-bookmarks',
                label: 'Go to Bookmarks',
                description: 'Open your bookmark collections',
                icon: <CmdIcon color="#0969da"><BookmarkIcon className="size-4" /></CmdIcon>,
                group: 'Go to',
                action: () => router.visit(route('bookmarks.index', currentWorkspace.slug)),
            },
            {
                id: 'nav-snippets',
                label: 'Go to Snippets',
                description: 'Open your code snippet collections',
                icon: <CmdIcon color="#1a7f37"><Code2 className="size-4" /></CmdIcon>,
                group: 'Go to',
                action: () => router.visit(route('snippets.index', currentWorkspace.slug)),
            },
        ] as PaletteCommand[] : []),

        // ── Create ──────────────────────────────────────────────────────────
        {
            id: 'quick-capture',
            label: 'Quick capture',
            keywords: 'idea note inbox jot capture later',
            description: 'Save an idea to your private captures (C)',
            icon: <CmdIcon color="#f59e0b"><Lightbulb className="size-4" /></CmdIcon>,
            group: 'Create',
            action: () => openQuickCapture(),
        },
        ...(currentWorkspace ? [
            {
                id: 'create-project',
                label: 'Create Project',
                keywords: 'new add project template scrum kanban marketing',
                description: 'Start a project from a template or blank',
                icon: <CmdIcon color="#6366F1"><FolderKanban className="size-4" /></CmdIcon>,
                group: 'Create',
                action: () => { openCreateProject(); },
            },
            {
                id: 'create-item',
                label: 'Create Issue',
                keywords: 'new add issue task card',
                description: 'Create a new task or item on a board',
                icon: <CmdIcon color="#0E83DC"><CheckSquare className="size-4" /></CmdIcon>,
                group: 'Create',
                action: () => { openCreateTaskModal(undefined, undefined, undefined, 'task'); },
            },
            {
                id: 'create-page',
                label: 'Create Page',
                keywords: 'new add document doc',
                description: 'Create a new document page',
                icon: <CmdIcon color="#22c55e"><FileText className="size-4" /></CmdIcon>,
                group: 'Create',
                action: () => { openCreateTaskModal(undefined, undefined, undefined, 'page'); },
            },
            {
                id: 'create-whiteboard',
                label: 'Create Whiteboard',
                keywords: 'new add draw excalidraw',
                description: 'Create a new Excalidraw whiteboard',
                icon: <CmdIcon color="#8b5cf6"><PencilRuler className="size-4" /></CmdIcon>,
                group: 'Create',
                action: () => { openCreateTaskModal(undefined, undefined, undefined, 'whiteboard'); },
            },
            {
                id: 'create-folder',
                label: 'Create Folder',
                keywords: 'new add wiki',
                description: 'Create a new folder in the wiki',
                icon: <CmdIcon color="#f59e0b"><Folder className="size-4" /></CmdIcon>,
                group: 'Create',
                action: () => { openCreateTaskModal(undefined, undefined, undefined, 'folder'); },
            },
            {
                id: 'create-snippet',
                label: 'Create Snippet',
                keywords: 'new add code paste',
                description: 'Add a code snippet to a collection',
                icon: <CmdIcon color="#1a7f37"><Code2 className="size-4" /></CmdIcon>,
                group: 'Create',
                action: () => setQuickCreateSnippetOpen(true),
            },
            {
                id: 'create-bookmark',
                label: 'Create Bookmark',
                keywords: 'new add link',
                description: 'Add a link, to-do or note to a collection',
                icon: <CmdIcon color="#0969da"><BookmarkIcon className="size-4" /></CmdIcon>,
                group: 'Create',
                action: () => setQuickCreateBookmarkOpen(true),
            },
        ] as PaletteCommand[] : []),

        // ── Actions ─────────────────────────────────────────────────────────
        {
            id: 'create-sticky-note',
            label: 'Create Sticky Note',
            description: 'Add a sticky note to this page',
            icon: (
                <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-yellow-100 text-yellow-700">
                    <StickyNote className="size-4" />
                </div>
            ),
            group: 'Actions',
            action: () =>
                window.dispatchEvent(new CustomEvent('page-sticky-note:create')),
        },
        {
            id: 'toggle-theme',
            label: resolvedTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode',
            keywords: 'theme dark light appearance',
            icon: <CmdIcon>{resolvedTheme === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}</CmdIcon>,
            group: 'Actions',
            action: toggleTheme,
        },
        {
            id: 'open-help',
            label: 'Help & what’s new',
            description: 'Shortcuts, guides and the changelog',
            keywords: 'changelog shortcuts docs',
            icon: <CmdIcon><HelpCircle className="size-4" /></CmdIcon>,
            group: 'Actions',
            action: () => setHelpModalOpen(true),
        },
        {
            id: 'account-settings',
            label: 'Account settings',
            description: 'Profile, password and connected accounts',
            keywords: 'profile password preferences',
            icon: <CmdIcon><Settings className="size-4" /></CmdIcon>,
            group: 'Actions',
            action: () => router.visit(route('profile.edit')),
        },
    ];

    return (
        <GlobalCreateModalContext.Provider value={globalCreateModalValue}>
            <SidebarProvider
                style={
                    {
                        '--sidebar-width': `${sidebarWidth}px`,
                    } as React.CSSProperties
                }
            >
                <AppSidebar
                    workspaces={workspaces}
                    currentWorkspace={currentWorkspace}
                    currentProjectId={currentProjectId}
                    onOpenSearch={onOpenSearch}
                    onOpenHelp={() => setHelpModalOpen(true)}
                />
                <SidebarResizer width={sidebarWidth} onWidthChange={setSidebarWidth} />
                <SidebarInset className="flex flex-col h-screen overflow-hidden">
                    <TopBar onCreateClick={() => openCreateTaskModal()} onSearchClick={onOpenSearch} onOpenHelp={() => setHelpModalOpen(true)} />
                    <main className="flex-1 overflow-auto bg-muted/30">
                        {children}
                    </main>
                </SidebarInset>
            </SidebarProvider>

            {/* Global Create Modal */}
            <GlobalCreateModal
                open={createTaskModalOpen}
                onClose={closeCreateTaskModal}
                preSelectedProjectId={preSelectedProjectId}
                preSelectedBoardId={preSelectedBoardId}
                preSelectedColumnId={preSelectedColumnId}
                defaultType={defaultCreateType}
            />
            <CommandPalette
                open={commandPaletteOpen}
                onOpenChange={setCommandPaletteOpen}
                initialQuery={paletteQuery}
                commands={commandPaletteCommands}
            />
            {currentWorkspace && (
                <>
                    <QuickCreateSnippetModal
                        open={quickCreateSnippetOpen}
                        onOpenChange={setQuickCreateSnippetOpen}
                        workspaceSlug={currentWorkspace.slug}
                    />
                    <QuickCreateBookmarkModal
                        open={quickCreateBookmarkOpen}
                        onOpenChange={setQuickCreateBookmarkOpen}
                        workspaceSlug={currentWorkspace.slug}
                    />
                </>
            )}
            <CreateProjectDialog workspace={currentWorkspace ?? null} />
            <QuickCaptureDialog />
            <QuickCaptureFab />
            <HelpModal open={helpModalOpen} onOpenChange={setHelpModalOpen} />
            <GlobalStickyNotes />
            <WelcomeModal />
            <PushPrompt />
            <Toaster richColors position="bottom-right" closeButton />
        </GlobalCreateModalContext.Provider>
    );
}
