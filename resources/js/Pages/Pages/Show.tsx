import AppLayout from '@/Layouts/AppLayout';
import { safeHtml } from '@/lib/sanitize';
import { Head, useForm, router, Link, usePage } from '@inertiajs/react';
import { PageProps, Workspace, Project, Page as PageType, PageDraftState, PageDraftHashState, PageReviewShowState, PageCollabProps, PagePresenceCollabState, User, WhiteboardCollabProps } from '@/types';
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
    DropdownMenuCheckboxItem,
    DropdownMenuItem,
    DropdownMenuLabel,
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
import { ProjectIcon } from '@/components/ProjectIcon';
import {
    MoreHorizontal,
    FileText,
    Folder,
    Plus,
    Trash2,
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
    Files,
    FileDown,
    FileCode,
    Printer,
    PanelTop,
    Lock,
    AlertTriangle,
    Loader2,
    History,
    MessageSquareText,
    Undo2,
    RefreshCw,
    ListChecks,
    ShieldCheck,
    MessageSquareMore,
    CloudOff,
    Users,
} from 'lucide-react';
import { toast } from 'sonner';
import RequestReviewDialog from '@/components/page-review/RequestReviewDialog';
import PageReviewBanner from '@/components/page-review/PageReviewBanner';
import TurndownService from 'turndown';
import { useState, useEffect, useRef, RefObject, FormEvent, Fragment, useCallback, useMemo, Suspense, lazy } from 'react';
import { usePagePresence } from '@/hooks/usePagePresence';
import { useLiveDocument } from '@/components/editor/collab/useLiveDocument';
import { PageHistoryDialog } from '@/components/page/PageHistoryDialog';
import { ExcalidrawViewMode } from '@/components/page/ExcalidrawViewMode';
import { useLiveWhiteboardView } from '@/hooks/useLiveWhiteboardView';
import type { ExcalidrawStatics, WhiteboardHandle } from '@/lib/whiteboard/live';
import RelatedContent from '@/components/references/RelatedContent';
import { Textarea } from '@/components/ui/textarea';

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
import ShareButton from '@/components/share/ShareButton';
import ExtractActionItemsDialog from '@/components/meetingNotes/ExtractActionItemsDialog';
import SubjectMeetings from '@/components/meetings/SubjectMeetings';
import { Video } from 'lucide-react';

interface Props extends PageProps {
    workspace: Workspace;
    project: Project;
    page: PageType & {
        children?: PageType[];
        uuid: string;
        status: 'draft' | 'published' | 'archived';
        published_at?: string;
        watchers?: User[];
    };
    breadcrumbs: { id: number; name: string; slug: string; uuid: string; type: string }[];
    can: { update: boolean; delete: boolean; create: boolean };
    /** document review (BRAI-80); null for folders and whiteboards */
    review: PageReviewShowState | null;
    /** live co-editing (BRAI-54); null = the single-user editor */
    collab: PageCollabProps | null;
    /** live whiteboard preview (BRAI-153); null = a static preview */
    whiteboardCollab?: WhiteboardCollabProps | null;
}

// Detect if page content is Excalidraw (full-page whiteboard)
function isExcalidrawPage(content: unknown): boolean {
    if (!content || typeof content !== 'object') return false;
    const c = content as Record<string, unknown>;
    return c.type === 'excalidraw' || (Array.isArray(c.elements) && c.appState !== undefined);
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
                className="group relative -ml-1.5 size-14 flex items-center justify-center text-5xl hover:bg-accent rounded-xl transition-colors"
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

const PAGE_TITLE_CLASS = 'text-[2.25rem] font-bold leading-[1.2] tracking-[-0.02em] md:text-[2.5rem]';

// Page title — edited in place like Outline; Enter jumps into the body
function PageTitleInput({
    title,
    onSave
}: {
    title: string;
    onSave: (title: string) => void;
}) {
    const [value, setValue] = useState(title);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const discardRef = useRef(false);

    useEffect(() => {
        setValue(title);
    }, [title]);

    // Grow with the content so long titles wrap instead of scrolling
    useEffect(() => {
        const el = textareaRef.current;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = `${el.scrollHeight}px`;
    }, [value]);

    const handleSave = () => {
        const trimmed = value.trim();
        if (!discardRef.current && trimmed && trimmed !== title) {
            onSave(trimmed);
        } else {
            setValue(title);
        }
        discardRef.current = false;
    };

    return (
        <textarea
            ref={textareaRef}
            rows={1}
            value={value}
            onChange={(e) => setValue(e.target.value.replace(/\n/g, ' '))}
            onBlur={handleSave}
            onKeyDown={(e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.currentTarget.blur();
                    document.querySelector<HTMLElement>('.lexical-editor-content')?.focus();
                }
                if (e.key === 'Escape') {
                    discardRef.current = true;
                    e.currentTarget.blur();
                }
            }}
            className={cn(PAGE_TITLE_CLASS, 'block w-full resize-none overflow-hidden border-none bg-transparent p-0 outline-none placeholder:text-muted-foreground/40 focus:ring-0')}
            placeholder="Untitled"
            maxLength={255}
            aria-label="Page title"
        />
    );
}

const relativeTimeFormat = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

/** "3 hours ago", "yesterday", … — falls back to a date after a month. */
function formatRelativeTime(value: string): string {
    const date = new Date(value);
    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const abs = Math.abs(seconds);
    if (abs < 60) return 'just now';
    if (abs < 3600) return relativeTimeFormat.format(Math.round(seconds / 60), 'minute');
    if (abs < 86400) return relativeTimeFormat.format(Math.round(seconds / 3600), 'hour');
    if (abs < 86400 * 30) return relativeTimeFormat.format(Math.round(seconds / 86400), 'day');
    return date.toLocaleDateString();
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
            <div className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3 px-2">
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
                                'relative flex items-center w-full text-left rounded-md border-l-2 border-transparent transition-colors',
                                'hover:bg-accent/60 hover:text-accent-foreground',
                                depth === 0 && 'text-sm font-medium py-1 px-2',
                                depth === 1 && 'text-[13px] py-0.5',
                                depth >= 2 && 'text-xs py-0.5',
                                isActive
                                    ? 'border-primary text-foreground bg-transparent'
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

function downloadBlob(filename: string, content: string, mimeType: string) {
    const blob = new Blob([content], { type: mimeType });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

const EXPORT_HTML_STYLES = `
  body{font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;max-width:860px;margin:0 auto;padding:48px 32px;color:#1a1a1a;line-height:1.7}
  h1{font-size:2.25rem;font-weight:700;margin:2rem 0 .5rem}
  h2{font-size:1.5rem;font-weight:600;margin:1.75rem 0 .4rem}
  h3{font-size:1.25rem;font-weight:600;margin:1.5rem 0 .3rem}
  h4,h5,h6{font-weight:600;margin:1.25rem 0 .25rem}
  p{margin:.75rem 0}
  ul,ol{padding-left:1.5rem;margin:.75rem 0}
  li{margin:.25rem 0}
  code{background:#f3f4f6;padding:.15em .4em;border-radius:4px;font-size:.875em;font-family:'JetBrains Mono','Fira Code',monospace}
  pre{background:#1e1e2e;color:#cdd6f4;padding:1rem 1.25rem;border-radius:8px;overflow-x:auto;font-size:.875rem;line-height:1.6}
  pre code{background:none;padding:0;color:inherit}
  blockquote{border-left:4px solid #0E83DC;margin:1rem 0;padding:.5rem 1rem;background:#f0f7ff;color:#374151;font-style:italic}
  table{border-collapse:collapse;width:100%;margin:1rem 0}
  th,td{border:1px solid #e5e7eb;padding:.5rem .75rem;text-align:left}
  th{background:#f9fafb;font-weight:600}
  a{color:#0E83DC;text-decoration:underline}
  hr{border:none;border-top:1px solid #e5e7eb;margin:2rem 0}
  img{max-width:100%;border-radius:6px}
  @media print{@page{margin:20mm 25mm}body{max-width:none;padding:0}}
`;

type DraftChanges = { content?: string; name?: string; icon?: string };

export default function PageShow({ workspace, project, page, breadcrumbs, can, review, collab, whiteboardCollab = null }: Props) {
    // New whiteboards: redirect straight to the Excalidraw editor.
    // We arrive here (pages.show?new=1) because Inertia can't follow a redirect
    // to a non-Inertia blade view directly from a form post.
    useEffect(() => {
        if (!isExcalidrawPage(page.content)) return;
        const params = new URLSearchParams(window.location.search);
        if (params.get('new') === '1') {
            window.location.href = route('pages.excalidraw', [workspace.slug, project.slug, page.uuid]) + '?new=1';
        }
    }, [page.uuid]);

    const routeParams = useMemo(
        () => [workspace.slug, project.slug, page.uuid] as [string, string, string],
        [workspace.slug, project.slug, page.uuid],
    );
    const isPublished = page.status === 'published';
    const isDocument = page.type === 'page' && !isExcalidrawPage(page.content);
    // Smart links (BRAI-143): live chips, paste-to-chip and the "/link" picker
    const smartLinkConfig = useMemo(() => ({ workspaceSlug: workspace.slug, projectSlug: project.slug }), [workspace.slug, project.slug]);

    // What the editor works on: the shared draft of a published page, or the page itself
    const editSource = isPublished && page.draft_updated_at
        ? { content: page.draft_content ?? page.content, name: page.draft_name || page.name, icon: page.draft_icon ?? page.icon }
        : { content: page.content, name: page.name, icon: page.icon };

    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [duplicateDialogOpen, setDuplicateDialogOpen] = useState(false);
    const [addSubpageOpen, setAddSubpageOpen] = useState(false);
    const [discardDialogOpen, setDiscardDialogOpen] = useState(false);
    const [changeNoteDialogOpen, setChangeNoteDialogOpen] = useState(false);
    // ?history=1 comes from the whiteboard editor's History button
    const [actionItemsOpen, setActionItemsOpen] = useState(false);
    const [meetingOpen, setMeetingOpen] = useState(false); // "Schedule meeting" (BRAI-147)
    const [historyOpen, setHistoryOpen] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('history') === '1');
    const [isSaving, setIsSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);
    const [isPublishing, setIsPublishing] = useState(false);
    const [lastSaved, setLastSaved] = useState<Date | null>(null);
    const [headings, setHeadings] = useState<TocHeading[]>([]);
    // Full width by default (v1.42.0); a document remembers "Exit full width" per browser
    const [isFullWidth, setIsFullWidth] = useState(() => {
        try { return localStorage.getItem(`page-full-width-${page.uuid}`) !== 'false'; } catch { return true; }
    });
    // Classic formatting toolbar is opt-in; the floating selection toolbar covers everyday formatting
    const [showFormattingToolbar, setShowFormattingToolbar] = useState(() => {
        try { return localStorage.getItem('page-formatting-toolbar') === 'true'; } catch { return false; }
    });
    const [isScrolled, setIsScrolled] = useState(false);

    // Confluence-style editing: never-published pages open in the editor; published pages
    // open for reading and collect edits in a shared draft until "Update"
    const [isEditing, setIsEditing] = useState(() => can.update && page.status === 'draft');
    const [hasDraft, setHasDraft] = useState(isPublished && !!page.draft_updated_at);
    const [draftEditor, setDraftEditor] = useState(page.draft_editor ?? null);
    const [draftUpdatedAt, setDraftUpdatedAt] = useState(page.draft_updated_at ?? null);
    const [conflict, setConflict] = useState<{ name: string | null } | null>(null);
    const [remoteChange, setRemoteChange] = useState<{ name: string | null } | null>(null);
    const [notifyWatchers, setNotifyWatchers] = useState(true);
    const [changeNote, setChangeNote] = useState('');
    const [editorKey, setEditorKey] = useState(0);
    // Document review (BRAI-80): hash of the saved draft, compared with the latest review's snapshot
    const [draftHash, setDraftHash] = useState<string | null>(review?.draft_hash ?? null);
    const [requestReviewOpen, setRequestReviewOpen] = useState(false);
    const [formData, setFormData] = useState({ name: editSource.name, icon: editSource.icon || '📄' });

    const saveTimeoutRef = useRef<NodeJS.Timeout | null>(null);
    const contentPanelRef = useRef<HTMLDivElement>(null);
    const revisionRef = useRef(page.edit_revision ?? 0);
    const pendingChangesRef = useRef<DraftChanges>({});
    const savePromiseRef = useRef<Promise<void> | null>(null);
    const conflictRef = useRef(false);
    const isEditingRef = useRef(isEditing);
    isEditingRef.current = isEditing;
    // Latest editor HTML (exports + Update); starts from the saved content
    const currentHtmlRef = useRef<string | null>(extractHtmlContent(editSource.content as any));

    // Watchers
    const currentUser = usePage<PageProps>().props.auth.user;
    // Live whiteboards (BRAI-153): the preview shows everyone's drawing and cursors as they happen
    // (a reload mounts a new canvas for a new revision: never reconnect the old, detached one)
    const [whiteboardCanvas, setWhiteboardCanvas] = useState<{ handle: WhiteboardHandle; statics: ExcalidrawStatics; revision: number } | null>(null);
    const onWhiteboardReady = useCallback((handle: WhiteboardHandle, statics: ExcalidrawStatics) => setWhiteboardCanvas({ handle, statics, revision: page.edit_revision ?? 0 }), [page.edit_revision]);
    const liveBoard = useLiveWhiteboardView({
        canvas: whiteboardCanvas?.revision === (page.edit_revision ?? 0) ? whiteboardCanvas : null,
        collab: whiteboardCollab,
        routeParams,
        user: { id: currentUser.id, name: currentUser.name },
    });
    const isWatching = page.watchers?.some(w => w.id === currentUser.id) ?? false;
    const otherWatcherCount = page.watchers?.filter(w => w.id !== currentUser.id).length ?? 0;

    const toggleWatch = () => {
        router.post(
            route('pages.watch', routeParams),
            { watch: !isWatching },
            { preserveScroll: true }
        );
    };

    const applyDraftState = useCallback((state: PageDraftHashState) => {
        revisionRef.current = state.edit_revision;
        if (state.draft_hash) setDraftHash(state.draft_hash);
        setHasDraft(state.has_unpublished_changes);
        setDraftEditor(state.draft_updated_by);
        setDraftUpdatedAt(state.draft_updated_at);
    }, []);

    // Live co-editing (BRAI-54): editors while editing, readers of a never-published page read-only.
    // Everything else (no realtime server configured, whiteboards, published pages for readers) as before.
    const liveWanted = !!collab && isDocument && (isEditing || (!can.update && page.status === 'draft'));
    const live = useLiveDocument({
        enabled: liveWanted,
        collab,
        routeParams,
        user: { id: currentUser.id, name: currentUser.name },
        fallback: { name: editSource.name, icon: editSource.icon ?? null },
        canUpdate: can.update,
        onDraftState: (state) => {
            applyDraftState(state);
            setLastSaved(new Date());
        },
    });
    // the shared document is shown once it has content; until then the stored draft, read-only
    const isLive = live.mode === 'live' && live.ready;
    const liveBusy = live.mode === 'connecting' || (live.mode === 'live' && !live.ready);
    const liveRef = useRef(live);
    liveRef.current = live;
    // single-user editing after the draft was replaced while live: start from the stored draft, not the page load's
    useEffect(() => {
        if (live.mode === 'fallback' && live.generation > 0) router.reload({ only: ['page'] });
    }, [live.mode, live.generation]);

    // Send queued changes; saves run one after another so revisions stay in order
    const flushSave = useCallback((force = false): Promise<void> => {
        if (saveTimeoutRef.current) {
            clearTimeout(saveTimeoutRef.current);
            saveTimeoutRef.current = null;
        }
        const changes = pendingChangesRef.current;
        if (Object.keys(changes).length === 0) {
            return savePromiseRef.current ?? Promise.resolve();
        }
        pendingChangesRef.current = {};
        const previous = savePromiseRef.current;

        const run = (async () => {
            await previous?.catch(() => {});
            setIsSaving(true);
            try {
                const { data } = await window.axios.put(route('pages.draft.save', routeParams), {
                    ...changes,
                    base_revision: revisionRef.current,
                    force,
                });
                applyDraftState(data);
                setLastSaved(new Date());
                setSaveError(null);
                if (force) {
                    conflictRef.current = false;
                    setConflict(null);
                    setRemoteChange(null);
                }
            } catch (error: any) {
                // Keep the changes so they can be retried / forced
                pendingChangesRef.current = { ...changes, ...pendingChangesRef.current };
                if (error?.response?.status === 409) {
                    conflictRef.current = true;
                    setConflict({ name: error.response.data?.conflict?.updated_by?.name ?? null });
                } else {
                    setSaveError('Not saved — check your connection');
                }
            } finally {
                setIsSaving(false);
            }
        })();

        savePromiseRef.current = run;
        return run;
    }, [routeParams, applyDraftState]);

    const queueSave = useCallback((changes: DraftChanges, immediate = false) => {
        pendingChangesRef.current = { ...pendingChangesRef.current, ...changes };
        if (conflictRef.current) return;
        if (saveTimeoutRef.current) clearTimeout(saveTimeoutRef.current);
        if (immediate) {
            flushSave();
            return;
        }
        // Debounce - wait 1 second after the last change
        saveTimeoutRef.current = setTimeout(() => {
            // Defer auto-save if a Lexical menu (like Mentions or Slash command) is currently open
            if (document.querySelector('[data-lexical-menu="true"]')) {
                queueSave({});
                return;
            }
            flushSave();
        }, 1000);
    }, [flushSave]);

    // Who else is here — and did someone else save while we were looking?
    const presentUsers = usePagePresence(route('pages.presence', routeParams), isEditing, (state) => {
        const hash = (state as PageDraftHashState).draft_hash;
        if (hash && can.update) setDraftHash(hash);
        // live: everyone's changes are in the document already; a newer epoch = the draft was replaced
        if (liveRef.current.mode !== 'off' && liveRef.current.mode !== 'fallback') {
            liveRef.current.checkEpoch((state as PagePresenceCollabState).collab_epoch);
            return;
        }
        const savedByOther = state.draft_updated_by && state.draft_updated_by.id !== currentUser.id;
        if (state.edit_revision > revisionRef.current && savedByOther && !conflictRef.current) {
            setRemoteChange({ name: state.draft_updated_by?.name ?? null });
        }
    });

    const reloadLatest = () => {
        if (saveTimeoutRef.current) clearTimeout(saveTimeoutRef.current);
        pendingChangesRef.current = {};
        conflictRef.current = false;
        setConflict(null);
        setRemoteChange(null);
        // Force the props effect to take the server state
        revisionRef.current = -1;
        router.reload({ only: ['page'] });
    };

    // Sync with fresh props (after Update, Discard, Restore, reload, …)
    const hasSyncedRef = useRef(false);
    useEffect(() => {
        if (!hasSyncedRef.current) {
            hasSyncedRef.current = true;
            return;
        }
        const serverRevision = page.edit_revision ?? 0;
        const changedElsewhere = serverRevision !== revisionRef.current;
        revisionRef.current = serverRevision;
        setHasDraft(isPublished && !!page.draft_updated_at);
        setDraftEditor(page.draft_editor ?? null);
        setDraftUpdatedAt(page.draft_updated_at ?? null);
        // Don't yank the editor away while typing unless the server state really moved on
        if (!isEditingRef.current || changedElsewhere) {
            setFormData({ name: editSource.name, icon: editSource.icon || '📄' });
            currentHtmlRef.current = extractHtmlContent(editSource.content as any);
            setEditorKey((key) => key + 1);
        }
    }, [page.uuid, page.edit_revision, page.status, page.draft_updated_at]);

    useEffect(() => {
        setIsEditing(can.update && page.status === 'draft');
    }, [page.uuid, page.status]);

    useEffect(() => {
        if (review?.draft_hash) setDraftHash(review.draft_hash);
    }, [review?.draft_hash]);

    // "Review required before publish": the server decides; this only mirrors it on the buttons
    const latestReview = review?.latest ?? null;
    const reviewApproved = !!latestReview && latestReview.status === 'approved' && latestReview.content_hash === draftHash;
    const publishBlocked = !!review?.required && !reviewApproved;
    const publishBlockedTitle = publishBlocked ? 'Needs an approved review of this draft before publishing' : undefined;

    const showReviewError = (errors: Record<string, string>) => {
        if (errors.review) toast.error(errors.review);
    };

    /** Save pending edits first: the review snapshots what's stored. False when that failed (409 / offline). */
    const saveBeforeReview = async (): Promise<boolean> => {
        if (isLive) return live.flush();
        await flushSave();
        return !conflictRef.current && Object.keys(pendingChangesRef.current).length === 0;
    };

    const openRequestReview = async () => {
        if (!(await saveBeforeReview())) {
            toast.error('Your latest changes aren\'t saved yet. Resolve that first, then request a review.');
            return;
        }
        setRequestReviewOpen(true);
    };

    const setReviewRequired = (required: boolean) => {
        router.put(route('pages.reviews.settings', routeParams), { review_required: required }, {
            preserveScroll: true,
            onSuccess: () => toast.success(required ? 'Publishing this page now needs an approved review.' : 'Publishing this page no longer needs a review.'),
            onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Could not change the review setting.'),
        });
    };

    // Save what's left when leaving; warn if a save could still be lost
    useEffect(() => {
        const beforeUnload = (event: BeforeUnloadEvent) => {
            // offline, the edits are in this browser's IndexedDB: no need to hold the user back
            if (liveRef.current.mode === 'live' && liveRef.current.connection === 'online' && liveRef.current.hasUnsavedChanges()) {
                void liveRef.current.flush();
                event.preventDefault();
                return;
            }
            if (Object.keys(pendingChangesRef.current).length > 0) {
                flushSave();
                event.preventDefault();
            }
        };
        window.addEventListener('beforeunload', beforeUnload);
        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            if (!conflictRef.current) flushSave();
        };
    }, [flushSave]);

    // Stored HTML is never trusted: the PDF export writes it into a window on our origin
    const getExportHtml = useCallback(() =>
        safeHtml((isEditing ? currentHtmlRef.current : null) ?? extractHtmlContent(page.content as any) ?? ''),
    [page.content, isEditing]);

    const exportTitle = (isEditing ? formData.name : page.name) || 'Untitled';

    const handleExportHtml = useCallback(() => {
        const html = getExportHtml();
        const title = exportTitle;
        const safe = title.replace(/[<>"'&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '&': '&amp;' }[c]!));
        const full = `<!DOCTYPE html>\n<html lang="en">\n<head>\n<meta charset="UTF-8">\n<meta name="viewport" content="width=device-width,initial-scale=1">\n<title>${safe}</title>\n<style>${EXPORT_HTML_STYLES}</style>\n</head>\n<body>\n<h1>${safe}</h1>\n${html}\n</body>\n</html>`;
        downloadBlob(`${title}.html`, full, 'text/html');
    }, [exportTitle, getExportHtml]);

    const handleExportMarkdown = useCallback(() => {
        const html = getExportHtml();
        const title = exportTitle;
        const td = new TurndownService({ headingStyle: 'atx', codeBlockStyle: 'fenced', bulletListMarker: '-' });
        td.addRule('strikethrough', {
            filter: ['del', 's'],
            replacement: (content) => `~~${content}~~`,
        });
        const md = `# ${title}\n\n${td.turndown(html)}`;
        downloadBlob(`${title}.md`, md, 'text/markdown');
    }, [exportTitle, getExportHtml]);

    const handleExportPdf = useCallback(() => {
        const html = getExportHtml();
        const title = exportTitle;
        const safe = title.replace(/[<>"'&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '&': '&amp;' }[c]!));
        const win = window.open('', '_blank');
        if (!win) return;
        win.document.write(
            `<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>${safe}</title><style>${EXPORT_HTML_STYLES}</style></head>` +
            `<body><h1>${safe}</h1>${html}<script>window.onload=function(){window.print();window.onafterprint=function(){window.close();};};<\/script></body></html>`
        );
        win.document.close();
    }, [exportTitle, getExportHtml]);

    const handleUpdateName = (name: string) => {
        setFormData(prev => ({ ...prev, name }));
        if (isLive) {
            live.setMeta({ name });
            return;
        }
        queueSave({ name }, true);
        // Unpublished pages are renamed directly — refresh the sidebar tree once saved
        if (!isPublished) savePromiseRef.current?.then(() => router.reload({ only: ['currentWorkspace'] }));
    };

    const handleUpdateIcon = (icon: string) => {
        setFormData(prev => ({ ...prev, icon }));
        if (isLive) {
            live.setMeta({ icon });
            return;
        }
        queueSave({ icon }, true);
        if (!isPublished) savePromiseRef.current?.then(() => router.reload({ only: ['currentWorkspace'] }));
    };

    // Debounced content save - receives HTML string from RichLexicalEditor
    const handleUpdateContent = useCallback((html: string) => {
        // Guard: don't process onChange calls that fire during the view→edit
        // remount before InitialContentPlugin has finished populating the editor.
        // Those spurious calls carry empty or partially-loaded HTML.
        if (!isEditing) return;

        currentHtmlRef.current = html;
        queueSave({ content: JSON.stringify({ type: 'lexical', html }) });
    }, [isEditing, queueSave]);

    // Live: the HTML is only kept for exports; useLiveDocument saves it
    const handleLiveHtml = useCallback((html: string) => {
        currentHtmlRef.current = html;
    }, []);

    /** Hand everything typed so far to a publish request (instead of a separate autosave). */
    const takePendingChanges = async (): Promise<DraftChanges> => {
        if (saveTimeoutRef.current) {
            clearTimeout(saveTimeoutRef.current);
            saveTimeoutRef.current = null;
        }
        await savePromiseRef.current?.catch(() => {});
        const pending = pendingChangesRef.current;
        pendingChangesRef.current = {};
        return pending;
    };

    const restorePending = (pending: DraftChanges) => {
        pendingChangesRef.current = { ...pending, ...pendingChangesRef.current };
    };

    // Unpublished pages save their pending edits first; publish takes the stored content
    const handlePublish = async () => {
        setIsPublishing(true);
        if (isLive && !(await live.flush())) {
            setIsPublishing(false);
            toast.error('Your latest changes aren\'t saved yet. Try again in a moment.');
            return;
        }
        await flushSave();
        if (conflictRef.current) {
            setIsPublishing(false);
            return;
        }
        router.post(route('pages.publish', routeParams), {}, {
            preserveScroll: true,
            onError: showReviewError,
            onFinish: () => setIsPublishing(false),
        });
    };

    const handlePublishChanges = async (message?: string) => {
        setIsPublishing(true);
        // live: store the shared document first, then publish the stored draft — the others' concurrent
        // edits are part of it, not a conflict (so no base revision)
        if (isLive && !(await live.flush())) {
            setIsPublishing(false);
            toast.error('Your latest changes aren\'t saved yet. Try again in a moment.');
            return;
        }
        const pending = isLive ? {} : await takePendingChanges();
        router.put(route('pages.publish-changes', routeParams), {
            ...pending,
            message: message || null,
            notify: notifyWatchers,
            ...(isLive ? {} : { base_revision: revisionRef.current }),
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setChangeNoteDialogOpen(false);
                setChangeNote('');
                setIsEditing(false);
            },
            onError: (errors) => {
                // a review refusal already saved the changes into the draft (the server commits them)
                if (!errors.review) restorePending(pending);
                showReviewError(errors);
                if (errors.page) {
                    conflictRef.current = true;
                    setConflict({ name: null });
                }
            },
            onFinish: () => setIsPublishing(false),
        });
    };

    const handleCloseEditor = async () => {
        if (isLive) await live.flush();
        await flushSave();
        setIsEditing(false);
        router.reload({ only: ['page'] });
    };

    const handleDiscard = async () => {
        await takePendingChanges();
        router.delete(route('pages.draft.discard', routeParams), {
            preserveScroll: true,
            onSuccess: () => {
                setDiscardDialogOpen(false);
                setIsEditing(false);
            },
        });
    };

    const handleUnpublish = async () => {
        if (isLive) await live.flush();
        await flushSave();
        router.post(route('pages.unpublish', routeParams), {}, {
            preserveScroll: true,
        });
    };

    const handleArchive = () => {
        router.post(route('pages.archive', routeParams), {}, {
            preserveScroll: true,
        });
    };

    const handleDuplicate = (withSubpages: boolean = false) => {
        router.post(route('pages.duplicate', routeParams), {
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
        router.delete(route('pages.destroy', routeParams));
    };

    const getStatusBadge = () => {
        switch (page.status) {
            case 'published':
                return (
                    <Badge
                        variant="outline"
                        className="cursor-pointer border-brand-green/25 bg-brand-green/5 text-brand-green hover:bg-brand-green/10"
                        onClick={() => setHistoryOpen(true)}
                        title="Page history"
                    >
                        <Globe className="size-3 mr-1" /> Published{page.current_version ? ` · v${page.current_version}` : ''}
                    </Badge>
                );
            case 'archived':
                return <Badge variant="secondary" className="bg-muted/70 text-muted-foreground"><Archive className="size-3 mr-1" /> Archived</Badge>;
            default:
                return <Badge variant="outline" className="border-border/70 bg-background text-muted-foreground" title="Only you and admins can see this page until it's published"><Lock className="size-3 mr-1" /> Draft</Badge>;
        }
    };

    const liveTitle = isLive ? live.meta.name || null : null;
    const liveIcon = isLive && live.meta.icon !== undefined ? live.meta.icon : null;
    const displayName = isLive ? (liveTitle ?? formData.name) : isEditing ? formData.name : page.name;
    const displayIcon = (isLive ? (liveIcon ?? formData.icon) : isEditing ? formData.icon : page.icon) || '📄';
    const livePeerIds = new Set(live.peers.map((peer) => peer.userId));
    // people in the live document get the coloured avatars; the heartbeat covers everyone else
    const otherPresent = isLive ? presentUsers.filter((user) => !livePeerIds.has(user.id)) : presentUsers;
    const otherEditors = isLive ? live.peers.filter((peer) => peer.edit).map((peer) => ({ id: peer.userId, name: peer.name })) : presentUsers.filter((user) => user.editing);
    const editorEditable = isLive ? live.canEdit && live.ready : isEditing;

    return (
        <AppLayout>
            <Head title={`${page.name} - ${project.name}`} />

            <div className="h-full flex flex-col bg-card">

                {/* Breadcrumb bar — always visible, outside the scroll panels */}
                <div className={cn("shrink-0 border-b bg-card/95 backdrop-blur z-10 transition-colors", isScrolled ? "border-border/60" : "border-transparent")}>
                    <div className="px-5 py-2.5">
                        <div className="flex items-center justify-between gap-4">
                            {/* Breadcrumbs */}
                            <Breadcrumb className="min-w-0">
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
                            <div className="flex items-center gap-1.5 shrink-0">
                                {/* Live co-editing (BRAI-54): who is in the document right now, in their caret colour */}
                                {isLive && live.peers.length > 0 && (
                                    <div
                                        className="flex -space-x-1.5 mr-1"
                                        role="list"
                                        aria-label="Editing live with you"
                                        title={live.peers.map((peer) => `${peer.name}${peer.edit ? ' (editing)' : ' (viewing)'}`).join(', ')}
                                    >
                                        {live.peers.slice(0, 5).map((peer) => (
                                            <Avatar key={peer.userId} role="listitem" aria-label={peer.name} className="size-6 border-2 border-card" style={{ boxShadow: `0 0 0 2px ${peer.color}` }}>
                                                <AvatarFallback className="text-[10px] font-semibold text-white" style={{ backgroundColor: peer.color }}>{peer.name.charAt(0)}</AvatarFallback>
                                            </Avatar>
                                        ))}
                                        {live.peers.length > 5 && (
                                            <span className="size-6 rounded-full border-2 border-card bg-muted flex items-center justify-center text-[10px] font-medium text-muted-foreground">+{live.peers.length - 5}</span>
                                        )}
                                    </div>
                                )}
                                {/* Who else is on the page */}
                                {otherPresent.length > 0 && (
                                    <div
                                        className="flex -space-x-1.5 mr-1"
                                        title={otherPresent.map((user) => `${user.name}${user.editing ? ' (editing)' : ''}`).join(', ')}
                                    >
                                        {otherPresent.slice(0, 4).map((user) => (
                                            <Avatar key={user.id} className={cn('size-6 border-2 border-card', user.editing && 'ring-2 ring-primary/60')}>
                                                <AvatarFallback className="text-[10px] bg-muted">{user.name.charAt(0)}</AvatarFallback>
                                            </Avatar>
                                        ))}
                                    </div>
                                )}

                                {/* Live status (BRAI-54): the shared document's state, not the HTML autosave */}
                                {isLive && live.canEdit && (
                                    <span className="text-xs text-muted-foreground flex items-center gap-1 px-1" role="status">
                                        {live.saveFailed && live.connection !== 'offline' ? (
                                            <span className="text-destructive flex items-center gap-1" title={live.saveFailed}><AlertTriangle className="size-3" /><span className="max-w-[12rem] truncate">{live.saveFailed}</span></span>
                                        ) : live.connection === 'offline' ? (
                                            <><CloudOff className="size-3 text-amber-600 dark:text-amber-400" /><span className="hidden sm:inline">Offline — changes kept on this device</span><span className="sm:hidden">Offline</span></>
                                        ) : live.pending || live.connection === 'connecting' ? (
                                            <><Save className="size-3 animate-pulse" />Saving…</>
                                        ) : (
                                            <><Check className="size-3 text-brand-green" />{isPublished ? 'Draft saved' : 'Saved'}</>
                                        )}
                                    </span>
                                )}
                                {/* Save status - only show when editing */}
                                {!isLive && isEditing && saveError && (
                                    <span className="text-xs text-destructive flex items-center gap-1 px-1">
                                        <AlertTriangle className="size-3" />
                                        {saveError}
                                    </span>
                                )}
                                {!isLive && isEditing && !saveError && isSaving && (
                                    <span className="text-xs text-muted-foreground flex items-center gap-1 px-1">
                                        <Save className="size-3 animate-pulse" />
                                        Saving…
                                    </span>
                                )}
                                {!isLive && isEditing && !saveError && !isSaving && lastSaved && (
                                    <span className="text-xs text-muted-foreground flex items-center gap-1 px-1">
                                        <Check className="size-3 text-brand-green" />
                                        {isPublished ? 'Draft saved' : 'Saved'}
                                    </span>
                                )}

                                {/* Status */}
                                {getStatusBadge()}
                                {review?.required && can.update && (
                                    <Badge variant="outline" className="hidden sm:inline-flex border-border/70 bg-background text-muted-foreground" title="Publishing needs an approved review of the draft">
                                        <ShieldCheck className="size-3 mr-1" /> Review required
                                    </Badge>
                                )}
                                {hasDraft && can.update && (
                                    <Badge variant="outline" className="border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400">
                                        Unpublished changes
                                    </Badge>
                                )}

                                {page.type === 'page' && !page.is_whiteboard && can.update && !isEditing && (
                                    <ShareButton type="page" id={page.uuid} label={page.name} className="h-8 px-2.5 text-xs gap-1.5" />
                                )}

                                {/* Watch / Watching button */}
                                {page.type === 'page' && !isEditing && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className={cn('h-8 px-2.5 text-xs gap-1.5 cursor-pointer border-transparent bg-transparent shadow-none hover:bg-accent', isWatching && 'bg-primary/5 text-primary border-primary/20')}
                                        onClick={toggleWatch}
                                    >
                                        {isWatching ? (
                                            <><BellRing className="size-3.5" />Watching</>
                                        ) : (
                                            <><Bell className="size-3.5" />Watch</>
                                        )}
                                    </Button>
                                )}

                                {/* Edit — published pages open for reading */}
                                {!isEditing && can.update && isPublished && isDocument && (
                                    <Button size="sm" variant={hasDraft ? 'default' : 'ghost'} className="h-8 px-2.5" onClick={() => setIsEditing(true)}>
                                        <Pencil className="size-4 mr-1.5" />
                                        {hasDraft ? 'Continue editing' : 'Edit'}
                                    </Button>
                                )}

                                {/* Publish — first publish of a draft */}
                                {isEditing && page.status === 'draft' && page.type === 'page' && (
                                    <span title={publishBlockedTitle} className="inline-flex"><Button size="sm" onClick={handlePublish} disabled={isPublishing || publishBlocked}>
                                        {isPublishing ? <Loader2 className="size-4 mr-1.5 animate-spin" /> : <Globe className="size-4 mr-1.5" />}
                                        Publish
                                    </Button></span>
                                )}

                                {/* Editing a published page: Close keeps the draft, Update publishes it */}
                                {isEditing && isPublished && page.type === 'page' && (
                                    <>
                                        <Button size="sm" variant="ghost" className="h-8 px-2.5" onClick={handleCloseEditor} title="Close the editor — your changes stay saved as a draft">
                                            Close
                                        </Button>
                                        {otherWatcherCount > 0 && (
                                            <label className="hidden lg:flex items-center gap-1.5 px-1.5 text-xs text-muted-foreground cursor-pointer select-none" title="Send a notification to the people watching this page">
                                                <input
                                                    type="checkbox"
                                                    checked={notifyWatchers}
                                                    onChange={(e) => setNotifyWatchers(e.target.checked)}
                                                    className="size-3.5 rounded border-border accent-[hsl(var(--primary))]"
                                                />
                                                Notify watchers
                                            </label>
                                        )}
                                        <span title={publishBlockedTitle} className="inline-flex"><Button size="sm" onClick={() => handlePublishChanges()} disabled={isPublishing || !!conflict || publishBlocked}>
                                            {isPublishing ? <Loader2 className="size-4 mr-1.5 animate-spin" /> : <Globe className="size-4 mr-1.5" />}
                                            Update
                                        </Button></span>
                                    </>
                                )}

                                {/* Formatting toolbar toggle */}
                                {isEditing && isDocument && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className={cn("size-8", showFormattingToolbar && "bg-accent text-foreground")}
                                        title={showFormattingToolbar ? 'Hide formatting toolbar' : 'Show formatting toolbar'}
                                        onClick={() => {
                                            const next = !showFormattingToolbar;
                                            setShowFormattingToolbar(next);
                                            try { localStorage.setItem('page-formatting-toolbar', String(next)); } catch { }
                                        }}
                                    >
                                        <PanelTop className="size-4" />
                                    </Button>
                                )}

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
                                        <Button variant="ghost" size="icon" className="size-8" aria-label="More actions">
                                            <MoreHorizontal className="size-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" className="w-56">
                                        {page.type === 'page' && (
                                            <DropdownMenuItem onClick={() => setHistoryOpen(true)}>
                                                <History className="size-4 mr-2" />
                                                Page history
                                            </DropdownMenuItem>
                                        )}
                                        {review?.can_request && (
                                            <DropdownMenuItem onClick={openRequestReview}>
                                                <MessageSquareMore className="size-4 mr-2" />
                                                Request review…
                                            </DropdownMenuItem>
                                        )}
                                        {review?.can_manage && (
                                            <DropdownMenuCheckboxItem checked={review.required} onCheckedChange={(checked) => setReviewRequired(checked === true)}>
                                                Review required before publish
                                            </DropdownMenuCheckboxItem>
                                        )}
                                        {isEditing && isPublished && (
                                            <DropdownMenuItem onClick={() => setChangeNoteDialogOpen(true)}>
                                                <MessageSquareText className="size-4 mr-2" />
                                                Update with change note…
                                            </DropdownMenuItem>
                                        )}
                                        {can.update && hasDraft && (
                                            <DropdownMenuItem onClick={() => setDiscardDialogOpen(true)}>
                                                <Undo2 className="size-4 mr-2" />
                                                Discard unpublished changes
                                            </DropdownMenuItem>
                                        )}
                                        {can.update && (
                                            <>
                                                <DropdownMenuSeparator />
                                                {isPublished && (
                                                    <DropdownMenuItem onClick={handleUnpublish}>
                                                        <FileEdit className="size-4 mr-2" />
                                                        Unpublish (back to draft)
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
                                            </>
                                        )}
                                        {page.type === 'page' && !isExcalidrawPage(page.content) && (
                                            <DropdownMenuItem onClick={() => setActionItemsOpen(true)}>
                                                <ListChecks className="size-4 mr-2" />
                                                Extract action items
                                            </DropdownMenuItem>
                                        )}
                                        {page.type === 'page' && can.update && (
                                            <DropdownMenuItem onClick={() => setMeetingOpen(true)}>
                                                <Video className="size-4 mr-2" />
                                                Schedule meeting
                                            </DropdownMenuItem>
                                        )}
                                        {can.create && (
                                            <DropdownMenuItem onClick={triggerDuplicate}>
                                                <Files className="size-4 mr-2" />
                                                Duplicate
                                            </DropdownMenuItem>
                                        )}
                                        <DropdownMenuSeparator />
                                        <DropdownMenuLabel className="text-xs text-muted-foreground font-normal px-2 py-1">Export</DropdownMenuLabel>
                                        <DropdownMenuItem onClick={handleExportMarkdown}>
                                            <FileDown className="size-4 mr-2" />
                                            Export as Markdown
                                        </DropdownMenuItem>
                                        <DropdownMenuItem onClick={handleExportHtml}>
                                            <FileCode className="size-4 mr-2" />
                                            Export as HTML
                                        </DropdownMenuItem>
                                        <DropdownMenuItem onClick={handleExportPdf}>
                                            <Printer className="size-4 mr-2" />
                                            Export as PDF
                                        </DropdownMenuItem>
                                        {can.delete && (
                                            <>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    className="text-destructive focus:text-destructive"
                                                    onClick={() => setDeleteDialogOpen(true)}
                                                >
                                                    <Trash2 className="size-4 mr-2" />
                                                    Delete
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Two-panel row: content | TOC — each scrolls independently */}
                <div className="flex-1 overflow-hidden flex">

                    {/* Page content panel */}
                    <div
                        ref={contentPanelRef}
                        className="flex-1 overflow-y-auto bg-card text-card-foreground"
                        onScroll={(e) => setIsScrolled(e.currentTarget.scrollTop > 4)}
                    >
                        <div className={cn(
                            "mx-auto px-6 pt-12 pb-24 md:px-16",
                            !isFullWidth && "max-w-[54rem]",
                            isFullWidth && "max-w-none xl:px-24"
                        )}>
                            {/* Live co-editing (BRAI-54) */}
                            {live.mode === 'fallback' && (
                                <div className="mb-6 flex flex-wrap items-center gap-3 rounded-lg border border-amber-500/30 bg-amber-500/5 px-4 py-3 text-sm" role="status">
                                    <Users className="size-4 text-amber-600 dark:text-amber-400 shrink-0" />
                                    {can.update ? (
                                        <span className="flex-1 min-w-[12rem]">
                                            <strong>Live editing unavailable — editing alone.</strong> Your changes are saved as usual; if someone else saves meanwhile you'll be asked which version to keep.
                                        </span>
                                    ) : (
                                        <span className="flex-1 min-w-[12rem]">
                                            <strong>Live updates unavailable</strong> — showing the last saved draft.
                                        </span>
                                    )}
                                    <Button size="sm" variant="outline" onClick={async () => { if (can.update) await flushSave(); live.retry(); }}>Try again</Button>
                                </div>
                            )}
                            {live.notice && (
                                <div className="mb-6 flex flex-wrap items-center gap-3 rounded-lg border bg-muted/40 px-4 py-3 text-sm" role="status">
                                    <RefreshCw className="size-4 text-muted-foreground shrink-0" />
                                    <span className="flex-1 min-w-[12rem]">{live.notice}</span>
                                    <Button size="sm" variant="outline" onClick={live.dismissNotice}>OK</Button>
                                </div>
                            )}
                            {isLive && !live.canEdit && (
                                <div className="mb-6 flex items-center gap-2 text-sm text-muted-foreground">
                                    <Eye className="size-4 shrink-0" />
                                    <span>Live view — you see changes as they are made, but can't edit this page.</span>
                                </div>
                            )}
                            {/* Concurrent edits — never overwrite silently */}
                            {conflict && (
                                <div className="mb-6 flex flex-wrap items-center gap-3 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm">
                                    <AlertTriangle className="size-4 text-destructive shrink-0" />
                                    <span className="flex-1 min-w-[16rem]">
                                        <strong>{conflict.name ?? 'Someone else'}</strong> changed this page while you were editing. Your latest changes aren't saved yet.
                                    </span>
                                    <Button size="sm" variant="outline" onClick={reloadLatest}>Load their version</Button>
                                    <Button size="sm" variant="destructive" onClick={() => flushSave(true)}>Keep mine</Button>
                                </div>
                            )}
                            {!conflict && remoteChange && (
                                <div className="mb-6 flex flex-wrap items-center gap-3 rounded-lg border bg-muted/40 px-4 py-3 text-sm">
                                    <RefreshCw className="size-4 text-muted-foreground shrink-0" />
                                    <span className="flex-1 min-w-[16rem]">
                                        <strong>{remoteChange.name ?? 'Someone'}</strong> saved changes to this page{isEditing ? ' — reload before you continue, or your next save will conflict.' : '.'}
                                    </span>
                                    <Button size="sm" variant="outline" onClick={reloadLatest}>Reload</Button>
                                </div>
                            )}
                            {/* Readers see the live version; editors are told a draft is waiting */}
                            {!isEditing && hasDraft && can.update && (
                                <div className="mb-6 flex flex-wrap items-center gap-3 rounded-lg border border-amber-500/30 bg-amber-500/5 px-4 py-3 text-sm">
                                    <FileEdit className="size-4 text-amber-600 dark:text-amber-400 shrink-0" />
                                    <span className="flex-1 min-w-[16rem]">
                                        Unpublished changes{draftEditor ? <> by <strong>{draftEditor.name}</strong></> : null}
                                        {draftUpdatedAt ? ` · ${formatRelativeTime(draftUpdatedAt)}` : ''}. Readers still see the published version.
                                    </span>
                                    <Button size="sm" variant="outline" onClick={() => setDiscardDialogOpen(true)}>Discard</Button>
                                    <Button size="sm" onClick={() => setIsEditing(true)}>Continue editing</Button>
                                </div>
                            )}
                            {/* Document review (BRAI-80) */}
                            {review && (
                                <PageReviewBanner
                                    review={review}
                                    draftHash={draftHash}
                                    hasSomethingToPublish={page.status === 'draft' || hasDraft}
                                    onRequestReview={openRequestReview}
                                />
                            )}

                            {/* Icon, title and meta */}
                            <div className="mb-6">
                                <div className="mb-3">
                                    {isEditing && !liveBusy && (!isLive || live.canEdit) ? (
                                        <PageIconPicker value={displayIcon} onChange={handleUpdateIcon} />
                                    ) : (
                                        (displayIcon.startsWith('data:') || displayIcon.startsWith('http') || displayIcon.startsWith('/'))
                                            ? <img src={displayIcon} className="size-12 rounded-lg object-cover" alt="icon" />
                                            : <span className="block text-5xl leading-none">{displayIcon}</span>
                                    )}
                                </div>
                                {isEditing && !liveBusy && (!isLive || live.canEdit) ? (
                                    <PageTitleInput title={displayName} onSave={handleUpdateName} />
                                ) : (
                                    <h1 className={PAGE_TITLE_CLASS}>{displayName || 'Untitled'}</h1>
                                )}
                                <div className="flex items-center gap-x-1.5 gap-y-1 mt-3 text-sm text-muted-foreground flex-wrap">
                                    {isEditing && isPublished && (
                                        <>
                                            <span className="font-medium text-foreground/80">Editing draft</span>
                                            <span>— readers see version {page.current_version ?? 1} until you update</span>
                                            {otherEditors.length > 0 && (
                                                <span className="text-primary">· {otherEditors.map((user) => user.name).join(', ')} {otherEditors.length === 1 ? 'is' : 'are'} editing too</span>
                                            )}
                                            <span aria-hidden className="text-muted-foreground/50">·</span>
                                        </>
                                    )}
                                    {(() => {
                                        const changedAt = isPublished ? (page.last_published_at ?? page.published_at ?? page.updated_at) : page.updated_at;
                                        return (
                                            <span title={new Date(changedAt).toLocaleString()}>
                                                {page.last_editor ? `${page.last_editor.name} updated` : 'Updated'} {formatRelativeTime(changedAt)}
                                            </span>
                                        );
                                    })()}
                                    {page.creator && (
                                        <>
                                            <span aria-hidden className="text-muted-foreground/50">·</span>
                                            <span>Created by {page.creator.name}</span>
                                        </>
                                    )}
                                    {page.published_at && page.status === 'published' && (
                                        <>
                                            <span aria-hidden className="text-muted-foreground/50">·</span>
                                            <span title={new Date(page.published_at).toLocaleString()}>Published {formatRelativeTime(page.published_at)}</span>
                                        </>
                                    )}
                                    {/* Watcher avatars */}
                                    {(page.watchers?.length ?? 0) > 0 && (
                                        <>
                                            <span aria-hidden className="text-muted-foreground/50">·</span>
                                            <div className="flex -space-x-1.5 overflow-hidden" title={`Watching: ${(page.watchers ?? []).map(w => w.name).join(', ')}`}>
                                                {(page.watchers ?? []).slice(0, 5).map(w => (
                                                    <Avatar key={w.id} className="size-5 border-2 border-background">
                                                        <AvatarFallback className="text-[9px] bg-muted">{w.name.charAt(0)}</AvatarFallback>
                                                    </Avatar>
                                                ))}
                                                {(page.watchers?.length ?? 0) > 5 && (
                                                    <div className="size-5 rounded-full border-2 border-background bg-muted flex items-center justify-center text-[9px] font-medium text-muted-foreground">
                                                        +{(page.watchers?.length ?? 0) - 5}
                                                    </div>
                                                )}
                                            </div>
                                        </>
                                    )}
                                </div>
                                {/* Online meetings of this doc (BRAI-147) */}
                                {page.type === 'page' && (
                                    <SubjectMeetings
                                        variant="strip"
                                        workspace={workspace}
                                        project={project}
                                        subject={{ type: 'page', id: page.uuid, title: page.name || 'Untitled', url: route('pages.show', [workspace.slug, project.slug, page.uuid]) }}
                                        scheduleOpen={meetingOpen}
                                        onScheduleOpenChange={setMeetingOpen}
                                    />
                                )}
                            </div>

                            {/* Whiteboard page: embedded view-mode canvas + open editor button */}
                            {page.type === 'page' && isExcalidrawPage(page.content) && (
                                <div className="mb-8">
                                    <div className="rounded-lg border border-border overflow-hidden bg-white dark:bg-card">
                                        <ExcalidrawViewMode key={page.edit_revision} id={`excalidraw-preview-${page.uuid}-${page.edit_revision}`} content={page.content} onReady={onWhiteboardReady} />
                                        <div className="px-4 py-3 border-t border-border bg-muted/20 flex flex-wrap items-center justify-between gap-3">
                                            <div className="flex min-w-0 items-center gap-2 text-sm text-muted-foreground">
                                                {liveBoard?.mode === 'live' && (
                                                    <span className={cn('size-2 shrink-0 rounded-full', liveBoard.connection === 'online' ? 'bg-emerald-500' : 'bg-amber-500')} aria-hidden="true" />
                                                )}
                                                {/* one live region for every state: announced when it changes, not when people come and go */}
                                                <span role="status" className={cn('truncate', liveBoard?.mode === 'live' && 'font-medium text-foreground')}>
                                                    {liveBoard?.mode === 'live'
                                                        ? (liveBoard.connection === 'online' ? 'Live' : 'Reconnecting…')
                                                        : liveBoard?.mode === 'replaced'
                                                            ? 'This whiteboard changed.'
                                                            : 'View mode · pan and zoom freely'}
                                                </span>
                                                {liveBoard?.mode === 'replaced' && (
                                                    <button type="button" className="underline underline-offset-2 hover:text-foreground" onClick={() => router.reload({ only: ['page', 'whiteboardCollab'] })}>Show the latest</button>
                                                )}
                                                {liveBoard?.mode === 'live' && (liveBoard.peers.length > 0 ? (
                                                    <ul className="flex -space-x-1.5" aria-label="Drawing live now">
                                                        {liveBoard.peers.slice(0, 5).map((peer) => (
                                                            <li key={peer.userId} aria-label={`${peer.name}, ${peer.edit ? 'drawing' : 'watching'}`} title={`${peer.name}${peer.edit ? ' (drawing)' : ' (watching)'}`}>
                                                                <Avatar className="size-6 border-2 border-card" style={{ boxShadow: `0 0 0 2px ${peer.color}` }}>
                                                                    <AvatarFallback className="text-[10px] font-semibold text-gray-900" style={{ backgroundColor: peer.color }}>{peer.name.charAt(0)}</AvatarFallback>
                                                                </Avatar>
                                                            </li>
                                                        ))}
                                                        {liveBoard.peers.length > 5 && (
                                                            <li aria-label={`${liveBoard.peers.length - 5} more`} title={liveBoard.peers.slice(5).map((peer) => peer.name).join(', ')} className="size-6 rounded-full border-2 border-card bg-muted flex items-center justify-center text-[10px] font-medium text-muted-foreground">+{liveBoard.peers.length - 5}</li>
                                                        )}
                                                    </ul>
                                                ) : (
                                                    <span className="hidden truncate sm:inline">Changes appear as people draw</span>
                                                ))}
                                            </div>
                                            {can.update && <a
                                                href={route('pages.excalidraw', [workspace.slug, project.slug, page.uuid])}
                                                className="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-primary text-primary-foreground text-sm font-medium hover:bg-primary/90 transition-colors"
                                            >
                                                <PencilRuler className="size-4" />
                                                Open whiteboard
                                            </a>}
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Page Content - RichLexicalEditor (documents only; Excalidraw pages use preview above) */}
                            {page.type === 'page' && !isExcalidrawPage(page.content) && (
                                <div className="relative" aria-busy={liveBusy}>
                                {liveBusy && (
                                    <div className="absolute right-0 -top-7 flex items-center gap-1.5 text-xs text-muted-foreground" role="status">
                                        <Loader2 className="size-3 animate-spin" /> Connecting to live editing…
                                    </div>
                                )}
                                <RichLexicalEditor
                                    key={isLive ? `${page.uuid}-live-${live.generation}` : `${page.uuid}-${isEditing}-${editorKey}`}
                                    content={isLive ? null : extractHtmlContent(((isEditing || liveWanted) ? editSource.content : page.content) as any)}
                                    onChange={isLive ? handleLiveHtml : handleUpdateContent}
                                    editable={isLive ? editorEditable : isEditing && !liveBusy}
                                    collaboration={isLive ? live.binding : null}
                                    pollScope={{ type: 'page', id: page.uuid }}
                                    smartLinks={smartLinkConfig}
                                    placeholder="Start writing, or press / for commands…"
                                    showToolbar={isEditing && showFormattingToolbar}
                                    minHeight="400px"
                                    workspaceSlug={workspace.slug}
                                    contentKey={`page-${page.uuid}-${isEditing}-${editorKey}`}
                                    onHeadingsChange={setHeadings}
                                    className="border-0 rounded-none shadow-none bg-transparent overflow-visible"
                                    documentMode
                                    stickyToolbar={isEditing}
                                    stickyTopOffset={0}
                                    projectId={project.id}
                                />
                                </div>
                            )}

                            {/* Subpages (for folders or pages with children) */}
                            {(page.type === 'folder' || (page.children && page.children.length > 0)) && (
                                <div className="mt-8">
                                    <div className="flex items-center justify-between mb-4">
                                        <h2 className="text-lg font-semibold">
                                            {page.type === 'folder' ? 'Contents' : 'Subpages'}
                                        </h2>
                                        {can.create && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setAddSubpageOpen(true)}
                                                className="gap-1.5"
                                            >
                                                <Plus className="size-4" />
                                                Add
                                            </Button>
                                        )}
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
                                            {can.create && (
                                                <Button onClick={() => setAddSubpageOpen(true)} className="gap-1.5">
                                                    <Plus className="size-4" />
                                                    Add Subpage
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* Related content + backlinks (BRAI-71); folders aren't linkable */}
                            {page.type === 'page' && (
                                <RelatedContent
                                    workspaceSlug={workspace.slug}
                                    projectSlug={project.slug}
                                    type="page"
                                    id={page.id}
                                    refreshKey={page.edit_revision}
                                    className="mt-10"
                                />
                            )}
                        </div>
                    </div>

                    {/* TOC panel — own scroll, naturally aligned next to content */}
                    {page.type === 'page' && !isExcalidrawPage(page.content) && headings.length > 0 && (
                        <div className="hidden xl:flex w-64 shrink-0 flex-col bg-card overflow-y-auto overflow-x-hidden px-5 pt-14">
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

            {/* Discard unpublished changes */}
            <AlertDialog open={discardDialogOpen} onOpenChange={setDiscardDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Discard unpublished changes?</AlertDialogTitle>
                        <AlertDialogDescription>
                            The page goes back to version {page.current_version ?? 1}, the one readers see now.
                            {draftEditor && draftEditor.id !== currentUser.id && (
                                <span className="block mt-2 font-medium">These changes were made by {draftEditor.name}.</span>
                            )}
                            {' '}This can't be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep editing</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleDiscard}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        >
                            Discard changes
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Update with a change note (shown in the page history) */}
            <Dialog open={changeNoteDialogOpen} onOpenChange={setChangeNoteDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Update page</DialogTitle>
                        <DialogDescription>
                            Publishes your changes as version {(page.current_version ?? 1) + 1}. The note appears in the page history.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-4"
                        onSubmit={(e) => { e.preventDefault(); handlePublishChanges(changeNote.trim()); }}
                    >
                        <div className="space-y-2">
                            <Label htmlFor="change-note">What changed?</Label>
                            <Textarea
                                id="change-note"
                                value={changeNote}
                                onChange={(e) => setChangeNote(e.target.value)}
                                maxLength={255}
                                rows={3}
                                placeholder="e.g. Added the rollback steps"
                                autoFocus
                            />
                        </div>
                        {otherWatcherCount > 0 && (
                            <label className="flex items-center gap-2 text-sm cursor-pointer select-none">
                                <input
                                    type="checkbox"
                                    checked={notifyWatchers}
                                    onChange={(e) => setNotifyWatchers(e.target.checked)}
                                    className="size-4 rounded border-border accent-[hsl(var(--primary))]"
                                />
                                Notify {otherWatcherCount} {otherWatcherCount === 1 ? 'watcher' : 'watchers'}
                            </label>
                        )}
                        {publishBlocked && <p className="text-sm text-muted-foreground">{publishBlockedTitle}.</p>}
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setChangeNoteDialogOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={isPublishing || publishBlocked}>
                                {isPublishing && <Loader2 className="size-4 mr-1.5 animate-spin" />}
                                Update
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {page.type === 'page' && (
                <PageHistoryDialog
                    open={historyOpen}
                    onOpenChange={setHistoryOpen}
                    routeParams={routeParams}
                    canRestore={can.update}
                    isPublished={isPublished}
                    isWhiteboard={isExcalidrawPage(page.content)}
                    hasUnpublishedChanges={hasDraft}
                />
            )}

            {review?.can_request && (
                <RequestReviewDialog
                    open={requestReviewOpen}
                    onOpenChange={setRequestReviewOpen}
                    routeParams={routeParams}
                    preselected={(review.latest?.reviewers ?? []).filter((r) => r.active && r.user_id !== null && r.user_id !== currentUser.id).map((r) => r.user_id as number)}
                    beforeSubmit={saveBeforeReview}
                />
            )}

            {page.type === 'page' && !isExcalidrawPage(page.content) && (
                <ExtractActionItemsDialog open={actionItemsOpen} onOpenChange={setActionItemsOpen} workspace={workspace} project={project} pageUuid={page.uuid} />
            )}
        </AppLayout>
    );
}
