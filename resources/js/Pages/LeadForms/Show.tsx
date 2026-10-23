import { useEffect, useMemo, useState } from 'react';
import { Head } from '@inertiajs/react';
import { BarChart3, ExternalLink, Kanban, PenSquare, Settings, Share2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { ProjectIcon } from '@/components/ProjectIcon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Project, Workspace } from '@/types';
import { LeadColumn, LeadFormPayload, LeadMember } from '@/lib/leadForms';
import LeadsBoard from '@/components/leadForms/LeadsBoard';
import FormBuilder from '@/components/leadForms/FormBuilder';
import SharePanel from '@/components/leadForms/SharePanel';
import AnalyticsPanel from '@/components/leadForms/AnalyticsPanel';
import SettingsPanel from '@/components/leadForms/SettingsPanel';

type Tab = 'leads' | 'builder' | 'share' | 'analytics' | 'settings';
const TABS: { id: Tab; label: string; icon: typeof Kanban }[] = [
    { id: 'leads', label: 'Leads', icon: Kanban },
    { id: 'builder', label: 'Builder', icon: PenSquare },
    { id: 'share', label: 'Share', icon: Share2 },
    { id: 'analytics', label: 'Analytics', icon: BarChart3 },
    { id: 'settings', label: 'Settings', icon: Settings },
];

interface Props {
    workspace: Workspace;
    project: Project;
    form: LeadFormPayload;
    columns: LeadColumn[];
    members: LeadMember[];
    can: { update: boolean; manageLeads: boolean; delete: boolean };
    tab?: string | null;
}

/**
 * A lead form (BRAI-93): its leads board, the builder, sharing (page + embed), analytics and settings.
 */
export default function Show({ workspace, project, form: initialForm, columns, members, can, tab: initialTab }: Props) {
    const [form, setForm] = useState(initialForm);
    const [tab, setTab] = useState<Tab>(() => (TABS.some((t) => t.id === initialTab) ? (initialTab as Tab) : initialForm.leads_count > 0 || !can.update ? 'leads' : 'builder'));
    const routeParams = useMemo<[string, string, string]>(() => [workspace.slug, project.slug, form.slug], [workspace.slug, project.slug, form.slug]);

    useEffect(() => setForm(initialForm), [initialForm]);

    const go = (next: Tab) => {
        setTab(next);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', next);
        window.history.replaceState(window.history.state, '', url);
    };

    const live = form.status === 'published' && !!form.public_url;
    const leadCount = columns.reduce((n, c) => n + c.leads.length, 0);

    return (
        <AppLayout>
            <Head title={`${form.name} - ${project.name}`} />

            <div className="flex h-full flex-col overflow-hidden">
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b bg-background/95 px-6 py-3 backdrop-blur">
                    <div className="flex min-w-0 items-center gap-3">
                        <ProjectIcon project={project} size="md" className="shadow-sm" />
                        <div className="min-w-0">
                            <h1 className="flex items-center gap-2 truncate text-lg font-semibold tracking-tight">
                                {form.name}
                                {live ? <Badge className="bg-emerald-600 hover:bg-emerald-600">Live</Badge> : <Badge variant="secondary">Draft</Badge>}
                            </h1>
                            <p className="text-xs text-muted-foreground">{project.name} • Lead form</p>
                        </div>
                    </div>

                    <nav className="flex items-center gap-1 rounded-lg bg-muted p-1" aria-label="Lead form sections">
                        {TABS.filter((t) => t.id !== 'settings' || can.update).map((t) => (
                            <button
                                key={t.id}
                                type="button"
                                onClick={() => go(t.id)}
                                className={cn(
                                    'flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                    tab === t.id ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                )}
                                aria-current={tab === t.id ? 'page' : undefined}
                            >
                                <t.icon className="size-4" />
                                <span className="hidden md:inline">{t.label}</span>
                                {t.id === 'leads' && leadCount > 0 && <span className="rounded-full bg-primary/10 px-1.5 text-[11px] text-primary">{leadCount}</span>}
                            </button>
                        ))}
                    </nav>

                    <div className="ml-auto flex items-center gap-2">
                        {live && form.public_url ? (
                            <Button variant="outline" size="sm" className="gap-1.5" asChild>
                                <a href={form.public_url} target="_blank" rel="noreferrer">
                                    <ExternalLink className="size-4" />
                                    Open form
                                </a>
                            </Button>
                        ) : (
                            can.update && (
                                <Button size="sm" onClick={() => go('share')}>
                                    Publish
                                </Button>
                            )
                        )}
                    </div>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    {tab === 'leads' && (
                        <LeadsBoard
                            columns={columns}
                            members={members}
                            routeParams={routeParams}
                            canManage={can.manageLeads}
                            canEditColumns={can.update}
                            isPublished={live}
                            onGoToShare={() => go('share')}
                        />
                    )}
                    {tab === 'builder' && <FormBuilder form={form} routeParams={routeParams} canUpdate={can.update} onSaved={setForm} />}
                    {tab === 'share' && <SharePanel form={form} routeParams={routeParams} canUpdate={can.update} />}
                    {tab === 'analytics' && <AnalyticsPanel routeParams={routeParams} />}
                    {tab === 'settings' && can.update && (
                        <SettingsPanel form={form} members={members} routeParams={routeParams} canUpdate={can.update} canDelete={can.delete} onSaved={setForm} />
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
