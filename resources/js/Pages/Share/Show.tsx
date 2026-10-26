import ApplicationLogo from '@/components/ApplicationLogo';
import { safeHtmlProp } from '@/lib/sanitize';
import { Head } from '@inertiajs/react';
import { Link2Off } from 'lucide-react';
import PageIcon from '@/components/page/PageIcon';

interface Shared {
    type: 'doc' | 'issue';
    title: string;
    icon?: string | null;
    key?: string;
    html: string;
    status?: string | null;
    item_type?: string | null;
    priority?: string | null;
    start_date?: string | null;
    due_date?: string | null;
    updated_at?: string | null;
}

/** Read-only view of one shared doc or issue (ShareLinkController::show); no app chrome. */
export default function ShareShow({ shared }: { shared: Shared | null }) {
    if (!shared) {
        return (
            <div className="min-h-screen bg-background text-foreground flex flex-col items-center justify-center px-6 text-center">
                <Head title="Link not available">
                    <meta name="robots" content="noindex, nofollow" />
                </Head>
                <div className="mb-5 flex h-14 w-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
                    <Link2Off className="h-7 w-7" />
                </div>
                <h1 className="text-2xl font-bold mb-2">This link is no longer available</h1>
                <p className="text-muted-foreground max-w-sm">It was revoked or has expired, or the shared item was deleted. Ask the person who sent it for a new link.</p>
            </div>
        );
    }

    const meta = [
        shared.status && ['Status', shared.status],
        shared.item_type && ['Type', shared.item_type],
        shared.priority && ['Priority', shared.priority],
        shared.start_date && ['Start', shared.start_date],
        shared.due_date && ['Due', shared.due_date],
    ].filter(Boolean) as [string, string][];

    return (
        <div className="min-h-screen bg-background text-foreground">
            <Head title={shared.title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <header className="border-b px-6 py-3 flex items-center justify-between">
                <ApplicationLogo className="h-7 w-auto" />
                <span className="text-xs text-muted-foreground">Shared {shared.type === 'doc' ? 'doc' : 'issue'} · read-only</span>
            </header>
            <main className="mx-auto max-w-3xl px-6 py-10">
                {shared.key && <p className="font-mono text-sm text-muted-foreground mb-1">{shared.key}</p>}
                <h1 className="text-3xl font-bold tracking-tight mb-4">
                    {shared.icon && shared.type === 'doc' ? <PageIcon icon={shared.icon} className="mr-2" imageClassName="mr-2 inline-block size-8 align-[-0.15em]" /> : null}
                    {shared.title}
                </h1>
                {meta.length > 0 && (
                    <dl className="mb-8 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                        {meta.map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-xs text-muted-foreground">{label}</dt>
                                <dd className="font-medium">{value}</dd>
                            </div>
                        ))}
                    </dl>
                )}
                {shared.html ? (
                    <article className="rich-html prose prose-sm sm:prose-base dark:prose-invert max-w-none" dangerouslySetInnerHTML={safeHtmlProp(shared.html)} />
                ) : (
                    <p className="text-muted-foreground">No content.</p>
                )}
                {shared.updated_at && (
                    <p className="mt-12 text-xs text-muted-foreground">Last updated {new Date(shared.updated_at).toLocaleString()}</p>
                )}
            </main>
        </div>
    );
}
