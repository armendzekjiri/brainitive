import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { PageProps, Workspace, User, Item, Comment, Page as PageType, Project } from '@/types';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ProjectIcon } from '@/components/ProjectIcon';
import {
    CheckCircle2,
    Circle,
    Clock,
    FileText,
    FolderOpen,
    LayoutGrid,
    Mail,
    MessageSquare,
    Phone,
    User as UserIcon,
} from 'lucide-react';
import { safeHtmlProp } from '@/lib/sanitize';

interface ActivityLog {
    id: number;
    loggable_type: string;
    loggable_id: number;
    action: string;
    changes?: Record<string, unknown> | null;
    metadata?: Record<string, unknown> | null;
    created_at: string;
}

interface AssignedItem extends Pick<Item, 'id' | 'uuid' | 'key' | 'title' | 'due_date' | 'completed_at' | 'updated_at'> {
    board?: { id: number; name: string; slug: string; project?: { id: number; name: string; slug: string; workspace_id: number } };
    column?: { id: number; name: string; is_done_column: boolean };
    priority?: { id: number; name: string; slug: string; icon?: string; color?: string };
}

interface RecentComment extends Pick<Comment, 'id' | 'body' | 'created_at'> {
    item?: { id: number; key: string; title: string; board?: { id: number; name: string; slug: string; project?: { id: number; name: string; slug: string; workspace_id: number } } };
}

interface RecentPage extends Pick<PageType, 'id' | 'uuid' | 'name' | 'icon' | 'status' | 'updated_at'> {
    project?: { id: number; name: string; slug: string; workspace_id: number };
}

interface Props extends PageProps {
    workspace: Workspace;
    member: Pick<User, 'id' | 'name' | 'email' | 'avatar' | 'avatar_display' | 'bio' | 'phone'> & { created_at: string };
    memberRole: string | null;
    assignedItems: AssignedItem[];
    recentComments: RecentComment[];
    recentPages: RecentPage[];
    activityLogs: ActivityLog[];
    projects: (Pick<Project, 'id' | 'name' | 'slug' | 'key' | 'icon' | 'description'> & { icon_display?: string | null; color?: string | null })[];
}

function Avatar({ member, size = 'lg' }: { member: Props['member']; size?: 'sm' | 'lg' }) {
    const sizeClass = size === 'lg' ? 'size-20' : 'size-10';
    const textSize = size === 'lg' ? 'text-3xl' : 'text-base';
    const initial = member.name.charAt(0).toUpperCase();

    if (member.avatar_display) {
        return <img src={member.avatar_display} alt="" className={`${sizeClass} rounded-full object-cover ring-4 ring-background shadow-lg`} />;
    }

    return (
        <div className={`${sizeClass} flex items-center justify-center rounded-full bg-primary/10 text-primary ring-4 ring-background shadow-lg`}>
            <span className={`${textSize} font-bold`}>{initial}</span>
        </div>
    );
}

function roleLabel(role: string | null): string {
    if (!role) return 'Member';
    return role.charAt(0).toUpperCase() + role.slice(1);
}

function roleBadgeVariant(role: string | null): 'default' | 'secondary' | 'outline' {
    if (role === 'owner' || role === 'admin') return 'default';
    if (role === 'editor') return 'secondary';
    return 'outline';
}

function timeAgo(dateStr: string): string {
    const now = new Date();
    const date = new Date(dateStr);
    const diffMs = now.getTime() - date.getTime();
    const diffMin = Math.floor(diffMs / 60000);
    if (diffMin < 1) return 'just now';
    if (diffMin < 60) return `${diffMin}m ago`;
    const diffH = Math.floor(diffMin / 60);
    if (diffH < 24) return `${diffH}h ago`;
    const diffD = Math.floor(diffH / 24);
    if (diffD < 30) return `${diffD}d ago`;
    return date.toLocaleDateString();
}

function actionLabel(action: string): string {
    return action.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function modelLabel(type: string): string {
    const name = type.split('\\').pop() ?? type;
    return name.replace(/([a-z])([A-Z])/g, '$1 $2');
}

export default function MemberProfile({ workspace, member, memberRole, assignedItems, recentComments, recentPages, activityLogs, projects }: Props) {
    const openItems = assignedItems.filter(i => !i.completed_at);
    const completedItems = assignedItems.filter(i => !!i.completed_at);

    return (
        <AppLayout>
            <Head title={`${member.name} — ${workspace.name}`} />

            <div className="mx-auto max-w-5xl space-y-8 px-4 py-8 sm:px-6">
                {/* Profile header */}
                <div className="flex flex-col items-center gap-5 sm:flex-row sm:items-start">
                    <Avatar member={member} size="lg" />
                    <div className="flex-1 text-center sm:text-left">
                        <div className="flex flex-wrap items-center justify-center gap-3 sm:justify-start">
                            <h1 className="text-2xl font-bold tracking-tight">{member.name}</h1>
                            <Badge variant={roleBadgeVariant(memberRole)}>{roleLabel(memberRole)}</Badge>
                        </div>
                        {member.bio && <p className="mt-1 text-sm text-muted-foreground">{member.bio}</p>}
                        <div className="mt-3 flex flex-wrap items-center justify-center gap-4 text-sm text-muted-foreground sm:justify-start">
                            <span className="flex items-center gap-1.5"><Mail className="size-3.5" />{member.email}</span>
                            {member.phone && <span className="flex items-center gap-1.5"><Phone className="size-3.5" />{member.phone}</span>}
                            <span className="flex items-center gap-1.5"><Clock className="size-3.5" />Joined {new Date(member.created_at).toLocaleDateString()}</span>
                        </div>
                    </div>
                </div>

                {/* Stats row */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <Card>
                        <CardContent className="flex flex-col items-center py-4">
                            <span className="text-2xl font-bold">{assignedItems.length}</span>
                            <span className="text-xs text-muted-foreground">Assigned Items</span>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex flex-col items-center py-4">
                            <span className="text-2xl font-bold">{completedItems.length}</span>
                            <span className="text-xs text-muted-foreground">Completed</span>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex flex-col items-center py-4">
                            <span className="text-2xl font-bold">{recentComments.length}</span>
                            <span className="text-xs text-muted-foreground">Comments</span>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex flex-col items-center py-4">
                            <span className="text-2xl font-bold">{projects.length}</span>
                            <span className="text-xs text-muted-foreground">Projects</span>
                        </CardContent>
                    </Card>
                </div>

                {/* Projects */}
                {projects.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base"><FolderOpen className="size-4" />Projects</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {projects.map(p => (
                                    <Link
                                        key={p.id}
                                        href={route('projects.show', [workspace.slug, p.slug])}
                                        className="flex items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-accent"
                                    >
                                        <ProjectIcon project={p} size="md" className="size-9" />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">{p.name}</p>
                                            <p className="truncate text-xs text-muted-foreground">{p.key}{p.description ? ` · ${p.description}` : ''}</p>
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Assigned items */}
                {assignedItems.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base"><LayoutGrid className="size-4" />Assigned Items</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-1">
                            {assignedItems.map(item => {
                                const boardUrl = item.board?.project
                                    ? route('boards.show', [workspace.slug, item.board.project.slug, item.board.slug])
                                    : '#';
                                return (
                                    <Link
                                        key={item.id}
                                        href={`${boardUrl}#item-${item.id}`}
                                        className="flex items-center gap-3 rounded-lg px-3 py-2 transition-colors hover:bg-accent"
                                    >
                                        {item.completed_at ? (
                                            <CheckCircle2 className="size-4 shrink-0 text-green-500" />
                                        ) : (
                                            <Circle className="size-4 shrink-0 text-muted-foreground" />
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <p className={`truncate text-sm font-medium ${item.completed_at ? 'line-through text-muted-foreground' : ''}`}>
                                                {item.title || item.key}
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {item.key}
                                                {item.column && ` · ${item.column.name}`}
                                                {item.board?.project && ` · ${item.board.project.name}`}
                                            </p>
                                        </div>
                                        {item.priority && (
                                            <Badge variant="outline" className="shrink-0 text-[10px]" style={item.priority.color ? { borderColor: item.priority.color, color: item.priority.color } : undefined}>
                                                {item.priority.name}
                                            </Badge>
                                        )}
                                        <span className="shrink-0 text-[11px] text-muted-foreground">{timeAgo(item.updated_at)}</span>
                                    </Link>
                                );
                            })}
                        </CardContent>
                    </Card>
                )}

                {/* Recent pages edited */}
                {recentPages.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base"><FileText className="size-4" />Recent Documents</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-1">
                            {recentPages.map(page => {
                                const pageUrl = page.project
                                    ? route('pages.show', [workspace.slug, page.project.slug, page.uuid])
                                    : '#';
                                return (
                                    <Link
                                        key={page.id}
                                        href={pageUrl}
                                        className="flex items-center gap-3 rounded-lg px-3 py-2 transition-colors hover:bg-accent"
                                    >
                                        {(page.icon?.startsWith('data:') || page.icon?.startsWith('http') || page.icon?.startsWith('/'))
                                            ? <img src={page.icon} className="size-6 shrink-0 rounded object-cover" alt="" />
                                            : <span className="flex size-6 shrink-0 items-center justify-center text-base">{page.icon || '📄'}</span>
                                        }
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">{page.name}</p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {page.project?.name}
                                                {page.status !== 'published' && ` · ${page.status}`}
                                            </p>
                                        </div>
                                        <span className="shrink-0 text-[11px] text-muted-foreground">{timeAgo(page.updated_at)}</span>
                                    </Link>
                                );
                            })}
                        </CardContent>
                    </Card>
                )}

                {/* Recent comments */}
                {recentComments.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base"><MessageSquare className="size-4" />Recent Comments</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-1">
                            {recentComments.map(comment => {
                                const boardUrl = comment.item?.board?.project
                                    ? route('boards.show', [workspace.slug, comment.item.board.project.slug, comment.item.board.slug])
                                    : '#';
                                return (
                                    <Link
                                        key={comment.id}
                                        href={`${boardUrl}#item-${comment.item?.id}`}
                                        className="flex items-start gap-3 rounded-lg px-3 py-2 transition-colors hover:bg-accent"
                                    >
                                        <MessageSquare className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm text-muted-foreground line-clamp-2" dangerouslySetInnerHTML={safeHtmlProp(comment.body)} />
                                            <p className="mt-0.5 truncate text-xs text-muted-foreground/70">
                                                on {comment.item?.key} {comment.item?.title ? `· ${comment.item.title}` : ''}
                                            </p>
                                        </div>
                                        <span className="shrink-0 text-[11px] text-muted-foreground">{timeAgo(comment.created_at)}</span>
                                    </Link>
                                );
                            })}
                        </CardContent>
                    </Card>
                )}

                {/* Activity log */}
                {activityLogs.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base"><Clock className="size-4" />Recent Activity</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="relative space-y-0 pl-4 before:absolute before:left-[7px] before:top-2 before:h-[calc(100%-16px)] before:w-px before:bg-border">
                                {activityLogs.map(log => (
                                    <div key={log.id} className="relative flex items-start gap-3 py-2">
                                        <div className="absolute -left-4 top-3 size-2 rounded-full bg-primary ring-2 ring-background" />
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm">
                                                <span className="font-medium">{actionLabel(log.action)}</span>
                                                <span className="text-muted-foreground"> · {modelLabel(log.loggable_type)}</span>
                                            </p>
                                            <p className="text-[11px] text-muted-foreground">{timeAgo(log.created_at)}</p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
