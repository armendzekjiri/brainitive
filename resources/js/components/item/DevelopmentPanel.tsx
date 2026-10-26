import { Button } from '@/components/ui/button';
import { formatDistanceToNow } from 'date-fns';
import { Check, ChevronRight, Copy, GitBranch, GitCommitHorizontal, GitMerge, GitPullRequest, Rocket, Star } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

export interface DevLink {
    id: number;
    repository: string;
    identifier: string;
    title: string | null;
    url: string | null;
    state: string | null;
    author: string | null;
    occurred_at: string | null;
    /** pull requests: the one whose merge moves the card (merge transitions, BRAI-61) */
    primary?: boolean;
}

export interface Development {
    connected: boolean;
    branches: DevLink[];
    commits: DevLink[];
    commit_count: number;
    pull_requests: DevLink[];
    deployments: DevLink[];
    /** the board's merge transition target, when one is set */
    merge_column?: string | null;
}

const STATE_STYLES: Record<string, string> = {
    open: 'bg-green-500/15 text-green-700 dark:text-green-400',
    draft: 'bg-muted text-muted-foreground',
    merged: 'bg-violet-500/15 text-violet-700 dark:text-violet-400',
    closed: 'bg-red-500/15 text-red-700 dark:text-red-400',
    success: 'bg-green-500/15 text-green-700 dark:text-green-400',
    failure: 'bg-red-500/15 text-red-700 dark:text-red-400',
    error: 'bg-red-500/15 text-red-700 dark:text-red-400',
    in_progress: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    queued: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    pending: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    inactive: 'bg-muted text-muted-foreground',
    deleted: 'bg-muted text-muted-foreground',
};

const STATE_LABELS: Record<string, string> = {
    success: 'deployed', failure: 'failed', error: 'failed', in_progress: 'deploying', queued: 'queued', pending: 'pending',
    inactive: 'replaced', active: '', committed: '',
};

function StateBadge({ state }: { state: string | null }) {
    if (!state) return null;
    const label = STATE_LABELS[state] ?? state.replace('_', ' ');
    if (!label) return null;
    return <span className={`shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${STATE_STYLES[state] ?? 'bg-muted text-muted-foreground'}`}>{label}</span>;
}

function ago(iso: string | null) {
    return iso ? formatDistanceToNow(new Date(iso), { addSuffix: true }) : '';
}

/** One collapsible row ("2 branches") with its entries. */
function Group({ icon: Icon, label, links, summary, render }: {
    icon: typeof GitBranch;
    label: string;
    links: DevLink[];
    summary?: React.ReactNode;
    render: (link: DevLink) => React.ReactNode;
}) {
    const [open, setOpen] = useState(false);
    if (links.length === 0) return null;

    return (
        <div>
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-expanded={open}
                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted/80"
            >
                <Icon className="size-4 shrink-0 text-muted-foreground" />
                <span className="flex-1 truncate">{label}</span>
                {summary}
                <ChevronRight className={`size-3.5 shrink-0 text-muted-foreground transition-transform ${open ? 'rotate-90' : ''}`} />
            </button>
            {open && <ul className="mb-1 ml-8 space-y-1.5 border-l pl-3 pt-1">{links.map((l) => <li key={l.id} className="min-w-0 text-xs">{render(l)}</li>)}</ul>}
        </div>
    );
}

function ExternalTitle({ link, fallback }: { link: DevLink; fallback: string }) {
    const text = link.title || fallback;
    return link.url
        ? <a href={link.url} target="_blank" rel="noopener noreferrer" className="block truncate font-medium text-foreground hover:underline" title={text}>{text}</a>
        : <span className="block truncate font-medium" title={text}>{text}</span>;
}

function branchName(key: string, title: string) {
    const slug = title.toLowerCase().normalize('NFKD').replace(/[^\w\s-]/g, '').trim().replace(/[\s_-]+/g, '-').slice(0, 40).replace(/-+$/, '');
    return `feat/${key.toLowerCase()}${slug ? '-' + slug : ''}`;
}

export function DevelopmentPanel({ development, itemKey, itemTitle, settingsUrl, primaryUrl, canEdit = false }: {
    development: Development | null;
    itemKey: string;
    itemTitle: string;
    settingsUrl: string;
    /** PATCH endpoint to pick the primary pull request (boards.items.development.primary) */
    primaryUrl?: string;
    canEdit?: boolean;
}) {
    const [copied, setCopied] = useState<string | null>(null);
    // the server's answer to "make primary" until the next details load
    const [local, setLocal] = useState<Development | null>(development);
    useEffect(() => setLocal(development), [development]);
    const makePrimary = (link: DevLink) => {
        if (!primaryUrl) return;
        window.axios.patch(primaryUrl, { link_id: link.id })
            .then(({ data }: { data: { development: Development } }) => {
                setLocal(data.development);
                toast.success(`#${link.identifier} is now the primary pull request.`);
            })
            .catch(() => toast.error('Could not change the primary pull request.'));
    };
    const copy = async (what: string, text: string) => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(what);
            setTimeout(() => setCopied(null), 1500);
        } catch {
            toast.error('Copy failed.');
        }
    };

    const d = local;
    const branch = branchName(itemKey, itemTitle);
    const empty = !d || (d.branches.length + d.commits.length + d.pull_requests.length + d.deployments.length === 0);
    const latestPr = d?.pull_requests[0];
    const deployed = d?.deployments ?? [];

    return (
        <section aria-labelledby="dev-heading" className="border-t pt-3">
            <h3 id="dev-heading" className="mb-1 px-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Development</h3>

            {d && !empty && (
                <div className="space-y-0.5">
                    <Group icon={GitBranch} label={`${d.branches.length} branch${d.branches.length === 1 ? '' : 'es'}`} links={d.branches}
                        render={(l) => (
                            <div className="flex items-center gap-2">
                                <ExternalTitle link={l} fallback={l.identifier} />
                                <StateBadge state={l.state} />
                            </div>
                        )} />
                    <Group icon={GitCommitHorizontal} label={`${d.commit_count} commit${d.commit_count === 1 ? '' : 's'}`} links={d.commits}
                        render={(l) => (
                            <>
                                <ExternalTitle link={l} fallback={l.identifier.slice(0, 7)} />
                                <span className="text-muted-foreground"><code>{l.identifier.slice(0, 7)}</code> · {l.author ?? 'unknown'} · {ago(l.occurred_at)}</span>
                            </>
                        )} />
                    <Group icon={GitPullRequest} label={`${d.pull_requests.length} pull request${d.pull_requests.length === 1 ? '' : 's'}`} links={d.pull_requests}
                        summary={<StateBadge state={latestPr?.state ?? null} />}
                        render={(l) => (
                            <>
                                <div className="flex items-center gap-2">
                                    <ExternalTitle link={l} fallback={`#${l.identifier}`} />
                                    {l.primary && d.pull_requests.length > 1 && (
                                        <span className="shrink-0 rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary">Primary</span>
                                    )}
                                    <StateBadge state={l.state} />
                                </div>
                                <span className="text-muted-foreground">{l.repository}#{l.identifier} · {l.author ?? 'unknown'} · {ago(l.occurred_at)}</span>
                                {canEdit && primaryUrl && !l.primary && (
                                    <button type="button" onClick={() => makePrimary(l)} className="ml-1 inline-flex items-center gap-1 text-primary hover:underline">
                                        <Star className="size-3" /> Make primary
                                    </button>
                                )}
                            </>
                        )} />
                    {d.merge_column && d.pull_requests.length > 0 && (
                        <p className="flex items-center gap-1.5 px-2 py-1 text-xs text-muted-foreground">
                            <GitMerge className="size-3.5 shrink-0" /> Merging the primary pull request moves this to {d.merge_column}.
                        </p>
                    )}
                    <Group icon={Rocket} label={deployed.length === 1 ? deployed[0].identifier : `${deployed.length} environments`} links={deployed}
                        summary={deployed.length === 1 ? <StateBadge state={deployed[0].state} /> : undefined}
                        render={(l) => (
                            <>
                                <div className="flex items-center gap-2"><ExternalTitle link={l} fallback={l.identifier} /><StateBadge state={l.state} /></div>
                                <span className="text-muted-foreground">{l.repository} · {ago(l.occurred_at)}</span>
                            </>
                        )} />
                </div>
            )}

            {d && empty && (
                <p className="px-2 py-1 text-xs text-muted-foreground">
                    {d.connected
                        ? <>Mention <code className="rounded bg-muted px-1">{itemKey}</code> in a commit, branch or pull request to link it here.</>
                        : <>Link commits and deployments: <a href={settingsUrl} className="text-primary hover:underline">connect GitHub</a> in the project settings.</>}
                </p>
            )}

            <div className="mt-1 flex flex-wrap gap-1 px-1">
                <Button variant="ghost" size="sm" className="h-7 gap-1.5 px-2 text-xs text-muted-foreground hover:text-foreground" onClick={() => copy('branch', `git checkout -b ${branch}`)} title={`git checkout -b ${branch}`}>
                    {copied === 'branch' ? <Check className="size-3.5 text-green-600" /> : <GitBranch className="size-3.5" />} Copy branch command
                </Button>
                <Button variant="ghost" size="sm" className="h-7 gap-1.5 px-2 text-xs text-muted-foreground hover:text-foreground" onClick={() => copy('commit', `feat(${itemKey}): `)} title={`feat(${itemKey}): `}>
                    {copied === 'commit' ? <Check className="size-3.5 text-green-600" /> : <Copy className="size-3.5" />} Copy commit prefix
                </Button>
            </div>
        </section>
    );
}
