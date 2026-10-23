import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import type { ProjectTemplateCategory, ProjectTemplateOption } from '@/types';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import TemplateGallery from '@/components/projects/TemplateGallery';

const OPEN_EVENT = 'brainitive:create-project';

/** Open the "Create project" gallery from anywhere (it is mounted once in AppLayout). */
export function openCreateProject(): void {
    window.dispatchEvent(new CustomEvent(OPEN_EVENT));
}

type Catalog = { templates: ProjectTemplateOption[]; categories: ProjectTemplateCategory[] };

/**
 * The Jira-style "Create project" popup (BRAI-91). The catalog is loaded each time it opens,
 * so newly saved workspace templates show up without a page reload.
 */
export default function CreateProjectDialog({ workspace }: { workspace: { slug: string; name: string } | null }) {
    const [open, setOpen] = useState(false);
    const [session, setSession] = useState(0);
    const [catalog, setCatalog] = useState<Catalog | null>(null);

    useEffect(() => {
        const show = () => {
            setCatalog(null); // never show the previous list as if it were current
            setSession((n) => n + 1);
            setOpen(true);
        };
        window.addEventListener(OPEN_EVENT, show);
        return () => window.removeEventListener(OPEN_EVENT, show);
    }, []);

    useEffect(() => {
        if (!open || !workspace) return;
        let cancelled = false;
        window.axios
            .get(route('project-templates.catalog', workspace.slug))
            .then(({ data }: { data: Catalog }) => { if (!cancelled) setCatalog(data); })
            .catch(() => {
                if (cancelled) return;
                // Fall back to the full page (it shows its own error, e.g. no permission)
                setOpen(false);
                router.visit(route('projects.create', workspace.slug));
            });
        return () => { cancelled = true; };
    }, [open, session, workspace?.slug]);

    if (!workspace) return null;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {/* A click next to the popup must not throw away a half-filled form (Esc and × still close it) */}
            <DialogContent className="flex h-[88vh] w-[95vw] max-w-6xl flex-col gap-0 overflow-hidden p-0" onInteractOutside={(e) => e.preventDefault()}>
                <div className="flex items-center gap-3 border-b px-5 py-3 pr-12">
                    <DialogTitle className="text-base">Create project</DialogTitle>
                    <DialogDescription className="hidden text-xs sm:block">Start from a template or a blank project in {workspace.name}.</DialogDescription>
                </div>
                <div className="flex min-h-0 flex-1 flex-col">
                    <TemplateGallery
                        key={session}
                        variant="dialog"
                        workspace={workspace}
                        templates={catalog?.templates ?? []}
                        categories={catalog?.categories ?? []}
                        loading={catalog === null}
                        onCancel={() => setOpen(false)}
                        onDeleted={(id) => setCatalog((current) => current && { ...current, templates: current.templates.filter((t) => t.id !== id) })}
                    />
                </div>
            </DialogContent>
        </Dialog>
    );
}
