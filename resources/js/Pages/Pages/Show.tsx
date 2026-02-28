import AppLayout from '@/Layouts/AppLayout';
import { Head, useForm, router, Link, usePage } from '@inertiajs/react';
import { PageProps, Workspace, Project, Page as PageType, User } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { ProjectIcon } from '@/components/ProjectIcon';
import {
    MoreHorizontal,
    FileText,
    Folder,
    Plus,
    Trash2,
    Clock,
    User as UserIcon,
    ChevronRight,
    Globe,
    FileEdit,
    Archive,
    Check,
    Save,
    Pencil,
    Eye,
    PencilRuler,
    List as ListIcon,
    Maximize2,
    Minimize2,
    Bell,
    BellRing,
    Users,
    Files,
} from 'lucide-react';
import { useState, useEffect, useRef, RefObject, FormEvent, Fragment, useCallback, Suspense, lazy } from 'react';

// Lazy-load emoji-mart to prevent customElements.define() firing at module load time
// (breaks Vite HMR with "already defined" error which silently kills onChange).
const EmojiPickerLazy = lazy(() => import('@emoji-mart/react'));
const loadEmojiData = () => import('@emoji-mart/data').then((m) => m.default);
function EmojiMartPicker(props: any) {
    return (
        <Suspense fallback={<div className="p-2 text-sm text-muted-foreground">Loading…</div>}>
            <EmojiPickerLazy data={loadEmojiData} {...props} />
        </Suspense>
    );
}
import { RichLexicalEditor, TocHeading } from '@/components/editor/RichLexicalEditorFull';
import { cn } from '@/lib/utils';
import { flattenPagesForParentSelect } from '@/lib/pageTree';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

interface Props extends PageProps {
    workspace: Workspace;
    project: Project;
    page: PageType & {
        children?: PageType[];
        uuid: string;
        status: 'draft' | 'published' | 'archived';
        published_at?: string;
        lastEditor?: User;
        watchers?: User[];
    };
    breadcrumbs: { id: number; name: string; slug: string; uuid: string; type: string }[];
}

// Detect if page content is Excalidraw (full-page whiteboard)
function isExcalidrawPage(content: unknown): boolean {
    if (!content || typeof content !== 'object') return false;
    const c = content as Record<string, unknown>;
    return c.type === 'excalidraw' || (Array.isArray(c.elements) && c.appState !== undefined);
}

// Embedded read-only Excalidraw canvas (view mode = can pan/zoom, cannot edit)
function ExcalidrawViewMode({ pageUuid, content }: { pageUuid: string; content: unknown }) {
    const containerId = `excalidraw-preview-${pageUuid}`;
    const containerRef = useRef<HTMLDivElement>(null);
    const initializedRef = useRef(false);

    useEffect(() => {
        if (initializedRef.current) return;
        const c = (content ?? {}) as Record<string, unknown>;
        const elements = (c.elements ?? []) as object[];
        const appState = (c.appState ?? {}) as Record<string, unknown>;
        const files = (c.files ?? {}) as Record<string, unknown>;

        const init = () => {
            const w = window as unknown as { BrainitiveExcalidraw?: { init: (id: string, opts: object) => void } };
            if (!w.BrainitiveExcalidraw) return;
            initializedRef.current = true;
            w.BrainitiveExcalidraw.init(containerId, {
                viewModeEnabled: true,
                zenModeEnabled: true,
                initialData: { elements, appState: { ...appState, viewModeEnabled: true }, files },
            });
        };

        const w = window as unknown as { BrainitiveExcalidraw?: { init: (id: string, opts: object) => void } };
        if (w.BrainitiveExcalidraw) {
            init();
        } else {
            if (!document.getElementById('excalidraw-preview-css')) {
                const link = document.createElement('link');
                link.id = 'excalidraw-preview-css';
                link.rel = 'stylesheet';
                link.href = '/js/excalidraw-dist/excalidraw-bundle.css';
                document.head.appendChild(link);
            }
            if (!document.getElementById('excalidraw-preview-js')) {
                const script = document.createElement('script');
                script.id = 'excalidraw-preview-js';
                script.src = '/js/excalidraw-dist/excalidraw-bundle.iife.js';
                script.onload = init;
                document.head.appendChild(script);
            } else {
                // Script tag exists but may still be loading — poll briefly
                const poll = setInterval(() => {
                    if (w.BrainitiveExcalidraw) { clearInterval(poll); init(); }
                }, 100);
                setTimeout(() => clearInterval(poll), 10000);
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [containerId]);

    return <div id={containerId} ref={containerRef} style={{ width: '100%', height: 500 }} />;
}

// Page Icon Picker — full emoji-mart + custom image upload
function PageIconPicker({ value, onChange }: { value: string; onChange: (val: string) => void }) {
    const [open, setOpen] = useState(false);
    const [tab, setTab] = useState<'emoji' | 'upload'>('emoji');
    const fileRef = useRef<HTMLInputElement>(null);

    const isImage = value?.startsWith('data:') || value?.startsWith('http') || value?.startsWith('/');

    return (
        <>
            {/* Trigger button */}
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="group relative size-14 flex items-center justify-center text-5xl hover:bg-accent rounded-xl transition-colors"
                title="Change icon"
            >
                {isImage
                    ? <img src={value} className="size-12 rounded-lg object-cover" alt="icon" />
                    : <span>{value || '📄'}</span>}
                <span className="absolute inset-0 flex items-center justify-center bg-black/50 opacity-0 group-hover:opacity-100 rounded-xl transition-opacity text-white text-xs font-medium">
                    Edit
                </span>
            </button>

            {/* Picker popover */}
            {open && (
                <div className="fixed inset-0 z-50 flex items-start justify-center pt-20" onClick={() => setOpen(false)}>
                    <div
                        className="bg-popover border rounded-xl shadow-2xl overflow-hidden w-[360px]"
                        onClick={(e) => e.stopPropagation()}
                    >
                        {/* Tabs */}
                        <div className="flex border-b">
                            <button
                                className={cn('flex-1 py-2 text-sm font-medium transition-colors', tab === 'emoji' ? 'bg-accent text-foreground' : 'text-muted-foreground hover:text-foreground')}
                                onClick={() => setTab('emoji')}
                            >😀 Emoji</button>
                            <button
                                className={cn('flex-1 py-2 text-sm font-medium transition-colors', tab === 'upload' ? 'bg-accent text-foreground' : 'text-muted-foreground hover:text-foreground')}
                                onClick={() => setTab('upload')}
                            >🖼 Custom Image</button>
                        </div>

                        {tab === 'emoji' && (
                            <EmojiMartPicker
                                onEmojiSelect={(emoji: { native: string }) => {
                                    onChange(emoji.native);
                                    setOpen(false);
                                }}
                                theme="light"
                                previewPosition="none"
                                skinTonePosition="search"
                                perLine={9}
                            />
                        )}

                        {tab === 'upload' && (
                            <div className="p-6 flex flex-col items-center gap-4">
                                <p className="text-sm text-muted-foreground text-center">
                                    Upload a PNG or SVG image to use as the page icon.
                                </p>
                                {isImage && (
                                    <img src={value} className="size-20 rounded-xl object-cover border" alt="current icon" />
                                )}
                                <input
                                    ref={fileRef}
                                    type="file"
                                    accept="image/png,image/svg+xml,image/jpeg,image/webp"
                                    className="hidden"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0];
                                        if (!file) return;
                                        const reader = new FileReader();
                                        reader.onload = (ev) => {
                                            const result = ev.target?.result as string;
                                            onChange(result);
                                            setOpen(false);
                                        };
                                        reader.readAsDataURL(file);
                                    }}
                                />
                                <button
                                    type="button"
                                    onClick={() => fileRef.current?.click()}
                                    className="px-4 py-2 rounded-lg border border-dashed border-primary text-sm font-medium hover:bg-accent transition-colors w-full text-center"
                                >
                                    Choose file…
                                </button>
                                {isImage && (
                                    <button
                                        type="button"
                                        onClick={() => { onChange('📄'); setOpen(false); }}
                                        className="text-xs text-muted-foreground hover:text-destructive transition-colors"
                                    >
                                        Remove custom image
                                    </button>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            )}
        </>
    );
}

// Page Title Editor
function PageTitleEditor({
    title,
    onSave
}: {
    title: string;
    onSave: (title: string) => void;
}) {
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(title);
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (editing && inputRef.current) {
            inputRef.current.focus();
            inputRef.current.select();
        }
    }, [editing]);

    useEffect(() => {
        setValue(title);
    }, [title]);

    const handleSave = () => {
        if (value.trim() && value !== title) {
            onSave(value.trim());
        } else {
            setValue(title);
        }
        setEditing(false);
    };

    if (editing) {
        return (
            <input
                ref={inputRef}
                type="text"
                value={value}
                onChange={(e) => setValue(e.target.value)}
                onBlur={handleSave}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') handleSave();
                    if (e.key === 'Escape') {
                        setValue(title);
                        setEditing(false);
                    }
                }}
                className="text-4xl font-bold bg-transparent border-none outline-none focus:ring-0 w-full placeholder:text-muted-foreground/50"
                placeholder="Untitled"
            />
        );
    }

    return (
        <h1
            onClick={() => setEditing(true)}
            className="text-4xl font-bold cursor-text hover:bg-accent/50 rounded-lg px-1 -mx-1 transition-colors"
        >
            {title || 'Untitled'}
        </h1>
    );
}

// Child Page Card
function ChildPageCard({ page, workspace, project }: { page: PageType & { uuid?: string }; workspace: Workspace; project: Project }) {
    return (
        <Link
            href={route('pages.show', [workspace.slug, project.slug, page.uuid])}
            className="flex items-center gap-3 p-4 rounded-lg border hover:border-primary/50 hover:bg-accent/50 transition-colors group"
        >
            <span className="text-2xl">
                {(page.icon && (page.icon.startsWith('data:') || page.icon.startsWith('http') || page.icon.startsWith('/')))
                    ? <img src={page.icon} className="size-8 rounded-lg object-cover" alt="" />
                    : (page.icon || (page.type === 'folder' ? '📁' : '📄'))
                }
            </span>
            <div className="flex-1 min-w-0">
                <h3 className="font-medium truncate group-hover:text-primary">{page.name}</h3>
                <p className="text-sm text-muted-foreground">
                    {page.type === 'folder' ? 'Folder' : 'Page'}
                </p>
            </div>
            <ChevronRight className="size-5 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity" />
        </Link>
    );
}

function AddSubpageDialog({
    open,
    onClose,
    workspace,
    project,
    parentId,
}: {
    open: boolean;
    onClose: () => void;
    workspace: Workspace;
    project: Project;
    parentId: number;
}) {
    const { props } = usePage<PageProps>();
    const projectPages = (props.currentWorkspace?.projects?.find((p) => p.id === project.id) as (Project & { pages?: PageType[] }) | undefined)?.pages ?? [];

    const [itemType, setItemType] = useState<'page' | 'folder' | 'excalidraw'>('page');
    const { data, setData, post, processing, reset } = useForm<{
        name: string;
        type: 'page' | 'folder';
        parent_id: number | null;
        content_type: 'doc' | 'excalidraw';
    }>({
        name: '',
        type: 'page',
        parent_id: parentId,
        content_type: 'doc',
    });

    // Sync parent when dialog opens or parentId changes (e.g. opened from a page's "Add")
    useEffect(() => {
        if (open) {
            setData('parent_id', parentId);
        }
    }, [open, parentId]);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        post(route('pages.store', [workspace.slug, project.slug]), {
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add Subpage</DialogTitle>
                    <DialogDescription>
                        Create a new page, Whiteboard, or folder. Choose a parent to nest under Root or any folder/page.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4 pt-2">
                    <div className="flex gap-2 flex-wrap">
                        <button
                            type="button"
                            onClick={() => {
                                setItemType('page');
                                setData('type', 'page');
                                setData('content_type', 'doc');
                            }}
                            className={`flex-1 min-w-[80px] flex items-center justify-center gap-2 p-3 rounded-lg border transition-colors ${itemType === 'page' ? 'border-primary bg-primary/5' : 'hover:border-muted-foreground/50'
                                }`}
                        >
                            <FileText className="size-5" />
                            <span>Page</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                setItemType('excalidraw');
                                setData('type', 'page');
                                setData('content_type', 'excalidraw');
                            }}
                            className={`flex-1 min-w-[80px] flex items-center justify-center gap-2 p-3 rounded-lg border transition-colors ${itemType === 'excalidraw' ? 'border-primary bg-primary/5' : 'hover:border-muted-foreground/50'
                                }`}
                        >
                            <svg className="size-5 text-brand-slate" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="M12 19l7-7 3 3-7 7-3-3z" />
                                <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z" />
                                <path d="M2 2l7.586 7.586" />
                                <circle cx="11" cy="11" r="2" />
                            </svg>
                            <span>Whiteboard</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                setItemType('folder');
                                setData('type', 'folder');
                            }}
                            className={`flex-1 min-w-[80px] flex items-center justify-center gap-2 p-3 rounded-lg border transition-colors ${itemType === 'folder' ? 'border-primary bg-primary/5' : 'hover:border-muted-foreground/50'
                                }`}
                        >
                            <Folder className="size-5" />
                            <span>Folder</span>
                        </button>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="parent-select">Parent</Label>
                        <Select
                            value={data.parent_id === null || data.parent_id === undefined ? 'root' : String(data.parent_id)}
                            onValueChange={(v) => setData('parent_id', v === 'root' ? null : Number(v))}
                        >
                            <SelectTrigger id="parent-select">
                                <SelectValue placeholder="Select parent" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="root">Root</SelectItem>
                                {flattenPagesForParentSelect(projectPages, {
                                    foldersOnly: itemType === 'folder',
                                }).map((p) => (
                                    <SelectItem key={p.id} value={String(p.id)}>
                                        <span className="flex items-center gap-2" style={{ paddingLeft: `${p.depth * 14}px` }}>
                                            {p.type === 'folder' ? (
                                                <Folder className="size-3.5 shrink-0 text-brand-slate" />
                                            ) : p.is_whiteboard ? (
                                                <PencilRuler className="size-3.5 shrink-0 text-brand-slate" />
                                            ) : (
                                                <FileText className="size-3.5 shrink-0 text-primary" />
                                            )}
                                            {p.name}
                                        </span>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="name">{itemType === 'folder' ? 'Folder' : itemType === 'excalidraw' ? 'Whiteboard' : 'Page'} Name</Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder={itemType === 'folder' ? 'e.g., Documentation' : itemType === 'excalidraw' ? 'e.g., Diagram, Sketch' : 'e.g., Getting Started'}
                            autoFocus
                            required
                        />
                    </div>

                    <div className="flex justify-end gap-3 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : `Create ${itemType === 'folder' ? 'Folder' : itemType === 'excalidraw' ? 'Whiteboard' : 'Page'}`}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// Table of Contents (Lexical edition) – fixed sidebar on right
function PageTableOfContents({ headings, headerOffset, scrollRoot }: {
    headings: TocHeading[];
    headerOffset: number;
    scrollRoot?: RefObject<HTMLDivElement>;
}) {
    const [activeId, setActiveId] = useState<string>('');
    const tocRef = useRef<HTMLDivElement>(null);

    // Auto-scroll TOC to keep active item visible
    useEffect(() => {
        if (!activeId || !tocRef.current) return;
        const el = tocRef.current.querySelector<HTMLElement>(`[data-toc-id="${activeId}"]`);
        if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }, [activeId]);

    useEffect(() => {
        if (headings.length === 0) return;

        const els = Array.from(document.querySelectorAll<HTMLElement>(
            '.lexical-editor-content h1, .lexical-editor-content h2, .lexical-editor-content h3, .lexical-editor-content h4'
        ));
        if (els.length === 0) return;

        const root = scrollRoot?.current ?? null;
        const visibleAbove = new Set<number>();

        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    const idx = els.indexOf(entry.target as HTMLElement);
                    if (idx === -1) return;
                    if (entry.isIntersecting) {
                        visibleAbove.delete(idx);
                    } else if (entry.boundingClientRect.top < (root ? root.getBoundingClientRect().top : 0)) {
                        visibleAbove.add(idx);
                    } else {
                        visibleAbove.delete(idx);
                    }
                });

                if (visibleAbove.size > 0) {
                    const lastAbove = Math.max(...visibleAbove);
                    const nextIdx = lastAbove + 1 < els.length ? lastAbove + 1 : lastAbove;
                    setActiveId(`toc-${nextIdx}`);
                } else {
                    setActiveId(els.length > 0 ? 'toc-0' : '');
                }
            },
            {
                root, // use the actual scroll container, not viewport
                rootMargin: `-${headerOffset + 10}px 0px 0px 0px`,
                threshold: 0,
            }
        );

        els.forEach((el) => observer.observe(el));
        return () => observer.disconnect();
    }, [headings, headerOffset, scrollRoot]);

    if (headings.length === 0) return null;
    const minLevel = Math.min(...headings.map((h) => h.level));

    const scrollTo = (h: TocHeading) => {
        const els = document.querySelectorAll<HTMLElement>(
            '.lexical-editor-content h1, .lexical-editor-content h2, .lexical-editor-content h3, .lexical-editor-content h4'
        );
        const el = els[h.index];
        if (!el) return;
        // Let the browser figure out the scroll container - works with any overflow element
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    return (
        <div className="w-56">
            <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-3 px-2">
                <ListIcon className="size-3.5" />
                <span>On this page</span>
            </div>
            <nav ref={tocRef} className="space-y-0.5">
                {headings.map((h) => {
                    const depth = h.level - minLevel;
                    const isActive = activeId === h.id;
                    return (
                        <button
                            key={h.id}
                            data-toc-id={h.id}
                            onClick={() => scrollTo(h)}
                            className={cn(
                                'flex items-center w-full text-left rounded-md transition-colors',
                                'hover:bg-accent hover:text-accent-foreground',
                                depth === 0 && 'text-sm font-medium py-1 px-2',
                                depth === 1 && 'text-[13px] py-0.5',
                                depth >= 2 && 'text-xs py-0.5',
                                isActive
                                    ? 'text-primary bg-primary/5'
                                    : depth === 0 ? 'text-foreground/80' : 'text-muted-foreground',
                            )}
                            style={{ paddingLeft: `${8 + depth * 14}px` }}
                            title={h.text}
                        >
                            {depth > 0 && (
                                <span className="mr-1.5 shrink-0 text-muted-foreground/40">{'–'.repeat(depth)}</span>
                            )}
                            <span className="truncate">{h.text || 'Untitled'}</span>
                        </button>
                    );
                })}
            </nav>
        </div>
    );
}

// Extract HTML string from stored page content (handles lexical format and legacy formats)
function extractHtmlContent(content: Record<string, any> | null | undefined): string | null {
    if (!content) return null;
    // New Lexical format: { type: 'lexical', html: '...' }
    if (content.type === 'lexical' && typeof content.html === 'string') {
        return content.html;
    }
    return null;
}

export default function PageShow({ workspace, project, page, breadcrumbs }: Props) {
    // New whiteboards: redirect straight to the Excalidraw editor.
    // We arrive here (pages.show?new=1) because Inertia can't follow a redirect
    // to a non-Inertia blade view directly from a form post.
    useEffect(() => {
        if (!isExcalidrawPage(page.content)) return;
        const params = new URLSearchParams(window.location.search);
        if (params.get('new') === '1') {
            window.location.href = route('pages.excalidraw', [workspace.slug, project.slug, page.uuid]);
        }
    }, [page.uuid]);

    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [duplicateDialogOpen, setDuplicateDialogOpen] = useState(false);
    const [addSubpageOpen, setAddSubpageOpen] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [lastSaved, setLastSaved] = useState<Date | null>(null);
    const [headings, setHeadings] = useState<TocHeading[]>([]);
    const [isFullWidth, setIsFullWidth] = useState(() => {
        try { return localStorage.getItem(`page-full-width-${page.uuid}`) === 'true'; } catch { return false; }
    });
    const saveTimeoutRef = useRef<NodeJS.Timeout | null>(null);
    const contentPanelRef = useRef<HTMLDivElement>(null);

    // Watchers
    const currentUser = usePage<PageProps>().props.auth.user;
    const isWatching = page.watchers?.some(w => w.id === currentUser.id) ?? false;

    const toggleWatch = () => {
        router.post(
            route('pages.watch', [workspace.slug, project.slug, page.uuid]),
            { user_id: currentUser.id, watch: !isWatching },
            { preserveScroll: true }
        );
    };

    // Explicit update — saves content AND notifies watchers
    const handleExplicitUpdate = () => {
        router.put(
            route('pages.explicit-update', [workspace.slug, project.slug, page.uuid]),
            { content: JSON.stringify({ type: 'lexical', html: currentHtmlRef.current ?? '' }) },
            { preserveScroll: true, preserveState: true }
        );
    };

    // Ref to hold latest HTML content for the explicit update
    // Initialise with the current saved content so Update never sends an empty string
    // even if onChange hasn't fired yet (e.g. user clicks Edit → Update immediately).
    const currentHtmlRef = useRef<string | null>(extractHtmlContent(page.content as any));

    // Edit mode state - published pages start in view mode
    const [isEditing, setIsEditing] = useState(page.status !== 'published');

    // Local state for form data
    const [formData, setFormData] = useState({
        name: page.name,
        icon: page.icon || '📄',
    });


    // Update formData and editing state when page prop changes
    useEffect(() => {
        setFormData({
            name: page.name,
            icon: page.icon || '📄',
        });
        // Reset to view mode if page is published
        setIsEditing(page.status !== 'published');
    }, [page.uuid, page.status]);

    const handleUpdateName = (name: string) => {
        setFormData(prev => ({ ...prev, name }));
        router.put(route('pages.update', [workspace.slug, project.slug, page.uuid]), {
            name,
        }, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const handleUpdateIcon = (icon: string) => {
        setFormData(prev => ({ ...prev, icon }));
        router.put(route('pages.update', [workspace.slug, project.slug, page.uuid]), {
            icon,
        }, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    // Debounced content save - receives HTML string from RichLexicalEditor
    const handleUpdateContent = useCallback((html: string) => {
        // Guard: don't process onChange calls that fire during the view→edit
        // remount before InitialContentPlugin has finished populating the editor.
        // Those spurious calls carry empty or partially-loaded HTML.
        if (!isEditing) return;

        currentHtmlRef.current = html;  // track latest for explicit update
        setIsSaving(true);

        // Clear any existing timeout
        if (saveTimeoutRef.current) {
            clearTimeout(saveTimeoutRef.current);
        }

        // Debounce save - wait 1 second after last change
        saveTimeoutRef.current = setTimeout(() => {
            // Defer auto-save if a Lexical menu (like Mentions or Slash command) is currently open
            if (document.querySelector('[data-lexical-menu="true"]')) {
                handleUpdateContent(html);
                return;
            }

            router.put(route('pages.update', [workspace.slug, project.slug, page.uuid]), {
                content: JSON.stringify({ type: 'lexical', html }),
            }, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setIsSaving(false);
                    setLastSaved(new Date());
                },
                onError: () => {
                    setIsSaving(false);
                },
            });
        }, 1000);
    }, [workspace.slug, project.slug, page.uuid, isEditing]);

    // Cleanup timeout on unmount
    useEffect(() => {
        return () => {
            if (saveTimeoutRef.current) {
                clearTimeout(saveTimeoutRef.current);
            }
        };
    }, []);

    const handlePublish = () => {
        router.post(route('pages.publish', [workspace.slug, project.slug, page.uuid]), {}, {
            preserveScroll: true,
            onSuccess: () => {
                setIsEditing(false); // Switch to view mode after publishing
            },
        });
    };

    const handleUnpublish = () => {
        router.post(route('pages.unpublish', [workspace.slug, project.slug, page.uuid]), {}, {
            preserveScroll: true,
            onSuccess: () => {
                setIsEditing(true); // Switch to edit mode after unpublishing
            },
        });
    };

    const handleArchive = () => {
        router.post(route('pages.archive', [workspace.slug, project.slug, page.uuid]), {}, {
            preserveScroll: true,
        });
    };

    const handleDuplicate = (withSubpages: boolean = false) => {
        router.post(route('pages.duplicate', [workspace.slug, project.slug, page.uuid]), {
            with_subpages: withSubpages
        }, {
            preserveScroll: true,
            onSuccess: () => setDuplicateDialogOpen(false)
        });
    };

    const triggerDuplicate = () => {
        if (page.children && page.children.length > 0) {
            setDuplicateDialogOpen(true);
        } else {
            handleDuplicate(false);
        }
    };

    const handleDelete = () => {
        router.delete(route('pages.destroy', [workspace.slug, project.slug, page.uuid]));
    };

    const getStatusBadge = () => {
        switch (page.status) {
            case 'published':
                return <Badge variant="default" className="bg-brand-green hover:bg-brand-green/90"><Globe className="size-3 mr-1" /> Published</Badge>;
            case 'archived':
                return <Badge variant="secondary"><Archive className="size-3 mr-1" /> Archived</Badge>;
            default:
                return <Badge variant="outline"><FileEdit className="size-3 mr-1" /> Draft</Badge>;
        }
    };

    return (
        <AppLayout>
            <Head title={`${page.name} - ${project.name}`} />

            <div className="h-full flex flex-col bg-background">

                {/* Breadcrumb bar — always visible, outside the scroll panels */}
                <div className="shrink-0 border-b bg-background/95 backdrop-blur z-10">
                    <div className={cn("mx-auto px-6 py-3", !isFullWidth && "max-w-6xl")}>
                        <div className="flex items-center justify-between">
                            {/* Breadcrumbs */}
                            <Breadcrumb>
                                <BreadcrumbList>
                                    <BreadcrumbItem>
                                        <BreadcrumbLink asChild>
                                            <Link href={route('projects.show', [workspace.slug, project.slug])} className="flex items-center gap-2">
                                                <ProjectIcon project={project} size="xs" />
                                                {project.name}
                                            </Link>
                                        </BreadcrumbLink>
                                    </BreadcrumbItem>
                                    {breadcrumbs.map((crumb, index) => (
                                        <Fragment key={crumb.id}>
                                            <BreadcrumbSeparator />
                                            <BreadcrumbItem>
                                                {index === breadcrumbs.length - 1 ? (
                                                    <BreadcrumbPage>{crumb.name}</BreadcrumbPage>
                                                ) : (
                                                    <BreadcrumbLink asChild>
                                                        <Link href={route('pages.show', [workspace.slug, project.slug, crumb.uuid])}>
                                                            {crumb.name}
                                                        </Link>
                                                    </BreadcrumbLink>
                                                )}
                                            </BreadcrumbItem>
                                        </Fragment>
                                    ))}
                                </BreadcrumbList>
                            </Breadcrumb>

                            {/* Actions */}
                            <div className="flex items-center gap-2">
                                {/* Save Status - only show when editing */}
                                {isEditing && isSaving && (
                                    <span className="text-xs text-muted-foreground flex items-center gap-1">
                                        <Save className="size-3 animate-pulse" />
                                        Saving...
                                    </span>
                                )}
                                {isEditing && !isSaving && lastSaved && (
                                    <span className="text-xs text-muted-foreground flex items-center gap-1">
                                        <Check className="size-3 text-brand-green" />
                                        Saved
                                    </span>
                                )}

                                {/* Status Badge */}
                                {getStatusBadge()}

                                {/* Watch / Watching button */}
                                {page.type === 'page' && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className={cn('h-8 px-2.5 text-xs gap-1.5 cursor-pointer', isWatching && 'bg-primary/5 text-primary border-primary/20')}
                                        onClick={toggleWatch}
                                    >
                                        {isWatching ? (
                                            <><BellRing className="size-3.5" />Watching</>
                                        ) : (
                                            <><Bell className="size-3.5" />Watch</>
                                        )}
                                    </Button>
                                )}

                                {/* Edit Button - show when in view mode and page is published */}
                                {!isEditing && page.status === 'published' && page.type === 'page' && (
                                    <Button size="sm" variant="outline" onClick={() => setIsEditing(true)}>
                                        <Pencil className="size-4 mr-1.5" />
                                        Edit
                                    </Button>
                                )}

                                {/* Publish Button - show when editing a draft */}
                                {isEditing && page.status === 'draft' && page.type === 'page' && (
                                    <Button size="sm" onClick={handlePublish}>
                                        <Globe className="size-4 mr-1.5" />
                                        Publish
                                    </Button>
                                )}

                                {/* Publish Button - show when editing a published page */}
                                {isEditing && page.status === 'published' && page.type === 'page' && (
                                    <>
                                        <Button size="sm" variant="outline" onClick={() => setIsEditing(false)}>
                                            <Eye className="size-4 mr-1.5" />
                                            Done
                                        </Button>
                                        <Button size="sm" onClick={handleExplicitUpdate}>
                                            <Globe className="size-4 mr-1.5" />
                                            Update
                                        </Button>
                                    </>
                                )}

                                {/* Unpublish in dropdown for published pages */}

                                {/* Full-width toggle */}
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-8"
                                    title={isFullWidth ? 'Exit full width' : 'Full width'}
                                    onClick={() => {
                                        const next = !isFullWidth;
                                        setIsFullWidth(next);
                                        try { localStorage.setItem(`page-full-width-${page.uuid}`, String(next)); } catch { }
                                    }}
                                >
                                    {isFullWidth ? <Minimize2 className="size-4" /> : <Maximize2 className="size-4" />}
                                </Button>

                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="ghost" size="icon" className="size-8">
                                            <MoreHorizontal className="size-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" className="w-48">
                                        {page.status === 'published' && (
                                            <DropdownMenuItem onClick={handleUnpublish}>
                                                <FileEdit className="size-4 mr-2" />
                                                Unpublish (to Draft)
                                            </DropdownMenuItem>
                                        )}
                                        {page.status !== 'archived' && (
                                            <DropdownMenuItem onClick={handleArchive}>
                                                <Archive className="size-4 mr-2" />
                                                Archive
                                            </DropdownMenuItem>
                                        )}
                                        {page.status === 'archived' && (
                                            <DropdownMenuItem onClick={handleUnpublish}>
                                                <FileEdit className="size-4 mr-2" />
                                                Restore to Draft
                                            </DropdownMenuItem>
                                        )}
                                        <DropdownMenuItem onClick={triggerDuplicate}>
                                            <Files className="size-4 mr-2" />
                                            Duplicate
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            className="text-destructive focus:text-destructive"
                                            onClick={() => setDeleteDialogOpen(true)}
                                        >
                                            <Trash2 className="size-4 mr-2" />
                                            Delete
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Two-panel row: content | TOC — each scrolls independently */}
                <div className="flex-1 overflow-hidden flex">

                    {/* Page content panel */}
                    <div ref={contentPanelRef} className="flex-1 overflow-y-auto bg-white">
                        <div className={cn(
                            "mx-auto px-6 pt-6 pb-8",
                            !isFullWidth && "max-w-6xl"
                        )}>
                            {/* Icon and Title */}
                            <div className="mb-8">
                                <div className="flex items-center gap-3">
                                    {isEditing ? (
                                        <PageIconPicker value={formData.icon} onChange={handleUpdateIcon} />
                                    ) : (
                                        (formData.icon?.startsWith('data:') || formData.icon?.startsWith('http') || formData.icon?.startsWith('/'))
                                            ? <img src={formData.icon} className="size-14 rounded-xl object-cover" alt="icon" />
                                            : <span className="text-5xl">{formData.icon || '📄'}</span>
                                    )}
                                    {isEditing ? (
                                        <PageTitleEditor title={formData.name} onSave={handleUpdateName} />
                                    ) : (
                                        <h1 className="text-4xl font-bold">{formData.name || 'Untitled'}</h1>
                                    )}
                                </div>
                                <div className="flex items-center gap-4 mt-4 text-sm text-muted-foreground flex-wrap">
                                    {page.creator && (
                                        <div className="flex items-center gap-1.5">
                                            <UserIcon className="size-4" />
                                            <span>Created by {page.creator.name}</span>
                                        </div>
                                    )}
                                    {page.lastEditor && (
                                        <div className="flex items-center gap-1.5">
                                            <UserIcon className="size-4" />
                                            <span>Last edited by {page.lastEditor.name}</span>
                                        </div>
                                    )}
                                    <div className="flex items-center gap-1.5">
                                        <Clock className="size-4" />
                                        <span>Updated {new Date(page.updated_at).toLocaleDateString()}</span>
                                    </div>
                                    {page.published_at && page.status === 'published' && (
                                        <div className="flex items-center gap-1.5">
                                            <Globe className="size-4" />
                                            <span>Published {new Date(page.published_at).toLocaleDateString()}</span>
                                        </div>
                                    )}
                                    {/* Watcher avatars */}
                                    {(page.watchers?.length ?? 0) > 0 && (
                                        <div className="flex items-center gap-1.5">
                                            <Users className="size-4" />
                                            <div className="flex -space-x-1.5 overflow-hidden">
                                                {(page.watchers ?? []).slice(0, 5).map(w => (
                                                    <Avatar key={w.id} className="size-5 border-2 border-background" title={w.name}>
                                                        <AvatarFallback className="text-[9px] bg-muted">{w.name.charAt(0)}</AvatarFallback>
                                                    </Avatar>
                                                ))}
                                                {(page.watchers?.length ?? 0) > 5 && (
                                                    <div className="size-5 rounded-full border-2 border-background bg-muted flex items-center justify-center text-[9px] font-medium text-muted-foreground">
                                                        +{(page.watchers?.length ?? 0) - 5}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>

                            <Separator className="mb-2" />

                            {/* Whiteboard page: embedded view-mode canvas + open editor button */}
                            {page.type === 'page' && isExcalidrawPage(page.content) && (
                                <div className="mb-8">
                                    <div className="rounded-lg border border-border overflow-hidden bg-white dark:bg-card">
                                        <ExcalidrawViewMode pageUuid={page.uuid} content={page.content} />
                                        <div className="px-4 py-3 border-t border-border bg-muted/20 flex items-center justify-between gap-3">
                                            <span className="text-sm text-muted-foreground">View mode · pan and zoom freely</span>
                                            <a
                                                href={route('pages.excalidraw', [workspace.slug, project.slug, page.uuid])}
                                                className="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-primary text-primary-foreground text-sm font-medium hover:bg-primary/90 transition-colors"
                                            >
                                                <PencilRuler className="size-4" />
                                                Open whiteboard
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Page Content - RichLexicalEditor (documents only; Excalidraw pages use preview above) */}
                            {page.type === 'page' && !isExcalidrawPage(page.content) && (
                                <RichLexicalEditor
                                    key={`${page.uuid}-${isEditing}`}
                                    content={extractHtmlContent(page.content as any)}
                                    onChange={handleUpdateContent}
                                    editable={isEditing}
                                    placeholder="Click here and start writing, or press / for commands..."
                                    showToolbar={isEditing}
                                    minHeight="400px"
                                    workspaceSlug={workspace.slug}
                                    contentKey={`page-${page.uuid}-${isEditing}`}
                                    onHeadingsChange={setHeadings}
                                    className="border-0 rounded-none shadow-none bg-transparent overflow-visible"
                                    stickyToolbar={isEditing}
                                    stickyTopOffset={0}
                                    projectId={project.id}
                                    mentionContextType="page"
                                    mentionContextTitle={page.name}
                                    mentionContextUrl={route('pages.show', [workspace.slug, project.slug, page.uuid])}
                                />
                            )}

                            {/* Subpages (for folders or pages with children) */}
                            {(page.type === 'folder' || (page.children && page.children.length > 0)) && (
                                <div className="mt-8">
                                    <div className="flex items-center justify-between mb-4">
                                        <h2 className="text-lg font-semibold">
                                            {page.type === 'folder' ? 'Contents' : 'Subpages'}
                                        </h2>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setAddSubpageOpen(true)}
                                            className="gap-1.5"
                                        >
                                            <Plus className="size-4" />
                                            Add
                                        </Button>
                                    </div>

                                    {page.children && page.children.length > 0 ? (
                                        <div className="grid gap-2">
                                            {page.children.map((child) => (
                                                <ChildPageCard
                                                    key={child.id}
                                                    page={child}
                                                    workspace={workspace}
                                                    project={project}
                                                />
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="text-center py-12 border rounded-lg border-dashed">
                                            <div className="size-12 rounded-full bg-muted mx-auto mb-4 flex items-center justify-center">
                                                <FileText className="size-6 text-muted-foreground" />
                                            </div>
                                            <h3 className="text-lg font-semibold mb-2">No subpages yet</h3>
                                            <p className="text-muted-foreground mb-4">
                                                Add pages or folders to organize your content.
                                            </p>
                                            <Button onClick={() => setAddSubpageOpen(true)} className="gap-1.5">
                                                <Plus className="size-4" />
                                                Add Subpage
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* TOC panel — own scroll, naturally aligned next to content */}
                    {page.type === 'page' && !isExcalidrawPage(page.content) && headings.length > 0 && (
                        <div className="hidden xl:flex w-64 shrink-0 flex-col border-l bg-background overflow-y-auto overflow-x-hidden px-4 pt-6">
                            <PageTableOfContents
                                headings={headings}
                                headerOffset={0}
                                scrollRoot={contentPanelRef}
                            />
                        </div>
                    )}

                </div>{/* end panels row */}
            </div>

            {/* Delete Confirmation Dialog */}
            <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete {page.type === 'folder' ? 'folder' : 'page'}?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to delete "{page.name}"?
                            {page.children && page.children.length > 0 && (
                                <span className="block mt-2 font-medium text-destructive">
                                    This will also delete all {page.children.length} subpage(s) inside.
                                </span>
                            )}
                            This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleDelete}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        >
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Duplicate Page Dialog */}
            <Dialog open={duplicateDialogOpen} onOpenChange={setDuplicateDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Duplicate Page</DialogTitle>
                        <DialogDescription>
                            You are about to duplicate the page <strong>{page.name}</strong>.
                            This page has {page.children?.length} subpage(s).
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex flex-col gap-3 mt-4">
                        <Button onClick={() => handleDuplicate(false)} variant="outline">
                            Duplicate Page Only
                        </Button>
                        <Button onClick={() => handleDuplicate(true)}>
                            Duplicate with Subpages
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>

            {/* Add Subpage Dialog */}
            <AddSubpageDialog
                open={addSubpageOpen}
                onClose={() => setAddSubpageOpen(false)}
                workspace={workspace}
                project={project}
                parentId={page.id}
            />
        </AppLayout>
    );
}
