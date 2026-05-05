import { useMemo, useState } from 'react';
import axios from 'axios';
import { Link, usePage } from '@inertiajs/react';
import { Check, ChevronDown, ChevronUp, Circle, Rocket, X } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { PageProps, Workspace } from '@/types';

type ChecklistItem = {
    key: keyof NonNullable<PageProps['onboarding']>['checklist'];
    label: string;
    description: string;
    cta: string;
    href: (workspace?: Workspace) => string | null;
};

export function SetupChecklist() {
    const { onboarding, workspaces, currentWorkspace } = usePage<PageProps>().props;
    const [expanded, setExpanded] = useState(true);
    const [dismissed, setDismissed] = useState(false);
    const [dismissing, setDismissing] = useState(false);

    const anchor = currentWorkspace ?? workspaces?.[0];

    const items: ChecklistItem[] = useMemo(
        () => [
            {
                key: 'has_workspace',
                label: 'Create your first workspace',
                description: 'Workspaces group your projects, docs, and team.',
                cta: 'Create workspace',
                href: () => route('workspaces.create'),
            },
            {
                key: 'has_project',
                label: 'Create a project',
                description: 'Projects hold your boards, pages, and helpdesks.',
                cta: 'Open workspace',
                href: (w) => (w ? route('workspaces.show', w.slug) : null),
            },
            {
                key: 'has_board',
                label: 'Make your first board',
                description: 'Track tasks with kanban columns, priorities, and labels.',
                cta: 'Open workspace',
                href: (w) => (w ? route('workspaces.show', w.slug) : null),
            },
            {
                key: 'has_page',
                label: 'Write a page',
                description: 'Draft notes, specs, or meeting minutes in rich text.',
                cta: 'Open workspace',
                href: (w) => (w ? route('workspaces.show', w.slug) : null),
            },
            {
                key: 'has_teammate',
                label: 'Invite a teammate',
                description: 'Brainitive is better with people. Add one now.',
                cta: 'Invite',
                href: (w) => (w ? route('workspaces.edit', w.slug) : null),
            },
        ],
        [],
    );

    if (!onboarding || onboarding.checklist_dismissed || dismissed) return null;

    const completed = items.filter((it) => onboarding.checklist[it.key]).length;
    const total = items.length;
    if (completed >= total) return null;

    const handleDismiss = async () => {
        setDismissing(true);
        setDismissed(true);
        try {
            await axios.post(route('onboarding.checklist-dismissed'));
        } catch {
            // UI is already dismissed for this session; will reappear on reload if save fails.
        } finally {
            setDismissing(false);
        }
    };

    return (
        <Card className="mb-6 border-primary/20 bg-gradient-to-br from-primary/5 via-background to-background">
            <CardContent className="p-5">
                <div className="flex items-start gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <Rocket className="size-5" />
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                            <h3 className="text-base font-semibold">Get started with Brainitive</h3>
                            <span className="text-xs text-muted-foreground">
                                {completed} / {total}
                            </span>
                        </div>
                        <div className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                className="h-full bg-primary transition-all"
                                style={{ width: `${(completed / total) * 100}%` }}
                            />
                        </div>
                    </div>
                    <div className="flex items-center gap-1 shrink-0">
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            onClick={() => setExpanded((e) => !e)}
                            aria-label={expanded ? 'Collapse checklist' : 'Expand checklist'}
                        >
                            {expanded ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            onClick={handleDismiss}
                            disabled={dismissing}
                            aria-label="Dismiss checklist"
                            title="Dismiss"
                        >
                            <X className="size-4" />
                        </Button>
                    </div>
                </div>
                {expanded && (
                    <ul className="mt-4 grid gap-1.5">
                        {items.map((item) => {
                            const done = onboarding.checklist[item.key];
                            const href = item.href(anchor);
                            return (
                                <li
                                    key={item.key}
                                    className={cn(
                                        'flex items-center gap-3 rounded-md px-2 py-2 transition-colors',
                                        done ? 'opacity-60' : 'hover:bg-accent/40',
                                    )}
                                >
                                    <div
                                        className={cn(
                                            'flex size-5 shrink-0 items-center justify-center rounded-full border',
                                            done
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'border-muted-foreground/30 text-muted-foreground',
                                        )}
                                    >
                                        {done ? <Check className="size-3.5" /> : <Circle className="size-2.5 fill-current" />}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <div className={cn('text-sm font-medium', done && 'line-through')}>{item.label}</div>
                                        <div className="text-xs text-muted-foreground">{item.description}</div>
                                    </div>
                                    {!done && href && (
                                        <Link href={href}>
                                            <Button variant="outline" size="sm" className="h-8">
                                                {item.cta}
                                            </Button>
                                        </Link>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
