import { Head } from '@inertiajs/react';
import { GanttChart } from 'lucide-react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { PlanChart } from '@/components/plan/PlanChart';
import { PlanData, PlanSettings } from '@/components/plan/types';

interface Props {
    plan: {
        name: string;
        description: string | null;
        project: string | null;
        color: string | null;
        data: PlanData;
        settings: PlanSettings;
        updated_at: string | null;
    };
}

/**
 * Plan behind a share link: read-only, no login, zoom and filter work locally.
 */
export default function PlanPublic({ plan }: Props) {
    return (
        <div className="flex h-screen flex-col bg-background text-foreground">
            <Head title={plan.name} />
            <header className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b px-4 py-3 sm:px-6">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="grid size-9 shrink-0 place-items-center rounded-lg" style={{ background: plan.color || '#0E83DC' }}>
                        <GanttChart className="size-5 text-white" />
                    </span>
                    <div className="min-w-0">
                        {plan.project && <div className="truncate text-xs font-medium uppercase tracking-wide text-muted-foreground">{plan.project}</div>}
                        <h1 className="truncate text-lg font-semibold leading-tight">{plan.name}</h1>
                    </div>
                </div>
                <div className="flex items-center gap-3 text-xs text-muted-foreground">
                    {plan.updated_at && <span>Updated {new Date(plan.updated_at).toLocaleDateString(undefined, { dateStyle: 'medium' })}</span>}
                    <a href="/" className="flex items-center gap-1.5 hover:text-foreground"><ApplicationLogo className="h-5 w-auto" alt="Brainitive" />Made with Brainitive</a>
                </div>
            </header>
            {plan.description && <p className="shrink-0 px-4 pt-2 text-sm text-muted-foreground sm:px-6">{plan.description}</p>}
            <PlanChart doc={{ data: plan.data, settings: plan.settings }} className="min-h-0 flex-1" />
        </div>
    );
}
