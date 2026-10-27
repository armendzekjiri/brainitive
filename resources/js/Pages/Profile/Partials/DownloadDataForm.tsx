import { useCallback, useEffect, useState } from 'react';
import { formatDistanceToNow } from 'date-fns';
import { toast } from 'sonner';
import type { DataExportData, DataExportState } from '@/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Download, FileArchive, Loader2 } from 'lucide-react';

const STATUS: Record<DataExportData['status'], { label: string; tone: string }> = {
    queued: { label: 'Preparing…', tone: 'border-primary/40 text-primary' },
    running: { label: 'Preparing…', tone: 'border-primary/40 text-primary' },
    ready: { label: 'Ready', tone: 'border-green-500/40 text-green-700 dark:text-green-400' },
    failed: { label: 'Failed', tone: 'border-destructive/40 text-destructive' },
    expired: { label: 'Expired', tone: 'text-muted-foreground' },
};

function size(bytes: number): string {
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/**
 * Profile → Your data → "Download my data" (GDPR Art. 15 + 20, BRAI-156): queue a ZIP of the
 * person's own data and download it here or from the notification / email. Links work 24 h and only
 * for them; one request per hour.
 */
export default function DownloadDataForm({ className = '' }: { className?: string }) {
    const [state, setState] = useState<DataExportState>({ exports: [], available_in: 0 });
    const [starting, setStarting] = useState(false);
    // the server's rule: a queued / running export older than an hour no longer blocks (a lost worker)
    const pending = state.exports.some((e) => (e.status === 'queued' || e.status === 'running') && !!e.created_at && Date.now() - new Date(e.created_at).getTime() < 3600_000);
    const waitMinutes = Math.ceil(state.available_in / 60);

    const load = useCallback(async () => {
        try {
            const { data } = await window.axios.get<DataExportState>(route('profile.data-export.index'));
            setState(data);
        } catch {
            // the list is a convenience; the notification and email still arrive
        }
    }, []);

    useEffect(() => { void load(); }, [load]);
    useEffect(() => {
        if (!pending) return;
        const timer = window.setInterval(() => void load(), 2000);
        return () => window.clearInterval(timer);
    }, [pending, load]);

    // refresh the "new copy in N minutes" text and re-enable the button when the hour is over
    useEffect(() => {
        if (pending || state.available_in <= 0) return;
        const timer = window.setTimeout(() => void load(), Math.min(state.available_in * 1000 + 1000, 60_000));
        return () => window.clearTimeout(timer);
    }, [pending, state.available_in, load]);

    const start = async () => {
        setStarting(true);
        try {
            const { data } = await window.axios.post<DataExportState>(route('profile.data-export.store'), {}, { headers: { Accept: 'application/json' } });
            setState(data);
            toast.success('Your data is being prepared. You’ll get a notification and an email when it’s ready.');
        } catch (error) {
            const response = (error as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } }).response;
            toast.error(response?.data?.errors?.export?.[0] ?? (response?.status === 429 ? 'Too many requests. Try again in a minute.' : response?.data?.message ?? 'The export could not be started.'));
            void load();
        } finally {
            setStarting(false);
        }
    };

    return (
        <div className={`space-y-4 ${className}`}>
            <p className="text-sm text-muted-foreground">
                A ZIP with the data Brainitive holds about you: your profile and settings, the issues, comments, pages, whiteboards, tasks,
                snippets, bookmarks and captures you created, your time entries, notifications, devices, connected calendars and apps, and the
                files you uploaded — as JSON with a README. Passwords, tokens and other people’s data are never included. The download link works
                for 24 hours, only for you.
            </p>
            <div className="flex flex-wrap items-center gap-3">
                <Button type="button" onClick={start} disabled={starting || pending || state.available_in > 0}>
                    {starting || pending ? <Loader2 className="h-4 w-4 animate-spin" /> : <FileArchive className="h-4 w-4" />}
                    {pending ? 'Preparing your data…' : 'Download my data'}
                </Button>
                {!pending && state.available_in > 0 && (
                    <span className="text-sm text-muted-foreground">You can request a new copy in {waitMinutes} {waitMinutes === 1 ? 'minute' : 'minutes'}.</span>
                )}
            </div>

            {state.exports.length > 0 && (
                <ul aria-label="Your data exports" aria-live="polite" className="divide-y rounded-md border text-sm">
                    {state.exports.map((e) => {
                        const status = e.status === 'ready' && e.expires_at && new Date(e.expires_at).getTime() <= Date.now() ? 'expired' : e.status;
                        return (
                            <li key={e.uuid} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                <Badge variant="outline" className={STATUS[status].tone}>{STATUS[status].label}</Badge>
                                {e.created_at && <span className="text-muted-foreground">{formatDistanceToNow(new Date(e.created_at), { addSuffix: true })}</span>}
                                {status === 'ready' && <span className="text-muted-foreground">· {size(e.file_size)}</span>}
                                {e.error && <span className="text-destructive">{e.error}</span>}
                                {status === 'ready' && e.download_url && (
                                    <Button asChild size="sm" variant="outline" className="ml-auto">
                                        <a href={e.download_url} download><Download className="h-4 w-4" /> Download</a>
                                    </Button>
                                )}
                                {status === 'ready' && e.expires_at && (
                                    <span className="w-full text-xs text-muted-foreground">Expires {formatDistanceToNow(new Date(e.expires_at), { addSuffix: true })}</span>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
