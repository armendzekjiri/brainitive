import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { PageProps, Workspace, SnippetCollection, Snippet } from '@/types';
import { Button } from '@/components/ui/button';
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
import {
    Collapsible, CollapsibleContent, CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    ChevronLeft, ChevronDown, ChevronRight, Code2, Copy, Globe, Link2, Lock,
    MoreVertical, Pencil, Plus, Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import hljs from 'highlight.js';
import 'highlight.js/styles/github-dark-dimmed.css';

function CollectionIcon({ icon, size = 'md' }: { icon?: string | null; size?: 'sm' | 'md' | 'lg' }) {
    const src = icon ?? '📋';
    const isUrl = src.startsWith('http') || src.startsWith('/');
    if (isUrl) {
        const cls = size === 'lg' ? 'size-10' : size === 'sm' ? 'size-5' : 'size-8';
        return <img src={src} alt="" className={`${cls} rounded-lg object-contain shrink-0`} />;
    }
    const textCls = size === 'lg' ? 'text-4xl' : size === 'sm' ? 'text-base' : 'text-3xl';
    return <span className={`${textCls} leading-none shrink-0`}>{src}</span>;
}

// Languages the user can pick from
const LANGUAGES = [
    { value: 'plaintext', label: 'Plain text' },
    { value: 'javascript', label: 'JavaScript' },
    { value: 'typescript', label: 'TypeScript' },
    { value: 'tsx', label: 'TSX / JSX' },
    { value: 'python', label: 'Python' },
    { value: 'php', label: 'PHP' },
    { value: 'bash', label: 'Bash / Shell' },
    { value: 'sql', label: 'SQL' },
    { value: 'html', label: 'HTML' },
    { value: 'css', label: 'CSS' },
    { value: 'json', label: 'JSON' },
    { value: 'yaml', label: 'YAML' },
    { value: 'markdown', label: 'Markdown' },
    { value: 'rust', label: 'Rust' },
    { value: 'go', label: 'Go' },
    { value: 'java', label: 'Java' },
    { value: 'c', label: 'C' },
    { value: 'cpp', label: 'C++' },
    { value: 'ruby', label: 'Ruby' },
    { value: 'swift', label: 'Swift' },
    { value: 'kotlin', label: 'Kotlin' },
];

function langLabel(value: string) {
    return LANGUAGES.find((l) => l.value === value)?.label ?? value;
}

const CODE_FONT = "'JetBrains Mono', 'Fira Code', 'Cascadia Code', ui-monospace, monospace";

/** Highlighted code block with line numbers */
function CodeBlock({ code, language }: { code: string; language: string }) {
    const lines = useMemo(() => {
        let html: string;
        if (language !== 'plaintext' && hljs.getLanguage(language)) {
            html = hljs.highlight(code, { language }).value;
        } else {
            html = code.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
        const rows = html.split('\n');
        if (rows.at(-1) === '') rows.pop();
        return rows;
    }, [code, language]);

    return (
        <div
            className="overflow-auto max-h-80 rounded-b-lg bg-[#22272e] text-sm"
            style={{ fontFamily: CODE_FONT }}
        >
            <table className="w-full border-collapse" style={{ lineHeight: '1.6' }}>
                <tbody>
                    {lines.map((line, i) => (
                        <tr key={i} className="hover:bg-white/5">
                            <td
                                className="select-none text-right pr-3 pl-4 w-px whitespace-nowrap border-r border-white/10 align-top"
                                style={{ color: '#636e7b', fontSize: '0.75rem', paddingTop: '1px', paddingBottom: '1px' }}
                            >
                                {i + 1}
                            </td>
                            <td
                                className="pl-4 pr-4 whitespace-pre align-top"
                                style={{ color: '#adbac7', paddingTop: '1px', paddingBottom: '1px' }}
                                dangerouslySetInnerHTML={{ __html: line || '\u00a0' }}
                            />
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

interface Props extends PageProps {
    workspace: Workspace;
    collection: SnippetCollection & { snippets: Snippet[] };
    canEdit: boolean;
}

export default function SnippetsShow({ workspace, collection, canEdit }: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editSnippet, setEditSnippet] = useState<Snippet | null>(null);
    const [deleteSnippet, setDeleteSnippet] = useState<Snippet | null>(null);
    const [openSnippetIds, setOpenSnippetIds] = useState<Set<number>>(new Set());

    // Track dark theme preference to swap hljs theme
    const isDark = typeof window !== 'undefined' && document.documentElement.classList.contains('dark');

    const createForm = useForm({
        title: '',
        description: '',
        language: 'plaintext',
        content: '',
        visibility: 'inherit' as 'inherit' | 'private' | 'public',
    });

    const editForm = useForm({
        title: '',
        description: '',
        language: 'plaintext',
        content: '',
        visibility: 'inherit' as 'inherit' | 'private' | 'public',
    });

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(route('snippets.snippets.store', [workspace.slug, collection.slug]), {
            onSuccess: () => {
                setCreateOpen(false);
                createForm.reset();
                toast.success('Snippet added');
            },
        });
    };

    const openEdit = (s: Snippet) => {
        setEditSnippet(s);
        editForm.setData({
            title: s.title,
            description: s.description ?? '',
            language: s.language,
            content: s.content ?? '',
            visibility: s.visibility,
        });
    };

    const submitEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editSnippet) return;
        editForm.put(
            route('snippets.snippets.update', [workspace.slug, collection.slug, editSnippet.slug]),
            { onSuccess: () => { setEditSnippet(null); toast.success('Snippet updated'); } }
        );
    };

    const confirmDeleteSnippet = () => {
        if (!deleteSnippet) return;
        router.delete(
            route('snippets.snippets.destroy', [workspace.slug, collection.slug, deleteSnippet.slug]),
            { onSuccess: () => { setDeleteSnippet(null); toast.success('Snippet deleted'); } }
        );
    };

    const copyContent = (s: Snippet) => {
        navigator.clipboard.writeText(s.content ?? '').then(() => toast.success('Copied to clipboard'));
    };

    const copyShareLink = (s: Snippet) => {
        const url = `${window.location.origin}/share/snippets/${s.uuid}`;
        navigator.clipboard.writeText(url).then(() => toast.success('Share link copied'));
    };

    const copyCollectionShareLink = () => {
        const url = `${window.location.origin}/share/snippet-collections/${collection.uuid}`;
        navigator.clipboard.writeText(url).then(() => toast.success('Share link copied'));
    };

    const toggleOpen = (id: number) => {
        setOpenSnippetIds((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    return (
        <AppLayout title={collection.name}>
            <Head title={`${collection.name} · Snippets`} />
            <div className="max-w-4xl mx-auto px-4 py-8">
                {/* Breadcrumb */}
                <div className="flex items-center gap-2 text-sm text-muted-foreground mb-6">
                    <Link href={route('snippets.index', workspace.slug)} className="hover:text-foreground flex items-center gap-1">
                        <Code2 className="size-4" />
                        Snippets
                    </Link>
                    <ChevronRight className="size-4" />
                    <span className="text-foreground font-medium">{collection.name}</span>
                </div>

                {/* Collection header */}
                <div className="flex items-start justify-between mb-8 gap-4">
                    <div className="flex items-start gap-3">
                        <CollectionIcon icon={collection.icon} size="lg" />
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight">{collection.name}</h1>
                            {collection.description && (
                                <p className="text-sm text-muted-foreground mt-1 max-w-xl">{collection.description}</p>
                            )}
                            <div className="flex items-center gap-2 mt-2">
                                {collection.visibility === 'public'
                                    ? <Badge variant="outline" className="text-xs gap-1"><Globe className="size-3" />Public</Badge>
                                    : <Badge variant="outline" className="text-xs gap-1"><Lock className="size-3" />Private</Badge>
                                }
                                <span className="text-xs text-muted-foreground">
                                    {collection.snippets.length} snippet{collection.snippets.length !== 1 ? 's' : ''}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                        {collection.visibility === 'public' && (
                            <Button variant="outline" size="sm" onClick={copyCollectionShareLink}>
                                <Link2 className="size-4 mr-2" />
                                Share collection
                            </Button>
                        )}
                        {canEdit && (
                            <Button size="sm" onClick={() => setCreateOpen(true)}>
                                <Plus className="size-4 mr-2" />
                                New Snippet
                            </Button>
                        )}
                    </div>
                </div>

                {/* Snippet list */}
                {collection.snippets.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-20 text-center border-2 border-dashed rounded-xl border-border">
                        <Code2 className="size-10 text-muted-foreground mb-3" />
                        <p className="font-medium mb-1">No snippets yet</p>
                        <p className="text-sm text-muted-foreground mb-4">Add your first code snippet or paste.</p>
                        {canEdit && (
                            <Button onClick={() => setCreateOpen(true)}>
                                <Plus className="size-4 mr-2" />New Snippet
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="flex flex-col gap-3">
                        {collection.snippets.map((s) => {
                            const isOpen = openSnippetIds.has(s.id);
                            const resolvedVis = s.visibility === 'inherit' ? collection.visibility : s.visibility;
                            return (
                                <Collapsible key={s.id} open={isOpen} onOpenChange={() => toggleOpen(s.id)}>
                                    <div className="border border-border rounded-xl overflow-hidden">
                                        {/* Snippet header row */}
                                        <div className="flex items-center gap-3 px-4 py-3 bg-card hover:bg-muted/40 transition-colors">
                                            <CollapsibleTrigger asChild>
                                                <button className="flex items-center gap-2 flex-1 min-w-0 text-left">
                                                    {isOpen
                                                        ? <ChevronDown className="size-4 text-muted-foreground shrink-0" />
                                                        : <ChevronRight className="size-4 text-muted-foreground shrink-0" />
                                                    }
                                                    <span className="font-medium text-sm truncate">{s.title}</span>
                                                </button>
                                            </CollapsibleTrigger>

                                            <div className="flex items-center gap-2 shrink-0">
                                                <Badge variant="secondary" className="text-xs font-mono hidden sm:flex">
                                                    {langLabel(s.language)}
                                                </Badge>
                                                {s.visibility !== 'inherit' && (
                                                    <Badge variant="outline" className="text-xs gap-1">
                                                        {resolvedVis === 'public'
                                                            ? <><Globe className="size-3" />Public</>
                                                            : <><Lock className="size-3" />Private</>
                                                        }
                                                    </Badge>
                                                )}
                                                <Button variant="ghost" size="icon" className="size-7" onClick={() => copyContent(s)} title="Copy code">
                                                    <Copy className="size-3.5" />
                                                </Button>
                                                {canEdit && (
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button variant="ghost" size="icon" className="size-7">
                                                                <MoreVertical className="size-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuItem onClick={() => openEdit(s)}>
                                                                <Pencil className="size-4 mr-2" />Edit
                                                            </DropdownMenuItem>
                                                            {resolvedVis === 'public' && (
                                                                <DropdownMenuItem onClick={() => copyShareLink(s)}>
                                                                    <Link2 className="size-4 mr-2" />Copy share link
                                                                </DropdownMenuItem>
                                                            )}
                                                            <DropdownMenuSeparator />
                                                            <DropdownMenuItem
                                                                className="text-destructive focus:text-destructive"
                                                                onClick={() => setDeleteSnippet(s)}
                                                            >
                                                                <Trash2 className="size-4 mr-2" />Delete
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                )}
                                            </div>
                                        </div>

                                        {/* Expanded content */}
                                        <CollapsibleContent>
                                            {s.description && (
                                                <p className="text-xs text-muted-foreground px-4 py-2 border-t border-border bg-muted/30">
                                                    {s.description}
                                                </p>
                                            )}
                                            <CodeBlock code={s.content ?? ''} language={s.language} />
                                        </CollapsibleContent>
                                    </div>
                                </Collapsible>
                            );
                        })}
                    </div>
                )}
            </div>

            {/* Create snippet dialog */}
            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>New Snippet</DialogTitle>
                        <DialogDescription>Add a code snippet or paste to this collection.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitCreate} className="space-y-4 mt-2">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="col-span-2 sm:col-span-1">
                                <Label>Title *</Label>
                                <Input
                                    value={createForm.data.title}
                                    onChange={(e) => createForm.setData('title', e.target.value)}
                                    placeholder="e.g. Nginx config"
                                    className="mt-1"
                                />
                                {createForm.errors.title && <p className="text-xs text-destructive mt-1">{createForm.errors.title}</p>}
                            </div>
                            <div>
                                <Label>Language</Label>
                                <Select value={createForm.data.language} onValueChange={(v) => createForm.setData('language', v)}>
                                    <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {LANGUAGES.map((l) => <SelectItem key={l.value} value={l.value}>{l.label}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div>
                            <Label>Description</Label>
                            <Input
                                value={createForm.data.description}
                                onChange={(e) => createForm.setData('description', e.target.value)}
                                placeholder="Optional short description"
                                className="mt-1"
                            />
                        </div>
                        <div>
                            <Label>Content *</Label>
                            <Textarea
                                value={createForm.data.content}
                                onChange={(e) => createForm.setData('content', e.target.value)}
                                placeholder="Paste your code here..."
                                rows={10}
                                className="mt-1 resize-y text-sm rounded-lg border-[#444c56]"
                                style={{ fontFamily: CODE_FONT, background: '#22272e', color: '#adbac7' }}
                            />
                        </div>
                        <div>
                            <Label>Visibility</Label>
                            <Select value={createForm.data.visibility} onValueChange={(v) => createForm.setData('visibility', v as 'inherit' | 'private' | 'public')}>
                                <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="inherit">Inherit from collection ({collection.visibility})</SelectItem>
                                    <SelectItem value="private"><span className="flex items-center gap-2"><Lock className="size-3" />Private</span></SelectItem>
                                    <SelectItem value="public"><span className="flex items-center gap-2"><Globe className="size-3" />Public</span></SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={createForm.processing || !createForm.data.title.trim()}>Add Snippet</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Edit snippet dialog */}
            {editSnippet && (
                <Dialog open onOpenChange={() => setEditSnippet(null)}>
                    <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>Edit Snippet</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={submitEdit} className="space-y-4 mt-2">
                            <div className="grid grid-cols-2 gap-3">
                                <div className="col-span-2 sm:col-span-1">
                                    <Label>Title</Label>
                                    <Input value={editForm.data.title} onChange={(e) => editForm.setData('title', e.target.value)} className="mt-1" />
                                </div>
                                <div>
                                    <Label>Language</Label>
                                    <Select value={editForm.data.language} onValueChange={(v) => editForm.setData('language', v)}>
                                        <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {LANGUAGES.map((l) => <SelectItem key={l.value} value={l.value}>{l.label}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div>
                                <Label>Description</Label>
                                <Input value={editForm.data.description} onChange={(e) => editForm.setData('description', e.target.value)} className="mt-1" />
                            </div>
                            <div>
                                <Label>Content</Label>
                                <Textarea
                                    value={editForm.data.content}
                                    onChange={(e) => editForm.setData('content', e.target.value)}
                                    rows={10}
                                    className="mt-1 resize-y text-sm rounded-lg border-[#444c56]"
                                    style={{ fontFamily: CODE_FONT, background: '#22272e', color: '#adbac7' }}
                                />
                            </div>
                            <div>
                                <Label>Visibility</Label>
                                <Select value={editForm.data.visibility} onValueChange={(v) => editForm.setData('visibility', v as 'inherit' | 'private' | 'public')}>
                                    <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="inherit">Inherit from collection ({collection.visibility})</SelectItem>
                                        <SelectItem value="private"><span className="flex items-center gap-2"><Lock className="size-3" />Private</span></SelectItem>
                                        <SelectItem value="public"><span className="flex items-center gap-2"><Globe className="size-3" />Public</span></SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex justify-end gap-2 pt-2">
                                <Button type="button" variant="outline" onClick={() => setEditSnippet(null)}>Cancel</Button>
                                <Button type="submit" disabled={editForm.processing}>Save</Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            )}

            {/* Delete snippet confirm */}
            <AlertDialog open={!!deleteSnippet} onOpenChange={(o) => !o && setDeleteSnippet(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete «{deleteSnippet?.title}»?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This snippet will be permanently deleted. This cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDeleteSnippet} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
                            Delete forever
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
