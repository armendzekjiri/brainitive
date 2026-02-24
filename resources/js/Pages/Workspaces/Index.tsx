import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { PageProps, Workspace } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Plus, Users, FolderKanban, ArrowRight, User as UserIcon } from 'lucide-react';

interface Props extends PageProps {
    workspaces: Workspace[];
    isInstanceAdmin: boolean;
}

// Gradient for cover when no header image; fallback color for logo circle
function getWorkspaceGradient(name: string): string {
    const gradients = [
        'from-brand-blue to-brand-slate',
        'from-brand-slate to-brand-blue',
        'from-brand-green to-brand-slate',
        'from-brand-blue to-brand-green',
        'from-brand-slate to-brand-green',
        'from-brand-green to-brand-blue',
    ];
    return gradients[name.charCodeAt(0) % gradients.length];
}
function getWorkspaceBg(name: string): string {
    const colors = ['bg-brand-blue', 'bg-brand-slate', 'bg-brand-green', 'bg-brand-blue', 'bg-brand-slate', 'bg-brand-green'];
    return colors[name.charCodeAt(0) % colors.length];
}

export default function WorkspacesIndex({ auth, workspaces, isInstanceAdmin }: Props) {
    return (
        <AppLayout>
            <Head title="Workspaces" />

            <div className="p-8 max-w-6xl mx-auto">
                {/* Header */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-6 mb-10">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight mb-1">Workspaces</h1>
                        <p className="text-base text-muted-foreground">
                            Manage your workspaces and access your projects
                        </p>
                    </div>
                    <Link href={route('workspaces.create')}>
                        <Button size="lg" className="gap-2 shrink-0">
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
                                <Button size="lg" className="gap-2 shrink-0">
                                    <Plus className="size-5" />
                                    Create Workspace
                                </Button>
                            </Link>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                        {workspaces.map((workspace) => (
                            <Link
                                key={workspace.id}
                                href={route('workspaces.show', workspace.slug)}
                                className="block h-full group"
                            >
                                <Card className="h-full overflow-hidden hover:shadow-xl hover:shadow-primary/5 hover:border-primary/30 transition-all duration-200 border-2">
                                    {/* Cover / header */}
                                    <div
                                        className={`relative h-28 sm:h-32 overflow-hidden ${
                                            workspace.header_image_display
                                                ? 'bg-muted'
                                                : `bg-gradient-to-br ${getWorkspaceGradient(workspace.name)}`
                                        }`}
                                    >
                                        {workspace.header_image_display ? (
                                            <img
                                                src={workspace.header_image_display}
                                                alt=""
                                                className="absolute inset-0 w-full h-full object-cover"
                                            />
                                        ) : null}
                                        <div className="absolute inset-0 bg-black/10" aria-hidden />
                                        {/* Logo or initial overlapping the cover */}
                                        <div
                                            className={`absolute left-4 -bottom-6 size-14 rounded-xl border-4 border-background shadow-lg flex items-center justify-center shrink-0 ${
                                                workspace.logo_display ? 'bg-background' : getWorkspaceBg(workspace.name)
                                            }`}
                                        >
                                            {workspace.logo_display ? (
                                                <img
                                                    src={workspace.logo_display}
                                                    alt=""
                                                    className="size-full rounded-lg object-cover"
                                                />
                                            ) : (
                                                <span className="text-2xl font-bold text-white">
                                                    {workspace.name.charAt(0).toUpperCase()}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    <CardContent className="pt-8 pb-5 px-5">
                                        <div className="flex items-start justify-between gap-2 mb-2">
                                            <h3 className="text-lg font-semibold leading-tight truncate group-hover:text-primary transition-colors">
                                                {workspace.name}
                                            </h3>
                                            <ArrowRight className="size-5 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity shrink-0 mt-0.5" />
                                        </div>
                                        {workspace.pivot?.role && (
                                            <Badge variant="secondary" className="mb-3 text-xs">
                                                {workspace.pivot.role}
                                            </Badge>
                                        )}
                                        <p className="text-sm text-muted-foreground line-clamp-2 min-h-[2.5rem] mb-4">
                                            {workspace.description || (
                                                <span className="italic">No description</span>
                                            )}
                                        </p>
                                        <div className="flex flex-wrap items-center gap-4 text-sm text-muted-foreground">
                                            <div className="flex items-center gap-1.5">
                                                <FolderKanban className="size-4 text-muted-foreground/80" />
                                                <span>
                                                    <span className="font-medium text-foreground">{workspace.projects_count ?? 0}</span>
                                                    {' '}projects
                                                </span>
                                            </div>
                                            <div className="flex items-center gap-1.5">
                                                <Users className="size-4 text-muted-foreground/80" />
                                                <span>
                                                    <span className="font-medium text-foreground">{workspace.users_count ?? 0}</span>
                                                    {' '}members
                                                </span>
                                            </div>
                                        </div>
                                        {workspace.owner && (
                                            <div className="mt-3 pt-3 border-t border-border/50 flex items-center gap-2 text-xs text-muted-foreground">
                                                <UserIcon className="size-3.5 shrink-0" />
                                                <span className="truncate">Owner: {workspace.owner.name}</span>
                                            </div>
                                        )}
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
