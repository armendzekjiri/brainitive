import { useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { Trash2, UserPlus } from 'lucide-react';
import type { Bookmark, BookmarkCollaborator, User } from '@/types';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';

type Role = 'viewer' | 'editor';

interface Member {
    id: number;
    name: string;
    email?: string;
    avatar_display?: string | null;
    role: 'owner' | Role;
}

const ROLE_LABEL: Record<Member['role'], string> = { owner: 'Owner', editor: 'Editor', viewer: 'Viewer' };
const STACK_SIZE = 3;

function MemberAvatar({ member, className }: { member: Pick<Member, 'name' | 'avatar_display'>; className?: string }) {
    return (
        <Avatar className={cn('size-7 ring-2 ring-background', className)}>
            {member.avatar_display && <AvatarImage src={member.avatar_display} alt="" />}
            <AvatarFallback className="bg-muted text-[11px] font-semibold text-muted-foreground">
                {member.name.charAt(0).toUpperCase()}
            </AvatarFallback>
        </Avatar>
    );
}

/**
 * The bookmark's people in the page header (GitHub #152): an avatar stack (owner first) that opens a
 * popover with everyone and their role. People who may edit the bookmark change roles, remove and
 * invite there (BookmarkPolicy::manageCollaborators = update, same routes as before). When the owner
 * is alone there is no stack: editors get "Invite", read-only viewers see nothing.
 */
export function BookmarkCollaborators({
    workspaceSlug,
    bookmark,
    workspaceMembers,
    canEdit,
    onRemove,
}: {
    workspaceSlug: string;
    bookmark: Bookmark;
    workspaceMembers: User[];
    canEdit: boolean;
    /** asks for confirmation first (the page's remove dialog) */
    onRemove: (userId: number, userName: string) => void;
}) {
    const [inviteUserId, setInviteUserId] = useState('');
    const [inviteRole, setInviteRole] = useState<Role>('viewer');
    const collaborators: BookmarkCollaborator[] = bookmark.collaborators ?? [];
    const members: Member[] = [
        ...(bookmark.creator ? [{ ...bookmark.creator, role: 'owner' as const }] : []),
        ...collaborators.map((c) => ({ ...c, role: c.pivot?.role ?? 'viewer' })),
    ];
    const availableToInvite = workspaceMembers.filter(
        (m) => m.id !== bookmark.created_by && !collaborators.some((c) => c.id === m.id),
    );
    const params = [workspaceSlug, bookmark.slug];

    if (collaborators.length === 0 && !canEdit) return null;

    const invite = (event: FormEvent) => {
        event.preventDefault();
        if (!inviteUserId) return;
        router.post(
            route('bookmarks.collaborators.invite', params),
            { user_id: Number(inviteUserId), role: inviteRole },
            { preserveScroll: true, onSuccess: () => setInviteUserId('') },
        );
    };

    const changeRole = (userId: number, role: Role) => {
        router.put(route('bookmarks.collaborators.update', [...params, userId]), { role }, { preserveScroll: true });
    };

    const shown = members.slice(0, STACK_SIZE);
    const more = members.length - shown.length;
    const label = collaborators.length === 0
        ? 'Invite collaborators'
        : `Collaborators: ${members.map((m) => m.name).join(', ')}`;

    return (
        <Popover>
            <PopoverTrigger asChild>
                {collaborators.length === 0 ? (
                    <Button variant="outline" size="sm" className="h-9 gap-1.5" aria-label={label}>
                        <UserPlus className="size-4" />
                        <span className="hidden sm:inline">Invite</span>
                    </Button>
                ) : (
                    <button
                        type="button"
                        aria-label={label}
                        title={members.map((m) => m.name).join(', ')}
                        className="flex h-9 items-center rounded-full px-1 transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <span className="flex -space-x-2">
                            {shown.map((member) => <MemberAvatar key={member.id} member={member} />)}
                        </span>
                        {more > 0 && (
                            <span className="-ml-2 flex size-7 items-center justify-center rounded-full bg-muted text-[11px] font-medium text-muted-foreground ring-2 ring-background">
                                +{more}
                            </span>
                        )}
                    </button>
                )}
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80 max-w-[calc(100vw-2rem)] p-0">
                <div className="border-b px-4 py-3">
                    <h2 className="text-sm font-medium">Collaborators</h2>
                    <p className="text-xs text-muted-foreground">
                        {canEdit ? 'Editors can add and change content; viewers can only look.' : 'People who work on this bookmark.'}
                    </p>
                </div>
                <ul className="max-h-72 space-y-1 overflow-y-auto p-2" aria-label="Collaborators">
                    {members.map((member) => (
                        <li key={member.id} className="flex items-center gap-2 rounded-md px-2 py-1.5">
                            <MemberAvatar member={member} className="ring-0" />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm">{member.name}</span>
                                {member.email && <span className="block truncate text-xs text-muted-foreground">{member.email}</span>}
                            </span>
                            {member.role === 'owner' || !canEdit ? (
                                <Badge variant="secondary" className="shrink-0 text-xs">{ROLE_LABEL[member.role]}</Badge>
                            ) : (
                                <span className="flex shrink-0 items-center gap-1">
                                    <Select value={member.role} onValueChange={(role) => changeRole(member.id, role as Role)}>
                                        <SelectTrigger className="h-7 w-[84px] text-xs" aria-label={`Role of ${member.name}`}><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="viewer">Viewer</SelectItem>
                                            <SelectItem value="editor">Editor</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <Button variant="ghost" size="icon" className="size-7" aria-label={`Remove ${member.name}`} onClick={() => onRemove(member.id, member.name)}>
                                        <Trash2 className="size-3.5" />
                                    </Button>
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
                {canEdit && availableToInvite.length > 0 && (
                    <form onSubmit={invite} className="flex flex-wrap gap-2 border-t p-3">
                        <Select value={inviteUserId} onValueChange={setInviteUserId} required>
                            <SelectTrigger className="h-8 min-w-0 flex-1 text-xs" aria-label="Person to invite"><SelectValue placeholder="Invite…" /></SelectTrigger>
                            <SelectContent>{availableToInvite.map((m) => <SelectItem key={m.id} value={String(m.id)}>{m.name}</SelectItem>)}</SelectContent>
                        </Select>
                        <Select value={inviteRole} onValueChange={(v) => setInviteRole(v as Role)}>
                            <SelectTrigger className="h-8 w-[84px] text-xs" aria-label="Role for the invite"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="viewer">Viewer</SelectItem>
                                <SelectItem value="editor">Editor</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button type="submit" size="sm" className="h-8 text-xs" disabled={!inviteUserId}>Invite</Button>
                    </form>
                )}
                {canEdit && availableToInvite.length === 0 && collaborators.length === 0 && (
                    <p className="border-t px-4 py-3 text-xs text-muted-foreground">There's no one else in this workspace yet. Invite people to the workspace first.</p>
                )}
            </PopoverContent>
        </Popover>
    );
}
