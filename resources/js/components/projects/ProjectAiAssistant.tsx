import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { safeHtmlProp } from '@/lib/sanitize';
import type { Project, Workspace } from '@/types';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { Check, ClipboardCopy, FilePlus2, FileText, ListChecks, Loader2, Sparkles, Terminal } from 'lucide-react';
import { useState } from 'react';

type Mode = 'summary' | 'task' | 'prompt';

const MODES: { mode: Mode; label: string; icon: typeof FileText; placeholder?: string }[] = [
    { mode: 'summary', label: 'Summarize project', icon: FileText },
    { mode: 'task', label: 'Draft a task', icon: ListChecks, placeholder: 'e.g. Add CSV export for the invoice list' },
    { mode: 'prompt', label: 'Implementation prompt', icon: Terminal, placeholder: 'e.g. Password reset by email' },
];

/**
 * AI help grounded in the project description: the result is shown for review, then
 * copied, saved as a draft doc or (summary) used as the project description after a confirm.
 */
export default function ProjectAiAssistant({
    workspace,
    project,
    aiConfigured,
    canUpdate,
    canCreatePages,
}: {
    workspace: Workspace;
    project: Project;
    aiConfigured: boolean;
    canUpdate: boolean;
    canCreatePages: boolean;
}) {
    const [mode, setMode] = useState<Mode | null>(null);
    const [request, setRequest] = useState('');
    const [result, setResult] = useState<string | null>(null);
    const [error, setError] = useState<{ message: string; needsDescription?: boolean } | null>(null);
    const [busy, setBusy] = useState(false);
    const [copied, setCopied] = useState(false);
    const [confirmReplace, setConfirmReplace] = useState(false);
    const current = MODES.find((m) => m.mode === mode);

    const open = (m: Mode) => {
        setMode(m);
        setRequest('');
        setResult(null);
        setError(null);
        if (m === 'summary') void generate(m, '');
    };

    const generate = async (m: Mode, text: string) => {
        setBusy(true);
        setError(null);
        setResult(null);
        try {
            const { data } = await axios.post(route('ai.project-assist', [workspace.slug, project.slug]), { mode: m, request: text || undefined });
            if (data.success) setResult(data.content);
            else setError({ message: data.message });
        } catch (e: any) {
            setError({ message: e.response?.data?.message ?? 'The AI request failed.', needsDescription: e.response?.data?.needs_description });
        } finally {
            setBusy(false);
        }
    };

    const copy = async () => {
        if (!result) return;
        const text = new DOMParser().parseFromString(result, 'text/html').body.innerText;
        await navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    const saveDoc = async () => {
        if (!result || !current) return;
        setBusy(true);
        try {
            const title = mode === 'summary' ? `${project.name} — summary` : `${current.label}: ${request}`.slice(0, 200);
            const { data } = await axios.post(route('ai.project-assist.save', [workspace.slug, project.slug]), { title, html: result });
            router.visit(data.url);
        } catch {
            setError({ message: 'Couldn’t save the doc.' });
            setBusy(false);
        }
    };

    const replaceDescription = () => {
        if (!result) return;
        router.put(route('projects.update', [workspace.slug, project.slug]), { name: project.name, description: result }, {
            preserveScroll: true,
            onSuccess: () => {
                setConfirmReplace(false);
                setMode(null);
            },
        });
    };

    if (!aiConfigured) {
        return (
            <p className="text-xs text-muted-foreground flex items-center gap-1.5">
                <Sparkles className="size-3.5" /> Set up AI in{' '}
                <Link href={route('workspaces.edit', workspace.slug)} className="underline hover:text-foreground">
                    workspace settings
                </Link>{' '}
                to summarize this project and draft tasks from its description.
            </p>
        );
    }

    return (
        <>
            <Card>
                <CardContent className="p-4 flex flex-wrap items-center gap-2">
                    <span className="flex items-center gap-1.5 text-sm font-medium mr-2">
                        <Sparkles className="size-4 text-primary" /> AI assistant
                    </span>
                    {MODES.map((m) => (
                        <Button key={m.mode} variant="outline" size="sm" className="gap-1.5" onClick={() => open(m.mode)}>
                            <m.icon className="size-3.5" /> {m.label}
                        </Button>
                    ))}
                </CardContent>
            </Card>

            <Dialog open={mode !== null} onOpenChange={(o) => !o && !busy && setMode(null)}>
                <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Sparkles className="size-4 text-primary" /> {current?.label}
                        </DialogTitle>
                        <DialogDescription>Based on the description of {project.name}. Review the result before you use it.</DialogDescription>
                    </DialogHeader>

                    {mode !== 'summary' && (
                        <form
                            className="flex gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (request.trim()) void generate(mode!, request.trim());
                            }}
                        >
                            <Input value={request} onChange={(e) => setRequest(e.target.value)} placeholder={current?.placeholder} autoFocus maxLength={2000} />
                            <Button type="submit" disabled={busy || !request.trim()}>
                                Generate
                            </Button>
                        </form>
                    )}

                    {busy && (
                        <div className="flex items-center gap-2 text-sm text-muted-foreground py-6 justify-center">
                            <Loader2 className="size-4 animate-spin" /> Thinking…
                        </div>
                    )}
                    {error && (
                        <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
                            {error.message}{' '}
                            {error.needsDescription && canUpdate && (
                                <Link href={route('projects.edit', [workspace.slug, project.slug])} className="underline">
                                    Edit the description
                                </Link>
                            )}
                        </div>
                    )}
                    {result && !busy && (
                        <div className="rounded-lg border bg-muted/30 p-4 prose prose-sm dark:prose-invert max-w-none" dangerouslySetInnerHTML={safeHtmlProp(result)} />
                    )}

                    {result && !busy && (
                        <DialogFooter className="flex-wrap gap-2 sm:justify-between">
                            <Button variant="ghost" size="sm" onClick={() => (mode === 'summary' ? generate('summary', '') : generate(mode!, request.trim()))}>
                                Try again
                            </Button>
                            <div className="flex flex-wrap gap-2">
                                <Button variant="outline" size="sm" onClick={copy}>
                                    {copied ? <Check className="size-4" /> : <ClipboardCopy className="size-4" />} {copied ? 'Copied' : 'Copy'}
                                </Button>
                                {canCreatePages && (
                                    <Button variant="outline" size="sm" onClick={saveDoc}>
                                        <FilePlus2 className="size-4" /> Save as draft doc
                                    </Button>
                                )}
                                {mode === 'summary' && canUpdate && (
                                    <Button size="sm" onClick={() => setConfirmReplace(true)}>
                                        Use as project description
                                    </Button>
                                )}
                            </div>
                        </DialogFooter>
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={confirmReplace} onOpenChange={setConfirmReplace}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Replace the project description?</DialogTitle>
                        <DialogDescription>The current description is overwritten with this summary. Copy it first if you want to keep it.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirmReplace(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={replaceDescription}>
                            Replace description
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
