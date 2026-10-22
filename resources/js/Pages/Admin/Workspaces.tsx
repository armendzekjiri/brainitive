import AdminShell from '@/components/admin/AdminShell';
import { relative } from '@/components/admin/format';
import Pagination from '@/components/admin/Pagination';
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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AdminWorkspaceRow, Paginated } from '@/types';
import { router } from '@inertiajs/react';
import { ArrowRightLeft, Loader2, MoreHorizontal, Search, Trash2 } from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

interface Props {
    /** Not `workspaces`: that name is the shared sidebar prop. */
    managedWorkspaces: Paginated<AdminWorkspaceRow>;
    filters: { q: string };
}

interface Member {
    id: number;
    name: string;
    email: string;
    role: string;
}

export default function Workspaces({ managedWorkspaces: workspaces, filters }: Props) {
    const [search, setSearch] = useState(filters.q);
    const [transferring, setTransferring] = useState<AdminWorkspaceRow | null>(null);
    const [deleting, setDeleting] = useState<AdminWorkspaceRow | null>(null);
    const firstRender = useRef(true);

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        const timer = setTimeout(() => {
            router.get(route('admin.workspaces.index'), { q: search || undefined }, { preserveState: true, replace: true });
        }, 300);
        return () => clearTimeout(timer);
    }, [search]);

    return (
        <AdminShell title="Workspaces">
            <div className="mb-4 relative">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                <Input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search by workspace name or owner email"
                    className="pl-9"
                    aria-label="Search workspaces"
                />
            </div>

            <div className="rounded-lg border overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Workspace</TableHead>
                            <TableHead>Owner</TableHead>
                            <TableHead className="text-right">Members</TableHead>
                            <TableHead className="text-right">Projects</TableHead>
                            <TableHead>Created</TableHead>
                            <TableHead className="w-10"><span className="sr-only">Actions</span></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {workspaces.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                                    No workspaces match.
                                </TableCell>
                            </TableRow>
                        )}
                        {workspaces.data.map((workspace) => (
                            <TableRow key={workspace.id}>
                                <TableCell className="font-medium">{workspace.name}</TableCell>
                                <TableCell className="text-sm">
                                    {workspace.owner ? (
                                        <>
                                            <div>{workspace.owner.name}</div>
                                            <div className="text-xs text-muted-foreground">{workspace.owner.email}</div>
                                        </>
                                    ) : (
                                        <span className="text-muted-foreground">—</span>
                                    )}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">{workspace.members_count}</TableCell>
                                <TableCell className="text-right tabular-nums">{workspace.projects_count}</TableCell>
                                <TableCell className="text-sm text-muted-foreground whitespace-nowrap">{relative(workspace.created_at, '—')}</TableCell>
                                <TableCell>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button variant="ghost" size="icon" aria-label={`Actions for ${workspace.name}`}>
                                                <MoreHorizontal className="size-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem onClick={() => setTransferring(workspace)}>
                                                <ArrowRightLeft className="size-4" /> Transfer ownership…
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem className="text-destructive focus:text-destructive" onClick={() => setDeleting(workspace)}>
                                                <Trash2 className="size-4" /> Delete workspace…
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            <Pagination page={workspaces} noun="workspaces" />

            <TransferDialog workspace={transferring} onClose={() => setTransferring(null)} />
            <DeleteWorkspaceDialog workspace={deleting} onClose={() => setDeleting(null)} />
        </AdminShell>
    );
}

function TransferDialog({ workspace, onClose }: { workspace: AdminWorkspaceRow | null; onClose: () => void }) {
    const [members, setMembers] = useState<Member[] | null>(null);
    const [userId, setUserId] = useState('');

    useEffect(() => {
        setMembers(null);
        setUserId('');
        if (!workspace) return;
        window.axios
            .get(route('admin.workspaces.members', workspace.id))
            .then(({ data }) => setMembers(data.members))
            .catch(() => toast.error('Couldn’t load the members.'));
    }, [workspace]);

    const candidates = members?.filter((member) => member.role !== 'owner') ?? [];

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!workspace || !userId) return;
        router.post(route('admin.workspaces.transfer', workspace.id), { user_id: Number(userId) }, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open={!!workspace} onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Transfer {workspace?.name}</DialogTitle>
                    <DialogDescription>
                        Pick a member to become the owner. They become a workspace admin; the current owner stays on as an admin.
                    </DialogDescription>
                </DialogHeader>
                {!members ? (
                    <Loader2 className="size-4 animate-spin text-muted-foreground" />
                ) : candidates.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        There’s nobody else in this workspace. Ask the owner to invite the new owner first.
                    </p>
                ) : (
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-2">
                            <Label>New owner</Label>
                            <Select value={userId} onValueChange={setUserId}>
                                <SelectTrigger aria-label="New owner">
                                    <SelectValue placeholder="Choose a member" />
                                </SelectTrigger>
                                <SelectContent>
                                    {candidates.map((member) => (
                                        <SelectItem key={member.id} value={String(member.id)}>
                                            {member.name} · {member.email} ({member.role})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                            <Button type="submit" disabled={!userId}>Transfer ownership</Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}

function DeleteWorkspaceDialog({ workspace, onClose }: { workspace: AdminWorkspaceRow | null; onClose: () => void }) {
    const [confirmName, setConfirmName] = useState('');

    useEffect(() => setConfirmName(''), [workspace]);

    const matches = !!workspace && confirmName.trim() === workspace.name;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!workspace || !matches) return;
        router.delete(route('admin.workspaces.destroy', workspace.id), {
            data: { confirm_name: confirmName },
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open={!!workspace} onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete {workspace?.name}?</DialogTitle>
                    <DialogDescription>
                        The workspace and its {workspace?.projects_count} projects disappear for all {workspace?.members_count} members.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="confirm-workspace">
                            Type <span className="font-mono">{workspace?.name}</span> to confirm
                        </Label>
                        <Input id="confirm-workspace" value={confirmName} onChange={(e) => setConfirmName(e.target.value)} autoComplete="off" />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                        <Button type="submit" variant="destructive" disabled={!matches}>Delete workspace</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
