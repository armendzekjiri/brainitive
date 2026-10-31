import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DONE_RETENTION_OPTIONS, retentionLabel } from '@/lib/doneRetention';
import type { Board, PageProps, Project, Workspace } from '@/types';
import { router } from '@inertiajs/react';
import { Archive } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

/**
 * Board settings → "Done column" (BRAI-346): how long finished cards stay on a Kanban board,
 * like Jira's "hide completed issues older than…". Older cards stay in the project.
 */
export default function DoneRetentionSettings({ workspace, project, board }: { workspace: Workspace; project: Project; board: Board }) {
    const stored = Number(board.settings?.done_retention_days ?? 0);
    const [days, setDays] = useState(String(stored));
    const [saving, setSaving] = useState(false);
    const isScrum = board.type === 'scrum';
    const dirty = days !== String(stored);

    const save = () => {
        setSaving(true);
        router.put(route('boards.done-retention.update', [workspace.slug, project.slug, board.slug]), { done_retention_days: Number(days) }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => toast.success((page.props as PageProps).flash?.success ?? 'Saved.'),
            onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Could not save the setting.'),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <Card id="done-column" className="scroll-mt-6">
            <CardHeader>
                <CardTitle className="flex items-center gap-2"><Archive className="size-5" /> Done column</CardTitle>
                <CardDescription>
                    Keep the board short, like Jira: finished cards leave the board after a while. They stay in the project — the list
                    view, search and links still find them, and the done column has a “Show” link for the older ones.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {isScrum && (
                    <p className="text-sm text-muted-foreground">
                        This board uses sprints: it only shows the running sprint, so every done card of the sprint stays visible. The
                        setting applies when the board is a Kanban board again.
                    </p>
                )}
                <div className="space-y-2">
                    <Label htmlFor="done-retention">Keep done cards on the board for</Label>
                    <Select value={days} onValueChange={setDays} disabled={isScrum}>
                        <SelectTrigger id="done-retention" className="max-w-xs" aria-label="Keep done cards on the board for">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {DONE_RETENTION_OPTIONS.map((option) => (
                                <SelectItem key={option} value={String(option)}>{retentionLabel(option)}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <p className="text-xs text-muted-foreground">
                        Counted from when a card reached a done column. New boards start with 14 days; boards from before this setting show all.
                    </p>
                </div>
                <div className="flex items-center gap-3 border-t pt-4">
                    <Button onClick={save} disabled={!dirty || saving || isScrum}>Save</Button>
                    {dirty && <span className="text-xs text-amber-700 dark:text-amber-400">Unsaved changes</span>}
                </div>
            </CardContent>
        </Card>
    );
}
