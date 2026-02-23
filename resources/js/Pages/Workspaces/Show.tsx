import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { PageProps, Workspace, Project } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Plus, FolderKanban, LayoutGrid, ArrowRight } from 'lucide-react';

interface Props extends PageProps {
    workspace: Workspace & { projects: Project[] };
    userRole: string;
}

export default function WorkspacesShow({ auth, workspace, userRole }: Props) {
    const canManage = userRole === 'admin';
    const canCreateProjects = ['admin', 'editor'].includes(userRole);

    return (
        <AppLayout>
            <Head title={workspace.name} />

            <div className="p-8 max-w-6xl mx-auto">
                {/* Header */}
                <div className="flex items-center justify-between mb-10">
                    <div>
                        <div className="flex items-center gap-3 mb-2">
                            <h1 className="text-3xl font-bold tracking-tight">{workspace.name}</h1>
                            <Badge variant="outline" className="text-sm">
                                {userRole}
                            </Badge>
                        </div>
                        <p className="text-base text-muted-foreground">
                            {workspace.description || 'No description'}
                        </p>
                    </div>
                    {canCreateProjects && (
                        <Link href={route('projects.create', workspace.slug)}>
                            <Button size="lg" className="gap-2">
                                <Plus className="size-5" />
                                New Project
                            </Button>
                        </Link>
                    )}
                </div>

                {workspace.projects.length === 0 ? (
                    <Card className="border-dashed border-2">
                        <CardContent className="flex flex-col items-center justify-center py-20">
                            <div className="size-20 rounded-full bg-primary/10 flex items-center justify-center mb-6">
                                <LayoutGrid className="size-10 text-primary" />
                            </div>
                            <h3 className="text-xl font-semibold mb-2">
                                No projects yet
                            </h3>
                            <p className="text-muted-foreground text-center max-w-md mb-8 text-base">
                                Create your first project to start managing tasks and collaborating with your team.
                            </p>
                            {canCreateProjects && (
                                <Link href={route('projects.create', workspace.slug)}>
                                    <Button size="lg" className="gap-2">
                                        <Plus className="size-5" />
                                        Create Project
                                    </Button>
                                </Link>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                        {workspace.projects.map((project) => (
                            <Link
                                key={project.id}
                                href={route('projects.show', [workspace.slug, project.slug])}
                            >
                                <Card className="hover:shadow-lg hover:border-primary/50 transition-all cursor-pointer h-full group">
                                    <CardContent className="p-6">
                                        <div className="flex items-start gap-4 mb-4">
                                            <div
                                                className="size-12 rounded-xl flex items-center justify-center text-white font-bold text-sm shadow-sm"
                                                style={{ backgroundColor: project.color || '#6366f1' }}
                                            >
                                                {project.key}
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <h3 className="text-lg font-semibold truncate group-hover:text-primary transition-colors">
                                                    {project.name}
                                                </h3>
                                                <span className="text-sm text-muted-foreground">{project.key}</span>
                                            </div>
                                            <ArrowRight className="size-5 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity" />
                                        </div>
                                        <p className="text-sm text-muted-foreground line-clamp-2 mb-5 min-h-[2.5rem]">
                                            {project.description || 'No description'}
                                        </p>
                                        <div className="flex items-center gap-5 text-sm text-muted-foreground">
                                            <div className="flex items-center gap-2">
                                                <FolderKanban className="size-4" />
                                                <span className="font-medium">{project.boards_count || 0}</span>
                                                <span>boards</span>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
