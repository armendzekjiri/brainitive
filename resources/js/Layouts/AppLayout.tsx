import { Link, router, usePage, useForm } from '@inertiajs/react';
import { Toaster } from 'sonner';
import { PropsWithChildren, useState, FormEvent, useEffect, useCallback, createContext, useContext } from 'react';
import { PageProps, Workspace, Project, Board, Page as PageType, ItemType, ItemPriority, User as UserType, BoardColumn } from '@/types';
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
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    FileText,
    Folder,
    Kanban,
    ListTodo,
    Bookmark as BookmarkIcon,
} from 'lucide-react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

interface Props extends PropsWithChildren {
    title?: string;
}

// Context for global create modal
interface GlobalCreateModalContextType {
    openCreateTaskModal: (projectId?: number, boardId?: number, columnId?: number) => void;
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

// Jira-like workspace icon with first letter
function WorkspaceIcon({ name, size = 'md' }: { name: string; size?: 'sm' | 'md' | 'lg' }) {
    const sizeClasses = {
        sm: 'size-6 text-xs',
        md: 'size-8 text-sm',
        lg: 'size-10 text-base',
    };

    const colors = [
        'bg-blue-600',
        'bg-purple-600',
        'bg-teal-600',
        'bg-orange-500',
        'bg-pink-600',
        'bg-indigo-600',
        'bg-green-600',
        'bg-red-500',
    ];
    const colorIndex = name.charCodeAt(0) % colors.length;

    return (
        <div className={`${sizeClasses[size]} ${colors[colorIndex]} rounded-md flex items-center justify-center text-white font-semibold`}>
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
                        <WorkspaceIcon name={currentWorkspace.name} size="md" />
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
                        <WorkspaceIcon name={workspace.name} size="sm" />
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
    const [itemType, setItemType] = useState<'page' | 'board' | 'folder' | 'excalidraw' | null>(null);

    const pageForm = useForm({
        name: '',
        type: 'page' as 'page' | 'folder',
        parent_id: parentId || null,
        content_type: 'doc' as 'doc' | 'excalidraw',
    });

    const boardForm = useForm({
        name: '',
        type: 'kanban',
    });

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

    const handleClose = () => {
        setItemType(null);
        pageForm.reset();
        boardForm.reset();
        onClose();
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && handleClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {itemType === null ? 'Create New' : itemType === 'board' ? 'Create Board' : itemType === 'folder' ? 'Create Folder' : itemType === 'excalidraw' ? 'Create Excalidraw' : 'Create Page'}
                    </DialogTitle>
                    <DialogDescription>
                        {itemType === null
                            ? `Add a new item to ${parentType === 'folder' ? 'this folder' : project.name}`
                            : itemType === 'excalidraw'
                                ? 'Create a whiteboard page. Opens Excalidraw to draw and save on this page.'
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
                            <div className="size-12 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center">
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
                            <div className="size-12 rounded-lg bg-violet-100 text-violet-600 flex items-center justify-center">
                                <svg className="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <path d="M12 19l7-7 3 3-7 7-3-3z" />
                                    <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z" />
                                    <path d="M2 2l7.586 7.586" />
                                    <circle cx="11" cy="11" r="2" />
                                </svg>
                            </div>
                            <span className="text-sm font-medium">Excalidraw</span>
                        </button>
                        {parentType === 'project' && (
                            <button
                                type="button"
                                onClick={() => setItemType('board')}
                                className="flex flex-col items-center gap-2 p-4 rounded-lg border hover:border-primary hover:bg-primary/5 transition-colors"
                            >
                                <div className="size-12 rounded-lg bg-purple-100 text-purple-600 flex items-center justify-center">
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
                ) : (itemType === 'page' || itemType === 'excalidraw' || itemType === 'folder') ? (
                    <form onSubmit={handleCreatePage} className="space-y-4 py-2">
                        <div className="space-y-2">
                            <Label htmlFor="page-name">
                                {itemType === 'folder' ? 'Folder Name' : itemType === 'excalidraw' ? 'Excalidraw Name' : 'Page Name'}
                            </Label>
                            <Input
                                id="page-name"
                                value={pageForm.data.name}
                                onChange={(e) => {
                                    pageForm.setData('name', e.target.value);
                                    pageForm.setData('type', itemType === 'folder' ? 'folder' : 'page');
                                }}
                                placeholder={itemType === 'folder' ? 'e.g., Documentation, Updates' : itemType === 'excalidraw' ? 'e.g., Diagram, Whiteboard' : 'e.g., Getting Started, API Reference'}
                                autoFocus
                                required
                            />
                        </div>
                        <div className="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="outline" onClick={() => setItemType(null)}>
                                Back
                            </Button>
                            <Button type="submit" disabled={pageForm.processing}>
                                {pageForm.processing ? 'Creating...' : `Create ${itemType === 'folder' ? 'Folder' : itemType === 'excalidraw' ? 'Excalidraw' : 'Page'}`}
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
    onAddItem
}: {
    page: PageType;
    workspace: Workspace;
    project: Project;
    level?: number;
    onAddItem: (parentId: number) => void;
}) {
    const [isOpen, setIsOpen] = useExpandedState(`page-${page.id}`, false);
    const hasChildren = page.children && page.children.length > 0;

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
                            <span className="shrink-0 text-base">{page.icon || '📁'}</span>
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
                        {page.children!.map((child) => (
                            <PageTreeItem
                                key={child.id}
                                page={child}
                                workspace={workspace}
                                project={project}
                                level={level + 1}
                                onAddItem={onAddItem}
                            />
                        ))}
                    </CollapsibleContent>
                )}
            </Collapsible>
        );
    }

    return (
        <Link
            href={route('pages.show', [workspace.slug, project.slug, page.uuid])}
            className="flex min-w-0 items-center gap-2 px-2 py-1.5 hover:bg-accent rounded-md transition-colors text-sm text-muted-foreground hover:text-foreground"
            style={{ paddingLeft: `${level * 12 + 24}px` }}
        >
            <span className="shrink-0 text-base">{page.icon || '📄'}</span>
            <span className="min-w-0 truncate">{page.name}</span>
        </Link>
    );
}

// Project Item with boards and pages
function ProjectItem({
    project,
    workspace,
    isActive,
    onAddItem
}: {
    project: Project & { boards: Board[]; pages?: PageType[] };
    workspace: Workspace;
    isActive: boolean;
    onAddItem: (projectId: number, parentId?: number) => void;
}) {
    const [isOpen, setIsOpen] = useExpandedState(`project-${project.id}`, isActive);
    const hasContent = (project.boards && project.boards.length > 0) || (project.pages && project.pages.length > 0);

    return (
        <Collapsible open={isOpen} onOpenChange={setIsOpen} className="group/collapsible">
            <SidebarMenuItem className="min-w-0">
                <div className="flex min-w-0 items-center group">
                    <CollapsibleTrigger asChild>
                        <button className="flex min-w-0 flex-1 items-center gap-3 px-2 py-2 hover:bg-accent rounded-lg transition-colors text-left">
                            <div
                                className="size-6 rounded flex items-center justify-center text-[11px] font-bold text-white shrink-0"
                                style={{ backgroundColor: project.color || '#0052CC' }}
                            >
                                {project.key?.substring(0, 2) || 'PR'}
                            </div>
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

                            {/* Pages Section */}
                            {project.pages && project.pages.length > 0 && (
                                <>
                                    {(project.boards?.length ?? 0) > 0 && (
                                        <div className="border-t border-border/50 my-1.5" />
                                    )}
                                    <div className="flex items-center justify-between px-2 py-1">
                                        <span className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Pages</span>
                                    </div>
                                    {project.pages.map((page) => (
                                        <PageTreeItem
                                            key={page.id}
                                            page={page}
                                            workspace={workspace}
                                            project={project}
                                            onAddItem={(parentId) => onAddItem(project.id, parentId)}
                                        />
                                    ))}
                                </>
                            )}
                        </div>
                    </CollapsibleContent>
                )}
            </SidebarMenuItem>
        </Collapsible>
    );
}

function AppSidebar({ workspaces, currentWorkspace }: { workspaces: Workspace[]; currentWorkspace?: Workspace & { projects: (Project & { boards: Board[]; pages?: PageType[] })[] } }) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;

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
            <Sidebar className="border-r border-border/50">
                <SidebarHeader className="p-3 pb-0">
                    <WorkspaceSwitcher workspaces={workspaces} currentWorkspace={currentWorkspace} />
                </SidebarHeader>

                {/* Main Navigation - Fixed, not scrollable */}
                <div className="px-3 py-4 flex-shrink-0">
                    <SidebarMenu className="space-y-0.5">
                        <SidebarMenuItem>
                            {currentWorkspace ? (
                                <Link
                                    href={route('workspaces.show', currentWorkspace.slug)}
                                    className="flex items-center gap-3 px-2 py-2 hover:bg-accent rounded-lg transition-colors"
                                >
                                    <Home className="size-5 text-muted-foreground" />
                                    <span className="text-sm font-medium">Home</span>
                                </Link>
                            ) : (
                                <Link
                                    href={route('workspaces.index')}
                                    className="flex items-center gap-3 px-2 py-2 hover:bg-accent rounded-lg transition-colors"
                                >
                                    <Home className="size-5 text-muted-foreground" />
                                    <span className="text-sm font-medium">Home</span>
                                </Link>
                            )}
                        </SidebarMenuItem>
                        {currentWorkspace && (
                            <SidebarMenuItem>
                                <Link
                                    href={route('bookmarks.index', currentWorkspace.slug)}
                                    className="flex items-center gap-3 px-2 py-2 hover:bg-accent rounded-lg transition-colors"
                                >
                                    <BookmarkIcon className="size-5 text-muted-foreground" />
                                    <span className="text-sm font-medium">Bookmarks</span>
                                </Link>
                            </SidebarMenuItem>
                        )}
                        <SidebarMenuItem>
                            <button className="flex items-center gap-3 px-2 py-2 hover:bg-accent rounded-lg transition-colors w-full text-left">
                                <Search className="size-5 text-muted-foreground" />
                                <span className="text-sm font-medium">Search</span>
                            </button>
                        </SidebarMenuItem>
                        <SidebarMenuItem>
                            <button className="flex items-center gap-3 px-2 py-2 hover:bg-accent rounded-lg transition-colors w-full text-left">
                                <Bell className="size-5 text-muted-foreground" />
                                <span className="text-sm font-medium">Notifications</span>
                            </button>
                        </SidebarMenuItem>
                        {currentWorkspace && (
                            <SidebarMenuItem>
                                <Link
                                    href={route('workspaces.edit', currentWorkspace.slug)}
                                    className="flex items-center gap-3 px-2 py-2 hover:bg-accent rounded-lg transition-colors"
                                >
                                    <Settings className="size-5 text-muted-foreground" />
                                    <span className="text-sm font-medium">Settings</span>
                                </Link>
                            </SidebarMenuItem>
                        )}
                    </SidebarMenu>
                </div>

                <Separator className="mx-3" />

                {/* Projects Section - Scrollable */}
                <SidebarContent className="px-3">
                    <SidebarGroup className="py-4">
                        <div className="flex min-w-0 items-center justify-between gap-2 px-2 mb-2">
                            <SidebarGroupLabel className="text-xs font-semibold text-muted-foreground uppercase tracking-wider p-0 m-0 truncate">
                                Projects
                            </SidebarGroupLabel>
                            {currentWorkspace && (
                                <Link href={route('projects.create', currentWorkspace.slug)} className="shrink-0">
                                    <Button variant="ghost" size="icon" className="size-6 hover:bg-accent">
                                        <Plus className="size-4" />
                                    </Button>
                                </Link>
                            )}
                        </div>
                        <SidebarGroupContent>
                            {currentWorkspace && currentWorkspace.projects && currentWorkspace.projects.length > 0 ? (
                                <SidebarMenu className="space-y-0.5">
                                    {currentWorkspace.projects.map((project) => (
                                        <ProjectItem
                                            key={project.id}
                                            project={project}
                                            workspace={currentWorkspace}
                                            isActive={false}
                                            onAddItem={handleAddItem}
                                        />
                                    ))}
                                </SidebarMenu>
                            ) : (
                                <div className="px-2 py-6 text-center">
                                    <div className="size-12 rounded-full bg-muted mx-auto mb-3 flex items-center justify-center">
                                        <FolderKanban className="size-6 text-muted-foreground" />
                                    </div>
                                    <p className="text-sm font-medium mb-1">No projects yet</p>
                                    <p className="text-xs text-muted-foreground mb-4">Create your first project to get started</p>
                                    {currentWorkspace && (
                                        <Link href={route('projects.create', currentWorkspace.slug)}>
                                            <Button size="sm" className="w-full">
                                                <Plus className="size-4 mr-1.5" />
                                                Create Project
                                            </Button>
                                        </Link>
                                    )}
                                </div>
                            )}
                        </SidebarGroupContent>
                    </SidebarGroup>
                </SidebarContent>

                <SidebarFooter className="p-3 pt-0">
                    <Separator className="mb-3" />
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button className="flex items-center gap-3 w-full px-2 py-2 hover:bg-accent rounded-lg transition-colors text-left">
                                <Avatar className="size-8">
                                    <AvatarFallback className="bg-gradient-to-br from-blue-500 to-purple-600 text-white text-sm font-semibold">
                                        {user.name.charAt(0).toUpperCase()}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm font-medium truncate">{user.name}</p>
                                    <p className="text-xs text-muted-foreground truncate">{user.email}</p>
                                </div>
                                <ChevronsUpDown className="size-4 text-muted-foreground shrink-0" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            className="w-64 p-2"
                            side="top"
                            align="start"
                            sideOffset={4}
                        >
                            <div className="flex items-center gap-3 p-2 mb-2">
                                <Avatar className="size-10">
                                    <AvatarFallback className="bg-gradient-to-br from-blue-500 to-purple-600 text-white font-semibold">
                                        {user.name.charAt(0).toUpperCase()}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm font-semibold truncate">{user.name}</p>
                                    <p className="text-xs text-muted-foreground truncate">{user.email}</p>
                                </div>
                            </div>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem asChild className="cursor-pointer">
                                <Link href={route('profile.edit')} className="flex items-center gap-3 p-2">
                                    <User className="size-4" />
                                    <span className="text-sm">Profile settings</span>
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem className="flex items-center gap-3 p-2 cursor-pointer">
                                <HelpCircle className="size-4" />
                                <span className="text-sm">Help & support</span>
                            </DropdownMenuItem>
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

function TopBar({ onCreateClick }: { onCreateClick?: () => void }) {
    const [searchQuery, setSearchQuery] = useState('');
    const { currentWorkspace } = usePage<PageProps>().props;

    return (
        <header className="flex h-14 shrink-0 items-center gap-4 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 px-4">
            <SidebarTrigger className="-ml-1 size-9" />

            {/* Breadcrumb / Title */}
            <div className="flex items-center gap-2">
                {currentWorkspace && (
                    <>
                        <Link
                            href={route('workspaces.show', currentWorkspace.slug)}
                            className="text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
                        >
                            {currentWorkspace.name}
                        </Link>
                    </>
                )}
            </div>

            {/* Spacer */}
            <div className="flex-1" />

            {/* Search */}
            <div className="w-full max-w-sm">
                <div className="relative">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                    <Input
                        type="search"
                        placeholder="Search..."
                        className="pl-9 h-9 bg-muted/50 border-0 focus-visible:ring-1 focus-visible:ring-primary/50 text-sm"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                    />
                    <kbd className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 hidden h-5 select-none items-center gap-1 rounded border bg-background px-1.5 font-mono text-[10px] font-medium sm:flex">
                        ⌘K
                    </kbd>
                </div>
            </div>

            {/* Right side actions */}
            <div className="flex items-center gap-2">
                <Button variant="ghost" size="icon" className="size-9">
                    <Bell className="size-5" />
                </Button>
                <Button variant="ghost" size="icon" className="size-9">
                    <HelpCircle className="size-5" />
                </Button>
                <Button size="sm" className="h-9 px-3 gap-1.5" onClick={onCreateClick}>
                    <Plus className="size-4" />
                    <span className="hidden sm:inline">Create</span>
                </Button>
            </div>
        </header>
    );
}

// Global Create Task Modal
interface GlobalCreateTaskModalProps {
    open: boolean;
    onClose: () => void;
    preSelectedProjectId?: number;
    preSelectedBoardId?: number;
    preSelectedColumnId?: number;
}

function GlobalCreateTaskModal({
    open,
    onClose,
    preSelectedProjectId,
    preSelectedBoardId,
    preSelectedColumnId,
}: GlobalCreateTaskModalProps) {
    const { currentWorkspace } = usePage<PageProps>().props;
    const [selectedProjectId, setSelectedProjectId] = useState<string>(preSelectedProjectId?.toString() || '');
    const [selectedBoardId, setSelectedBoardId] = useState<string>(preSelectedBoardId?.toString() || '');
    const [boards, setBoards] = useState<Board[]>([]);
    const [columns, setColumns] = useState<BoardColumn[]>([]);

    const { data, setData, post, processing, reset, errors } = useForm({
        title: '',
        description: '',
        column_id: preSelectedColumnId?.toString() || '',
        item_type_id: '',
        priority_id: '',
        assigned_to: '',
    });

    // Update state when pre-selected values change
    useEffect(() => {
        if (preSelectedProjectId) {
            setSelectedProjectId(preSelectedProjectId.toString());
        }
        if (preSelectedBoardId) {
            setSelectedBoardId(preSelectedBoardId.toString());
        }
        if (preSelectedColumnId) {
            setData('column_id', preSelectedColumnId.toString());
        }
    }, [preSelectedProjectId, preSelectedBoardId, preSelectedColumnId]);

    // Load boards when project changes
    useEffect(() => {
        if (selectedProjectId && currentWorkspace) {
            const project = currentWorkspace.projects?.find(p => p.id.toString() === selectedProjectId);
            if (project?.boards) {
                setBoards(project.boards);
                // Auto-select first board if none selected
                if (!selectedBoardId && project.boards.length > 0) {
                    setSelectedBoardId(project.boards[0].id.toString());
                }
            }
        } else {
            setBoards([]);
            setSelectedBoardId('');
        }
    }, [selectedProjectId, currentWorkspace]);

    // Load columns when board changes
    useEffect(() => {
        if (selectedBoardId) {
            const board = boards.find(b => b.id.toString() === selectedBoardId);
            if (board?.columns && board.columns.length > 0) {
                setColumns(board.columns);
                // Check if current column_id is valid for this board
                const currentColumnValid = board.columns.some(c => c.id.toString() === data.column_id);
                if (!currentColumnValid) {
                    // Auto-select first column
                    setData('column_id', board.columns[0].id.toString());
                }
            } else {
                setColumns([]);
                setData('column_id', '');
            }
        } else {
            setColumns([]);
            setData('column_id', '');
        }
    }, [selectedBoardId, boards]);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (!currentWorkspace || !selectedProjectId || !selectedBoardId) return;

        const project = currentWorkspace.projects?.find(p => p.id.toString() === selectedProjectId);
        const board = boards.find(b => b.id.toString() === selectedBoardId);

        if (!project || !board) return;

        post(route('boards.items.store', [currentWorkspace.slug, project.slug, board.slug]), {
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    const handleClose = () => {
        reset();
        onClose();
    };

    if (!currentWorkspace) return null;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && handleClose()}>
            <DialogContent className="sm:max-w-lg" aria-describedby={undefined}>
                <DialogTitle>Create Task</DialogTitle>
                <form onSubmit={handleSubmit} className="space-y-4">
                    {/* Project Selection */}
                    <div className="space-y-2">
                        <Label>Project</Label>
                        <Select value={selectedProjectId} onValueChange={setSelectedProjectId}>
                            <SelectTrigger>
                                <SelectValue placeholder="Select project" />
                            </SelectTrigger>
                            <SelectContent>
                                {currentWorkspace.projects?.map((project) => (
                                    <SelectItem key={project.id} value={project.id.toString()}>
                                        {project.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {/* Board Selection */}
                    {boards.length > 0 && (
                        <div className="space-y-2">
                            <Label>Board</Label>
                            <Select value={selectedBoardId} onValueChange={setSelectedBoardId}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select board" />
                                </SelectTrigger>
                                <SelectContent>
                                    {boards.map((board) => (
                                        <SelectItem key={board.id} value={board.id.toString()}>
                                            {board.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    {/* Column/Status Selection */}
                    {columns.length > 0 && (
                        <div className="space-y-2">
                            <Label>Status</Label>
                            <Select value={data.column_id} onValueChange={(v) => setData('column_id', v)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select status" />
                                </SelectTrigger>
                                <SelectContent>
                                    {columns.map((col) => (
                                        <SelectItem key={col.id} value={col.id.toString()}>
                                            <div className="flex items-center gap-2">
                                                <div
                                                    className="w-2 h-2 rounded-full"
                                                    style={{ backgroundColor: col.color || '#94a3b8' }}
                                                />
                                                {col.name}
                                            </div>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    {/* Title */}
                    <div className="space-y-2">
                        <Label htmlFor="task-title">Title</Label>
                        <Input
                            id="task-title"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder="What needs to be done?"
                            autoFocus
                            required
                        />
                        {errors.title && <p className="text-sm text-destructive">{errors.title}</p>}
                    </div>

                    {/* Description */}
                    <div className="space-y-2">
                        <Label htmlFor="task-description">Description (optional)</Label>
                        <Textarea
                            id="task-description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="Add a description..."
                            rows={3}
                        />
                    </div>

                    <div className="flex justify-end gap-3 pt-2">
                        <Button type="button" variant="outline" onClick={handleClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing || !selectedProjectId || !selectedBoardId || !data.column_id || !data.title}
                        >
                            {processing ? 'Creating...' : 'Create Task'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function AppLayout({ children, title }: Props) {
    const { workspaces = [], currentWorkspace } = usePage<PageProps>().props;
    const [createTaskModalOpen, setCreateTaskModalOpen] = useState(false);
    const [preSelectedProjectId, setPreSelectedProjectId] = useState<number | undefined>();
    const [preSelectedBoardId, setPreSelectedBoardId] = useState<number | undefined>();
    const [preSelectedColumnId, setPreSelectedColumnId] = useState<number | undefined>();

    const openCreateTaskModal = useCallback((projectId?: number, boardId?: number, columnId?: number) => {
        setPreSelectedProjectId(projectId);
        setPreSelectedBoardId(boardId);
        setPreSelectedColumnId(columnId);
        setCreateTaskModalOpen(true);
    }, []);

    const closeCreateTaskModal = useCallback(() => {
        setCreateTaskModalOpen(false);
        setPreSelectedProjectId(undefined);
        setPreSelectedBoardId(undefined);
        setPreSelectedColumnId(undefined);
    }, []);

    const globalCreateModalValue = {
        openCreateTaskModal,
        closeCreateTaskModal,
    };

    return (
        <GlobalCreateModalContext.Provider value={globalCreateModalValue}>
            <SidebarProvider>
                <AppSidebar workspaces={workspaces} currentWorkspace={currentWorkspace} />
                <SidebarInset className="flex flex-col h-screen overflow-hidden">
                    <TopBar onCreateClick={() => openCreateTaskModal()} />
                    <main className="flex-1 overflow-auto bg-muted/30">
                        {children}
                    </main>
                </SidebarInset>
            </SidebarProvider>

            {/* Global Create Task Modal */}
            <GlobalCreateTaskModal
                open={createTaskModalOpen}
                onClose={closeCreateTaskModal}
                preSelectedProjectId={preSelectedProjectId}
                preSelectedBoardId={preSelectedBoardId}
                preSelectedColumnId={preSelectedColumnId}
            />
            <Toaster richColors position="bottom-right" closeButton />
        </GlobalCreateModalContext.Provider>
    );
}
