import type { SourcePreviewProps } from '@/components/imports/sources';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { ProjectArchivePreview } from '@/types';
import { usePage } from '@inertiajs/react';
import { Loader2, Play, ShieldCheck } from 'lucide-react';
import { useState, type FormEvent } from 'react';

const UNASSIGNED = 'none';

const COUNT_LABELS: Record<string, string> = {
    boards: 'boards',
    sprints: 'sprints',
    releases: 'releases',
    issues: 'issues',
    comments: 'comments',
    attachments: 'attachments',
    links: 'issue links',
    labels: 'labels',
    custom_fields: 'custom fields',
    pages: 'docs and folders',
    task_lists: 'task lists',
    tasks: 'tasks',
    plans: 'plans',
    helpdesks: 'helpdesks',
};

/** Restore a Brainitive project export (BRAI-81): what's inside, the new name / key, and who is who. */
export default function ArchivePreview({ run, busy, onStart }: SourcePreviewProps) {
    const preview = run.preview as unknown as ProjectArchivePreview;
    const errors = usePage().props.errors as Record<string, string>;
    const [name, setName] = useState(preview.suggested.name);
    const [key, setKey] = useState(preview.suggested.key);
    const [people, setPeople] = useState<Record<string, string>>(() =>
        Object.fromEntries(preview.people.map((p) => [p.ref, p.member_id ? String(p.member_id) : UNASSIGNED])),
    );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        onStart({
            name,
            key,
            people: Object.fromEntries(Object.entries(people).map(([ref, user]) => [ref, user === UNASSIGNED ? null : Number(user)])),
        });
    };

    const counts = Object.entries(preview.counts).filter(([, count]) => count > 0);
    const peopleError = Object.entries(errors).find(([k]) => k.startsWith('options.people'))?.[1];

    return (
        <form onSubmit={submit} className="space-y-4" aria-label="Project restore preview">
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">{preview.source.name}</CardTitle>
                    <CardDescription className="flex items-center gap-1.5">
                        <ShieldCheck className="size-4 text-green-600" aria-hidden />
                        Checked: format {preview.format_version}, every file matches its checksum.
                        {preview.exported_at && <> Exported {new Date(preview.exported_at).toLocaleString()}{preview.app_version ? ` (Brainitive ${preview.app_version})` : ''}.</>}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-5 text-sm">
                    {counts.length > 0 ? (
                        <ul aria-label="Contents" className="flex flex-wrap gap-x-4 gap-y-1 text-muted-foreground">
                            {counts.map(([what, count]) => <li key={what}><span className="font-medium text-foreground">{count}</span> {COUNT_LABELS[what] ?? what}</li>)}
                        </ul>
                    ) : (
                        <p className="text-muted-foreground">The export is empty: you get a new project without content.</p>
                    )}

                    <div className="grid gap-4 sm:grid-cols-[1fr_10rem]">
                        <div className="space-y-1.5">
                            <Label htmlFor="restore-name">New project name</Label>
                            <Input id="restore-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={255} required />
                            {errors['options.name'] && <p className="text-sm text-destructive">{errors['options.name']}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="restore-key">Key</Label>
                            <Input id="restore-key" value={key} onChange={(e) => setKey(e.target.value.toUpperCase().replace(/[^A-Z]/g, ''))} maxLength={10} required />
                            {errors['options.key'] && <p className="text-sm text-destructive">{errors['options.key']}</p>}
                        </div>
                    </div>
                    <p className="text-xs text-muted-foreground">Everything is created new: issues get new keys ({key || 'KEY'}-1, …), links between them point at the copies.</p>
                </CardContent>
            </Card>

            {preview.people.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">People</CardTitle>
                        <CardDescription>
                            Who is who in this workspace. Matched by email where possible; mapped people are added to the new project as editors.
                            Nobody is invited. Unmapped issues and tasks stay unassigned; comments keep the original author's name.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul aria-label="People in the export" className="space-y-2 text-sm">
                            {preview.people.map((person) => (
                                <li key={person.ref} className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="min-w-0">
                                        <span className="font-medium">{person.name}</span>
                                        {person.email && <span className="ml-1 text-muted-foreground">{person.email}</span>}
                                    </span>
                                    <Select value={people[person.ref]} onValueChange={(value) => setPeople((p) => ({ ...p, [person.ref]: value }))}>
                                        <SelectTrigger aria-label={`${person.name} is`} className="w-56"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={UNASSIGNED}>Nobody (unassigned)</SelectItem>
                                            {preview.members.map((user) => <SelectItem key={user.id} value={String(user.id)}>{user.name}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </li>
                            ))}
                        </ul>
                        {peopleError && <p className="mt-2 text-sm text-destructive">{peopleError}</p>}
                    </CardContent>
                </Card>
            )}

            {preview.omitted.length > 0 && (
                <Card>
                    <CardContent className="py-4 text-sm">
                        <p className="font-medium">Not in the export</p>
                        <ul aria-label="Not in the export" className="mt-1 list-disc space-y-0.5 pl-5 text-muted-foreground">
                            {preview.omitted.map((o) => (
                                <li key={o.what}>
                                    <span className="text-foreground">{o.what}{o.count ? ` (${o.count})` : ''}</span>: {o.why}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            )}

            <Button type="submit" disabled={busy || !name.trim() || !key}>
                {busy ? <Loader2 className="size-4 animate-spin" /> : <Play className="size-4" />}
                Restore as a new project
            </Button>
        </form>
    );
}
