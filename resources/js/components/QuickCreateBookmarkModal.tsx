/**
 * QuickCreateBookmarkModal
 *
 * Opened from the Command Palette. Lets the user pick a bookmark
 * collection and add a link, todo, note, or file to it without leaving the page.
 */

import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Bookmark as BookmarkIcon, Loader2, ExternalLink, Link2, ListTodo, StickyNote, Paperclip, Upload, X } from 'lucide-react';
import { toast } from 'sonner';
import { Bookmark } from '@/types';

type ItemType = 'link' | 'todo' | 'note' | 'file';

interface Props {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    workspaceSlug: string;
}

interface FormState {
    bookmarkSlug: string;
    type: ItemType;
    url: string;
    title: string;
    content: string;
}

function BookmarkCollectionIcon({ icon }: { icon?: string | null }) {
    const src = icon ?? '🔖';
    if (src.startsWith('http') || src.startsWith('/')) {
        return <img src={src} alt="" className="size-5 rounded object-contain shrink-0" />;
    }
    return <span className="text-base leading-none shrink-0">{src}</span>;
}

const TYPE_OPTIONS: { value: ItemType; label: string; icon: React.ReactNode }[] = [
    { value: 'link',  label: 'Link',   icon: <Link2 className="size-4" /> },
    { value: 'file',  label: 'File',   icon: <Paperclip className="size-4" /> },
    { value: 'todo',  label: 'To-do',  icon: <ListTodo className="size-4" /> },
    { value: 'note',  label: 'Note',   icon: <StickyNote className="size-4" /> },
];

export function QuickCreateBookmarkModal({ open, onOpenChange, workspaceSlug }: Props) {
    const [bookmarks, setBookmarks] = useState<Bookmark[]>([]);
    const [loading, setLoading] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [isDragging, setIsDragging] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const [form, setForm] = useState<FormState>({
        bookmarkSlug: '',
        type: 'link',
        url: '',
        title: '',
        content: '',
    });

    const set = <K extends keyof FormState>(key: K, value: FormState[K]) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    // Fetch bookmarks when dialog opens
    useEffect(() => {
        if (!open) return;
        setLoading(true);
        setSelectedFile(null);
        setForm({ bookmarkSlug: '', type: 'link', url: '', title: '', content: '' });
        axios
            .get(route('bookmarks.list', workspaceSlug))
            .then((res) => {
                setBookmarks(res.data);
                if (res.data.length === 1) set('bookmarkSlug', res.data[0].slug);
            })
            .catch(() => toast.error('Could not load bookmark collections'))
            .finally(() => setLoading(false));
    }, [open]);

    const selectedBookmark = bookmarks.find((b) => b.slug === form.bookmarkSlug) ?? null;

    const isValid = () => {
        if (!form.bookmarkSlug) return false;
        if (form.type === 'link') return form.url.trim().length > 0;
        if (form.type === 'file') return selectedFile !== null;
        if (form.type === 'todo') return form.title.trim().length > 0 || form.content.trim().length > 0;
        if (form.type === 'note') return form.content.trim().length > 0;
        return false;
    };

    const handleFileSelect = (file: File) => {
        setSelectedFile(file);
        if (!form.title) set('title', file.name);
    };

    const handleDrop = (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(false);
        const file = e.dataTransfer.files[0];
        if (file) handleFileSelect(file);
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!isValid()) return;
        setSubmitting(true);
        try {
            const formData = new FormData();
            formData.append('type', form.type);
            if (form.title) formData.append('title', form.title);

            if (form.type === 'link') {
                formData.append('url', form.url);
            } else if (form.type === 'file' && selectedFile) {
                formData.append('file', selectedFile);
            } else {
                if (form.content) formData.append('content', form.content);
            }

            await axios.post(
                route('bookmarks.items.store', [workspaceSlug, form.bookmarkSlug]),
                formData,
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );
            onOpenChange(false);
            toast.success('Bookmark item added!', {
                action: {
                    label: 'View collection',
                    onClick: () => router.visit(route('bookmarks.show', [workspaceSlug, form.bookmarkSlug])),
                },
            });
        } catch {
            toast.error('Failed to create bookmark item — please try again');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 items-center justify-center rounded-xl bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 shrink-0">
                            <BookmarkIcon className="size-5" />
                        </div>
                        <div>
                            <DialogTitle className="text-lg">New Bookmark</DialogTitle>
                            <DialogDescription className="text-sm">
                                Add a link, file, to-do, or note to a bookmark collection
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                {loading ? (
                    <div className="flex items-center justify-center py-12 text-muted-foreground">
                        <Loader2 className="size-5 animate-spin mr-2" />
                        Loading collections…
                    </div>
                ) : bookmarks.length === 0 ? (
                    <div className="py-10 text-center space-y-3">
                        <p className="text-sm text-muted-foreground">You don't have any bookmark collections yet.</p>
                        <Button variant="outline" size="sm" onClick={() => {
                            onOpenChange(false);
                            router.visit(route('bookmarks.index', workspaceSlug) + '?create=true');
                        }}>
                            <ExternalLink className="size-4 mr-1.5" />
                            Create a collection first
                        </Button>
                    </div>
                ) : (
                    <form onSubmit={handleSubmit} className="space-y-4 mt-1">
                        {/* Collection */}
                        <div>
                            <Label>Collection *</Label>
                            <Select
                                value={form.bookmarkSlug}
                                onValueChange={(v) => set('bookmarkSlug', v)}
                            >
                                <SelectTrigger className="mt-1">
                                    <SelectValue placeholder="Pick a collection…">
                                        {selectedBookmark && (
                                            <span className="flex items-center gap-2">
                                                <BookmarkCollectionIcon icon={selectedBookmark.icon} />
                                                {selectedBookmark.name}
                                            </span>
                                        )}
                                    </SelectValue>
                                </SelectTrigger>
                                <SelectContent>
                                    {bookmarks.map((b) => (
                                        <SelectItem key={b.slug} value={b.slug}>
                                            <span className="flex items-center gap-2">
                                                <BookmarkCollectionIcon icon={b.icon} />
                                                {b.name}
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Type */}
                        <div>
                            <Label>Type *</Label>
                            <div className="mt-1 flex gap-2 flex-wrap">
                                {TYPE_OPTIONS.map((opt) => (
                                    <button
                                        key={opt.value}
                                        type="button"
                                        onClick={() => { set('type', opt.value); setSelectedFile(null); }}
                                        className={`flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium transition-colors ${
                                            form.type === opt.value
                                                ? 'border-primary bg-primary/10 text-primary'
                                                : 'border-border text-muted-foreground hover:border-primary/50 hover:text-foreground'
                                        }`}
                                    >
                                        {opt.icon}
                                        {opt.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* URL (link only) */}
                        {form.type === 'link' && (
                            <div>
                                <Label>URL *</Label>
                                <Input
                                    type="url"
                                    value={form.url}
                                    onChange={(e) => set('url', e.target.value)}
                                    placeholder="https://example.com"
                                    className="mt-1"
                                    autoFocus
                                />
                            </div>
                        )}

                        {/* File upload */}
                        {form.type === 'file' && (
                            <div>
                                <Label>File *</Label>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    className="hidden"
                                    onChange={(e) => {
                                        const f = e.target.files?.[0];
                                        if (f) handleFileSelect(f);
                                    }}
                                />
                                {selectedFile ? (
                                    <div className="mt-1 flex items-center gap-3 rounded-lg border border-border bg-muted/40 px-4 py-3">
                                        <Paperclip className="size-4 shrink-0 text-muted-foreground" />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">{selectedFile.name}</p>
                                            <p className="text-xs text-muted-foreground">
                                                {(selectedFile.size / 1024).toFixed(0)} KB
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => { setSelectedFile(null); if (fileInputRef.current) fileInputRef.current.value = ''; }}
                                            className="shrink-0 rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
                                        >
                                            <X className="size-4" />
                                        </button>
                                    </div>
                                ) : (
                                    <div
                                        className={`mt-1 flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed px-6 py-8 text-center transition-colors ${
                                            isDragging
                                                ? 'border-primary bg-primary/5 text-primary'
                                                : 'border-border text-muted-foreground hover:border-primary/50 hover:text-foreground'
                                        }`}
                                        onClick={() => fileInputRef.current?.click()}
                                        onDragOver={(e) => { e.preventDefault(); setIsDragging(true); }}
                                        onDragLeave={() => setIsDragging(false)}
                                        onDrop={handleDrop}
                                    >
                                        <Upload className="size-6" />
                                        <div>
                                            <p className="text-sm font-medium">Click to upload or drag & drop</p>
                                            <p className="text-xs mt-0.5">PDF, images, documents, archives… up to 200 MB</p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Title */}
                        <div>
                            <Label>
                                Title{' '}
                                {form.type !== 'link' && <span className="text-muted-foreground font-normal">(optional)</span>}
                            </Label>
                            <Input
                                value={form.title}
                                onChange={(e) => set('title', e.target.value)}
                                placeholder={
                                    form.type === 'link' ? 'Auto-fetched if left blank'
                                    : form.type === 'file' ? 'Defaults to filename'
                                    : form.type === 'todo' ? 'Task title…'
                                    : 'Note title…'
                                }
                                className="mt-1"
                                autoFocus={form.type === 'todo' || form.type === 'note'}
                            />
                        </div>

                        {/* Content (todo / note) */}
                        {(form.type === 'todo' || form.type === 'note') && (
                            <div>
                                <Label>
                                    {form.type === 'todo' ? 'Notes' : 'Content'}{' '}
                                    <span className="text-muted-foreground font-normal">(optional)</span>
                                </Label>
                                <Textarea
                                    value={form.content}
                                    onChange={(e) => set('content', e.target.value)}
                                    placeholder={form.type === 'todo' ? 'Additional notes…' : 'Note content…'}
                                    rows={4}
                                    className="mt-1 resize-y text-sm"
                                />
                            </div>
                        )}

                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={submitting || !isValid()}>
                                {submitting && <Loader2 className="size-4 mr-2 animate-spin" />}
                                Add Bookmark
                            </Button>
                        </div>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
