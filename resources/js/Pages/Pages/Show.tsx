import AppLayout from '@/Layouts/AppLayout';
import { Head, useForm, router, Link, usePage } from '@inertiajs/react';
import type { OutputData } from '@editorjs/editorjs';
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
} from 'lucide-react';
import { useState, useEffect, useRef, FormEvent, Fragment, useCallback } from 'react';
import EditorJSComponent, { HeadingItem } from '@/components/editor/EditorJS';
import '@/components/editor/editorjs.css';
import { cn } from '@/lib/utils';
import { List as ListIcon } from 'lucide-react';
import { flattenPagesForParentSelect } from '@/lib/pageTree';
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
    };
    breadcrumbs: { id: number; name: string; slug: string; uuid: string; type: string }[];
}

// Detect if page content is Excalidraw (full-page whiteboard)
function isExcalidrawPage(content: unknown): boolean {
    if (!content || typeof content !== 'object') return false;
    const c = content as Record<string, unknown>;
    return c.type === 'excalidraw' || (Array.isArray(c.elements) && c.appState !== undefined);
}

// Emoji Picker (simplified)
const commonEmojis = ['📄', '📁', '📝', '📋', '📌', '🎯', '💡', '🔥', '⭐', '📊', '📈', '🚀', '💻', '🔧', '📚', '📖'];

function EmojiPicker({ value, onChange }: { value: string; onChange: (emoji: string) => void }) {
    const [open, setOpen] = useState(false);

    return (
        <DropdownMenu open={open} onOpenChange={setOpen}>
            <DropdownMenuTrigger asChild>
                <button className="text-4xl hover:bg-accent rounded-lg p-2 transition-colors">
                    {value || '📄'}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent className="w-64 p-2">
                <div className="grid grid-cols-8 gap-1">
                    {commonEmojis.map((emoji) => (
                        <button
                            key={emoji}
                            onClick={() => {
                                onChange(emoji);
                                setOpen(false);
                            }}
                            className="text-xl p-1.5 hover:bg-accent rounded transition-colors"
                        >
                            {emoji}
                        </button>
                    ))}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
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
            <span className="text-2xl">{page.icon || (page.type === 'folder' ? '📁' : '📄')}</span>
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

// Table of Contents Component - Fixed on right side
function TableOfContents({ headings }: { headings: HeadingItem[] }) {
    const [activeId, setActiveId] = useState<string>('');

    useEffect(() => {
        const handleScroll = () => {
            const headingElements = document.querySelectorAll('.ce-header[id]');
            let currentActiveId = '';

            headingElements.forEach((heading) => {
                const rect = heading.getBoundingClientRect();
                if (rect.top <= 120) {
                    currentActiveId = heading.id || '';
                }
            });

            setActiveId(currentActiveId);
        };

        window.addEventListener('scroll', handleScroll, { passive: true });
        handleScroll(); // Initial check
        return () => window.removeEventListener('scroll', handleScroll);
    }, [headings]);

    const scrollToHeading = (id: string) => {
        const element = document.getElementById(id);
        if (element) {
            // Get the sticky header height (approximately 60px)
            const headerOffset = 80;
            const elementPosition = element.getBoundingClientRect().top;
            const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

            window.scrollTo({
                top: offsetPosition,
                behavior: 'smooth'
            });
        }
    };

    if (headings.length === 0) {
        return null;
    }

    const minLevel = Math.min(...headings.map(h => h.level));

    return (
        <div className="w-56">
            <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground mb-3">
                <ListIcon className="size-4" />
                <span>On this page</span>
            </div>
            <nav className="space-y-1">
                {headings.map((heading) => {
                    const indent = (heading.level - minLevel) * 12;
                    const isActive = activeId === heading.id;

                    return (
                        <button
                            key={heading.id}
                            onClick={() => scrollToHeading(heading.id)}
                            className={cn(
                                'block w-full text-left text-sm py-1 px-2 rounded-md transition-colors truncate',
                                'hover:bg-accent hover:text-accent-foreground',
                                isActive
                                    ? 'text-primary font-medium bg-primary/5'
                                    : 'text-muted-foreground'
                            )}
                            style={{ paddingLeft: `${8 + indent}px` }}
                            title={heading.text}
                        >
                            <ChevronRight
                                className={cn(
                                    'size-3 inline-block mr-1 opacity-50',
                                    heading.level === minLevel && 'hidden'
                                )}
                            />
                            {heading.text || 'Untitled'}
                        </button>
                    );
                })}
            </nav>
        </div>
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
                            className={`flex-1 min-w-[80px] flex items-center justify-center gap-2 p-3 rounded-lg border transition-colors ${
                                itemType === 'page' ? 'border-primary bg-primary/5' : 'hover:border-muted-foreground/50'
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
                            className={`flex-1 min-w-[80px] flex items-center justify-center gap-2 p-3 rounded-lg border transition-colors ${
                                itemType === 'excalidraw' ? 'border-primary bg-primary/5' : 'hover:border-muted-foreground/50'
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
                            className={`flex-1 min-w-[80px] flex items-center justify-center gap-2 p-3 rounded-lg border transition-colors ${
                                itemType === 'folder' ? 'border-primary bg-primary/5' : 'hover:border-muted-foreground/50'
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

export default function PageShow({ workspace, project, page, breadcrumbs }: Props) {
    // Redirect Excalidraw pages to full-page editor (unless they just came back from the editor)
    useEffect(() => {
        if (page.type !== 'page' || !isExcalidrawPage(page.content)) return;
        const params = new URLSearchParams(window.location.search);
        if (params.get('from') === 'excalidraw') return; // Back / Save & exit: stay on page, don't redirect
        window.location.href = route('pages.excalidraw', [workspace.slug, project.slug, page.uuid]);
    }, [page.type, page.content, page.uuid, workspace.slug, project.slug]);

    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [addSubpageOpen, setAddSubpageOpen] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [lastSaved, setLastSaved] = useState<Date | null>(null);
    const [headings, setHeadings] = useState<HeadingItem[]>([]);
    const [contentWithEmbedImage, setContentWithEmbedImage] = useState<Record<string, unknown> | null>(null);
    const appliedEmbedReturnRef = useRef(false);
    const saveTimeoutRef = useRef<NodeJS.Timeout | null>(null);

    // Edit mode state - published pages start in view mode
    const [isEditing, setIsEditing] = useState(page.status !== 'published');

    // Local state for form data
    const [formData, setFormData] = useState({
        name: page.name,
        icon: page.icon || '📄',
        content: page.content || { type: 'doc', content: [] },
    });

    // When returning from Excalidraw embed editor: merge image_url into block and save
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const from = params.get('from');
        const blockId = params.get('block_id');
        const imageUrl = params.get('image_url');
        if (from !== 'excalidraw_embed' || !blockId || !imageUrl || appliedEmbedReturnRef.current) return;
        const raw = page.content as { blocks?: Array<{ id?: string; type?: string; data?: Record<string, unknown> }> } | null;
        const blocks = raw?.blocks ?? [];
        const idx = blocks.findIndex((b) => b.id === blockId && b.type === 'excalidrawEmbed');
        if (idx < 0) return;
        appliedEmbedReturnRef.current = true;
        const decodedUrl = decodeURIComponent(imageUrl);
        const newBlocks = blocks.map((b, i) =>
            i === idx && b.data ? { ...b, data: { ...b.data, imageUrl: decodedUrl } } : b
        );
        const merged = { ...raw, blocks: newBlocks };
        setContentWithEmbedImage(merged);
        router.put(route('pages.update', [workspace.slug, project.slug, page.uuid]), {
            content: JSON.stringify(merged),
        }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const url = new URL(window.location.href);
                url.searchParams.delete('from');
                url.searchParams.delete('block_id');
                url.searchParams.delete('diagram_id');
                url.searchParams.delete('image_url');
                window.history.replaceState({}, '', url.pathname + url.search);
            },
        });
    }, [page.uuid, page.content, workspace.slug, project.slug]);

    // Update formData and editing state when page prop changes
    useEffect(() => {
        setFormData({
            name: page.name,
            icon: page.icon || '📄',
            content: page.content || { type: 'doc', content: [] },
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

    // Debounced content save
    const handleUpdateContent = useCallback((content: any) => {
        setIsSaving(true);

        // Clear any existing timeout
        if (saveTimeoutRef.current) {
            clearTimeout(saveTimeoutRef.current);
        }

        // Debounce save - wait 1 second after last change
        saveTimeoutRef.current = setTimeout(() => {
            router.put(route('pages.update', [workspace.slug, project.slug, page.uuid]), {
                content: JSON.stringify(content),
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
    }, [workspace.slug, project.slug, page.uuid]);

    // Cleanup timeout on unmount
    useEffect(() => {
        return () => {
            if (saveTimeoutRef.current) {
                clearTimeout(saveTimeoutRef.current);
            }
        };
    }, []);

    const handleExcalidrawEmbedInsert = useCallback(
        async (content: OutputData, diagramId: string, blockId: string) => {
            await new Promise<void>((resolve, reject) => {
                router.put(route('pages.update', [workspace.slug, project.slug, page.uuid]), {
                    content: JSON.stringify(content),
                }, {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => resolve(),
                    onError: () => reject(new Error('Failed to save')),
                });
            });
            const url = route('pages.excalidraw', [workspace.slug, project.slug, page.uuid])
                + '?embed=1&diagram_id=' + encodeURIComponent(diagramId) + '&block_id=' + encodeURIComponent(blockId);
            window.location.href = url;
        },
        [workspace.slug, project.slug, page.uuid]
    );

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

            <div className="min-h-full bg-background relative">
                {/* Table of Contents - Fixed on right side outside main content (only for doc pages) */}
                {page.type === 'page' && !isExcalidrawPage(page.content) && headings.length > 0 && (
                    <div className="hidden xl:block fixed right-8 top-32 z-20 max-h-[calc(100vh-10rem)] overflow-y-auto">
                        <TableOfContents headings={headings} />
                    </div>
                )}

                {/* Header */}
                <div className="border-b bg-background/95 backdrop-blur sticky top-0 z-10">
                    <div className="max-w-6xl mx-auto px-6 py-3">
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
                                        <Button size="sm" onClick={handlePublish}>
                                            <Globe className="size-4 mr-1.5" />
                                            Update
                                        </Button>
                                    </>
                                )}

                                {/* Unpublish in dropdown for published pages */}

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

                {/* Content */}
                <div className="max-w-6xl mx-auto px-6 py-8">
                    {/* Icon and Title */}
                    <div className="mb-8">
                        <div className="flex items-center gap-3">
                            {isEditing ? (
                                <EmojiPicker value={formData.icon} onChange={handleUpdateIcon} />
                            ) : (
                                <span className="text-4xl">{formData.icon}</span>
                            )}
                            {isEditing ? (
                                <PageTitleEditor title={formData.name} onSave={handleUpdateName} />
                            ) : (
                                <h1 className="text-4xl font-bold">{formData.name || 'Untitled'}</h1>
                            )}
                        </div>
                        <div className="flex items-center gap-4 mt-4 text-sm text-muted-foreground">
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
                        </div>
                    </div>

                    <Separator className="mb-8" />

                    {/* Whiteboard page: show preview image/card, click to open editor */}
                    {page.type === 'page' && isExcalidrawPage(page.content) && (
                        <div className="mb-8">
                            <a
                                href={route('pages.excalidraw', [workspace.slug, project.slug, page.uuid])}
                                className="block rounded-lg border border-border bg-muted/30 overflow-hidden transition-colors hover:bg-muted/50 hover:border-primary/50 focus:outline-none focus:ring-2 focus:ring-primary/20"
                            >
                                {(page.content as { previewImageUrl?: string })?.previewImageUrl ? (
                                    <img
                                        src={(page.content as { previewImageUrl: string }).previewImageUrl}
                                        alt={`Whiteboard: ${formData.name || 'Untitled'}`}
                                        className="w-full max-h-[480px] object-contain bg-white"
                                    />
                                ) : (
                                    <div className="flex flex-col items-center justify-center py-16 px-8 text-center">
                                        <span className="text-5xl mb-4">✏️</span>
                                        <p className="text-lg font-medium text-foreground">Whiteboard</p>
                                        <p className="text-sm text-muted-foreground mt-1">Click to open and edit</p>
                                    </div>
                                )}
                                <div className="px-4 py-3 border-t border-border bg-muted/20 text-sm text-muted-foreground text-center">
                                    Open whiteboard
                                </div>
                            </a>
                        </div>
                    )}

                    {/* Page Content - Editor.js (documents only; Excalidraw pages use preview above) */}
                    {page.type === 'page' && !isExcalidrawPage(page.content) && (
                        <EditorJSComponent
                            key={`${page.uuid}-${isEditing}`}
                            content={(contentWithEmbedImage ?? page.content) as any || { blocks: [] }}
                            onChange={handleUpdateContent}
                            onHeadingsChange={setHeadings}
                            editable={isEditing}
                            placeholder="Click here and start writing, or type / for commands..."
                            excalidrawEmbedConfig={{
                                getEmbedEditorUrl: ({ diagramId, blockId }) =>
                                    route('pages.excalidraw', [workspace.slug, project.slug, page.uuid]) +
                                    '?embed=1&diagram_id=' + encodeURIComponent(diagramId) + '&block_id=' + encodeURIComponent(blockId),
                            }}
                            onExcalidrawEmbedInsert={handleExcalidrawEmbedInsert}
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
