import AppLayout from '@/Layouts/AppLayout';
import { ProjectIcon } from '@/components/ProjectIcon';
import { ReleaseFormDialog, ReleaseProgressBar, ReleaseStatusBadge, ReleaseSummary, dueText, formatDay } from '@/components/releases/ReleaseBits';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { Project, Workspace } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ExternalLink, Globe, Plus, Rocket } from 'lucide-react';
import { useState } from 'react';

interface Props {
    workspace: Workspace;
    project: Project;
    releases: ReleaseSummary[];
    canManage: boolean;
    changelogUrl: string | null;
}

export default function ReleasesIndex({ workspace, project, releases, canManage, changelogUrl }: Props) {
    const [creating, setCreating] = useState(false);
    const groups = {
        unreleased: releases.filter((r) => r.status === 'unreleased'),
        released: releases.filter((r) => r.status === 'released'),
        archived: releases.filter((r) => r.status === 'archived'),
    };

    const list = (items: ReleaseSummary[], empty: string) => items.length === 0
        ? <p className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">{empty}</p>
        : (
            <ul className="divide-y rounded-lg border bg-card">
                {items.map((r) => (
                    <li key={r.id}>
                        <Link href={route('releases.show', [workspace.slug, project.slug, r.id])} className="grid gap-3 p-4 transition-colors hover:bg-muted/50 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_auto] sm:items-center">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="truncate font-semibold">{r.name}</span>
                                    <ReleaseStatusBadge release={r} />
                                    {r.published && <span title="Release notes are public"><Globe className="size-3.5 text-muted-foreground" /></span>}
                                </div>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {r.status === 'unreleased'
                                        ? [r.start_date && `Starts ${formatDay(r.start_date)}`, r.release_date ? `Release ${formatDay(r.release_date)}` : 'No release date', dueText(r)].filter(Boolean).join(' · ')
                                        : `Released ${formatDay(r.release_date) ?? ''}`}
                                </p>
                            </div>
                            <ReleaseProgressBar progress={r.progress} />
                            <span className="hidden text-sm text-muted-foreground sm:block">{r.progress.total} issue{r.progress.total === 1 ? '' : 's'}</span>
                        </Link>
                    </li>
                ))}
            </ul>
        );

    return (
        <AppLayout>
            <Head title={`Releases · ${project.name}`} />
            <div className="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6">
                <div className="mb-6 flex flex-wrap items-center gap-3">
                    <ProjectIcon project={project} size="md" />
                    <div className="min-w-0 flex-1">
                        <h1 className="flex items-center gap-2 text-xl font-semibold"><Rocket className="size-5" /> Releases</h1>
                        <p className="text-sm text-muted-foreground">Plan versions, track what’s in them and publish release notes.</p>
                    </div>
                    {changelogUrl && (
                        <Button variant="outline" asChild>
                            <a href={changelogUrl} target="_blank" rel="noopener noreferrer"><ExternalLink className="size-4" /> Public changelog</a>
                        </Button>
                    )}
                    {canManage && <Button onClick={() => setCreating(true)}><Plus className="size-4" /> Create release</Button>}
                </div>

                <Tabs defaultValue="unreleased">
                    <TabsList>
                        <TabsTrigger value="unreleased">Unreleased ({groups.unreleased.length})</TabsTrigger>
                        <TabsTrigger value="released">Released ({groups.released.length})</TabsTrigger>
                        <TabsTrigger value="archived">Archived ({groups.archived.length})</TabsTrigger>
                    </TabsList>
                    <TabsContent value="unreleased" className="mt-4">{list(groups.unreleased, canManage ? 'No planned releases. Create one and give issues a fix version.' : 'No planned releases.')}</TabsContent>
                    <TabsContent value="released" className="mt-4">{list(groups.released, 'Nothing released yet.')}</TabsContent>
                    <TabsContent value="archived" className="mt-4">{list(groups.archived, 'No archived releases.')}</TabsContent>
                </Tabs>
            </div>
            <ReleaseFormDialog open={creating} onOpenChange={setCreating} url={route('releases.store', [workspace.slug, project.slug])} method="post" />
        </AppLayout>
    );
}
