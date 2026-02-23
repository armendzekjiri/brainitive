import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { PageProps, Workspace, Bookmark, BookmarkItem, User as UserType } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
    Link2,
    FileText,
    File,
    FileImage,
    FileSpreadsheet,
    FileArchive,
    FileCode,
    FileType,
    ListTodo,
    StickyNote,
    ExternalLink,
    Trash2,
    Check,
    ChevronLeft,
    Lock,
    Globe,
    Copy,
    Pencil,
    AlertTriangle,
    Loader2,
    Eye,
    Download,
} from 'lucide-react';
import { useState, useRef, useEffect } from 'react';
import { toast } from 'sonner';

/** File type icon + color by extension. Default icon for unknown types. */
function getFileIcon(filename: string): { Icon: React.ComponentType<{ className?: string }>; className: string } {
    const ext = filename?.split('.').pop()?.toLowerCase() ?? '';
    const map: Record<string, { Icon: React.ComponentType<{ className?: string }>; className: string }> = {
        pdf: { Icon: FileText, className: 'text-red-600' },
        doc: { Icon: FileText, className: 'text-blue-600' },
        docx: { Icon: FileText, className: 'text-blue-600' },
        rtf: { Icon: FileText, className: 'text-blue-500' },
        txt: { Icon: FileType, className: 'text-muted-foreground' },
        md: { Icon: FileType, className: 'text-muted-foreground' },
        xls: { Icon: FileSpreadsheet, className: 'text-green-600' },
        xlsx: { Icon: FileSpreadsheet, className: 'text-green-600' },
        csv: { Icon: FileSpreadsheet, className: 'text-green-500' },
        ppt: { Icon: FileText, className: 'text-orange-600' },
        pptx: { Icon: FileText, className: 'text-orange-600' },
        jpg: { Icon: FileImage, className: 'text-purple-500' },
        jpeg: { Icon: FileImage, className: 'text-purple-500' },
        png: { Icon: FileImage, className: 'text-sky-500' },
        gif: { Icon: FileImage, className: 'text-pink-500' },
        webp: { Icon: FileImage, className: 'text-teal-500' },
        bmp: { Icon: FileImage, className: 'text-muted-foreground' },
        ico: { Icon: FileImage, className: 'text-amber-500' },
        tiff: { Icon: FileImage, className: 'text-muted-foreground' },
        tif: { Icon: FileImage, className: 'text-muted-foreground' },
        svg: { Icon: FileImage, className: 'text-amber-500' },
        heic: { Icon: FileImage, className: 'text-muted-foreground' },
        heif: { Icon: FileImage, className: 'text-muted-foreground' },
        avif: { Icon: FileImage, className: 'text-muted-foreground' },
        zip: { Icon: FileArchive, className: 'text-amber-600' },
        rar: { Icon: FileArchive, className: 'text-amber-600' },
        '7z': { Icon: FileArchive, className: 'text-amber-600' },
        json: { Icon: FileCode, className: 'text-amber-500' },
        xml: { Icon: FileCode, className: 'text-orange-500' },
    };
    return map[ext] ?? { Icon: File, className: 'text-muted-foreground' };
}

type PreviewType = 'pdf' | 'image' | 'video' | 'other';
function getFilePreviewType(filename: string): PreviewType {
    const ext = filename?.split('.').pop()?.toLowerCase() ?? '';
    const pdf = ['pdf'];
    const image = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'tiff', 'tif', 'svg', 'heic', 'heif', 'avif'];
    const video = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'];
    if (pdf.includes(ext)) return 'pdf';
    if (image.includes(ext)) return 'image';
    if (video.includes(ext)) return 'video';
    return 'other';
}

type DeleteTarget =
    | { type: 'item'; item: BookmarkItem }
    | { type: 'collaborator'; userId: number; userName: string };

interface Props extends PageProps {
    workspace: Workspace;
    bookmark: Bookmark & { items: BookmarkItem[] };
    workspaceMembers: UserType[];
    canEdit: boolean;
}

export default function BookmarksShow({ workspace, bookmark, workspaceMembers, canEdit = true }: Props) {
    const [pasteUrl, setPasteUrl] = useState('');
    const [newTodo, setNewTodo] = useState('');
    const [newNote, setNewNote] = useState('');
    const [editingTitle, setEditingTitle] = useState(false);
    const [editName, setEditName] = useState(bookmark.name);
    const [editingDescription, setEditingDescription] = useState(false);
    const [editDescription, setEditDescription] = useState(bookmark.description ?? '');
    const [editingNote, setEditingNote] = useState<BookmarkItem | null>(null);
    const [editNoteContent, setEditNoteContent] = useState('');
    const [editNoteTitle, setEditNoteTitle] = useState('');
    const [editingTodo, setEditingTodo] = useState<BookmarkItem | null>(null);
    const [editTodoTitle, setEditTodoTitle] = useState('');
    const todoInputRef = useRef<HTMLInputElement>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const pastePendingCountRef = useRef(0);
    const pasteLoadingToastIdRef = useRef<string | number | null>(null);

    const openTodoEdit = (item: BookmarkItem) => {
        setEditingTodo(item);
        setEditTodoTitle(item.title ?? '');
        setTimeout(() => todoInputRef.current?.focus(), 0);
    };

    const saveTodoEdit = () => {
        if (!editingTodo) return;
        const title = editTodoTitle.trim() || 'To-do';
        router.put(
            route('bookmarks.items.update', [workspace.slug, bookmark.slug, editingTodo.id]),
            { title },
            { preserveScroll: true, onSuccess: () => setEditingTodo(null) }
        );
    };

    const openNoteEditor = (item: BookmarkItem) => {
        setEditingNote(item);
        setEditNoteContent(item.content ?? '');
        setEditNoteTitle(item.title ?? '');
    };

    const saveNoteEdit = () => {
        if (!editingNote) return;
        router.put(
            route('bookmarks.items.update', [workspace.slug, bookmark.slug, editingNote.id]),
            { content: editNoteContent, title: editNoteTitle || editNoteContent.slice(0, 100) },
            { preserveScroll: true, onSuccess: () => setEditingNote(null) }
        );
    };

    useEffect(() => {
        setEditName(bookmark.name);
        setEditDescription(bookmark.description ?? '');
    }, [bookmark.name, bookmark.description]);

    useEffect(() => {
        if (!canEdit) return;
        const onPaste = (e: ClipboardEvent) => {
            const el = document.activeElement;
            const tag = el?.tagName?.toLowerCase();
            if (el && (tag === 'input' || tag === 'textarea' || (el as HTMLElement).isContentEditable)) {
                return;
            }
            const dt = e.clipboardData;
            if (!dt) return;
            if (dt.files && dt.files.length > 0) {
                e.preventDefault();
                e.stopPropagation();
                const fileCount = dt.files.length;
                pastePendingCountRef.current = fileCount;
                pasteLoadingToastIdRef.current = toast.loading(
                    fileCount === 1 ? 'Uploading file…' : `Uploading ${fileCount} files…`
                );
                for (let i = 0; i < fileCount; i++) {
                    const formData = new FormData();
                    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
                    if (token) formData.append('_token', token);
                    formData.append('type', 'file');
                    formData.append('file', dt.files[i]);
                    formData.append('title', dt.files[i].name || 'pasted-file');
                    router.post(
                        route('bookmarks.items.store', [workspace.slug, bookmark.slug]),
                        formData,
                        {
                            preserveScroll: true,
                            forceFormData: true,
                            onSuccess: () => {
                                pastePendingCountRef.current--;
                                if (pastePendingCountRef.current <= 0) {
                                    toast.success(fileCount === 1 ? 'File added' : 'Files added', {
                                        id: pasteLoadingToastIdRef.current ?? undefined,
                                    });
                                    pasteLoadingToastIdRef.current = null;
                                }
                            },
                            onError: () => {
                                pastePendingCountRef.current--;
                                if (pastePendingCountRef.current <= 0) {
                                    toast.error('Upload failed', { id: pasteLoadingToastIdRef.current ?? undefined });
                                    pasteLoadingToastIdRef.current = null;
                                }
                            },
                        }
                    );
                }
                return;
            }
            const text = dt.getData('text/plain')?.trim();
            if (!text) return;
            e.preventDefault();
            e.stopPropagation();
            const urlRegex = /^https?:\/\/[^\s]+/i;
            const isLink = urlRegex.test(text);
            const loadingId = toast.loading(isLink ? 'Adding link…' : 'Adding note…');
            const payload = isLink
                ? { type: 'link' as const, url: text, title: text.slice(0, 200) }
                : { type: 'note' as const, content: text, title: text.slice(0, 100) };
            router.post(
                route('bookmarks.items.store', [workspace.slug, bookmark.slug]),
                payload,
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        toast.success(isLink ? 'Link added' : 'Note added', { id: loadingId });
                    },
                    onError: () => {
                        toast.error('Failed to add', { id: loadingId });
                    },
                }
            );
        };
        document.addEventListener('paste', onPaste);
        return () => document.removeEventListener('paste', onPaste);
    }, [canEdit, workspace.slug, bookmark.slug]);

    type AddTab = 'link' | 'file' | 'note' | 'todo';
    const [formLoading, setFormLoading] = useState<AddTab | null>(null);
    const [filePreview, setFilePreview] = useState<{ url: string; title: string; previewType: PreviewType } | null>(null);

    const isValidUrl = (s: string) => /^https?:\/\/[^\s]+/i.test(s.trim());

    const submitUrl = (e: React.FormEvent) => {
        e.preventDefault();
        const url = pasteUrl.trim();
        if (!url || formLoading) return;
        if (!isValidUrl(url)) {
            toast.error('That is not a valid URL. Use a link starting with http:// or https://');
            return;
        }
        setFormLoading('link');
        const loadingId = toast.loading('Adding link…');
        router.post(
            route('bookmarks.items.store', [workspace.slug, bookmark.slug]),
            { type: 'link', url, title: url.slice(0, 200) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setPasteUrl('');
                    toast.success('Link added', { id: loadingId });
                },
                onError: () => toast.error('Failed to add link', { id: loadingId }),
                onFinish: () => setFormLoading(null),
            }
        );
    };

    const submitTodo = (e: React.FormEvent) => {
        e.preventDefault();
        if (!newTodo.trim() || formLoading) return;
        setFormLoading('todo');
        const loadingId = toast.loading('Adding to-do…');
        router.post(
            route('bookmarks.items.store', [workspace.slug, bookmark.slug]),
            { type: 'todo', title: newTodo.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setNewTodo('');
                    toast.success('To-do added', { id: loadingId });
                },
                onError: () => toast.error('Failed to add to-do', { id: loadingId }),
                onFinish: () => setFormLoading(null),
            }
        );
    };

    const submitNote = (e: React.FormEvent) => {
        e.preventDefault();
        if (!newNote.trim() || formLoading) return;
        setFormLoading('note');
        const loadingId = toast.loading('Adding note…');
        router.post(
            route('bookmarks.items.store', [workspace.slug, bookmark.slug]),
            { type: 'note', content: newNote.trim(), title: newNote.trim().slice(0, 100) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setNewNote('');
                    toast.success('Note added', { id: loadingId });
                },
                onError: () => toast.error('Failed to add note', { id: loadingId }),
                onFinish: () => setFormLoading(null),
            }
        );
    };

    const uploadFile = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file || formLoading) return;
        const MAX_MB = 200;
        if (file.size > MAX_MB * 1024 * 1024) {
            toast.error(`File must be under ${MAX_MB}MB`);
            e.target.value = '';
            return;
        }
        setFormLoading('file');
        const loadingId = toast.loading('Uploading file…');
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
                onSuccess: () => toast.success('File added', { id: loadingId }),
                onError: () => toast.error('Upload failed', { id: loadingId }),
                onFinish: () => {
                    setFormLoading(null);
                    if (fileInputRef.current) fileInputRef.current.value = '';
                },
            }
        );
    };

    const toggleTodo = (item: BookmarkItem) => {
        if (item.type !== 'todo') return;
        router.patch(
            route('bookmarks.items.toggle', [workspace.slug, bookmark.slug, item.id]),
            {},
            { preserveScroll: true }
        );
    };

    const [deleteConfirm, setDeleteConfirm] = useState<DeleteTarget | null>(null);

    const deleteItem = (item: BookmarkItem) => {
        setDeleteConfirm({ type: 'item', item });
    };

    const confirmDeleteItem = () => {
        if (!deleteConfirm || deleteConfirm.type !== 'item') return;
        router.delete(
            route('bookmarks.items.destroy', [workspace.slug, bookmark.slug, deleteConfirm.item.id]),
            { preserveScroll: true, onSuccess: () => setDeleteConfirm(null) }
        );
    };

    const openRemoveCollaboratorConfirm = (userId: number, userName: string) => {
        setDeleteConfirm({ type: 'collaborator', userId, userName });
    };

    const confirmRemoveCollaborator = () => {
        if (!deleteConfirm || deleteConfirm.type !== 'collaborator') return;
        router.delete(
            route('bookmarks.collaborators.remove', [workspace.slug, bookmark.slug, deleteConfirm.userId]),
            { preserveScroll: true, onSuccess: () => setDeleteConfirm(null) }
        );
    };

    const handleConfirmDelete = () => {
        if (!deleteConfirm) return;
        if (deleteConfirm.type === 'item') confirmDeleteItem();
        else confirmRemoveCollaborator();
    };

    const getDeleteModalContent = (): { title: string; description: string; label: string } => {
        if (!deleteConfirm) return { title: '', description: '', label: 'Delete' };
        if (deleteConfirm.type === 'item') {
            const typeLabels = { link: 'Link', file: 'File', todo: 'To-do', note: 'Note' };
            const typeLabel = typeLabels[deleteConfirm.item.type] ?? 'Item';
            const name = deleteConfirm.item.title || deleteConfirm.item.url || typeLabel;
            return {
                title: `Remove ${typeLabel}?`,
                description: `"${String(name).slice(0, 60)}${String(name).length > 60 ? '…' : ''}" will be removed. This cannot be undone.`,
                label: 'Remove',
            };
        }
        return {
            title: 'Remove collaborator?',
            description: `${deleteConfirm.userName} will lose access to this bookmark. They can be invited again later.`,
            label: 'Remove',
        };
    };

    const updateVisibility = (visibility: string) => {
        router.put(
            route('bookmarks.update', [workspace.slug, bookmark.slug]),
            { visibility },
            { preserveScroll: true }
        );
    };

    const items = bookmark.items ?? [];
    const links = items.filter((i) => i.type === 'link');
    const files = items.filter((i) => i.type === 'file');
    const todos = items.filter((i) => i.type === 'todo');
    const notes = items.filter((i) => i.type === 'note');

    const visibilityConfig = {
        private: { label: 'Private', icon: Lock },
        public: { label: 'Shared (view only)', icon: Globe },
    };

    const [inviteUserId, setInviteUserId] = useState('');
    const [inviteRole, setInviteRole] = useState<'viewer' | 'editor'>('viewer');
    const collaborators = bookmark.collaborators ?? [];
    const availableToInvite = workspaceMembers.filter(
        (m) => m.id !== bookmark.created_by && !collaborators.some((c) => c.id === m.id)
    );

    const inviteCollaborator = (e: React.FormEvent) => {
        e.preventDefault();
        if (!inviteUserId) return;
        router.post(
            route('bookmarks.collaborators.invite', [workspace.slug, bookmark.slug]),
            { user_id: Number(inviteUserId), role: inviteRole },
            { preserveScroll: true, onSuccess: () => setInviteUserId('') }
        );
    };

    const updateCollaboratorRole = (userId: number, role: 'viewer' | 'editor') => {
        router.put(
            route('bookmarks.collaborators.update', [workspace.slug, bookmark.slug, userId]),
            { role },
            { preserveScroll: true }
        );
    };

    const removeCollaborator = (userId: number) => {
        router.delete(
            route('bookmarks.collaborators.remove', [workspace.slug, bookmark.slug, userId]),
            { preserveScroll: true }
        );
    };

    const [addTab, setAddTab] = useState<AddTab>('link');

    return (
        <AppLayout>
            <Head title={bookmark.name} />

            <div className="min-h-screen bg-background">
                {/* Top bar: back + title + visibility */}
                <header className="sticky top-0 z-10 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60">
                    <div className="w-full px-4 sm:px-6 lg:px-8">
                        <div className="flex h-14 sm:h-16 items-center gap-3 sm:gap-4">
                            <Link
                                href={route('bookmarks.index', [workspace.slug])}
                                className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground transition-colors -ml-1 rounded-md px-2 py-1.5"
                            >
                                <ChevronLeft className="size-4 shrink-0" />
                                <span className="hidden sm:inline">Back</span>
                            </Link>
                            <span className="text-2xl sm:text-3xl shrink-0 flex items-center justify-center size-9 overflow-hidden rounded" aria-hidden>
                                {bookmark.icon_display && (bookmark.icon_display.startsWith('http') || bookmark.icon_display.startsWith('/')) ? (
                                    <img src={bookmark.icon_display} alt="" className="size-9 object-contain" />
                                ) : (
                                    bookmark.icon_display || bookmark.icon || '📑'
                                )}
                            </span>
                            <div className="min-w-0 flex-1">
                                {canEdit && editingTitle ? (
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <Input
                                            value={editName}
                                            onChange={(e) => setEditName(e.target.value)}
                                            className="h-8 text-base font-semibold border-0 px-0 shadow-none focus-visible:ring-0 rounded-none border-b flex-1 min-w-[120px]"
                                            placeholder="Title"
                                            autoFocus
                                        />
                                        <div className="flex gap-1">
                                            <Button size="sm" className="h-7 text-xs" onClick={() => { router.put(route('bookmarks.update', [workspace.slug, bookmark.slug]), { name: editName, description: bookmark.description ?? '' }, { preserveScroll: true }); setEditingTitle(false); }}>Save</Button>
                                            <Button size="sm" variant="ghost" className="h-7 text-xs" onClick={() => { setEditName(bookmark.name); setEditingTitle(false); }}>Cancel</Button>
                                        </div>
                                    </div>
                                ) : (
                                    <>
                                        <h1 className="text-lg sm:text-xl font-semibold tracking-tight truncate">
                                            {bookmark.name}
                                        </h1>
                                        {canEdit && (
                                            <button type="button" onClick={() => { setEditName(bookmark.name); setEditingTitle(true); }} className="text-xs text-muted-foreground hover:text-foreground mt-0.5">
                                                Edit title
                                            </button>
                                        )}
                                    </>
                                )}
                            </div>
                            <div className="flex items-center gap-2 shrink-0">
                                {canEdit && bookmark.visibility === 'public' && bookmark.uuid && (
                                    <Button size="sm" variant="ghost" className="hidden sm:flex gap-1.5" onClick={() => { const path = route('bookmarks.public.show', [bookmark.uuid]); const u = path.startsWith('http') ? path : `${window.location.origin}${path.startsWith('/') ? '' : '/'}${path}`; navigator.clipboard.writeText(u); }}>
                                        <Copy className="size-4" /> Copy link
                                    </Button>
                                )}
                                {canEdit ? (
                                    <Select value={bookmark.visibility} onValueChange={updateVisibility}>
                                        <SelectTrigger className="w-[120px] sm:w-[140px] h-9">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(['private', 'public'] as const).map((v) => {
                                                const Icon = visibilityConfig[v].icon;
                                                return (
                                                    <SelectItem key={v} value={v}>
                                                        <span className="flex items-center gap-2">
                                                            <Icon className="size-4" />
                                                            {visibilityConfig[v].label}
                                                        </span>
                                                    </SelectItem>
                                                );
                                            })}
                                        </SelectContent>
                                    </Select>
                                ) : (
                                    <Badge variant={bookmark.visibility === 'public' ? 'default' : 'secondary'}>
                                        {bookmark.visibility === 'public' ? 'Shared' : 'Private'}
                                    </Badge>
                                )}
                            </div>
                        </div>
                    </div>
                </header>

                <div className="w-full px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
                    <div className={`flex flex-col gap-6 lg:gap-8 ${canEdit ? 'lg:flex-row' : ''}`}>
                        {/* Left: Add form + Collaborators (sticky on lg, only when can edit) */}
                        {canEdit && (
                        <aside className="lg:w-72 xl:w-80 shrink-0 lg:sticky lg:top-[4.5rem] lg:self-start space-y-4">
                            {canEdit && (
                                <div className="rounded-2xl border bg-card shadow-sm overflow-hidden">
                                    <div className="px-4 py-3 border-b bg-muted/40">
                                        <h2 className="text-sm font-medium">Add to bookmark</h2>
                                        <p className="text-xs text-muted-foreground mt-0.5">Ctrl+V to paste</p>
                                    </div>
                                    <div className="p-3 space-y-3">
                                        <div className="flex rounded-lg bg-muted/50 p-0.5">
                                            {(['link', 'file', 'note', 'todo'] as const).map((tab) => (
                                                <button
                                                    key={tab}
                                                    type="button"
                                                    onClick={() => setAddTab(tab)}
                                                    className={`flex-1 flex items-center justify-center gap-1.5 py-2 text-xs font-medium rounded-md transition-colors ${addTab === tab ? 'bg-background shadow text-foreground' : 'text-muted-foreground hover:text-foreground'}`}
                                                >
                                                    {tab === 'link' && <Link2 className="size-3.5" />}
                                                    {tab === 'file' && <FileText className="size-3.5" />}
                                                    {tab === 'note' && <StickyNote className="size-3.5" />}
                                                    {tab === 'todo' && <ListTodo className="size-3.5" />}
                                                    <span className="capitalize">{tab === 'todo' ? 'To-do' : tab}</span>
                                                </button>
                                            ))}
                                        </div>
                                        {addTab === 'link' && (
                                            <form onSubmit={submitUrl} className="flex gap-2">
                                                <Input placeholder="Paste URL…" value={pasteUrl} onChange={(e) => setPasteUrl(e.target.value)} className="h-9 flex-1 text-sm" disabled={!!formLoading} />
                                                <Button type="submit" size="sm" className="h-9 shrink-0 gap-1.5" disabled={!pasteUrl.trim() || !!formLoading}>
                                                    {formLoading === 'link' ? <Loader2 className="size-4 animate-spin" /> : null}
                                                    Add
                                                </Button>
                                            </form>
                                        )}
                                        {addTab === 'file' && (
                                            <div className="space-y-2">
                                                <p className="text-xs text-muted-foreground">Max 200MB</p>
                                                <Button type="button" variant="outline" size="sm" className="w-full h-9 gap-2" disabled={!!formLoading} onClick={() => fileInputRef.current?.click()}>
                                                    {formLoading === 'file' ? <Loader2 className="size-4 animate-spin" /> : <FileText className="size-4" />}
                                                    {formLoading === 'file' ? 'Uploading…' : 'Choose file'}
                                                </Button>
                                                <input ref={fileInputRef} type="file" className="hidden" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z,.rtf,.md,.json,.xml,.jpg,.jpeg,.png,.gif,.webp,.bmp,.ico,.tiff,.tif,.svg,.heic,.heif,.avif" onChange={uploadFile} />
                                            </div>
                                        )}
                                        {addTab === 'note' && (
                                            <form onSubmit={submitNote} className="space-y-2">
                                                <Textarea placeholder="Note…" value={newNote} onChange={(e) => setNewNote(e.target.value)} className="min-h-[80px] text-sm resize-none" rows={3} disabled={!!formLoading} />
                                                <Button type="submit" size="sm" className="w-full h-9 gap-1.5" disabled={!newNote.trim() || !!formLoading}>
                                                    {formLoading === 'note' ? <Loader2 className="size-4 animate-spin" /> : null}
                                                    Add note
                                                </Button>
                                            </form>
                                        )}
                                        {addTab === 'todo' && (
                                            <form onSubmit={submitTodo} className="flex gap-2">
                                                <Input placeholder="To-do…" value={newTodo} onChange={(e) => setNewTodo(e.target.value)} className="h-9 flex-1 text-sm" disabled={!!formLoading} />
                                                <Button type="submit" size="sm" className="h-9 shrink-0 gap-1.5" disabled={!newTodo.trim() || !!formLoading}>
                                                    {formLoading === 'todo' ? <Loader2 className="size-4 animate-spin" /> : null}
                                                    Add
                                                </Button>
                                            </form>
                                        )}
                                    </div>
                                </div>
                            )}

                            {canEdit && (bookmark.creator || collaborators.length > 0 || availableToInvite.length > 0) && (
                                <div className="rounded-2xl border bg-card shadow-sm overflow-hidden">
                                    <div className="px-4 py-3 border-b bg-muted/40">
                                        <h2 className="text-sm font-medium">Collaborators</h2>
                                    </div>
                                    <div className="p-3 space-y-3">
                                        {bookmark.creator && (
                                            <div className="flex items-center justify-between text-sm">
                                                <span className="font-medium truncate">{bookmark.creator.name}</span>
                                                <Badge variant="secondary" className="text-xs">Owner</Badge>
                                            </div>
                                        )}
                                        {collaborators.map((c) => (
                                            <div key={c.id} className="flex items-center justify-between gap-2 py-1">
                                                <span className="text-sm truncate">{c.name}</span>
                                                <div className="flex items-center gap-1 shrink-0">
                                                    <Select value={(c as { pivot?: { role: string } }).pivot?.role ?? 'viewer'} onValueChange={(role) => updateCollaboratorRole(c.id, role as 'viewer' | 'editor')}>
                                                        <SelectTrigger className="h-7 w-[82px] text-xs"><SelectValue /></SelectTrigger>
                                                        <SelectContent><SelectItem value="viewer">Viewer</SelectItem><SelectItem value="editor">Editor</SelectItem></SelectContent>
                                                    </Select>
                                                    <Button variant="ghost" size="icon" className="size-7" onClick={() => openRemoveCollaboratorConfirm(c.id, c.name)}><Trash2 className="size-3.5" /></Button>
                                                </div>
                                            </div>
                                        ))}
                                        {availableToInvite.length > 0 && (
                                            <form onSubmit={inviteCollaborator} className="flex flex-wrap gap-2 pt-1 border-t">
                                                <Select value={inviteUserId} onValueChange={setInviteUserId} required>
                                                    <SelectTrigger className="h-8 flex-1 min-w-0 text-xs"><SelectValue placeholder="Invite…" /></SelectTrigger>
                                                    <SelectContent>{availableToInvite.map((m) => <SelectItem key={m.id} value={String(m.id)}>{m.name}</SelectItem>)}</SelectContent>
                                                </Select>
                                                <Select value={inviteRole} onValueChange={(v) => setInviteRole(v as 'viewer' | 'editor')}>
                                                    <SelectTrigger className="h-8 w-20 text-xs"><SelectValue /></SelectTrigger>
                                                    <SelectContent><SelectItem value="viewer">Viewer</SelectItem><SelectItem value="editor">Editor</SelectItem></SelectContent>
                                                </Select>
                                                <Button type="submit" size="sm" className="h-8 text-xs">Invite</Button>
                                            </form>
                                        )}
                                    </div>
                                </div>
                            )}

                            {/* Description card */}
                            <div className="rounded-2xl border bg-card shadow-sm overflow-hidden">
                                <div className="px-4 py-3 border-b bg-muted/40 flex items-center justify-between">
                                    <h2 className="text-sm font-medium">Description</h2>
                                    {canEdit && !editingDescription && (
                                        <button type="button" onClick={() => setEditingDescription(true)} className="text-xs text-muted-foreground hover:text-foreground">
                                            Edit
                                        </button>
                                    )}
                                </div>
                                <div className="p-3">
                                    {editingDescription ? (
                                        <div className="space-y-2">
                                            <Textarea
                                                value={editDescription}
                                                onChange={(e) => setEditDescription(e.target.value)}
                                                placeholder="Add a description…"
                                                className="min-h-[80px] text-sm resize-none"
                                                rows={3}
                                            />
                                            <div className="flex gap-2">
                                                <Button size="sm" className="h-8 text-xs" onClick={() => { router.put(route('bookmarks.update', [workspace.slug, bookmark.slug]), { name: bookmark.name, description: editDescription }, { preserveScroll: true, onSuccess: () => setEditingDescription(false) }); }}>
                                                    Save
                                                </Button>
                                                <Button size="sm" variant="ghost" className="h-8 text-xs" onClick={() => { setEditDescription(bookmark.description ?? ''); setEditingDescription(false); }}>
                                                    Cancel
                                                </Button>
                                            </div>
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground whitespace-pre-wrap min-h-[2rem]">
                                            {bookmark.description || (canEdit ? 'Add a description…' : '—')}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </aside>
                        )}

                        {/* Right: Content sections — full width 2x2 grid */}
                        <main className="flex-1 min-w-0">
                        {!canEdit && bookmark.description && (
                            <section className="mb-6">
                                <h2 className="text-xs font-medium uppercase tracking-wider text-muted-foreground mb-3">Description</h2>
                                <div className="rounded-xl border bg-card px-4 py-3 shadow-sm">
                                    <p className="text-sm text-muted-foreground whitespace-pre-wrap">{bookmark.description}</p>
                                </div>
                            </section>
                        )}
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 lg:gap-6">
                            {/* Links */}
                            <section className="rounded-xl border bg-card overflow-hidden shadow-sm flex flex-col min-h-0">
                                <div className="px-4 py-3 border-b bg-muted/30 flex items-center gap-2 shrink-0">
                                    <Link2 className="size-4 text-muted-foreground" />
                                    <span className="text-sm font-medium">Links</span>
                                    <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium tabular-nums ml-auto">{links.length}</span>
                                </div>
                                <div className="flex-1 min-h-0 overflow-auto">
                                    {links.length > 0 ? (
                                        <ul className="divide-y divide-border/50">
                                            {links.map((item) => (
                                                <li key={item.id} className="group flex items-start gap-3 px-4 py-3 hover:bg-muted/30 transition-colors">
                                                    <a href={item.url || '#'} target="_blank" rel="noopener noreferrer" className="flex-1 min-w-0 flex items-start gap-3 text-primary hover:underline">
                                                        {item.metadata?.favicon_url ? (
                                                            <img src={item.metadata.favicon_url} alt="" className="size-5 shrink-0 rounded mt-0.5" />
                                                        ) : (
                                                            <ExternalLink className="size-5 shrink-0 text-muted-foreground mt-0.5" />
                                                        )}
                                                        <span className="min-w-0">
                                                            <span className="block font-medium truncate text-sm">{item.title || item.url || 'Link'}</span>
                                                            {item.metadata?.description && (
                                                                <span className="block text-xs text-muted-foreground line-clamp-2 mt-0.5">{item.metadata.description}</span>
                                                            )}
                                                        </span>
                                                    </a>
                                                    {canEdit && (
                                                        <Button variant="ghost" size="icon" className="size-8 shrink-0 opacity-0 group-hover:opacity-100" onClick={() => deleteItem(item)}>
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="px-4 py-6 text-sm text-muted-foreground text-center">No links yet</p>
                                    )}
                                </div>
                            </section>

                            {/* Files */}
                            <section className="rounded-xl border bg-card overflow-hidden shadow-sm flex flex-col min-h-0">
                                <div className="px-4 py-3 border-b bg-muted/30 flex items-center gap-2 shrink-0">
                                    <FileText className="size-4 text-muted-foreground" />
                                    <span className="text-sm font-medium">Files</span>
                                    <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium tabular-nums ml-auto">{files.length}</span>
                                </div>
                                <div className="flex-1 min-h-0 overflow-auto">
                                    {files.length > 0 ? (
                                        <ul className="divide-y divide-border/50">
                                            {files.map((item) => {
                                                const { Icon, className } = getFileIcon(item.title || '');
                                                const previewType = getFilePreviewType(item.title || '');
                                                const canPreview = previewType !== 'other';
                                                const fileUrl = item.file_url || '#';
                                                return (
                                                <li key={item.id} className="group flex items-center gap-2 px-4 py-2.5 hover:bg-muted/30 transition-colors">
                                                    <Icon className={`size-4 shrink-0 ${className}`} />
                                                    <a href={fileUrl} target="_blank" rel="noopener noreferrer" className="flex-1 min-w-0 truncate text-sm text-primary hover:underline">
                                                        {item.title || 'File'}
                                                    </a>
                                                    <div className="flex items-center shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                                        {canPreview && (
                                                            <Button variant="ghost" size="icon" className="size-8" onClick={() => setFilePreview({ url: fileUrl, title: item.title || 'File', previewType })} title="Preview">
                                                                <Eye className="size-4" />
                                                            </Button>
                                                        )}
                                                        <a href={fileUrl} download={item.title || undefined} target="_blank" rel="noopener noreferrer" className="inline-flex items-center justify-center size-8 rounded-md hover:bg-muted" title="Download">
                                                            <Download className="size-4" />
                                                        </a>
                                                        {canEdit && (
                                                            <Button variant="ghost" size="icon" className="size-8" onClick={() => deleteItem(item)}>
                                                                <Trash2 className="size-4" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                </li>
                                            );
                                            })}
                                        </ul>
                                    ) : (
                                        <p className="px-4 py-6 text-sm text-muted-foreground text-center">No files yet</p>
                                    )}
                                </div>
                            </section>

                            {/* To-dos */}
                            <section className="rounded-xl border bg-card overflow-hidden shadow-sm flex flex-col min-h-0">
                                <div className="px-4 py-3 border-b bg-muted/30 flex items-center gap-2 shrink-0">
                                    <ListTodo className="size-4 text-muted-foreground" />
                                    <span className="text-sm font-medium">To-dos</span>
                                    <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium tabular-nums ml-auto">{todos.filter((t) => t.is_completed).length}/{todos.length}</span>
                                </div>
                                {canEdit && (
                                    <form onSubmit={submitTodo} className="p-3 border-b border-border/50 flex gap-2 shrink-0">
                                        <Input
                                            placeholder="Add a to-do…"
                                            value={newTodo}
                                            onChange={(e) => setNewTodo(e.target.value)}
                                            className="h-9 text-sm flex-1"
                                            disabled={!!formLoading}
                                        />
                                        <Button type="submit" size="sm" className="h-9 shrink-0" disabled={!newTodo.trim() || !!formLoading}>
                                            {formLoading === 'todo' ? <Loader2 className="size-4 animate-spin" /> : 'Add'}
                                        </Button>
                                    </form>
                                )}
                                <div className="flex-1 min-h-0 overflow-auto">
                                    {todos.length > 0 ? (
                                        <ul className="divide-y divide-border/50">
                                            {todos.map((item) => (
                                                <li key={item.id} className="group flex items-center gap-3 px-4 py-2.5 hover:bg-muted/30 transition-colors">
                                                    <button
                                                        type="button"
                                                        onClick={() => canEdit && toggleTodo(item)}
                                                        disabled={!canEdit}
                                                        className="size-5 rounded border shrink-0 flex items-center justify-center hover:bg-muted transition-colors disabled:opacity-50"
                                                    >
                                                        {item.is_completed ? <Check className="size-3 text-primary" /> : null}
                                                    </button>
                                                    {editingTodo?.id === item.id ? (
                                                        <Input
                                                            ref={todoInputRef}
                                                            value={editTodoTitle}
                                                            onChange={(e) => setEditTodoTitle(e.target.value)}
                                                            onBlur={saveTodoEdit}
                                                            onKeyDown={(e) => {
                                                                if (e.key === 'Enter') { e.preventDefault(); saveTodoEdit(); }
                                                                if (e.key === 'Escape') { setEditingTodo(null); setEditTodoTitle(item.title ?? ''); (e.target as HTMLInputElement).blur(); }
                                                            }}
                                                            className="flex-1 h-8 text-sm"
                                                            placeholder="To-do title"
                                                        />
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            onClick={() => canEdit && openTodoEdit(item)}
                                                            className={`flex-1 min-w-0 text-left truncate text-sm ${item.is_completed ? 'text-muted-foreground line-through' : ''} ${canEdit ? 'cursor-text' : 'cursor-default'}`}
                                                        >
                                                            {item.title || 'To-do'}
                                                        </button>
                                                    )}
                                                    {canEdit && editingTodo?.id !== item.id && (
                                                        <div className="flex items-center shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                                            <Button variant="ghost" size="icon" className="size-8" onClick={() => openTodoEdit(item)} title="Edit">
                                                                <Pencil className="size-4" />
                                                            </Button>
                                                            <Button variant="ghost" size="icon" className="size-8" onClick={() => deleteItem(item)}>
                                                                <Trash2 className="size-4" />
                                                            </Button>
                                                        </div>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="px-4 py-6 text-sm text-muted-foreground text-center">No to-dos yet</p>
                                    )}
                                </div>
                            </section>

                            {/* Notes */}
                            <section className="rounded-xl border bg-card overflow-hidden shadow-sm flex flex-col min-h-0">
                                <div className="px-4 py-3 border-b bg-muted/30 flex items-center gap-2 shrink-0">
                                    <StickyNote className="size-4 text-muted-foreground" />
                                    <span className="text-sm font-medium">Notes</span>
                                    <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium tabular-nums ml-auto">{notes.length}</span>
                                </div>
                                {canEdit && (
                                    <form onSubmit={submitNote} className="p-3 border-b border-border/50 flex gap-2 shrink-0">
                                        <Input
                                            placeholder="Add a note…"
                                            value={newNote}
                                            onChange={(e) => setNewNote(e.target.value)}
                                            className="h-9 text-sm flex-1"
                                            disabled={!!formLoading}
                                        />
                                        <Button type="submit" size="sm" className="h-9 shrink-0" disabled={!newNote.trim() || !!formLoading}>
                                            {formLoading === 'note' ? <Loader2 className="size-4 animate-spin" /> : 'Add'}
                                        </Button>
                                    </form>
                                )}
                                <div className="flex-1 min-h-0 overflow-auto">
                                    {notes.length > 0 ? (
                                        <ul className="divide-y divide-border/50">
                                            {notes.map((item) => (
                                                <li key={item.id} className="group flex items-start gap-3 px-4 py-3 hover:bg-muted/30 transition-colors">
                                                    <p className="flex-1 min-w-0 text-sm whitespace-pre-wrap">{item.content || item.title || 'Note'}</p>
                                                    {canEdit && (
                                                        <div className="flex items-center shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                                            <Button variant="ghost" size="icon" className="size-8" onClick={() => openNoteEditor(item)} title="Edit note">
                                                                <Pencil className="size-4" />
                                                            </Button>
                                                            <Button variant="ghost" size="icon" className="size-8" onClick={() => deleteItem(item)}>
                                                                <Trash2 className="size-4" />
                                                            </Button>
                                                        </div>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="px-4 py-6 text-sm text-muted-foreground text-center">No notes yet</p>
                                    )}
                                </div>
                            </section>
                        </div>
                        </main>
                    </div>
                </div>
            </div>

            {/* Delete confirmation modal */}
            <AlertDialog open={!!deleteConfirm} onOpenChange={(open) => !open && setDeleteConfirm(null)}>
                <AlertDialogContent className="sm:max-w-md rounded-2xl border shadow-xl">
                    <AlertDialogHeader className="sm:text-center space-y-4">
                        <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-destructive/10">
                            <AlertTriangle className="size-6 text-destructive" />
                        </div>
                        <div className="space-y-2">
                            <AlertDialogTitle className="text-xl">
                                {deleteConfirm ? getDeleteModalContent().title : ''}
                            </AlertDialogTitle>
                            <AlertDialogDescription className="text-muted-foreground">
                                {deleteConfirm ? getDeleteModalContent().description : ''}
                            </AlertDialogDescription>
                        </div>
                    </AlertDialogHeader>
                    <AlertDialogFooter className="flex flex-col-reverse sm:flex-row gap-2 sm:gap-2 sm:justify-end mt-6">
                        <AlertDialogCancel className="w-full sm:w-auto">Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleConfirmDelete}
                            className="w-full sm:w-auto bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        >
                            {deleteConfirm ? getDeleteModalContent().label : 'Delete'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* File preview modal */}
            <Dialog open={!!filePreview} onOpenChange={(open) => !open && setFilePreview(null)}>
                <DialogContent className="max-w-4xl max-h-[90vh] flex flex-col p-0 overflow-hidden">
                    {filePreview && (
                        <>
                            <DialogHeader className="px-6 py-3 border-b shrink-0">
                                <DialogTitle className="truncate pr-8">{filePreview.title}</DialogTitle>
                            </DialogHeader>
                            <div className="flex-1 min-h-0 overflow-auto p-6 bg-muted/30">
                                {filePreview.previewType === 'pdf' && (
                                    <iframe src={filePreview.url} title={filePreview.title} className="w-full h-[70vh] rounded border bg-background" />
                                )}
                                {filePreview.previewType === 'image' && (
                                    <img src={filePreview.url} alt={filePreview.title} className="max-w-full max-h-[70vh] object-contain mx-auto rounded" />
                                )}
                                {filePreview.previewType === 'video' && (
                                    <video src={filePreview.url} controls className="w-full max-h-[70vh] rounded" title={filePreview.title} />
                                )}
                                {filePreview.previewType === 'other' && (
                                    <div className="text-center py-8 text-muted-foreground">
                                        <p className="mb-4">Preview not available for this file type.</p>
                                        <a href={filePreview.url} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">Open in new tab</a>
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </DialogContent>
            </Dialog>

            {/* Edit note dialog */}
            <Dialog open={!!editingNote} onOpenChange={(open) => !open && setEditingNote(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Edit note</DialogTitle>
                        <DialogDescription>
                            Change the note content. You can use multiple lines.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 mt-4">
                        <div className="space-y-2">
                            <Label htmlFor="edit-note-title">Title (optional)</Label>
                            <Input
                                id="edit-note-title"
                                value={editNoteTitle}
                                onChange={(e) => setEditNoteTitle(e.target.value)}
                                placeholder="Short title for the note"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-note-content">Content</Label>
                            <Textarea
                                id="edit-note-content"
                                value={editNoteContent}
                                onChange={(e) => setEditNoteContent(e.target.value)}
                                placeholder="Note content…"
                                className="min-h-[200px] font-mono text-sm"
                            />
                        </div>
                        <div className="flex justify-end gap-2 pt-2">
                            <Button variant="outline" onClick={() => setEditingNote(null)}>
                                Cancel
                            </Button>
                            <Button onClick={saveNoteEdit}>
                                Save
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
