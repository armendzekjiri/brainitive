import { useEffect, useId, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Lock, PinOff, RotateCcw } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import {
    CUSTOMIZE_SIDEBAR_EVENT,
    SIDEBAR_GROUPS,
    movePin,
    projectPreferences,
    resetSidebar,
    setGroupVisible,
    setNavHidden,
    setPinnedOnly,
    togglePin,
    useSidebarPreferences,
} from '@/lib/sidebarPreferences';
import type { SidebarNavKey } from '@/types';
import { ProjectIcon } from '@/components/ProjectIcon';
import { useSidebarNavItems } from './navItems';
import { PinIcon, resolvePins, type SidebarProject } from './pins';

/** An accessible on/off switch row (role="switch", named by its label). */
function SwitchRow({
    label,
    hint,
    checked,
    disabled,
    onChange,
    icon,
}: {
    label: string;
    hint?: string;
    checked: boolean;
    disabled?: boolean;
    onChange?: (checked: boolean) => void;
    icon?: React.ReactNode;
}) {
    const id = useId();
    return (
        <li className="flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-muted/50">
            {icon && <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">{icon}</span>}
            <div className="min-w-0 flex-1">
                <label htmlFor={id} className={cn('block text-sm font-medium', disabled ? 'text-muted-foreground' : 'cursor-pointer')}>{label}</label>
                {hint && <p className="truncate text-xs text-muted-foreground">{hint}</p>}
            </div>
            {disabled ? (
                <span className="inline-flex items-center gap-1 text-[11px] text-muted-foreground"><Lock className="size-3" />Always shown</span>
            ) : (
                <button
                    id={id}
                    type="button"
                    role="switch"
                    aria-checked={checked}
                    onClick={() => onChange?.(!checked)}
                    className={cn(
                        'relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                        checked ? 'bg-primary' : 'bg-input',
                    )}
                >
                    <span className={cn('inline-block size-4 rounded-full bg-background shadow transition-transform', checked ? 'translate-x-4' : 'translate-x-0.5')} />
                </button>
            )}
        </li>
    );
}

function SectionTitle({ id, children }: { id: string; children: React.ReactNode }) {
    return <h3 id={id} className="mb-1 px-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{children}</h3>;
}

/**
 * "Customize sidebar" (BRAI-106): which top-level entries and project groups the sidebar shows,
 * "show only pinned" and the pinned items of the active project, and "Reset to default".
 * Opens on `openCustomizeSidebar()` (account menu, the pencil at the sidebar bottom, ⌘K).
 */
export function CustomizeSidebarSheet({ workspaceSlug, project }: { workspaceSlug?: string; project?: SidebarProject | null }) {
    const [open, setOpen] = useState(false);
    const prefs = useSidebarPreferences();
    const navItems = useSidebarNavItems();

    useEffect(() => {
        const handler = () => setOpen(true);
        window.addEventListener(CUSTOMIZE_SIDEBAR_EVENT, handler);
        return () => window.removeEventListener(CUSTOMIZE_SIDEBAR_EVENT, handler);
    }, []);

    const projectPrefs = project ? projectPreferences(prefs, project.id) : null;
    const pinned = project && projectPrefs && workspaceSlug ? resolvePins(workspaceSlug, project, projectPrefs.pins) : [];
    const pinnedListRef = useRef<HTMLUListElement>(null);

    /** Move a pin one step and keep focus on its row (the other arrow once it reaches an end). */
    const move = (index: number, step: -1 | 1) => {
        if (!project) return;
        const key = `${pinned[index].pin.type}-${pinned[index].pin.id}`;
        movePin(project.id, pinned.map((entry) => entry.pin), index, index + step);
        requestAnimationFrame(() => {
            const row = pinnedListRef.current?.querySelector(`[data-pin="${key}"]`);
            const target = row?.querySelector<HTMLButtonElement>(`[data-move="${step}"]:not(:disabled)`)
                ?? row?.querySelector<HTMLButtonElement>('[data-move]:not(:disabled)');
            target?.focus();
        });
    };
    const isDefault = prefs.hidden_nav.length === 0 && Object.keys(prefs.projects).length === 0;

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetContent side="left" className="flex w-full flex-col gap-0 p-0 sm:max-w-sm">
                <SheetHeader className="border-b px-5 pb-4 pt-5 text-left">
                    <SheetTitle>Customize sidebar</SheetTitle>
                    <SheetDescription>
                        Choose what your sidebar shows. Hidden entries stay on the project page and in search (⌘K).
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-6 overflow-y-auto px-3 py-4">
                    <section aria-labelledby="customize-nav-title">
                        <SectionTitle id="customize-nav-title">Navigation</SectionTitle>
                        <ul className="space-y-0.5">
                            {navItems.map((item) => (
                                <SwitchRow
                                    key={item.key}
                                    label={item.label}
                                    hint={item.hint}
                                    icon={<item.icon className="size-4" />}
                                    checked={!item.hideable || !prefs.hidden_nav.includes(item.key as SidebarNavKey)}
                                    disabled={!item.hideable}
                                    onChange={(visible) => setNavHidden(item.key as SidebarNavKey, !visible)}
                                />
                            ))}
                        </ul>
                    </section>

                    {project && projectPrefs && (
                        <section aria-labelledby="customize-project-title">
                            <SectionTitle id="customize-project-title">
                                <span className="inline-flex items-center gap-1.5 normal-case tracking-normal">
                                    <ProjectIcon project={project} size="sm" />
                                    <span className="truncate">{project.name}</span>
                                </span>
                            </SectionTitle>
                            <ul className="space-y-0.5">
                                <SwitchRow
                                    label="Show only pinned"
                                    hint="Collapse the groups into a link to the project page"
                                    checked={projectPrefs.pinned_only}
                                    onChange={(value) => setPinnedOnly(project.id, value)}
                                />
                            </ul>

                            <p className="mb-1 mt-3 px-2 text-xs font-medium text-muted-foreground">Project tools</p>
                            <ul className={cn('grid grid-cols-1 gap-0.5 min-[420px]:grid-cols-2', projectPrefs.pinned_only && 'opacity-60')} aria-label="Project tools">
                                {SIDEBAR_GROUPS.filter((group) => group.section === 'tools').map((group) => (
                                    <SwitchRow
                                        key={group.key}
                                        label={group.label}
                                        checked={projectPrefs.groups[group.key]}
                                        onChange={(visible) => setGroupVisible(project.id, group.key, visible)}
                                    />
                                ))}
                            </ul>

                            <p className="mb-1 mt-3 px-2 text-xs font-medium text-muted-foreground">Groups</p>
                            <ul className={cn('grid grid-cols-1 gap-0.5 min-[420px]:grid-cols-2', projectPrefs.pinned_only && 'opacity-60')}>
                                {SIDEBAR_GROUPS.filter((group) => group.section === 'content').map((group) => (
                                    <SwitchRow
                                        key={group.key}
                                        label={group.label}
                                        checked={projectPrefs.groups[group.key]}
                                        onChange={(visible) => setGroupVisible(project.id, group.key, visible)}
                                    />
                                ))}
                            </ul>

                            <p className="mb-1 mt-3 px-2 text-xs font-medium text-muted-foreground">Pinned</p>
                            {pinned.length === 0 ? (
                                <p className="rounded-lg border border-dashed px-3 py-3 text-xs text-muted-foreground">
                                    Nothing pinned yet. Hover a board, list or page in the sidebar or on the{' '}
                                    {workspaceSlug ? (
                                        <Link href={route('projects.show', [workspaceSlug, project.slug])} className="underline hover:text-foreground" onClick={() => setOpen(false)}>project page</Link>
                                    ) : 'project page'}{' '}
                                    and click the pin.
                                </p>
                            ) : (
                                <ul ref={pinnedListRef} className="space-y-0.5" aria-label="Pinned items">
                                    {pinned.map((entry, index) => (
                                        <li key={`${entry.pin.type}-${entry.pin.id}`} data-pin={`${entry.pin.type}-${entry.pin.id}`} className="flex items-center gap-2.5 rounded-lg px-2 py-1.5 hover:bg-muted/50">
                                            <PinIcon entry={entry} className="text-muted-foreground" />
                                            <span className="min-w-0 flex-1 truncate text-sm">{entry.name}</span>
                                            {/* reorder (BRAI-284): the keyboard alternative to dragging in the sidebar */}
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-7 text-muted-foreground"
                                                aria-label={`Move ${entry.name} up`}
                                                title="Move up"
                                                data-move="-1"
                                                disabled={index === 0}
                                                onClick={() => move(index, -1)}
                                            >
                                                <ChevronUp className="size-3.5" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-7 text-muted-foreground"
                                                aria-label={`Move ${entry.name} down`}
                                                title="Move down"
                                                data-move="1"
                                                disabled={index === pinned.length - 1}
                                                onClick={() => move(index, 1)}
                                            >
                                                <ChevronDown className="size-3.5" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-7 text-muted-foreground"
                                                aria-label={`Unpin ${entry.name}`}
                                                onClick={() => togglePin(project.id, entry.pin)}
                                            >
                                                <PinOff className="size-3.5" />
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    )}
                </div>

                <SheetFooter className="flex-row items-center justify-between gap-2 border-t px-5 py-3 sm:justify-between">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="gap-1.5 text-muted-foreground"
                        disabled={isDefault}
                        onClick={() => {
                            resetSidebar();
                            toast.success('Sidebar reset to default');
                        }}
                    >
                        <RotateCcw className="size-3.5" />
                        Reset to default
                    </Button>
                    <Button size="sm" onClick={() => setOpen(false)}>Done</Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
