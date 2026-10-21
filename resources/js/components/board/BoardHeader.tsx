import { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { Calendar, GanttChart, Kanban, LineChart, List, ListOrdered, Plus, Settings } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Board, Project, Workspace } from '@/types';
import { cn } from '@/lib/utils';

export type BoardView = 'kanban' | 'list' | 'gantt' | 'calendar';
export type BoardTab = BoardView | 'backlog' | 'reports';

const VIEW_TABS: { key: BoardView; label: string; icon: typeof Kanban }[] = [
    { key: 'kanban', label: 'Board', icon: Kanban },
    { key: 'list', label: 'List', icon: List },
    { key: 'gantt', label: 'Timeline', icon: GanttChart },
    { key: 'calendar', label: 'Calendar', icon: Calendar },
];

/**
 * Jira-style board header shared by the board, backlog and reports pages: breadcrumb,
 * title, actions and the view tabs (Backlog / Reports only on Scrum boards).
 */
export function BoardHeader({
    workspace,
    project,
    board,
    activeTab,
    onSelectView,
    onCreate,
    actions,
}: {
    workspace: Workspace;
    project: Project;
    board: Board;
    activeTab: BoardTab;
    /** On the board page views switch in place; elsewhere they link to the board. */
    onSelectView?: (view: BoardView) => void;
    onCreate?: () => void;
    actions?: ReactNode;
}) {
    const params = [workspace.slug, project.slug, board.slug];
    const isScrum = board.type === 'scrum';

    const tabClass = (active: boolean) => cn(
        'relative -mb-px inline-flex items-center gap-1.5 pb-2 pt-1 text-[14px] font-medium border-b-2 transition-colors whitespace-nowrap',
        active ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border',
    );

    return (
        <div className="px-6 pt-4 shrink-0">
            <nav className="flex items-center gap-1.5 text-[13px] text-muted-foreground" aria-label="Breadcrumb">
                <Link href={route('projects.show', [workspace.slug, project.slug])} className="hover:text-foreground hover:underline">Projects</Link>
                <span aria-hidden>/</span>
                <Link href={route('projects.show', [workspace.slug, project.slug])} className="inline-flex items-center gap-1.5 hover:text-foreground hover:underline">
                    <span className="size-4 rounded-[3px] grid place-items-center" style={{ background: project.color || '#0E83DC' }}>
                        <Kanban className="size-2.5 text-white" />
                    </span>
                    {project.name}
                </Link>
            </nav>
            <div className="flex items-center justify-between gap-4 mt-2">
                <h1 className="m-0 text-2xl font-semibold tracking-[-0.01em] text-foreground truncate">
                    {activeTab === 'backlog' ? 'Backlog' : activeTab === 'reports' ? 'Reports' : board.name}
                </h1>
                <div className="flex items-center gap-2">
                    {actions}
                    <Link href={route('boards.edit', params)}>
                        <Button variant="ghost" size="icon" className="size-8 text-muted-foreground" title="Board settings">
                            <Settings className="size-4" />
                        </Button>
                    </Link>
                    {onCreate && (
                        <Button size="sm" className="h-8 gap-1.5" onClick={onCreate}>
                            <Plus className="size-4" />Create
                        </Button>
                    )}
                </div>
            </div>

            <div className="flex items-center gap-4 mt-3 border-b border-border overflow-x-auto" role="tablist">
                {isScrum && (
                    <Link href={route('boards.backlog', params)} role="tab" aria-selected={activeTab === 'backlog'} className={tabClass(activeTab === 'backlog')}>
                        <ListOrdered className="size-3.5" />Backlog
                    </Link>
                )}
                {VIEW_TABS.map(({ key, label, icon: Icon }) => {
                    const content = <><Icon className="size-3.5" />{isScrum && key === 'kanban' ? 'Active sprint' : label}</>;
                    return onSelectView ? (
                        <button key={key} type="button" role="tab" aria-selected={activeTab === key} onClick={() => onSelectView(key)} className={tabClass(activeTab === key)}>
                            {content}
                        </button>
                    ) : (
                        <Link key={key} href={route('boards.show', [...params, { view: key }])} role="tab" aria-selected={activeTab === key} className={tabClass(activeTab === key)}>
                            {content}
                        </Link>
                    );
                })}
                {isScrum && (
                    <Link href={route('boards.reports', params)} role="tab" aria-selected={activeTab === 'reports'} className={tabClass(activeTab === 'reports')}>
                        <LineChart className="size-3.5" />Reports
                    </Link>
                )}
            </div>
        </div>
    );
}
