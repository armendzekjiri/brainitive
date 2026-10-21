import { useEffect, useState } from 'react';
import { History, Loader2, RotateCcw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { PlanChart } from './PlanChart';
import { PlanDocument } from './types';

interface VersionSummary {
    version: number;
    name: string;
    message: string | null;
    author: { id: number; name: string } | null;
    created_at: string | null;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    versionsUrl: string;
    versionUrl: (version: number) => string;
    canRestore: boolean;
    onRestore: (version: number) => Promise<void>;
}

const formatDateTime = (value: string | null) => (value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '');

/**
 * Checkpoints of a plan with a read-only preview and restore.
 */
export function PlanHistoryDialog({ open, onOpenChange, versionsUrl, versionUrl, canRestore, onRestore }: Props) {
    const [versions, setVersions] = useState<VersionSummary[] | null>(null);
    const [current, setCurrent] = useState(0);
    const [selected, setSelected] = useState<(PlanDocument & { version: number }) | null>(null);
    const [loading, setLoading] = useState<number | null>(null);
    const [restoring, setRestoring] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const select = (version: number) => {
        setLoading(version);
        window.axios.get(versionUrl(version))
            .then(({ data }) => setSelected({ version: data.version, data: data.data, settings: data.settings }))
            .catch(() => setError('Could not load this version.'))
            .finally(() => setLoading(null));
    };

    useEffect(() => {
        if (!open) return;
        setVersions(null);
        setSelected(null);
        setError(null);
        window.axios.get(versionsUrl)
            .then(({ data }) => {
                setVersions(data.versions);
                setCurrent(data.current_version);
                if (data.versions.length) select(data.versions[0].version);
            })
            .catch(() => setError('Could not load the history.'));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, versionsUrl]);

    const restore = async () => {
        if (!selected) return;
        setRestoring(true);
        try {
            await onRestore(selected.version);
            onOpenChange(false);
        } finally {
            setRestoring(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex h-[85vh] max-w-6xl flex-col gap-0 overflow-hidden p-0">
                <DialogHeader className="border-b px-5 py-4">
                    <DialogTitle className="flex items-center gap-2"><History className="size-4" />Plan history</DialogTitle>
                    <DialogDescription>A checkpoint is saved on “Save version”, at least every 30 minutes while editing, and when someone else edits. Restoring keeps the history.</DialogDescription>
                </DialogHeader>
                <div className="flex min-h-0 flex-1">
                    <nav className="w-64 shrink-0 overflow-y-auto border-r p-2" aria-label="Versions">
                        {error && <p className="p-3 text-sm text-destructive">{error}</p>}
                        {!versions && !error && <div className="flex items-center gap-2 p-3 text-sm text-muted-foreground"><Loader2 className="size-4 animate-spin" />Loading…</div>}
                        {versions?.map((v) => (
                            <button
                                key={v.version}
                                type="button"
                                onClick={() => select(v.version)}
                                className={cn('mb-0.5 w-full rounded-md px-3 py-2.5 text-left transition-colors hover:bg-accent', selected?.version === v.version && 'bg-accent')}
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-sm font-medium">Version {v.version}</span>
                                    {v.version === current && <span className="text-[11px] font-medium text-brand-green">Current</span>}
                                    {loading === v.version && <Loader2 className="size-3.5 animate-spin text-muted-foreground" />}
                                </div>
                                <div className="mt-0.5 text-xs text-muted-foreground">{v.author?.name ?? 'Unknown'} · {formatDateTime(v.created_at)}</div>
                                {v.message && <div className="mt-1 line-clamp-2 text-xs text-foreground/80">{v.message}</div>}
                            </button>
                        ))}
                    </nav>
                    <div className="flex min-w-0 flex-1 flex-col">
                        {selected ? (
                            <>
                                <div className="flex items-center justify-between gap-3 border-b bg-muted/30 px-5 py-2.5">
                                    <span className="text-sm text-muted-foreground">Viewing <span className="font-medium text-foreground">version {selected.version}</span>{selected.version === current ? ' (current)' : ''}</span>
                                    {canRestore && selected.version !== current && (
                                        <Button size="sm" variant="outline" onClick={restore} disabled={restoring} className="gap-1.5">
                                            {restoring ? <Loader2 className="size-4 animate-spin" /> : <RotateCcw className="size-4" />}Restore this version
                                        </Button>
                                    )}
                                </div>
                                <PlanChart key={selected.version} doc={selected} className="min-h-0 flex-1" />
                            </>
                        ) : (
                            <div className="flex flex-1 items-center justify-center text-sm text-muted-foreground">{versions?.length ? <Loader2 className="size-4 animate-spin" /> : null}</div>
                        )}
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
