import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { PageProps, Workspace } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Plus, Users, FolderKanban, ArrowRight } from 'lucide-react';

interface Props extends PageProps {
    workspaces: Workspace[];
    isInstanceAdmin: boolean;
}

// Generate consistent colors for workspace icons
function getWorkspaceColor(name: string): string {
    const colors = [
        'bg-blue-600',
        'bg-purple-600',
        'bg-teal-600',
        'bg-orange-500',
        'bg-pink-600',
        'bg-indigo-600',
        'bg-green-600',
        'bg-red-500',
    ];
    return colors[name.charCodeAt(0) % colors.length];
}

export default function WorkspacesIndex({ auth, workspaces, isInstanceAdmin }: Props) {
    return (
        <AppLayout>
            <Head title="Workspaces" />

            <div className="p-8 max-w-6xl mx-auto">
                {/* Header */}
                <div className="flex items-center justify-between mb-10">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight mb-1">Workspaces</h1>
                        <p className="text-base text-muted-foreground">
                            Manage your workspaces and access your projects
                        </p>
                    </div>
                    <Link href={route('workspaces.create')}>
                        <Button size="lg" className="gap-2">
                            <Plus className="size-5" />
                            New Workspace
                        </Button>
                    </Link>
                </div>

                {workspaces.length === 0 ? (
                    <Card className="border-dashed border-2">
                        <CardContent className="flex flex-col items-center justify-center py-20">
                            <div className="size-20 rounded-full bg-primary/10 flex items-center justify-center mb-6">
                                <FolderKanban className="size-10 text-primary" />
                            </div>
                            <h3 className="text-xl font-semibold mb-2">
                                No workspaces yet
                            </h3>
                            <p className="text-muted-foreground text-center max-w-md mb-8 text-base">
                                {isInstanceAdmin
                                    ? "Create your first workspace to organize your projects and collaborate with your team."
                                    : "You haven't been added to any workspaces yet. Contact an administrator to get access."}
                            </p>
                            <Link href={route('workspaces.create')}>
                                <Button size="lg" className="gap-2">
                                    <Plus className="size-5" />
                                    Create Workspace
                                </Button>
                            </Link>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                        {workspaces.map((workspace) => (
                            <Link
                                key={workspace.id}
                                href={route('workspaces.show', workspace.slug)}
                            >
                                <Card className="hover:shadow-lg hover:border-primary/50 transition-all cursor-pointer h-full group">
                                    <CardContent className="p-6">
                                        <div className="flex items-start gap-4 mb-4">
                                            <div className={`size-12 rounded-xl ${getWorkspaceColor(workspace.name)} flex items-center justify-center text-white text-lg font-bold shadow-sm`}>
                                                {workspace.name.charAt(0).toUpperCase()}
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <h3 className="text-lg font-semibold truncate group-hover:text-primary transition-colors">
                                                    {workspace.name}
                                                </h3>
                                                {workspace.pivot?.role && (
                                                    <Badge variant="secondary" className="mt-1">
                                                        {workspace.pivot.role}
                                                    </Badge>
                                                )}
                                            </div>
                                            <ArrowRight className="size-5 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity" />
                                        </div>
                                        <p className="text-sm text-muted-foreground line-clamp-2 mb-5 min-h-[2.5rem]">
                                            {workspace.description || 'No description'}
                                        </p>
                                        <div className="flex items-center gap-5 text-sm text-muted-foreground">
                                            <div className="flex items-center gap-2">
                                                <FolderKanban className="size-4" />
                                                <span className="font-medium">{workspace.projects_count || 0}</span>
                                                <span>projects</span>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Users className="size-4" />
                                                <span className="font-medium">{workspace.users_count || 0}</span>
                                                <span>members</span>
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
