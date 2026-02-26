import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { PageProps, Workspace, SnippetCollection } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription,
} from '@/components/ui/dialog';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
    AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Code2, Globe, Lock, MoreVertical, Plus, ArrowRight, Pencil, Trash2 } from 'lucide-react';
import { useState, useCallback, useEffect } from 'react';
import { toast } from 'sonner';
import { IconPicker } from '@/components/IconPicker';

const SNIPPET_EMOJIS = [
    '📋', '🗂️', '💾', '🧩', '⚙️', '🛠️', '🔧', '📦', '🚀', '⭐',
    '🔑', '🔐', '🧪', '🖥️', '📡', '🧬', '🎛️', '💻', '🔬', '🗃️',
];

function CollectionIcon({ icon, size = 'md' }: { icon?: string | null; size?: 'sm' | 'md' | 'lg' }) {
    const src = icon ?? '📋';
    const isUrl = src.startsWith('http') || src.startsWith('/');
    if (isUrl) {
        const cls = size === 'lg' ? 'size-9' : size === 'sm' ? 'size-5' : 'size-7';
        return <img src={src} alt="" className={`${cls} rounded object-contain shrink-0`} />;
    }
    const textCls = size === 'lg' ? 'text-3xl' : size === 'sm' ? 'text-base' : 'text-2xl';
    return <span className={`${textCls} leading-none shrink-0`}>{src}</span>;
}

interface Props extends PageProps {
    workspace: Workspace;
    collections: (SnippetCollection & { snippets_count: number })[];
}

export default function SnippetsIndex({ workspace, collections }: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editCollection, setEditCollection] = useState<SnippetCollection | null>(null);
    const [deleteTarget, setDeleteTarget] = useState<SnippetCollection | null>(null);

    // Auto-open create dialog when navigated here with ?create=true
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('create') === 'true') {
            setCreateOpen(true);
            // Clean up the URL without triggering a navigation
            window.history.replaceState({}, '', window.location.pathname);
        }
    }, []);

    const { data, setData, post, processing, reset, errors } = useForm({
        name: '', description: '', icon: '📋', visibility: 'private' as 'private' | 'public',
    });

    const editForm = useForm({
        name: '', description: '', icon: '📋', visibility: 'private' as 'private' | 'public',
    });

    const handleIconUpload = useCallback(async (file: File): Promise<string> => {
        const formData = new FormData();
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        if (token) formData.append('_token', token);
        formData.append('icon', file);
        const res = await fetch(route('snippets.icon.upload-temp', workspace.slug), {
            method: 'POST',
            body: formData,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
            toast.error(json.message ?? 'Icon upload failed');
            throw new Error(json.message ?? 'Upload failed');
        }
        return json.icon_display;
    }, [workspace.slug]);

    const createCollection = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('snippets.store', workspace.slug), {
            onSuccess: () => { setCreateOpen(false); reset(); },
        });
    };

    const openEdit = (c: SnippetCollection) => {
        setEditCollection(c);
        editForm.setData({
            name: c.name,
            description: c.description ?? '',
            icon: c.icon ?? '📋',
            visibility: c.visibility,
        });
    };

    const saveEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editCollection) return;
        editForm.put(route('snippets.update', [workspace.slug, editCollection.slug]), {
            onSuccess: () => setEditCollection(null),
        });
    };

    const confirmDelete = () => {
        if (!deleteTarget) return;
        router.delete(route('snippets.destroy', [workspace.slug, deleteTarget.slug]), {
            onSuccess: () => { setDeleteTarget(null); toast.success('Collection deleted'); },
        });
    };

    return (
        <AppLayout title="Snippets">
            <Head title={`Snippets · ${workspace.name}`} />
            <div className="max-w-5xl mx-auto px-4 py-8">
                {/* Header */}
                <div className="flex items-center justify-between mb-8">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight flex items-center gap-2">
                            <Code2 className="size-6 text-primary" />
                            Snippets
                        </h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            Paste bins and code snippets for your workspace
                        </p>
                    </div>
                    <Button onClick={() => setCreateOpen(true)}>
                        <Plus className="size-4 mr-2" />
                        New Collection
                    </Button>
                </div>

                {/* Empty state */}
                {collections.length === 0 && (
                    <div className="flex flex-col items-center justify-center py-24 text-center">
                        <div className="flex items-center justify-center size-16 rounded-2xl bg-muted mb-4">
                            <Code2 className="size-8 text-muted-foreground" />
                        </div>
                        <h2 className="text-lg font-semibold mb-1">No snippet collections yet</h2>
                        <p className="text-sm text-muted-foreground mb-6 max-w-xs">
                            Create a collection to start saving code snippets, pastes, and configs.
                        </p>
                        <Button onClick={() => setCreateOpen(true)}>
                            <Plus className="size-4 mr-2" /> New Collection
                        </Button>
                    </div>
                )}

                {/* Grid */}
                {collections.length > 0 && (
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        {collections.map((c) => (
                            <Card
                                key={c.id}
                                className="group relative hover:shadow-md transition-shadow border border-border"
                            >
                                <CardContent className="p-5">
                                    {/* Actions */}
                                    <div className="absolute top-3 right-3 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button variant="ghost" size="icon" className="size-7">
                                                    <MoreVertical className="size-4" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem onClick={() => openEdit(c)}>
                                                    <Pencil className="size-4 mr-2" /> Edit
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    className="text-destructive focus:text-destructive"
                                                    onClick={() => setDeleteTarget(c)}
                                                >
                                                    <Trash2 className="size-4 mr-2" /> Delete
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>

                                    <Link href={route('snippets.show', [workspace.slug, c.slug])}>
                                        <div className="flex items-start gap-3 mb-3">
                                            <CollectionIcon icon={c.icon} size="md" />
                                            <div className="min-w-0">
                                                <p className="font-semibold truncate text-sm">{c.name}</p>
                                                {c.description && (
                                                    <p className="text-xs text-muted-foreground line-clamp-2 mt-0.5">
                                                        {c.description}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex items-center justify-between mt-3">
                                            <div className="flex items-center gap-2">
                                                <Badge variant="secondary" className="text-xs font-mono">
                                                    {c.snippets_count} snippet{c.snippets_count !== 1 ? 's' : ''}
                                                </Badge>
                                                {c.visibility === 'public'
                                                    ? <Badge variant="outline" className="text-xs gap-1"><Globe className="size-3" />Public</Badge>
                                                    : <Badge variant="outline" className="text-xs gap-1"><Lock className="size-3" />Private</Badge>
                                                }
                                            </div>
                                            <ArrowRight className="size-4 text-muted-foreground" />
                                        </div>
                                    </Link>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>

            {/* Create dialog */}
            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>New Snippet Collection</DialogTitle>
                        <DialogDescription>Give your collection a name and configure visibility.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={createCollection} className="space-y-4 mt-2">
                        <div className="flex items-end gap-3">
                            <IconPicker
                                label="Icon"
                                value={data.icon}
                                onChange={(v) => setData('icon', v)}
                                placeholderEmoji="📋"
                                emojis={SNIPPET_EMOJIS}
                                onImageUpload={handleIconUpload}
                                className="shrink-0"
                            />
                            <div className="flex-1">
                                <Label htmlFor="name">Name *</Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="My snippets"
                                    className="mt-1"
                                />
                                {errors.name && <p className="text-xs text-destructive mt-1">{errors.name}</p>}
                            </div>
                        </div>
                        <div>
                            <Label htmlFor="description">Description</Label>
                            <Textarea
                                id="description"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder="What's in this collection?"
                                rows={2}
                                className="mt-1 resize-none"
                            />
                        </div>
                        <div>
                            <Label>Visibility</Label>
                            <Select value={data.visibility} onValueChange={(v) => setData('visibility', v as 'private' | 'public')}>
                                <SelectTrigger className="mt-1">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="private"><span className="flex items-center gap-2"><Lock className="size-3" /> Private</span></SelectItem>
                                    <SelectItem value="public"><span className="flex items-center gap-2"><Globe className="size-3" /> Public (shareable)</span></SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={processing || !data.name.trim()}>Create</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Edit dialog */}
            {editCollection && (
                <Dialog open onOpenChange={() => setEditCollection(null)}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Edit Collection</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={saveEdit} className="space-y-4 mt-2">
                            <div className="flex items-end gap-3">
                                <IconPicker
                                    label="Icon"
                                    value={editForm.data.icon}
                                    onChange={(v) => editForm.setData('icon', v)}
                                    placeholderEmoji="📋"
                                    emojis={SNIPPET_EMOJIS}
                                    onImageUpload={handleIconUpload}
                                    className="shrink-0"
                                />
                                <div className="flex-1">
                                    <Label>Name</Label>
                                    <Input value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} className="mt-1" />
                                </div>
                            </div>
                            <div>
                                <Label>Description</Label>
                                <Textarea value={editForm.data.description} onChange={(e) => editForm.setData('description', e.target.value)} rows={2} className="mt-1 resize-none" />
                            </div>
                            <div>
                                <Label>Visibility</Label>
                                <Select value={editForm.data.visibility} onValueChange={(v) => editForm.setData('visibility', v as 'private' | 'public')}>
                                    <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="private"><span className="flex items-center gap-2"><Lock className="size-3" /> Private</span></SelectItem>
                                        <SelectItem value="public"><span className="flex items-center gap-2"><Globe className="size-3" /> Public</span></SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex justify-end gap-2 pt-2">
                                <Button type="button" variant="outline" onClick={() => setEditCollection(null)}>Cancel</Button>
                                <Button type="submit" disabled={editForm.processing}>Save</Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            )}

            {/* Delete confirm */}
            <AlertDialog open={!!deleteTarget} onOpenChange={(o) => !o && setDeleteTarget(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete «{deleteTarget?.name}»?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This will permanently delete the collection and all its snippets. This cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDelete} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
                            Delete forever
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
