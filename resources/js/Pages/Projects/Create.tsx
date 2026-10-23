import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { PageProps, ProjectTemplateCategory, ProjectTemplateOption, Workspace } from '@/types';
import { Button } from '@/components/ui/button';
import TemplateGallery from '@/components/projects/TemplateGallery';

interface Props extends PageProps {
    workspace: Workspace;
    templates?: ProjectTemplateOption[];
    categories?: ProjectTemplateCategory[];
}

/**
 * "Create project" as a page (direct links); the same gallery opens as a popup from every
 * "New project" button (components/projects/CreateProjectDialog).
 */
export default function ProjectsCreate({ workspace, templates = [], categories = [] }: Props) {
    return (
        <AppLayout>
            <Head title="Create Project" />

            <div className="p-6">
                <div className="mx-auto max-w-6xl space-y-4">
                    <div className="flex items-end justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight">Create Project</h1>
                            <p className="text-muted-foreground">Start from a template or a blank project in {workspace.name}.</p>
                        </div>
                        <Link href={route('workspaces.show', workspace.slug)}>
                            <Button type="button" variant="outline">Cancel</Button>
                        </Link>
                    </div>
                    <TemplateGallery
                        variant="page"
                        workspace={workspace}
                        templates={templates}
                        categories={categories}
                    />
                </div>
            </div>
        </AppLayout>
    );
}
