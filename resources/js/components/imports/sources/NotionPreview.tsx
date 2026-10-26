import type { SourcePreviewProps } from '@/components/imports/sources';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { AlertTriangle, FileText, Folder, Loader2, Lock, Table2 } from 'lucide-react';
import { useMemo, useState } from 'react';

/** NotionImporter::preview() (BRAI-66). */
interface NotionNode {
    key: string;
    title: string;
    kind: 'page' | 'database' | 'folder';
    empty: boolean;
    rows?: number;
    children?: NotionNode[];
}

interface NotionPreviewData {
    tree: NotionNode[];
    counts: { pages: number; databases: number; folders: number; empty_pages: number; images: number; files: number };
    broken_links: { page: string; target: string }[];
    broken_link_count: number;
    skipped_files: { page: string; target: string }[];
    unsupported: Record<string, number>;
    folders: { id: number; path: string }[];
}

const SHOWN_NODES = 300;
const ROOT = 'root';

const KIND_ICON = { page: FileText, database: Table2, folder: Folder } as const;

/** Notion export: the page tree, what can't come over, and where it goes. */
export default function NotionPreview({ run, busy, onStart }: SourcePreviewProps) {
    const preview = run.preview as unknown as NotionPreviewData;
    const [parent, setParent] = useState<string>(ROOT);
    const [skipEmpty, setSkipEmpty] = useState(true);
    const counts = preview.counts;
    const unsupported = Object.entries(preview.unsupported ?? {});

    // render at most SHOWN_NODES entries of a big export
    const { tree, hidden } = useMemo(() => {
        let budget = SHOWN_NODES;
        let total = 0;
        const cut = (nodes: NotionNode[]): NotionNode[] => nodes.flatMap((n) => {
            total++;
            if (budget <= 0) {
                total += countAll(n.children ?? []);
                return [];
            }
            budget--;
            return [{ ...n, children: n.children ? cut(n.children) : undefined }];
        });
        const shown = cut(preview.tree ?? []);
        return { tree: shown, hidden: Math.max(0, total - SHOWN_NODES) };
    }, [preview.tree]);

    return (
        <div className="space-y-4">
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">What will be imported</CardTitle>
                    <CardDescription>Pages keep their order and nesting. A page with subpages becomes a folder that holds the page and its subpages.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <ul className="flex flex-wrap gap-2" aria-label="Summary">
                        <li><Badge variant="secondary">{counts.pages} pages</Badge></li>
                        {counts.databases > 0 && <li><Badge variant="secondary">{counts.databases} {counts.databases === 1 ? 'database' : 'databases'} as tables</Badge></li>}
                        {counts.folders > 0 && <li><Badge variant="secondary">{counts.folders} folders</Badge></li>}
                        <li><Badge variant="secondary">{counts.images} images</Badge></li>
                        <li><Badge variant="secondary">{counts.files} files</Badge></li>
                    </ul>

                    <div className="max-h-96 overflow-auto rounded-md border p-3">
                        <NodeList nodes={tree} skipEmpty={skipEmpty} label="Pages to import" />
                        {hidden > 0 && <p className="mt-2 text-xs text-muted-foreground">…and {hidden} more</p>}
                    </div>

                    {(preview.broken_link_count > 0 || preview.skipped_files.length > 0 || unsupported.length > 0) && (
                        <div className="space-y-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                            <p className="flex items-center gap-2 font-medium"><AlertTriangle className="size-4" /> Not imported</p>
                            {unsupported.length > 0 && (
                                <ul className="list-disc pl-5">
                                    {unsupported.map(([what, count]) => <li key={what}>{what}: {count}</li>)}
                                </ul>
                            )}
                            {preview.broken_link_count > 0 && (
                                <div>
                                    <p>Links to pages or files that aren’t in the export are kept as plain text:</p>
                                    <ul className="mt-1 list-disc pl-5" aria-label="Broken links">
                                        {preview.broken_links.map((b, i) => <li key={i}><span className="font-medium">{b.page}</span> → {b.target}</li>)}
                                    </ul>
                                    {preview.broken_link_count > preview.broken_links.length && <p className="text-xs">…and {preview.broken_link_count - preview.broken_links.length} more</p>}
                                </div>
                            )}
                            {preview.skipped_files.length > 0 && (
                                <div>
                                    <p>Files of types the editor doesn’t accept (only images, PDFs and office documents are):</p>
                                    <ul className="mt-1 list-disc pl-5" aria-label="Skipped files">
                                        {preview.skipped_files.map((f, i) => <li key={i}><span className="font-medium">{f.page}</span> → {f.target}</li>)}
                                    </ul>
                                </div>
                            )}
                        </div>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Options</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="notion-parent">Put the pages in</Label>
                        <Select value={parent} onValueChange={setParent}>
                            <SelectTrigger id="notion-parent" aria-label="Parent folder" className="max-w-sm"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ROOT}>Top of the project</SelectItem>
                                {preview.folders.map((f) => <SelectItem key={f.id} value={String(f.id)}>{f.path}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>

                    <label className="flex items-start gap-2 text-sm">
                        <input type="checkbox" className="mt-0.5 size-4 rounded border-input" checked={skipEmpty} onChange={(e) => setSkipEmpty(e.target.checked)} />
                        <span>
                            Skip empty pages{counts.empty_pages > 0 && <span className="text-muted-foreground"> ({counts.empty_pages} in this export)</span>}
                        </span>
                    </label>

                    <label className="flex items-start gap-2 text-sm">
                        <input type="checkbox" className="mt-0.5 size-4 rounded border-input" checked disabled aria-describedby="notion-drafts-help" />
                        <span>
                            <span className="inline-flex items-center gap-1">Import as drafts <Lock className="size-3" aria-hidden /></span>
                            <span id="notion-drafts-help" className="block text-muted-foreground">
                                Imported pages stay drafts: only you and project admins see them until each one is published. Folder names are visible to everyone in the project.
                            </span>
                        </span>
                    </label>

                    <Button onClick={() => onStart({ parent_id: parent === ROOT ? null : Number(parent), skip_empty: skipEmpty })} disabled={busy}>
                        {busy && <Loader2 className="size-4 animate-spin" />} Start import
                    </Button>
                </CardContent>
            </Card>
        </div>
    );
}

function countAll(nodes: NotionNode[]): number {
    return nodes.reduce((sum, n) => sum + 1 + countAll(n.children ?? []), 0);
}

function NodeList({ nodes, skipEmpty, label }: { nodes: NotionNode[]; skipEmpty: boolean; label?: string }) {
    return (
        <ul aria-label={label} className={cn('space-y-1 text-sm', !label && 'mt-1 border-l pl-4')}>
            {nodes.map((n) => {
                const Icon = KIND_ICON[n.kind];
                const skipped = skipEmpty && n.empty && !(n.children && n.children.length > 0);
                return (
                    <li key={n.key}>
                        <span className={cn('inline-flex items-center gap-1.5', skipped && 'text-muted-foreground line-through')}>
                            <Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                            <span>{n.title}</span>
                        </span>
                        {n.kind === 'database' && <span className="ml-2 text-xs text-muted-foreground">table, {n.rows ?? 0} rows</span>}
                        {n.empty && <span className="ml-2 text-xs text-muted-foreground">{skipped ? 'empty, skipped' : 'empty'}</span>}
                        {n.children && n.children.length > 0 && <NodeList nodes={n.children} skipEmpty={skipEmpty} />}
                    </li>
                );
            })}
        </ul>
    );
}
