import ApplicationLogo from '@/components/ApplicationLogo';
import { safeHtmlProp } from '@/lib/sanitize';
import { Head } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';

interface Entry {
    name: string;
    date: string | null;
    html: string | null;
    url: string;
}

interface Props {
    project: { name: string };
    releases: Entry[];
    changelogUrl: string | null;
    pagination?: { current: number; last: number; next: string | null; prev: string | null };
}

/** Published release notes of one release, or a project's public changelog. No app chrome. */
export default function ReleasesPublic({ project, releases, changelogUrl, pagination }: Props) {
    const single = !pagination;
    const title = single ? `${project.name} ${releases[0]?.name ?? ''} — release notes` : `${project.name} changelog`;

    return (
        <div className="min-h-screen bg-background text-foreground">
            <Head title={title} />
            <header className="border-b">
                <div className="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                    <div className="min-w-0">
                        <p className="text-xs uppercase tracking-wide text-muted-foreground">{single ? 'Release notes' : 'Changelog'}</p>
                        <p className="truncate text-lg font-semibold">{project.name}</p>
                    </div>
                    <a href="/" aria-label="Brainitive" className="shrink-0 opacity-80 hover:opacity-100"><ApplicationLogo className="h-7 w-auto" /></a>
                </div>
            </header>

            <main className="mx-auto max-w-3xl space-y-12 px-4 py-10 sm:px-6">
                {releases.map((r, i) => (
                    <article key={r.url} aria-labelledby={`release-${i}`}>
                        <div className="mb-4 flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b pb-3">
                            <h1 id={`release-${i}`} className="text-2xl font-bold">
                                {single ? r.name : <a href={r.url} className="hover:underline">{r.name}</a>}
                            </h1>
                            {r.date && <time dateTime={r.date} className="text-sm text-muted-foreground">{format(parseISO(r.date), 'd MMMM yyyy')}</time>}
                        </div>
                        <div className="rich-html prose prose-sm sm:prose-base dark:prose-invert max-w-none" dangerouslySetInnerHTML={safeHtmlProp(r.html)} />
                    </article>
                ))}

                {pagination && pagination.last > 1 && (
                    <nav className="flex justify-between text-sm" aria-label="Pages">
                        {pagination.prev ? <a href={pagination.prev} className="text-primary hover:underline">← Newer</a> : <span />}
                        <span className="text-muted-foreground">Page {pagination.current} of {pagination.last}</span>
                        {pagination.next ? <a href={pagination.next} className="text-primary hover:underline">Older →</a> : <span />}
                    </nav>
                )}

                {single && changelogUrl && (
                    <p className="text-sm"><a href={changelogUrl} className="text-primary hover:underline">All releases of {project.name} →</a></p>
                )}
            </main>
        </div>
    );
}
