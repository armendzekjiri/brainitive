import type { ProjectTemplateSection, ProjectTemplateSummary } from '@/types';
import { FileText, GanttChart, Kanban, ListChecks, Tag, SlidersHorizontal } from 'lucide-react';

export const SECTION_LABELS: Record<ProjectTemplateSection, string> = {
    issues: 'Sample issues',
    pages: 'Docs',
    task_lists: 'Task lists',
    plans: 'Plans',
};

/** Which leave-out-able sections a template actually has. */
export function availableSections(summary: ProjectTemplateSummary): ProjectTemplateSection[] {
    return (Object.keys(SECTION_LABELS) as ProjectTemplateSection[]).filter((section) => {
        switch (section) {
            case 'issues': return summary.issues > 0;
            case 'pages': return summary.docs + summary.folders > 0;
            case 'task_lists': return summary.task_lists.length > 0;
            case 'plans': return summary.plans.length > 0;
        }
    });
}

const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;

/** One line for template cards: "1 board · 7 issues · 2 docs". */
export function summaryLine(summary: ProjectTemplateSummary): string {
    return [
        plural(summary.boards.length, 'board'),
        summary.issues ? plural(summary.issues, 'issue') : null,
        summary.docs ? plural(summary.docs, 'doc') : null,
        summary.task_lists.length ? plural(summary.task_lists.length, 'list') : null,
        summary.plans.length ? plural(summary.plans.length, 'plan') : null,
    ].filter(Boolean).join(' · ');
}

/**
 * What a template brings (or would capture): boards with their columns, then the extras.
 * Sections left out are greyed and struck through.
 */
export default function TemplateSummary({ summary, include }: { summary: ProjectTemplateSummary; include?: ProjectTemplateSection[] }) {
    const off = (section: ProjectTemplateSection) => include !== undefined && !include.includes(section);
    const row = 'flex items-start gap-2.5 text-sm';
    const muted = 'text-muted-foreground line-through opacity-60';

    return (
        <div className="space-y-3">
            {summary.boards.map((board, index) => (
                <div key={`${board.name}-${index}`} className={row}>
                    <Kanban className="size-4 mt-0.5 shrink-0 text-primary" />
                    <div className="min-w-0">
                        <p className="font-medium">
                            {board.name}
                            <span className="ml-1.5 text-xs font-normal text-muted-foreground">{board.type === 'scrum' ? 'Scrum' : 'Kanban'}</span>
                        </p>
                        <div className="mt-1 flex flex-wrap gap-1">
                            {board.columns.map((column, i) => (
                                <span key={`${column}-${i}`} className="rounded-[4px] border bg-muted/50 px-1.5 py-0.5 text-[11px] text-muted-foreground">{column}</span>
                            ))}
                        </div>
                        {board.issues > 0 && <p className={`mt-1 text-xs ${off('issues') ? muted : 'text-muted-foreground'}`}>{plural(board.issues, 'starter issue')}</p>}
                    </div>
                </div>
            ))}
            {summary.docs + summary.folders > 0 && (
                <div className={`${row} ${off('pages') ? muted : ''}`}>
                    <FileText className="size-4 mt-0.5 shrink-0 text-muted-foreground" />
                    <span>{plural(summary.docs, 'doc')}{summary.folders ? ` in ${plural(summary.folders, 'folder')}` : ''}</span>
                </div>
            )}
            {summary.task_lists.length > 0 && (
                <div className={`${row} ${off('task_lists') ? muted : ''}`}>
                    <ListChecks className="size-4 mt-0.5 shrink-0 text-muted-foreground" />
                    <span>{summary.task_lists.map((list) => `${list.name} (${list.tasks})`).join(', ')}</span>
                </div>
            )}
            {summary.plans.length > 0 && (
                <div className={`${row} ${off('plans') ? muted : ''}`}>
                    <GanttChart className="size-4 mt-0.5 shrink-0 text-muted-foreground" />
                    <span>{summary.plans.join(', ')}</span>
                </div>
            )}
            {(summary.labels > 0 || summary.item_types.length > 0) && (
                <div className={row}>
                    <Tag className="size-4 mt-0.5 shrink-0 text-muted-foreground" />
                    <span className="text-muted-foreground">
                        {[
                            summary.labels ? plural(summary.labels, 'label') : null,
                            summary.item_types.length ? `types: ${summary.item_types.join(', ')}` : null,
                        ].filter(Boolean).join(' · ')}
                    </span>
                </div>
            )}
            {summary.fields.length > 0 && (
                <div className={row}>
                    <SlidersHorizontal className="size-4 mt-0.5 shrink-0 text-muted-foreground" />
                    <span className="text-muted-foreground">Fields: {summary.fields.join(', ')}</span>
                </div>
            )}
        </div>
    );
}
