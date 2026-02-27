import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { PageProps, Workspace, Bookmark } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import {
    Bookmark as BookmarkIcon,
    Plus,
    ArrowRight,
    Link2,
    FileText,
    ListTodo,
    StickyNote,
    Lock,
    Globe,
    ClipboardPaste,
    Pencil,
    Loader2,
    Copy,
} from 'lucide-react';
import { useState, useRef, useCallback, useEffect, lazy, Suspense } from 'react';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

const URL_REGEX = /^https?:\/\/[^\s]+/i;

// Lazy-load emoji-mart like we do on pages (for bookmark icon picker)
const EmojiPickerLazy = lazy(() => import('@emoji-mart/react'));
const loadEmojiData = () => import('@emoji-mart/data').then((m) => m.default);

function EmojiMartPicker(props: any) {
    return (
        <Suspense fallback={<div className="p-2 text-sm text-muted-foreground">Loading…</div>}>
            <EmojiPickerLazy data={loadEmojiData} {...props} />
        </Suspense>
    );
}

// Bookmark Icon Picker — same UX as Page title icon (emoji-mart + upload)
function BookmarkIconPicker({
    value,
    onChange,
    onImageUpload,
}: {
    value: string;
    onChange: (val: string) => void;
    onImageUpload?: (file: File) => Promise<string>;
}) {
    const [open, setOpen] = useState(false);
    const [tab, setTab] = useState<'emoji' | 'upload'>('emoji');
    const [uploading, setUploading] = useState(false);
    const fileRef = useRef<HTMLInputElement>(null);

    const isImage = value?.startsWith('http') || value?.startsWith('/');

    const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file || !onImageUpload) return;
        setUploading(true);
        try {
            const url = await onImageUpload(file);
            onChange(url);
            setOpen(false);
        } finally {
            setUploading(false);
        }
    };

    return (
        <>
            <div className="flex flex-col items-center gap-2">
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    className="group relative size-14 flex items-center justify-center text-4xl hover:bg-accent rounded-xl transition-colors border border-border/60 bg-background"
                    title="Change icon"
                >
                    {isImage ? (
                        <img src={value} className="size-10 rounded-lg object-cover" alt="icon" />
                    ) : (
                        <span>{value || '📑'}</span>
                    )}
                    <span className="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 group-hover:opacity-100 rounded-xl transition-opacity text-white text-xs font-medium">
                        Edit
                    </span>
                </button>
                <p className="text-[11px] text-muted-foreground">Emoji or custom image</p>
            </div>

            {open && (
                <div className="fixed inset-0 z-50 flex items-start justify-center pt-20" onClick={() => setOpen(false)}>
                    <div
                        className="bg-popover border rounded-xl shadow-2xl overflow-hidden w-[360px]"
                        onClick={(e) => e.stopPropagation()}
                    >
                        {/* Tabs */}
                        <div className="flex border-b">
                            <button
                                type="button"
                                className={cn(
                                    'flex-1 py-2 text-sm font-medium transition-colors',
                                    tab === 'emoji' ? 'bg-accent text-foreground' : 'text-muted-foreground hover:text-foreground',
                                )}
                                onClick={() => setTab('emoji')}
                            >
                                😀 Emoji
                            </button>
                            <button
                                type="button"
                                className={cn(
                                    'flex-1 py-2 text-sm font-medium transition-colors',
                                    tab === 'upload' ? 'bg-accent text-foreground' : 'text-muted-foreground hover:text-foreground',
                                )}
                                onClick={() => setTab('upload')}
                            >
                                🖼 Custom Image
                            </button>
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

                        {tab === 'upload' && onImageUpload && (
                            <div className="p-6 flex flex-col items-center gap-4">
                                <p className="text-sm text-muted-foreground text-center">
                                    Upload a PNG or JPG image to use as the bookmark icon.
                                </p>
                                {isImage && (
                                    <img
                                        src={value}
                                        className="size-20 rounded-xl object-cover border"
                                        alt="current icon"
                                    />
                                )}
                                <input
                                    ref={fileRef}
                                    type="file"
                                    accept="image/png,image/jpeg,image/jpg,.png,.jpg,.jpeg"
                                    className="hidden"
                                    onChange={handleFileChange}
                                />
                                <button
                                    type="button"
                                    onClick={() => fileRef.current?.click()}
                                    className="px-4 py-2 rounded-lg border border-dashed border-primary text-sm font-medium hover:bg-accent transition-colors w-full text-center disabled:opacity-70"
                                    disabled={uploading}
                                >
                                    {uploading ? 'Uploading…' : 'Choose file…'}
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </>
    );
}

interface Props extends PageProps {
    workspace: Workspace;
    bookmarks: Bookmark[];
}

export default function BookmarksIndex({ workspace, bookmarks }: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editBookmark, setEditBookmark] = useState<Bookmark | null>(null);

    // Auto-open create dialog when navigated here with ?create=true
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('create') === 'true') {
            setCreateOpen(true);
            window.history.replaceState({}, '', window.location.pathname);
        }
    }, []);
    const [pasteLoading, setPasteLoading] = useState<string | null>(null);
    const [hoveredCardSlug, setHoveredCardSlug] = useState<string | null>(null);
    const [quickTodoValue, setQuickTodoValue] = useState('');
    const [quickTodoSubmitting, setQuickTodoSubmitting] = useState(false);
    const hoverEnterRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const hoverLeaveRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const showTodoInput = useCallback((slug: string) => {
        if (hoverLeaveRef.current) {
            clearTimeout(hoverLeaveRef.current);
            hoverLeaveRef.current = null;
        }
        hoverEnterRef.current = setTimeout(() => setHoveredCardSlug(slug), 200);
    }, []);

    const hideTodoInput = useCallback(() => {
        if (hoverEnterRef.current) {
            clearTimeout(hoverEnterRef.current);
            hoverEnterRef.current = null;
        }
        hoverLeaveRef.current = setTimeout(() => {
            setHoveredCardSlug(null);
            setQuickTodoValue('');
            hoverLeaveRef.current = null;
        }, 300);
    }, []);

    const { data, setData, post, processing, reset, errors } = useForm({
        name: '',
        description: '',
        icon: '',
        visibility: 'private' as 'private' | 'public',
    });

    const editForm = useForm({
        name: '',
        description: '',
        icon: '',
        visibility: 'private' as 'private' | 'public',
    });

    const createBookmark = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('bookmarks.store', [workspace.slug]), {
            onSuccess: () => {
                setCreateOpen(false);
                reset();
            },
        });
    };

    const openEdit = (b: Bookmark) => {
        setEditBookmark(b);
        editForm.setData({
            name: b.name,
            description: b.description ?? '',
            icon: b.icon_display ?? b.icon ?? '',
            visibility: b.visibility ?? 'private',
        });
    };

    const handleBookmarkIconUpload = async (file: File, bookmarkSlug: string): Promise<string> => {
        const formData = new FormData();
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        if (token) formData.append('_token', token);
        formData.append('icon', file);
        const res = await fetch(route('bookmarks.icon.upload', [workspace.slug, bookmarkSlug]), {
            method: 'POST',
            body: formData,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            toast.error(data.message ?? 'Icon upload failed');
            throw new Error(data.message ?? 'Upload failed');
        }
        return data.icon_display;
    };

    const handleCreateBookmarkIconUpload = async (file: File): Promise<string> => {
        const formData = new FormData();
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        if (token) formData.append('_token', token);
        formData.append('icon', file);
        const res = await fetch(route('bookmarks.icon.upload-temp', [workspace.slug]), {
            method: 'POST',
            body: formData,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            toast.error(data.message ?? 'Icon upload failed');
            throw new Error(data.message ?? 'Upload failed');
        }
        return data.icon_display;
    };

    const saveEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editBookmark) return;
        editForm.put(route('bookmarks.update', [workspace.slug, editBookmark.slug]), {
            onSuccess: () => setEditBookmark(null),
        });
    };

    const copySharedLinkFromEdit = (e: React.MouseEvent) => {
        e.preventDefault();
        if (!editBookmark?.uuid) return;
        const path = route('bookmarks.public.show', [editBookmark.uuid]);
        const url = path.startsWith('http') ? path : `${window.location.origin}${path.startsWith('/') ? '' : '/'}${path}`;
        navigator.clipboard.writeText(url).then(() => toast.success('Link copied')).catch(() => toast.error('Could not copy'));
    };

    const postFile = (
        bookmark: Bookmark,
        file: File,
        callbacks?: { onSuccess?: () => void; onError?: () => void }
    ) => {
        const formData = new FormData();
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        if (token) formData.append('_token', token);
        formData.append('type', 'file');
        formData.append('file', file);
        formData.append('title', file.name);
        router.post(
            route('bookmarks.items.store', [workspace.slug, bookmark.slug]),
            formData,
            {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: callbacks?.onSuccess,
                onError: callbacks?.onError,
            }
        );
    };

    const handlePaste = async (bookmark: Bookmark, e: React.MouseEvent) => {
        e.preventDefault();
        e.stopPropagation();
        if (pasteLoading) return;
        try {
            const items = await navigator.clipboard.read();
            if (!items.length) {
                toast.error('Clipboard is empty');
                return;
            }
            setPasteLoading(bookmark.slug);
            const loadingId = toast.loading('Pasting…');

            let handled = false;
            for (const item of items) {
                const types = item.types;
                const imageType = types.find((t) => t.startsWith('image/'));
                if (imageType) {
                    const blob = await item.getType(imageType);
                    const ext = imageType.split('/')[1]?.replace('jpeg', 'jpg') ?? 'png';
                    const file = new File([blob], `pasted-image.${ext}`, { type: blob.type });
                    postFile(bookmark, file, {
                        onSuccess: () => toast.success(`File saved to "${bookmark.name}"`, { id: loadingId }),
                        onError: () => toast.error('Upload failed', { id: loadingId }),
                    });
                    handled = true;
                    break;
                }
                const appType = types.find((t) => t.startsWith('application/'));
                if (appType) {
                    const blob = await item.getType(appType);
                    const extMap: Record<string, string> = {
                        'application/pdf': 'pdf',
                        'application/zip': 'zip',
                        'application/x-rar-compressed': 'rar',
                        'application/msword': 'doc',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx',
                    };
                    const ext = extMap[appType] ?? appType.split('/')[1]?.replace(/^x-/, '') ?? 'bin';
                    const file = new File([blob], `pasted-file.${ext}`, { type: blob.type });
                    postFile(bookmark, file, {
                        onSuccess: () => toast.success(`File saved to "${bookmark.name}"`, { id: loadingId }),
                        onError: () => toast.error('Upload failed', { id: loadingId }),
                    });
                    handled = true;
                    break;
                }
            }
            if (!handled) {
                for (const item of items) {
                    if (item.types.includes('text/plain')) {
                        const text = await (await item.getType('text/plain')).text();
                        const trimmed = text.trim();
                        if (!trimmed) continue;
                        if (URL_REGEX.test(trimmed)) {
                            router.post(
                                route('bookmarks.items.store', [workspace.slug, bookmark.slug]),
                                { type: 'link', url: trimmed, title: trimmed.slice(0, 200) },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => toast.success(`Link saved to "${bookmark.name}"`, { id: loadingId }),
                                    onError: () => toast.error('Failed to add link', { id: loadingId }),
                                }
                            );
                        } else {
                            router.post(
                                route('bookmarks.items.store', [workspace.slug, bookmark.slug]),
                                { type: 'note', content: trimmed, title: trimmed.slice(0, 100) },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => toast.success(`Note saved to "${bookmark.name}"`, { id: loadingId }),
                                    onError: () => toast.error('Failed to add note', { id: loadingId }),
                                }
                            );
                        }
                        handled = true;
                        break;
                    }
                }
            }
            if (!handled) {
                toast.dismiss(loadingId);
                toast.info('No link, file, or text found in clipboard');
            }
        } catch (err) {
            toast.error('Could not read clipboard');
        } finally {
            setPasteLoading(null);
        }
    };

    const submitQuickTodo = (bookmark: Bookmark) => {
        const title = quickTodoValue.trim();
        if (!title || quickTodoSubmitting) return;
        setQuickTodoSubmitting(true);
        router.post(
            route('bookmarks.items.store', [workspace.slug, bookmark.slug]),
            { type: 'todo', title },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setQuickTodoValue('');
                    toast.success(`To-do added to "${bookmark.name}"`);
                },
                onError: () => toast.error('Failed to add to-do'),
                onFinish: () => setQuickTodoSubmitting(false),
            }
        );
    };

    const copySharedLink = (bookmark: Bookmark, e: React.MouseEvent) => {
        e.preventDefault();
        e.stopPropagation();
        if (!bookmark.uuid) return;
        const path = route('bookmarks.public.show', [bookmark.uuid]);
        const url = path.startsWith('http') ? path : `${typeof window !== 'undefined' ? window.location.origin : ''}${path.startsWith('/') ? '' : '/'}${path}`;
        navigator.clipboard.writeText(url).then(() => toast.success('Link copied')).catch(() => toast.error('Could not copy'));
    };

    const visibilityConfig: Record<string, { label: string; variant: 'secondary' | 'default' }> = {
        private: { label: 'Private', variant: 'secondary' },
        public: { label: 'Shared', variant: 'default' },
    };
    const sharedBadgeClass = 'border-transparent bg-brand-green text-white hover:bg-brand-green';

    return (
        <AppLayout>
            <Head title={`Bookmarks – ${workspace.name}`} />

            <div className="min-h-screen bg-gradient-to-b from-muted/30 to-background">
                <div className="p-6 md:p-8 lg:p-10 max-w-6xl mx-auto space-y-8">
                    {/* Header */}
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div className="flex items-center gap-4 min-w-0">
                            <div className="size-12 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                <BookmarkIcon className="size-6 text-primary" />
                            </div>
                            <div className="min-w-0">
                                <h1 className="text-2xl font-bold tracking-tight truncate">Bookmarks</h1>
                                <p className="text-sm text-muted-foreground">
                                    Save links, files, and to-dos. Workspace: {workspace.name}
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2 shrink-0">
                            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                                <DialogTrigger asChild>
                                    <Button size="lg" className="gap-2">
                                        <Plus className="size-5" />
                                        Add Bookmark
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="sm:max-w-lg">
                                    <DialogHeader>
                                        <DialogTitle className="text-xl">New bookmark collection</DialogTitle>
                                        <DialogDescription>
                                            Create a card to collect links, files, and to-dos. You can share it publicly (view only) or invite workspace members to view or edit.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <form onSubmit={createBookmark} className="space-y-5 mt-4">
                                        <div className="flex gap-3 items-end">
                                            <BookmarkIconPicker
                                                value={data.icon}
                                                onChange={(v) => setData('icon', v)}
                                                onImageUpload={handleCreateBookmarkIconUpload}
                                            />
                                            <div className="flex-1 min-w-0 space-y-2">
                                                <Label htmlFor="name">Name</Label>
                                                <Input
                                                    id="name"
                                                    value={data.name}
                                                    onChange={(e) => setData('name', e.target.value)}
                                                    placeholder="e.g. Research, Reading list"
                                                    className="h-10"
                                                    required
                                                />
                                                {errors.name && (
                                                    <p className="text-sm text-destructive">{errors.name}</p>
                                                )}
                                            </div>
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="description">Description (optional)</Label>
                                            <Textarea
                                                id="description"
                                                value={data.description}
                                                onChange={(e) => setData('description', e.target.value)}
                                                placeholder="What is this collection for?"
                                                className="min-h-[80px] resize-none"
                                            />
                                        </div>
                                        <div className="rounded-lg border bg-muted/30 p-4 space-y-2">
                                            <Label>Visibility</Label>
                                            <div className="flex gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => setData('visibility', 'private')}
                                                    className={`flex-1 flex items-center justify-center gap-2 rounded-lg border-2 py-2.5 text-sm font-medium transition-colors ${
                                                        data.visibility === 'private'
                                                            ? 'border-primary bg-primary/10 text-primary'
                                                            : 'border-transparent bg-background hover:bg-muted'
                                                    }`}
                                                >
                                                    <Lock className="size-4" />
                                                    Private
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setData('visibility', 'public')}
                                                    className={`flex-1 flex items-center justify-center gap-2 rounded-lg border-2 py-2.5 text-sm font-medium transition-colors ${
                                                        data.visibility === 'public'
                                                            ? 'border-primary bg-primary/10 text-primary'
                                                            : 'border-transparent bg-background hover:bg-muted'
                                                    }`}
                                                >
                                                    <Globe className="size-4" />
                                                    Shared
                                                </button>
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                {data.visibility === 'public' ? 'Anyone with the link can view (read-only).' : 'Only you and invited collaborators can see this.'}
                                            </p>
                                        </div>
                                        <div className="flex justify-end gap-2 pt-2 border-t">
                                            <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button type="submit" disabled={processing}>
                                                {processing ? 'Creating…' : 'Create'}
                                            </Button>
                                        </div>
                                    </form>
                                </DialogContent>
                            </Dialog>
                            <Link href={route('workspaces.show', [workspace.slug])}>
                                <Button variant="outline" size="lg">
                                    Back to workspace
                                </Button>
                            </Link>
                        </div>
                    </div>

                    {/* Cards grid */}
                    {bookmarks.length === 0 ? (
                        <Card className="border-2 border-dashed">
                            <CardContent className="flex flex-col items-center justify-center py-20">
                                <div className="size-20 rounded-full bg-primary/10 flex items-center justify-center mb-6">
                                    <BookmarkIcon className="size-10 text-primary" />
                                </div>
                                <h2 className="text-xl font-semibold mb-2">No bookmarks yet</h2>
                                <p className="text-muted-foreground text-center max-w-md mb-8">
                                    Create a bookmark card to start saving links, PDFs, and to-dos. Use Paste on a card to add from clipboard. Share publicly (view only) or invite workspace members to view or edit.
                                </p>
                                <Button size="lg" className="gap-2" onClick={() => setCreateOpen(true)}>
                                    <Plus className="size-5" />
                                    Add Bookmark
                                </Button>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {bookmarks.map((bookmark) => {
                                const config = visibilityConfig[bookmark.visibility] ?? visibilityConfig.private;
                                const VisibilityIcon = bookmark.visibility === 'public' ? Globe : Lock;
                                const isPasting = pasteLoading === bookmark.slug;
                                return (
                                    <div
                                        key={bookmark.id}
                                        className="flex flex-col gap-2"
                                        onMouseEnter={() => showTodoInput(bookmark.slug)}
                                        onMouseLeave={hideTodoInput}
                                    >
                                        <Card className="h-full hover:shadow-lg hover:border-primary/30 transition-all group flex-1 flex flex-col">
                                            <CardContent className="p-5 flex flex-col flex-1">
                                                {/* Top: status + copy link + edit */}
                                                <div className="flex items-center justify-between gap-2 mb-3">
                                                    <div className="flex items-center gap-2">
                                                        <Badge
                                                            variant={config.variant}
                                                            className={bookmark.visibility === 'public' ? `gap-1 ${sharedBadgeClass}` : 'gap-1'}
                                                        >
                                                            <VisibilityIcon className="size-3" />
                                                            {config.label}
                                                        </Badge>
                                                        {bookmark.visibility === 'public' && bookmark.uuid && (
                                                            <Button variant="ghost" size="sm" className="h-8 gap-1.5 text-xs" onClick={(e) => copySharedLink(bookmark, e)} title="Copy shared link">
                                                                <Copy className="size-3.5" />
                                                                Copy link
                                                            </Button>
                                                        )}
                                                    </div>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="size-8 p-0 shrink-0"
                                                        onClick={(e) => {
                                                            e.preventDefault();
                                                            openEdit(bookmark);
                                                        }}
                                                        title="Edit name and description"
                                                    >
                                                        <Pencil className="size-4" />
                                                    </Button>
                                                </div>
                                                <Link
                                                    href={route('bookmarks.show', [workspace.slug, bookmark.slug])}
                                                    className="flex-1 min-w-0 flex items-start gap-3"
                                                >
                                                    <span className="text-2xl shrink-0 flex items-center justify-center size-8 overflow-hidden rounded">
                                                        {bookmark.icon_display && (bookmark.icon_display.startsWith('http') || bookmark.icon_display.startsWith('/')) ? (
                                                            <img src={bookmark.icon_display} alt="" className="size-8 object-contain" />
                                                        ) : (
                                                            bookmark.icon_display || bookmark.icon || '📑'
                                                        )}
                                                    </span>
                                                    <div className="min-w-0 flex-1">
                                                        <h3 className="font-semibold text-foreground group-hover:text-primary transition-colors truncate">
                                                            {bookmark.name}
                                                        </h3>
                                                    </div>
                                                    <ArrowRight className="size-4 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity mt-0.5" />
                                                </Link>
                                                <Link
                                                    href={route('bookmarks.show', [workspace.slug, bookmark.slug])}
                                                    className="block"
                                                >
                                                    <p className="text-sm text-muted-foreground line-clamp-2 min-h-[2.5rem] mb-4">
                                                        {bookmark.description || 'No description'}
                                                    </p>
                                                </Link>
                                                <div className="flex flex-wrap gap-3 text-xs text-muted-foreground mb-4">
                                                    <span className="flex items-center gap-1">
                                                        <Link2 className="size-3.5" />
                                                        {bookmark.links_count ?? 0}
                                                    </span>
                                                    <span className="flex items-center gap-1">
                                                        <FileText className="size-3.5" />
                                                        {bookmark.files_count ?? 0}
                                                    </span>
                                                    <span className="flex items-center gap-1">
                                                        <ListTodo className="size-3.5" />
                                                        {bookmark.todos_completed_count ?? 0}/
                                                        {bookmark.todos_count ?? 0}
                                                    </span>
                                                    <span className="flex items-center gap-1">
                                                        <StickyNote className="size-3.5" />
                                                        {bookmark.notes_count ?? 0}
                                                    </span>
                                                </div>
                                                {/* Bottom right: Paste button with blue background */}
                                                <div className="mt-auto pt-2 flex justify-end" onClick={(e) => e.preventDefault()}>
                                                    <Button
                                                        size="sm"
                                                        className="gap-1.5 bg-primary text-primary-foreground hover:bg-primary/90"
                                                        disabled={isPasting}
                                                        onClick={(e) => handlePaste(bookmark, e)}
                                                        title="Paste from clipboard (link, text, or image)"
                                                    >
                                                        {isPasting ? (
                                                            <Loader2 className="size-4 animate-spin" />
                                                        ) : (
                                                            <ClipboardPaste className="size-4" />
                                                        )}
                                                        {isPasting ? 'Pasting…' : 'Paste'}
                                                    </Button>
                                                </div>
                                            </CardContent>
                                        </Card>
                                        {/* Reserved space for quick-add to-do – prevents layout shift on hover */}
                                        <div className="min-h-[2.5rem] flex items-center px-1">
                                            {hoveredCardSlug === bookmark.slug && (
                                                <div className="flex gap-2 w-full" onClick={(e) => e.stopPropagation()}>
                                                    <Input
                                                        placeholder="Add a to-do…"
                                                        value={quickTodoValue}
                                                        onChange={(e) => setQuickTodoValue(e.target.value)}
                                                        onKeyDown={(e) => {
                                                            if (e.key === 'Enter') {
                                                                e.preventDefault();
                                                                submitQuickTodo(bookmark);
                                                            }
                                                        }}
                                                        className="h-8 text-sm flex-1"
                                                        disabled={quickTodoSubmitting}
                                                        autoFocus
                                                    />
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        className="h-8 shrink-0"
                                                        disabled={!quickTodoValue.trim() || quickTodoSubmitting}
                                                        onClick={() => submitQuickTodo(bookmark)}
                                                    >
                                                        {quickTodoSubmitting ? <Loader2 className="size-4 animate-spin" /> : 'Add'}
                                                    </Button>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>

            {/* Edit bookmark dialog – settings */}
            <Dialog open={!!editBookmark} onOpenChange={(open) => !open && setEditBookmark(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="text-xl">Bookmark settings</DialogTitle>
                        <DialogDescription>
                            Edit name, description, icon, and sharing for this bookmark.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={saveEdit} className="space-y-5 mt-4">
                        <div className="flex gap-3 items-end">
                            {editBookmark && (
                                <BookmarkIconPicker
                                    value={editForm.data.icon}
                                    onChange={(v) => editForm.setData('icon', v)}
                                    onImageUpload={async (file) => handleBookmarkIconUpload(file, editBookmark.slug)}
                                />
                            )}
                            <div className="flex-1 min-w-0 space-y-2">
                                <Label htmlFor="edit-name">Name</Label>
                                <Input
                                    id="edit-name"
                                    value={editForm.data.name}
                                    onChange={(e) => editForm.setData('name', e.target.value)}
                                    placeholder="Bookmark name"
                                    className="h-10"
                                    required
                                />
                                {editForm.errors.name && (
                                    <p className="text-sm text-destructive">{editForm.errors.name}</p>
                                )}
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-description">Description</Label>
                            <Textarea
                                id="edit-description"
                                value={editForm.data.description}
                                onChange={(e) => editForm.setData('description', e.target.value)}
                                placeholder="What is this collection for?"
                                className="min-h-[80px] resize-none"
                            />
                        </div>
                        <div className="rounded-lg border bg-muted/30 p-4 space-y-2">
                            <Label>Visibility</Label>
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    onClick={() => editForm.setData('visibility', 'private')}
                                    className={`flex-1 flex items-center justify-center gap-2 rounded-lg border-2 py-2.5 text-sm font-medium transition-colors ${
                                        editForm.data.visibility === 'private'
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-transparent bg-background hover:bg-muted'
                                    }`}
                                >
                                    <Lock className="size-4" />
                                    Private
                                </button>
                                <button
                                    type="button"
                                    onClick={() => editForm.setData('visibility', 'public')}
                                    className={`flex-1 flex items-center justify-center gap-2 rounded-lg border-2 py-2.5 text-sm font-medium transition-colors ${
                                        editForm.data.visibility === 'public'
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-transparent bg-background hover:bg-muted'
                                    }`}
                                >
                                    <Globe className="size-4" />
                                    Shared
                                </button>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {editForm.data.visibility === 'public'
                                    ? 'Anyone with the link can view (read-only).'
                                    : 'Only you and invited collaborators can see this.'}
                            </p>
                            {editForm.data.visibility === 'public' && editBookmark?.uuid && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="mt-2 gap-2"
                                    onClick={copySharedLinkFromEdit}
                                >
                                    <Copy className="size-4" />
                                    Copy shared link
                                </Button>
                            )}
                        </div>
                        <div className="flex justify-end gap-2 pt-2 border-t">
                            <Button type="button" variant="outline" onClick={() => setEditBookmark(null)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={editForm.processing}>
                                {editForm.processing ? 'Saving…' : 'Save'}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
