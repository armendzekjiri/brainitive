import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { Project, Workspace } from '@/types';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { ArrowRightLeft, Loader2, TriangleAlert, UserCheck, UserMinus } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Person {
    id: number;
    name: string;
    email: string;
}

interface Preview {
    key_taken: boolean;
    new_slug: string;
    keep: Person[];
    remove: Person[];
    external_links: number;
}

/**
 * Move the project (boards, docs, whiteboards, plans, lists, helpdesks, labels, types…) to
 * another workspace the user administers. Shows who keeps access before confirming.
 */
export default function MoveProjectCard({ workspace, project, targets }: { workspace: Workspace; project: Project; targets: { id: number; name: string }[] }) {
    const [targetId, setTargetId] = useState('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [loading, setLoading] = useState(false);
    const [confirmName, setConfirmName] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [moving, setMoving] = useState(false);
    const target = targets.find((t) => String(t.id) === targetId);

    useEffect(() => {
        setPreview(null);
        setError(null);
        if (!targetId) return;
        setLoading(true);
        axios
            .get(route('projects.move.preview', [workspace.slug, project.slug]), { params: { target_workspace_id: targetId } })
            .then(({ data }) => setPreview(data))
            .catch((e) => setError(e.response?.data?.message ?? 'Couldn’t check that workspace.'))
            .finally(() => setLoading(false));
    }, [targetId]);

    const move = () => {
        router.post(
            route('projects.move', [workspace.slug, project.slug]),
            { target_workspace_id: Number(targetId), confirm_name: confirmName },
            {
                onStart: () => setMoving(true),
                onFinish: () => setMoving(false),
                onError: (errors) => setError(errors.confirm_name ?? errors.target_workspace_id ?? 'The project could not be moved.'),
            },
        );
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <ArrowRightLeft className="size-5 text-primary" />
                    Move to another workspace
                </CardTitle>
                <CardDescription>
                    Everything moves with the project: boards and issues, docs, whiteboards, plans, task lists, helpdesks and tickets, labels and issue types.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {targets.length === 0 ? (
                    <p className="text-sm text-muted-foreground">You can move a project into another workspace where you’re also an admin. You don’t administer any other workspace yet.</p>
                ) : (
                    <>
                        <div className="space-y-2">
                            <Label>Move to</Label>
                            <Select value={targetId} onValueChange={(v) => { setTargetId(v); setConfirmName(''); }}>
                                <SelectTrigger className="max-w-sm">
                                    <SelectValue placeholder="Choose a workspace" />
                                </SelectTrigger>
                                <SelectContent>
                                    {targets.map((t) => (
                                        <SelectItem key={t.id} value={String(t.id)}>
                                            {t.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {loading && <Loader2 className="size-4 animate-spin text-muted-foreground" />}

                        {preview && target && (
                            <div className="space-y-3 rounded-lg border bg-muted/30 p-4 text-sm">
                                {preview.key_taken ? (
                                    <p className="flex items-start gap-2 text-destructive">
                                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                        “{target.name}” already has a project with the key {project.key}. Change this project’s key under General first: it’s part of every issue and ticket key.
                                    </p>
                                ) : (
                                    <>
                                        <div>
                                            <p className="mb-1 flex items-center gap-2 font-medium">
                                                <UserCheck className="size-4 text-green-600" /> Keep access ({preview.keep.length})
                                            </p>
                                            <p className="text-muted-foreground">
                                                {preview.keep.length ? `${preview.keep.map((p) => p.name).join(', ')}.` : 'Nobody from the project is in that workspace yet.'} Admins of “{target.name}” see every project there anyway.
                                            </p>
                                        </div>
                                        {preview.remove.length > 0 && (
                                            <div>
                                                <p className="mb-1 flex items-center gap-2 font-medium">
                                                    <UserMinus className="size-4 text-amber-600" /> Lose access ({preview.remove.length})
                                                </p>
                                                <p className="text-muted-foreground">
                                                    {preview.remove.map((p) => `${p.name} (${p.email})`).join(', ')}. They aren’t in “{target.name}”: invite them to the workspace, then to the project.
                                                </p>
                                            </div>
                                        )}
                                        {preview.external_links > 0 && (
                                            <p className="text-muted-foreground">
                                                {preview.external_links} link{preview.external_links === 1 ? '' : 's'} to issues in other projects will be removed.
                                            </p>
                                        )}
                                        {preview.new_slug !== project.slug && (
                                            <p className="text-muted-foreground">The address changes to …/projects/{preview.new_slug} (the old name is taken there).</p>
                                        )}
                                        <p className="text-muted-foreground">Old links that include “{workspace.name}” in the address stop working.</p>
                                        <div className="space-y-2 pt-1">
                                            <Label htmlFor="confirm-move">
                                                Type <span className="font-mono">{project.name}</span> to confirm
                                            </Label>
                                            <div className="flex flex-wrap gap-2">
                                                <Input id="confirm-move" value={confirmName} onChange={(e) => setConfirmName(e.target.value)} className="max-w-sm" autoComplete="off" />
                                                <Button onClick={move} disabled={moving || confirmName.trim() !== project.name}>
                                                    {moving && <Loader2 className="size-4 animate-spin" />}
                                                    Move to {target.name}
                                                </Button>
                                            </div>
                                        </div>
                                    </>
                                )}
                            </div>
                        )}
                        {error && <p className="text-sm text-destructive">{error}</p>}
                    </>
                )}
            </CardContent>
        </Card>
    );
}
