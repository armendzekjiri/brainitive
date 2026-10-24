import { FileText, Folder, GanttChart, LayoutGrid, LifeBuoy, List, Magnet, PencilRuler } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { Board, Helpdesk, Page as PageType, PlanSummary, Project, SidebarPin, TaskList } from '@/types';

/** A project as the sidebar payload carries it (HandleInertiaRequests::sidebarWorkspace). */
export type SidebarProject = Project & {
    boards: Board[];
    task_lists?: TaskList[];
    plans?: PlanSummary[];
    pages?: PageType[];
    helpdesks?: Helpdesk[];
};

export type PinKind = 'board' | 'list' | 'plan' | 'helpdesk' | 'lead_form' | 'page' | 'whiteboard' | 'folder';

export interface ResolvedPin {
    pin: SidebarPin;
    name: string;
    href: string;
    kind: PinKind;
    /** Page icon: an emoji or an image URL. */
    pageIcon?: string | null;
}

function findPage(pages: PageType[] | undefined, id: number): PageType | null {
    for (const page of pages ?? []) {
        if (page.id === id) return page;
        const child = findPage(page.children, id);
        if (child) return child;
    }
    return null;
}

/**
 * The project's pins, resolved against the (permission-filtered) sidebar payload. Pins of items
 * that were deleted or that the user can no longer see resolve to nothing and are skipped.
 */
export function resolvePins(workspaceSlug: string, project: SidebarProject, pins: SidebarPin[]): ResolvedPin[] {
    const params = [workspaceSlug, project.slug];
    const out: ResolvedPin[] = [];
    for (const pin of pins) {
        switch (pin.type) {
            case 'board': {
                const board = project.boards?.find((b) => b.id === pin.id);
                if (board) out.push({ pin, name: board.name, href: route('boards.show', [...params, board.slug]), kind: 'board' });
                break;
            }
            case 'list': {
                const list = project.task_lists?.find((l) => l.id === pin.id);
                if (list) out.push({ pin, name: list.name, href: route('task-lists.show', [...params, list.slug]), kind: 'list' });
                break;
            }
            case 'plan': {
                const plan = project.plans?.find((p) => p.id === pin.id);
                if (plan) out.push({ pin, name: plan.name, href: route('plans.show', [...params, plan.slug]), kind: 'plan' });
                break;
            }
            case 'helpdesk': {
                const helpdesk = project.helpdesks?.find((h) => h.id === pin.id);
                if (helpdesk) out.push({ pin, name: helpdesk.name, href: route('helpdesks.show', [...params, helpdesk.slug]), kind: 'helpdesk' });
                break;
            }
            case 'lead_form': {
                const form = project.lead_forms?.find((f) => f.id === pin.id);
                if (form) out.push({ pin, name: form.name, href: route('lead-forms.show', [...params, form.slug]), kind: 'lead_form' });
                break;
            }
            case 'page': {
                const page = findPage(project.pages, pin.id);
                if (page) {
                    out.push({
                        pin,
                        name: page.name,
                        href: route('pages.show', [...params, page.uuid]),
                        kind: page.type === 'folder' ? 'folder' : page.is_whiteboard ? 'whiteboard' : 'page',
                        pageIcon: page.icon,
                    });
                }
                break;
            }
        }
    }
    return out;
}

const KIND_ICONS = { board: LayoutGrid, list: List, plan: GanttChart, helpdesk: LifeBuoy, lead_form: Magnet, whiteboard: PencilRuler, folder: Folder, page: FileText };

/** The icon of a pinned item (page emoji / image when the page has one). */
export function PinIcon({ entry, className }: { entry: Pick<ResolvedPin, 'kind' | 'pageIcon'>; className?: string }) {
    const icon = entry.pageIcon;
    if ((entry.kind === 'page' || entry.kind === 'folder') && icon) {
        if (icon.startsWith('data:') || icon.startsWith('http') || icon.startsWith('/')) {
            return <img src={icon} className={cn('size-4 shrink-0 rounded object-cover', className)} alt="" />;
        }
        return <span className={cn('flex size-4 shrink-0 items-center justify-center text-sm leading-none', className)} aria-hidden="true">{icon}</span>;
    }
    const Icon = KIND_ICONS[entry.kind];
    return <Icon className={cn('size-4 shrink-0', entry.kind === 'whiteboard' && 'text-brand-slate', className)} />;
}
