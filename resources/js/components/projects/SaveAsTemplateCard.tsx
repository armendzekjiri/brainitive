import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import type { Project, ProjectTemplate, ProjectTemplateSection, ProjectTemplateSummary, Workspace } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import TemplateSummary, { SECTION_LABELS } from '@/components/projects/TemplateSummary';
import { LayoutTemplate, Loader2 } from 'lucide-react';

const ALL_SECTIONS: ProjectTemplateSection[] = ['issues', 'pages', 'task_lists', 'plans'];

/**
 * Project settings → "Save as template" (workspace admins, BRAI-72): preview what gets
 * captured, choose what to leave out, save as a new template or a new version of one.
 */
export default function SaveAsTemplateCard({ workspace, project, templates }: {
    workspace: Workspace;
    project: Project;
    templates: ProjectTemplate[];
}) {
    const [open, setOpen] = useState(false);
    const [target, setTarget] = useState<string>('new');
    const [name, setName] = useState(project.name);
    const [description, setDescription] = useState('');
    const [include, setInclude] = useState<ProjectTemplateSection[]>(ALL_SECTIONS);
    const [summary, setSummary] = useState<ProjectTemplateSummary | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    // Live preview of what would be captured with the current choices
    useEffect(() => {
        if (!open) return;
        let cancelled = false;
        setLoading(true);
        setPreviewError(null);
        window.axios
            .get(route('projects.templates.preview', [workspace.slug, project.slug]), { params: { include: include.length ? include : ['none'] } })
            .then(({ data }: { data: { summary: ProjectTemplateSummary } }) => { if (!cancelled) setSummary(data.summary); })
            .catch((error) => { if (!cancelled) setPreviewError(error?.response?.data?.message ?? 'The preview could not be loaded.'); })
            .finally(() => { if (!cancelled) setLoading(false); });
        return () => { cancelled = true; };
    }, [open, include.join(',')]);

    const chooseTarget = (value: string) => {
        setTarget(value);
        const existing = templates.find((t) => t.uuid === value);
        setName(existing?.name ?? project.name);
        setDescription(existing?.description ?? '');
    };

    // Every opening starts fresh, so a second save never silently repeats the first one
    const openDialog = () => {
        setTarget('new');
        setName(project.name);
        setDescription('');
        setInclude(ALL_SECTIONS);
        setErrors({});
        setOpen(true);
    };

    const toggle = (section: ProjectTemplateSection) =>
        setInclude((current) => (current.includes(section) ? current.filter((s) => s !== section) : [...current, section]));

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(route('projects.templates.store', [workspace.slug, project.slug]), {
            name,
            description: description || null,
            include: include.length ? include : ['none'],
            ...(target !== 'new' ? { template: target } : {}),
        }, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => {
                setOpen(false);
                toast.success(target === 'new' ? `Saved as template “${name}”.` : `New version of “${name}” saved.`);
            },
            onError: (errs) => setErrors(errs),
        });
    };

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 gap-4">
                <div className="space-y-1">
                    <CardTitle className="flex items-center gap-2">
                        <LayoutTemplate className="size-5" />
                        Save as template
                    </CardTitle>
                    <CardDescription>
                        Reuse this project's boards, columns, fields and — if you like — its issues, docs, lists and plans when someone in {workspace.name} starts a new project.
                    </CardDescription>
                </div>
                <Button variant="outline" size="sm" onClick={openDialog}>Save as template…</Button>
            </CardHeader>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Save “{project.name}” as a template</DialogTitle>
                        <DialogDescription>
                            Everyone who can create projects in {workspace.name} can use this template — including people who aren't members of this project — and sees the content you include. Comments, attachments, history, people, sprints, releases and unpublished docs are never saved.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={save} className="grid gap-6 md:grid-cols-[minmax(0,1fr)_260px]">
                        <div className="space-y-4">
                            {templates.length > 0 && (
                                <div className="space-y-2">
                                    <Label>Save as</Label>
                                    <Select value={target} onValueChange={chooseTarget}>
                                        <SelectTrigger aria-label="Save as"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="new">A new template</SelectItem>
                                            {templates.map((t) => <SelectItem key={t.uuid} value={t.uuid}>New version of “{t.name}”</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                    {target !== 'new' && <p className="text-xs text-muted-foreground">Projects already made from it keep their copy.</p>}
                                </div>
                            )}
                            <div className="space-y-2">
                                <Label htmlFor="template-name">Template name</Label>
                                <Input id="template-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={100} required />
                                {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="template-description">Description (optional)</Label>
                                <Textarea id="template-description" value={description} onChange={(e) => setDescription(e.target.value)} maxLength={500} rows={2} placeholder="When to use this template" />
                            </div>
                            <fieldset className="space-y-1.5">
                                <legend className="mb-1 text-sm font-medium">Include</legend>
                                {ALL_SECTIONS.map((section) => (
                                    <label key={section} className="flex cursor-pointer items-center gap-2 text-sm">
                                        <input type="checkbox" className="size-4 accent-primary" checked={include.includes(section)} onChange={() => toggle(section)} />
                                        {SECTION_LABELS[section]}
                                        {section === 'pages' && <span className="text-xs text-muted-foreground">(published only)</span>}
                                    </label>
                                ))}
                                <p className="pt-1 text-xs text-muted-foreground">Boards, columns, swimlanes, labels, issue types and custom fields are always included.</p>
                            </fieldset>
                            {(errors.include || errors.template) && <p className="text-sm text-destructive">{errors.include ?? errors.template}</p>}
                        </div>

                        <div className="rounded-lg border bg-muted/20 p-3">
                            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Preview</p>
                            {loading && !summary && <span role="status"><Loader2 className="size-4 animate-spin text-muted-foreground" aria-label="Loading preview" /></span>}
                            {previewError && <p className="text-sm text-destructive">{previewError}</p>}
                            {summary && !previewError && <div className={loading ? 'opacity-60' : ''}><TemplateSummary summary={summary} /></div>}
                        </div>

                        <div className="flex justify-end gap-2 md:col-span-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={saving || !!previewError}>{saving ? 'Saving…' : target === 'new' ? 'Save template' : 'Save new version'}</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
