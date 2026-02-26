/**
 * QuickCreateSnippetModal
 *
 * Opened from the Command Palette. Lets the user pick a snippet
 * collection, fill in snippet details, and create it without leaving the page.
 */

import { useEffect, useState } from 'react';
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
import { Code2, Loader2, ExternalLink } from 'lucide-react';
import { toast } from 'sonner';
import { SnippetCollection } from '@/types';

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
    { value: 'csharp', label: 'C#' },
    { value: 'ruby', label: 'Ruby' },
];

const CODE_FONT = "'JetBrains Mono', 'Fira Mono', 'Cascadia Code', 'Menlo', monospace";

function CollectionIcon({ icon }: { icon?: string | null }) {
    const src = icon ?? '📋';
    if (src.startsWith('http') || src.startsWith('/')) {
        return <img src={src} alt="" className="size-5 rounded object-contain shrink-0" />;
    }
    return <span className="text-base leading-none shrink-0">{src}</span>;
}

interface Props {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    workspaceSlug: string;
}

interface FormState {
    collectionSlug: string;
    title: string;
    description: string;
    language: string;
    content: string;
    visibility: 'inherit' | 'private' | 'public';
}

export function QuickCreateSnippetModal({ open, onOpenChange, workspaceSlug }: Props) {
    const [collections, setCollections] = useState<SnippetCollection[]>([]);
    const [loading, setLoading] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const [form, setForm] = useState<FormState>({
        collectionSlug: '',
        title: '',
        description: '',
        language: 'plaintext',
        content: '',
        visibility: 'inherit',
    });

    const set = <K extends keyof FormState>(key: K, value: FormState[K]) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    // Fetch collections when dialog opens
    useEffect(() => {
        if (!open) return;
        setLoading(true);
        setForm({ collectionSlug: '', title: '', description: '', language: 'plaintext', content: '', visibility: 'inherit' });
        axios
            .get(route('snippets.list', workspaceSlug))
            .then((res) => {
                setCollections(res.data);
                if (res.data.length === 1) set('collectionSlug', res.data[0].slug);
            })
            .catch(() => toast.error('Could not load snippet collections'))
            .finally(() => setLoading(false));
    }, [open]);

    const selectedCollection = collections.find((c) => c.slug === form.collectionSlug) ?? null;

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!form.collectionSlug || !form.title.trim()) return;
        setSubmitting(true);
        try {
            await axios.post(
                route('snippets.snippets.store', [workspaceSlug, form.collectionSlug]),
                {
                    title: form.title,
                    description: form.description || null,
                    language: form.language,
                    content: form.content || null,
                    visibility: form.visibility,
                },
            );
            onOpenChange(false);
            toast.success('Snippet created!', {
                action: {
                    label: 'View collection',
                    onClick: () => router.visit(route('snippets.show', [workspaceSlug, form.collectionSlug])),
                },
            });
        } catch {
            toast.error('Failed to create snippet — please try again');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400 shrink-0">
                            <Code2 className="size-5" />
                        </div>
                        <div>
                            <DialogTitle className="text-lg">New Snippet</DialogTitle>
                            <DialogDescription className="text-sm">
                                Add a code snippet to one of your collections
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                {loading ? (
                    <div className="flex items-center justify-center py-12 text-muted-foreground">
                        <Loader2 className="size-5 animate-spin mr-2" />
                        Loading collections…
                    </div>
                ) : collections.length === 0 ? (
                    <div className="py-10 text-center space-y-3">
                        <p className="text-sm text-muted-foreground">You don't have any snippet collections yet.</p>
                        <Button variant="outline" size="sm" onClick={() => {
                            onOpenChange(false);
                            router.visit(route('snippets.index', workspaceSlug) + '?create=true');
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
                                value={form.collectionSlug}
                                onValueChange={(v) => set('collectionSlug', v)}
                            >
                                <SelectTrigger className="mt-1">
                                    <SelectValue placeholder="Pick a collection…">
                                        {selectedCollection && (
                                            <span className="flex items-center gap-2">
                                                <CollectionIcon icon={selectedCollection.icon} />
                                                {selectedCollection.name}
                                            </span>
                                        )}
                                    </SelectValue>
                                </SelectTrigger>
                                <SelectContent>
                                    {collections.map((c) => (
                                        <SelectItem key={c.slug} value={c.slug}>
                                            <span className="flex items-center gap-2">
                                                <CollectionIcon icon={c.icon} />
                                                {c.name}
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Title + Language */}
                        <div className="grid grid-cols-2 gap-3">
                            <div className="col-span-2 sm:col-span-1">
                                <Label>Title *</Label>
                                <Input
                                    value={form.title}
                                    onChange={(e) => set('title', e.target.value)}
                                    placeholder="e.g. Nginx config"
                                    className="mt-1"
                                    autoFocus
                                />
                            </div>
                            <div>
                                <Label>Language</Label>
                                <Select value={form.language} onValueChange={(v) => set('language', v)}>
                                    <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {LANGUAGES.map((l) => (
                                            <SelectItem key={l.value} value={l.value}>{l.label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        {/* Description */}
                        <div>
                            <Label>Description <span className="text-muted-foreground font-normal">(optional)</span></Label>
                            <Input
                                value={form.description}
                                onChange={(e) => set('description', e.target.value)}
                                placeholder="Short description"
                                className="mt-1"
                            />
                        </div>

                        {/* Content */}
                        <div>
                            <Label>Content *</Label>
                            <Textarea
                                value={form.content}
                                onChange={(e) => set('content', e.target.value)}
                                placeholder="Paste your code here…"
                                rows={10}
                                className="mt-1 resize-y text-sm rounded-lg border-[#444c56]"
                                style={{ fontFamily: CODE_FONT, background: '#22272e', color: '#adbac7' }}
                            />
                        </div>

                        {/* Visibility */}
                        <div>
                            <Label>Visibility</Label>
                            <Select
                                value={form.visibility}
                                onValueChange={(v) => set('visibility', v as FormState['visibility'])}
                            >
                                <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="inherit">Inherit from collection</SelectItem>
                                    <SelectItem value="private">Private</SelectItem>
                                    <SelectItem value="public">Public</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={submitting || !form.collectionSlug || !form.title.trim() || !form.content.trim()}
                            >
                                {submitting && <Loader2 className="size-4 mr-2 animate-spin" />}
                                Add Snippet
                            </Button>
                        </div>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
