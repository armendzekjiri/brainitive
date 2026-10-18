import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { History, Loader2, RotateCcw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
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
import { RichLexicalEditor } from '@/components/editor/RichLexicalEditorFull';
import { PageVersionDetail, PageVersionSummary } from '@/types';
import { cn } from '@/lib/utils';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** [workspace slug, project slug, page uuid] */
    routeParams: [string, string, string];
    canRestore: boolean;
    isPublished: boolean;
    hasUnpublishedChanges: boolean;
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * Confluence-style page history: every published version, a read-only preview and restore.
 */
export function PageHistoryDialog({ open, onOpenChange, routeParams, canRestore, isPublished, hasUnpublishedChanges }: Props) {
    const [versions, setVersions] = useState<PageVersionSummary[] | null>(null);
    const [currentVersion, setCurrentVersion] = useState(0);
    const [selected, setSelected] = useState<PageVersionDetail | null>(null);
    const [loadingVersion, setLoadingVersion] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [confirmRestore, setConfirmRestore] = useState(false);
    const [restoring, setRestoring] = useState(false);

    const selectVersion = (version: number) => {
        setLoadingVersion(version);
        window.axios
            .get(route('pages.versions.show', [...routeParams, version]))
            .then(({ data }: { data: PageVersionDetail }) => setSelected(data))
            .catch(() => setError('Could not load this version.'))
            .finally(() => setLoadingVersion(null));
    };

    useEffect(() => {
        if (!open) return;
        setVersions(null);
        setSelected(null);
        setError(null);
        window.axios
            .get(route('pages.versions.index', routeParams))
            .then(({ data }: { data: { current_version: number; versions: PageVersionSummary[] } }) => {
                setVersions(data.versions);
                setCurrentVersion(data.current_version);
                if (data.versions.length > 0) selectVersion(data.versions[0].version);
            })
            .catch(() => setError('Could not load the page history.'));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, routeParams.join('/')]);

    const restore = () => {
        if (!selected) return;
        setRestoring(true);
        router.post(route('pages.versions.restore', [...routeParams, selected.version]), {}, {
            preserveScroll: true,
            onSuccess: () => {
                setConfirmRestore(false);
                onOpenChange(false);
            },
            onFinish: () => setRestoring(false),
        });
    };

    const isCurrent = selected?.version === currentVersion;
    const html = selected?.content && (selected.content as { type?: string; html?: string }).type === 'lexical'
        ? (selected.content as { html?: string }).html ?? ''
        : '';

    return (
        <>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="max-w-5xl h-[80vh] p-0 gap-0 flex flex-col overflow-hidden">
                    <DialogHeader className="px-5 py-4 border-b">
                        <DialogTitle className="flex items-center gap-2">
                            <History className="size-4" />
                            Page history
                        </DialogTitle>
                        <DialogDescription>Every published version of this page. Restoring one publishes it again as a new version.</DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-1 min-h-0">
                        <nav className="w-72 shrink-0 border-r overflow-y-auto p-2" aria-label="Versions">
                            {error && <p className="p-3 text-sm text-destructive">{error}</p>}
                            {!versions && !error && (
                                <div className="flex items-center gap-2 p-3 text-sm text-muted-foreground">
                                    <Loader2 className="size-4 animate-spin" /> Loading…
                                </div>
                            )}
                            {versions?.length === 0 && (
                                <p className="p-3 text-sm text-muted-foreground">No versions yet. A version is saved each time the page is published.</p>
                            )}
                            {versions?.map((version) => (
                                <button
                                    key={version.version}
                                    type="button"
                                    onClick={() => selectVersion(version.version)}
                                    className={cn(
                                        'w-full text-left rounded-md px-3 py-2.5 mb-0.5 transition-colors hover:bg-accent',
                                        selected?.version === version.version && 'bg-accent',
                                    )}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-sm font-medium">Version {version.version}</span>
                                        {version.version === currentVersion && (
                                            <span className="text-[11px] font-medium text-brand-green">Current</span>
                                        )}
                                        {loadingVersion === version.version && <Loader2 className="size-3.5 animate-spin text-muted-foreground" />}
                                    </div>
                                    <div className="text-xs text-muted-foreground mt-0.5">
                                        {version.author?.name ?? 'Unknown'} · {formatDateTime(version.created_at)}
                                    </div>
                                    {version.message && <div className="text-xs text-foreground/80 mt-1 line-clamp-2">{version.message}</div>}
                                </button>
                            ))}
                        </nav>

                        <div className="flex-1 min-w-0 flex flex-col">
                            {selected ? (
                                <>
                                    <div className="flex items-center justify-between gap-3 px-6 py-3 border-b bg-muted/30">
                                        <div className="text-sm text-muted-foreground truncate">
                                            Viewing <span className="font-medium text-foreground">version {selected.version}</span>
                                            {isCurrent ? ' (current)' : ''}
                                        </div>
                                        {canRestore && !isCurrent && (
                                            <Button size="sm" variant="outline" onClick={() => setConfirmRestore(true)}>
                                                <RotateCcw className="size-4 mr-1.5" />
                                                Restore this version
                                            </Button>
                                        )}
                                    </div>
                                    <div className="flex-1 overflow-y-auto px-8 py-6">
                                        <h2 className="text-2xl font-bold tracking-tight mb-4">
                                            {selected.icon && !selected.icon.startsWith('data:') && !selected.icon.startsWith('http') && !selected.icon.startsWith('/') && (
                                                <span className="mr-2">{selected.icon}</span>
                                            )}
                                            {selected.name}
                                        </h2>
                                        <RichLexicalEditor
                                            key={selected.version}
                                            content={html}
                                            onChange={() => {}}
                                            editable={false}
                                            showToolbar={false}
                                            minHeight="200px"
                                            contentKey={`version-${selected.version}`}
                                            className="border-0 rounded-none shadow-none bg-transparent overflow-visible"
                                            documentMode
                                        />
                                    </div>
                                </>
                            ) : (
                                <div className="flex-1 flex items-center justify-center text-sm text-muted-foreground">
                                    {versions && versions.length > 0 ? <Loader2 className="size-4 animate-spin" /> : null}
                                </div>
                            )}
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

            <AlertDialog open={confirmRestore} onOpenChange={setConfirmRestore}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Restore version {selected?.version}?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {isPublished
                                ? `This publishes version ${selected?.version} again as version ${currentVersion + 1}. The history is kept.`
                                : `The draft is replaced with the content of version ${selected?.version}.`}
                            {hasUnpublishedChanges && (
                                <span className="block mt-2 font-medium text-destructive">The current unpublished changes will be discarded.</span>
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={restoring}>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={(e) => { e.preventDefault(); restore(); }} disabled={restoring}>
                            {restoring && <Loader2 className="size-4 mr-1.5 animate-spin" />}
                            Restore
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
