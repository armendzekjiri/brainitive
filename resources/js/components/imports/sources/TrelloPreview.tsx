import type { SourcePreviewProps } from '@/components/imports/sources';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePage } from '@inertiajs/react';
import { AlertTriangle, Loader2, Play } from 'lucide-react';
import { useState, type FormEvent } from 'react';

/** TrelloImporter::preview() (BRAI-65). */
interface TrelloPreviewData {
    board: { name: string; url: string | null };
    lists: { id: string; name: string; cards: number }[];
    archived_lists: number;
    done_list: string | null;
    cards: { total: number; open: number; archived: number };
    labels: { id: string; name: string; color: string; cards: number; exists: boolean }[];
    checklists: { checklists: number; items: number; cards: number };
    members: { id: string; name: string; username: string; email: string | null; cards: number; suggested_user_id: number | null }[];
    project_members: { id: number; name: string; email: string | null }[];
    attachments: number;
    unsupported: Record<string, number>;
    warnings: string[];
}

const UNASSIGNED = 'none';

/** Trello preview + options: board name, done list, archived cards, checklists, attachments, people. */
export default function TrelloPreview({ run, busy, onStart }: SourcePreviewProps) {
    const preview = run.preview as unknown as TrelloPreviewData;
    const errors = usePage().props.errors as Record<string, string>;
    const [boardName, setBoardName] = useState(preview.board.name);
    const [doneList, setDoneList] = useState(preview.done_list ?? preview.lists[preview.lists.length - 1]?.id ?? '');
    const [includeArchived, setIncludeArchived] = useState(false);
    const [checklists, setChecklists] = useState<'description' | 'subtasks'>('description');
    const [attachmentLinks, setAttachmentLinks] = useState(false);
    const [members, setMembers] = useState<Record<string, string>>(() =>
        Object.fromEntries(preview.members.map((m) => [m.id, m.suggested_user_id ? String(m.suggested_user_id) : UNASSIGNED])),
    );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        onStart({
            board_name: boardName,
            done_list: doneList,
            include_archived: includeArchived,
            checklists,
            attachment_links: attachmentLinks,
            members: Object.fromEntries(Object.entries(members).map(([id, user]) => [id, user === UNASSIGNED ? null : Number(user)])),
        });
    };

    const unsupported = Object.entries(preview.unsupported ?? {});
    const memberError = Object.entries(errors).find(([key]) => key.startsWith('options.members'))?.[1];

    return (
        <form onSubmit={submit} className="space-y-4" aria-label="Trello import preview">
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">{preview.board.name}</CardTitle>
                    <CardDescription>
                        {preview.cards.open} open card{preview.cards.open === 1 ? '' : 's'} in {preview.lists.length} list{preview.lists.length === 1 ? '' : 's'}
                        {preview.cards.archived > 0 && <> · {preview.cards.archived} archived</>}
                        {preview.archived_lists > 0 && <> · {preview.archived_lists} archived list{preview.archived_lists === 1 ? '' : 's'} (not imported)</>}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-5 text-sm">
                    <div className="space-y-1.5">
                        <Label htmlFor="trello-board-name">New board name</Label>
                        <Input id="trello-board-name" value={boardName} onChange={(e) => setBoardName(e.target.value)} maxLength={255} className="max-w-md" required />
                        {errors['options.board_name'] && <p className="text-sm text-destructive">{errors['options.board_name']}</p>}
                    </div>

                    <div className="space-y-1.5">
                        <h3 className="font-medium">Lists → columns</h3>
                        <ul aria-label="Lists" className="divide-y rounded-md border">
                            {preview.lists.map((list) => (
                                <li key={list.id} className="flex items-center justify-between gap-2 px-3 py-2">
                                    <span className="truncate">{list.name}</span>
                                    <span className="flex shrink-0 items-center gap-2 text-muted-foreground">
                                        {list.id === doneList && <Badge variant="outline" className="border-green-500/40 text-green-700 dark:text-green-400">Done column</Badge>}
                                        {list.cards} card{list.cards === 1 ? '' : 's'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="trello-done-list">Done column</Label>
                        <Select value={doneList} onValueChange={setDoneList}>
                            <SelectTrigger id="trello-done-list" aria-label="Done column" className="max-w-sm"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                {preview.lists.map((list) => <SelectItem key={list.id} value={list.id}>{list.name}</SelectItem>)}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">Issues in this column count as done.</p>
                    </div>

                    {preview.labels.length > 0 && (
                        <div className="space-y-1.5">
                            <h3 className="font-medium">Labels</h3>
                            <ul aria-label="Labels" className="flex flex-wrap gap-2">
                                {preview.labels.map((label) => (
                                    <li key={label.id}>
                                        <Badge variant="outline" className="gap-1.5">
                                            <span className="size-2.5 rounded-full" style={{ backgroundColor: label.color }} aria-hidden />
                                            {label.name}
                                            <span className="text-muted-foreground">{label.cards}</span>
                                            {label.exists && <span className="text-muted-foreground">(exists)</span>}
                                        </Badge>
                                    </li>
                                ))}
                            </ul>
                            <p className="text-xs text-muted-foreground">Labels the imported cards use are created, or reused when the project already has one with the same name.</p>
                        </div>
                    )}

                    <fieldset className="space-y-2">
                        <legend className="font-medium">Options</legend>
                        {preview.cards.archived > 0 && (
                            <div className="flex items-center gap-2">
                                <input type="checkbox" id="trello-archived" checked={includeArchived} onChange={(e) => setIncludeArchived(e.target.checked)} className="size-4 rounded border-input" />
                                <Label htmlFor="trello-archived" className="font-normal">Include {preview.cards.archived} archived card{preview.cards.archived === 1 ? '' : 's'} (in the done column)</Label>
                            </div>
                        )}
                        {preview.attachments > 0 && (
                            <div className="flex items-center gap-2">
                                <input type="checkbox" id="trello-attachments" checked={attachmentLinks} onChange={(e) => setAttachmentLinks(e.target.checked)} className="size-4 rounded border-input" />
                                <Label htmlFor="trello-attachments" className="font-normal">Add links to the {preview.attachments} attachment{preview.attachments === 1 ? '' : 's'} (the files stay in Trello)</Label>
                            </div>
                        )}
                        {preview.checklists.items > 0 && (
                            <div role="radiogroup" aria-label="Checklists" className="space-y-1">
                                <p className="text-muted-foreground">{preview.checklists.checklists} checklist{preview.checklists.checklists === 1 ? '' : 's'} with {preview.checklists.items} item{preview.checklists.items === 1 ? '' : 's'}:</p>
                                <div className="flex items-center gap-2">
                                    <input type="radio" id="trello-checklists-description" name="trello-checklists" checked={checklists === 'description'} onChange={() => setChecklists('description')} className="size-4" />
                                    <Label htmlFor="trello-checklists-description" className="font-normal">Keep them as checklists in the description</Label>
                                </div>
                                <div className="flex items-center gap-2">
                                    <input type="radio" id="trello-checklists-subtasks" name="trello-checklists" checked={checklists === 'subtasks'} onChange={() => setChecklists('subtasks')} className="size-4" />
                                    <Label htmlFor="trello-checklists-subtasks" className="font-normal">Turn checklist items into subtasks</Label>
                                </div>
                            </div>
                        )}
                    </fieldset>
                </CardContent>
            </Card>

            {preview.members.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">People</CardTitle>
                        <CardDescription>Pick who gets each Trello member's cards. Nobody is invited; unmapped cards stay unassigned.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul aria-label="Trello members" className="space-y-2 text-sm">
                            {preview.members.map((member) => (
                                <li key={member.id} className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="min-w-0">
                                        <span className="font-medium">{member.name}</span>
                                        <span className="ml-1 text-muted-foreground">
                                            {member.username ? `@${member.username}` : ''}{member.email ? ` · ${member.email}` : ''} · {member.cards} card{member.cards === 1 ? '' : 's'}
                                        </span>
                                    </span>
                                    <Select value={members[member.id]} onValueChange={(value) => setMembers((m) => ({ ...m, [member.id]: value }))}>
                                        <SelectTrigger aria-label={`Assign ${member.name}'s cards to`} className="w-56"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={UNASSIGNED}>Unassigned</SelectItem>
                                            {preview.project_members.map((user) => <SelectItem key={user.id} value={String(user.id)}>{user.name}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </li>
                            ))}
                        </ul>
                        {memberError && <p className="mt-2 text-sm text-destructive">{memberError}</p>}
                    </CardContent>
                </Card>
            )}

            {(unsupported.length > 0 || preview.warnings.length > 0) && (
                <Card>
                    <CardContent className="space-y-3 py-4 text-sm">
                        {unsupported.length > 0 && (
                            <div>
                                <p className="font-medium">Not imported</p>
                                <ul aria-label="Not imported" className="mt-1 list-disc pl-5 text-muted-foreground">
                                    {unsupported.map(([what, count]) => <li key={what}>{what}: {count}</li>)}
                                </ul>
                            </div>
                        )}
                        {preview.warnings.map((warning) => (
                            <p key={warning} className="flex items-start gap-2 text-amber-800 dark:text-amber-300"><AlertTriangle className="mt-0.5 size-4 shrink-0" /> {warning}</p>
                        ))}
                    </CardContent>
                </Card>
            )}

            <Button type="submit" disabled={busy || boardName.trim() === '' || doneList === ''}>
                {busy ? <Loader2 className="size-4 animate-spin" /> : <Play className="size-4" />} Start import
            </Button>
        </form>
    );
}
