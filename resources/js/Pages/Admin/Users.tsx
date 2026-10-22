import AdminShell from '@/components/admin/AdminShell';
import { plural, relative } from '@/components/admin/format';
import Pagination from '@/components/admin/Pagination';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AdminUserDetail, AdminUserRow, PageProps, Paginated } from '@/types';
import { router, usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    Ban,
    Copy,
    KeyRound,
    Link2,
    Loader2,
    Mail,
    MoreHorizontal,
    Search,
    ShieldCheck,
    ShieldOff,
    Trash2,
    UserPlus,
    UserRoundCheck,
} from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

type Filter = 'all' | 'admins' | 'suspended' | 'unverified';

interface Props {
    users: Paginated<AdminUserRow>;
    filters: { q: string; filter: Filter };
}

interface Confirmation {
    title: string;
    description: string;
    action: string;
    destructive?: boolean;
    run: () => void;
}

const initials = (name: string) =>
    name
        .split(/\s+/)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

async function copy(text: string) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Copied to clipboard.');
    } catch {
        toast.error('Couldn’t copy — select the link and copy it manually.');
    }
}

export default function Users({ users, filters }: Props) {
    const me = usePage<PageProps>().props.auth.user;
    const [search, setSearch] = useState(filters.q);
    const [detailUser, setDetailUser] = useState<AdminUserRow | null>(null);
    const [resetLink, setResetLink] = useState<{ user: AdminUserRow; url: string; expires: number } | null>(null);
    const [deleteUser, setDeleteUser] = useState<AdminUserRow | null>(null);
    const [creating, setCreating] = useState(false);
    const [confirmation, setConfirmation] = useState<Confirmation | null>(null);
    const firstRender = useRef(true);

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        const timer = setTimeout(() => {
            router.get(route('admin.users.index'), { q: search || undefined, filter: filters.filter === 'all' ? undefined : filters.filter }, { preserveState: true, replace: true });
        }, 300);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const setFilter = (filter: Filter) =>
        router.get(route('admin.users.index'), { q: search || undefined, filter: filter === 'all' ? undefined : filter }, { preserveState: true });

    const post = (name: string, user: AdminUserRow) => router.post(route(name, user.id), {}, { preserveScroll: true });

    const createResetLink = async (user: AdminUserRow) => {
        try {
            const { data } = await window.axios.post(route('admin.users.password-reset-link', user.id));
            setResetLink({ user, url: data.url, expires: data.expires_in_minutes });
        } catch {
            toast.error('Couldn’t create a reset link.');
        }
    };

    const confirm = (c: Confirmation) => setConfirmation(c);

    return (
        <AdminShell
            title="Users"
            actions={
                <Button onClick={() => setCreating(true)} className="gap-2">
                    <UserPlus className="size-4" /> Add user
                </Button>
            }
        >
            <div className="mb-4 flex flex-col sm:flex-row gap-3">
                <div className="relative flex-1">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search by name or email"
                        className="pl-9"
                        aria-label="Search users"
                    />
                </div>
                <Select value={filters.filter} onValueChange={(value) => setFilter(value as Filter)}>
                    <SelectTrigger className="sm:w-48" aria-label="Filter users">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All users</SelectItem>
                        <SelectItem value="admins">Instance admins</SelectItem>
                        <SelectItem value="suspended">Suspended</SelectItem>
                        <SelectItem value="unverified">Email not verified</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div className="rounded-lg border overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>User</TableHead>
                            <TableHead>Workspaces</TableHead>
                            <TableHead className="text-right" title="Projects they can open">Projects</TableHead>
                            <TableHead>Last sign-in</TableHead>
                            <TableHead>Joined</TableHead>
                            <TableHead className="w-10"><span className="sr-only">Actions</span></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {users.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                                    No users match.
                                </TableCell>
                            </TableRow>
                        )}
                        {users.data.map((user) => {
                            const isMe = user.id === me.id;
                            return (
                                <TableRow key={user.id} className="cursor-pointer" onClick={() => setDetailUser(user)}>
                                    <TableCell>
                                        <div className="flex items-center gap-3 min-w-0">
                                            <Avatar className="size-8">
                                                {user.avatar_display && <AvatarImage src={user.avatar_display} alt="" />}
                                                <AvatarFallback className="text-xs">{initials(user.name)}</AvatarFallback>
                                            </Avatar>
                                            <div className="min-w-0">
                                                <div className="flex items-center gap-2 flex-wrap">
                                                    <span className="font-medium truncate">{user.name}</span>
                                                    {isMe && <Badge variant="outline">You</Badge>}
                                                    {user.is_instance_admin && <Badge variant="secondary">Admin</Badge>}
                                                    {user.suspended_at && <Badge variant="destructive">Suspended</Badge>}
                                                    {!user.email_verified_at && <Badge variant="outline">Unverified</Badge>}
                                                </div>
                                                <div className="text-xs text-muted-foreground truncate">{user.email}</div>
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-sm whitespace-nowrap">
                                        {user.workspaces_count}
                                        {user.owned_workspaces_count > 0 && (
                                            <span className="text-muted-foreground"> · owns {user.owned_workspaces_count}</span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right text-sm tabular-nums">{user.projects_count}</TableCell>
                                    <TableCell className="text-sm text-muted-foreground whitespace-nowrap">{relative(user.last_login_at)}</TableCell>
                                    <TableCell className="text-sm text-muted-foreground whitespace-nowrap">{relative(user.created_at, '—')}</TableCell>
                                    <TableCell onClick={(e) => e.stopPropagation()}>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button variant="ghost" size="icon" aria-label={`Actions for ${user.name}`}>
                                                    <MoreHorizontal className="size-4" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end" className="w-60">
                                                <DropdownMenuItem onClick={() => post('admin.users.password-reset-email', user)}>
                                                    <Mail className="size-4" /> Email a password reset link
                                                </DropdownMenuItem>
                                                <DropdownMenuItem onClick={() => createResetLink(user)}>
                                                    <Link2 className="size-4" /> Copy a password reset link
                                                </DropdownMenuItem>
                                                {!user.email_verified_at && (
                                                    <>
                                                        <DropdownMenuItem onClick={() => post('admin.users.verify-email', user)}>
                                                            <BadgeCheck className="size-4" /> Mark email as verified
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem onClick={() => post('admin.users.resend-verification', user)}>
                                                            <Mail className="size-4" /> Resend verification email
                                                        </DropdownMenuItem>
                                                    </>
                                                )}
                                                <DropdownMenuSeparator />
                                                {user.is_instance_admin ? (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            confirm({
                                                                title: isMe ? 'Give up your admin role?' : `Remove ${user.name} as instance admin?`,
                                                                description: isMe
                                                                    ? 'You’ll lose access to this console immediately.'
                                                                    : 'They’ll lose access to this console.',
                                                                action: 'Remove admin role',
                                                                run: () => router.delete(route('admin.users.admin.revoke', user.id), { preserveScroll: true }),
                                                            })
                                                        }
                                                    >
                                                        <ShieldOff className="size-4" /> Remove instance admin
                                                    </DropdownMenuItem>
                                                ) : (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            confirm({
                                                                title: `Make ${user.name} an instance admin?`,
                                                                description: 'They’ll be able to manage all accounts, workspaces and instance settings. They won’t see workspace content.',
                                                                action: 'Make admin',
                                                                run: () => post('admin.users.admin.grant', user),
                                                            })
                                                        }
                                                    >
                                                        <ShieldCheck className="size-4" /> Make instance admin
                                                    </DropdownMenuItem>
                                                )}
                                                {user.suspended_at ? (
                                                    <DropdownMenuItem onClick={() => post('admin.users.unsuspend', user)}>
                                                        <UserRoundCheck className="size-4" /> Unsuspend
                                                    </DropdownMenuItem>
                                                ) : (
                                                    <DropdownMenuItem
                                                        disabled={isMe}
                                                        onClick={() =>
                                                            confirm({
                                                                title: `Suspend ${user.name}?`,
                                                                description: 'They’re signed out everywhere, their API and AI connector tokens are revoked, and they can’t sign in until you unsuspend them. Nothing is deleted.',
                                                                action: 'Suspend',
                                                                destructive: true,
                                                                run: () => post('admin.users.suspend', user),
                                                            })
                                                        }
                                                    >
                                                        <Ban className="size-4" /> Suspend
                                                    </DropdownMenuItem>
                                                )}
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    disabled={isMe}
                                                    className="text-destructive focus:text-destructive"
                                                    onClick={() => setDeleteUser(user)}
                                                >
                                                    <Trash2 className="size-4" /> Delete user…
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>

            <Pagination page={users} noun="users" />

            <UserDetailSheet user={detailUser} onClose={() => setDetailUser(null)} />

            <Dialog open={!!resetLink} onOpenChange={(open) => !open && setResetLink(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Password reset link</DialogTitle>
                        <DialogDescription>
                            Send this to {resetLink?.user.name} yourself. It works once, expires in {resetLink?.expires} minutes,
                            and replaces any earlier reset link. You never see their password.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex gap-2">
                        <Input readOnly value={resetLink?.url ?? ''} onFocus={(e) => e.target.select()} aria-label="Reset link" />
                        <Button variant="outline" onClick={() => resetLink && copy(resetLink.url)} className="gap-2 shrink-0">
                            <Copy className="size-4" /> Copy
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>

            <CreateUserDialog open={creating} onClose={() => setCreating(false)} />
            <DeleteUserDialog user={deleteUser} onClose={() => setDeleteUser(null)} />

            <AlertDialog open={!!confirmation} onOpenChange={(open) => !open && setConfirmation(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{confirmation?.title}</AlertDialogTitle>
                        <AlertDialogDescription>{confirmation?.description}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className={confirmation?.destructive ? 'bg-destructive text-destructive-foreground hover:bg-destructive/90' : undefined}
                            onClick={() => confirmation?.run()}
                        >
                            {confirmation?.action}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AdminShell>
    );
}

function useUserDetail(user: AdminUserRow | null) {
    const [detail, setDetail] = useState<AdminUserDetail | null>(null);

    useEffect(() => {
        setDetail(null);
        if (!user) return;
        let cancelled = false;
        window.axios
            .get(route('admin.users.show', user.id))
            .then(({ data }) => !cancelled && setDetail(data))
            .catch(() => !cancelled && toast.error('Couldn’t load this user.'));
        return () => {
            cancelled = true;
        };
    }, [user]);

    return detail;
}

function UserDetailSheet({ user, onClose }: { user: AdminUserRow | null; onClose: () => void }) {
    const detail = useUserDetail(user);

    return (
        <Sheet open={!!user} onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="w-full sm:max-w-md overflow-y-auto">
                {user && (
                    <>
                        <SheetHeader>
                            <SheetTitle>{user.name}</SheetTitle>
                            <SheetDescription>{user.email}</SheetDescription>
                        </SheetHeader>

                        <dl className="mt-6 grid grid-cols-2 gap-y-3 text-sm">
                            <dt className="text-muted-foreground">Joined</dt>
                            <dd>{relative(user.created_at, '—')}</dd>
                            <dt className="text-muted-foreground">Last sign-in</dt>
                            <dd>{relative(user.last_login_at)}</dd>
                            <dt className="text-muted-foreground">Email</dt>
                            <dd>{user.email_verified_at ? 'Verified' : 'Not verified'}</dd>
                            <dt className="text-muted-foreground">Signs in with</dt>
                            <dd className="capitalize">{user.sign_in_methods.join(', ')}</dd>
                            <dt className="text-muted-foreground">API / AI tokens</dt>
                            <dd>{detail ? (detail.has_api_tokens ? 'Active' : 'None') : '…'}</dd>
                        </dl>

                        <h3 className="mt-8 mb-2 text-sm font-semibold">Workspaces</h3>
                        <p className="mb-3 text-xs text-muted-foreground">Names and counts only — content stays private.</p>
                        {!detail ? (
                            <Loader2 className="size-4 animate-spin text-muted-foreground" />
                        ) : detail.workspaces.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Not a member of any workspace.</p>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {detail.workspaces.map((workspace) => (
                                    <li key={workspace.id} className="p-3 text-sm">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="font-medium truncate">{workspace.name}</span>
                                            <Badge variant={workspace.role === 'owner' ? 'secondary' : 'outline'} className="capitalize">
                                                {workspace.role}
                                            </Badge>
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {plural(workspace.projects_count, 'project')} · {plural(workspace.members_count, 'member')} ·{' '}
                                            {workspace.all_projects ? 'access to all projects' : `assigned to ${workspace.project_memberships}`}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

function CreateUserDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [sendEmail, setSendEmail] = useState(true);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [link, setLink] = useState<string | null>(null);

    const close = () => {
        setName('');
        setEmail('');
        setErrors({});
        setLink(null);
        onClose();
    };

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        try {
            const { data } = await window.axios.post(route('admin.users.store'), { name, email, send_email: sendEmail });
            toast.success(data.message);
            router.reload({ only: ['users'] });
            if (data.url) {
                setLink(data.url);
            } else {
                close();
            }
        } catch (error: any) {
            const fieldErrors = error?.response?.data?.errors ?? {};
            setErrors(Object.fromEntries(Object.entries(fieldErrors).map(([key, value]) => [key, (value as string[])[0]])));
            if (!Object.keys(fieldErrors).length) toast.error('Couldn’t create the account.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && close()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add user</DialogTitle>
                    <DialogDescription>
                        Creates an account with a verified email. They choose their own password from a reset link — you never set it.
                        Add them to workspaces by inviting them from a workspace.
                    </DialogDescription>
                </DialogHeader>
                {link ? (
                    <div className="space-y-3">
                        <p className="text-sm">Send this link so {name} can set a password:</p>
                        <div className="flex gap-2">
                            <Input readOnly value={link} onFocus={(e) => e.target.select()} aria-label="Set-password link" />
                            <Button variant="outline" onClick={() => copy(link)} className="gap-2 shrink-0">
                                <Copy className="size-4" /> Copy
                            </Button>
                        </div>
                        <DialogFooter>
                            <Button onClick={close}>Done</Button>
                        </DialogFooter>
                    </div>
                ) : (
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="new-user-name">Name</Label>
                            <Input id="new-user-name" value={name} onChange={(e) => setName(e.target.value)} required autoFocus />
                            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="new-user-email">Email</Label>
                            <Input id="new-user-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
                            {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                        </div>
                        <label className="flex items-start gap-2 text-sm">
                            <input type="checkbox" className="mt-0.5" checked={sendEmail} onChange={(e) => setSendEmail(e.target.checked)} />
                            <span>
                                Email them a link to set their password
                                <span className="block text-xs text-muted-foreground">Untick to get a link you can share yourself (no SMTP needed).</span>
                            </span>
                        </label>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={close}>Cancel</Button>
                            <Button type="submit" disabled={busy} className="gap-2">
                                {busy ? <Loader2 className="size-4 animate-spin" /> : <KeyRound className="size-4" />} Create account
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}

function DeleteUserDialog({ user, onClose }: { user: AdminUserRow | null; onClose: () => void }) {
    const detail = useUserDetail(user);
    const [confirmEmail, setConfirmEmail] = useState('');
    const blocked = !!detail && detail.delete_blockers.length > 0;
    const matches = !!user && confirmEmail.trim().toLowerCase() === user.email.toLowerCase();

    useEffect(() => setConfirmEmail(''), [user]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!user || !matches) return;
        router.delete(route('admin.users.destroy', user.id), {
            data: { confirm_email: confirmEmail },
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open={!!user} onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete {user?.name}?</DialogTitle>
                    <DialogDescription>This permanently deletes the account. It can’t be undone.</DialogDescription>
                </DialogHeader>
                {!detail ? (
                    <Loader2 className="size-4 animate-spin text-muted-foreground" />
                ) : blocked ? (
                    <div className="rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm space-y-1">
                        {detail.delete_blockers.map((reason) => (
                            <p key={reason}>{reason}</p>
                        ))}
                    </div>
                ) : (
                    <form onSubmit={submit} className="space-y-4">
                        <ul className="list-disc pl-5 text-sm space-y-1 text-muted-foreground">
                            <li>
                                {plural(detail.delete_impact.reassigned, 'item')} they created in other people’s workspaces (projects,
                                boards, issues, lists, files) will be kept and handed to that workspace’s owner.
                            </li>
                            <li>{plural(detail.delete_impact.comments_deleted, 'comment')} they wrote will be deleted (replies by others are kept).</li>
                            <li>Their memberships, watchers, calendar connections and tokens are removed.</li>
                        </ul>
                        <div className="space-y-2">
                            <Label htmlFor="confirm-email">
                                Type <span className="font-mono">{user?.email}</span> to confirm
                            </Label>
                            <Input id="confirm-email" value={confirmEmail} onChange={(e) => setConfirmEmail(e.target.value)} autoComplete="off" />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                            <Button type="submit" variant="destructive" disabled={!matches}>Delete user</Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
