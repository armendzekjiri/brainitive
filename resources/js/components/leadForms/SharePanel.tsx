import { useState } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { Check, Code2, Copy, ExternalLink, Globe, Link2, PauseCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { LeadFormPayload } from '@/lib/leadForms';

function slugify(value: string): string {
    return value.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
}

function escapeAttr(value: string): string {
    return value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

export function CopyButton({ value, label = 'Copy' }: { value: string; label?: string }) {
    const [copied, setCopied] = useState(false);
    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            className="shrink-0 gap-1.5"
            onClick={() => {
                void navigator.clipboard.writeText(value).then(() => {
                    setCopied(true);
                    window.setTimeout(() => setCopied(false), 1500);
                });
            }}
        >
            {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
            {copied ? 'Copied' : label}
        </Button>
    );
}

export default function SharePanel({ form, routeParams, canUpdate }: { form: LeadFormPayload; routeParams: [string, string, string]; canUpdate: boolean }) {
    const [slug, setSlug] = useState(form.public_slug ?? slugify(form.name));
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const base = `${window.location.origin}/f/`;
    const live = form.status === 'published' && !!form.public_url;
    const embedUrl = form.embed_url ?? `${base}${slug}/embed`;

    const publish = () => {
        setSaving(true);
        router.post(route('lead-forms.publish', routeParams), { public_slug: slug }, {
            preserveScroll: true,
            onSuccess: () => {
                setError(null);
                toast.success('Your form is live');
            },
            onError: (errors) => setError(errors.public_slug ?? 'Could not publish.'),
            onFinish: () => setSaving(false),
        });
    };

    const iframe = `<iframe src="${embedUrl}" title="${escapeAttr(form.name)}" style="width:100%;border:0;min-height:560px" loading="lazy" data-brainitive-form="${form.public_slug ?? slug}"></iframe>
<script>window.addEventListener("message",function(e){var d=e.data||{};if(e.origin!=="${window.location.origin}"||d.type!=="brainitive:lead-form:height"||!/^[a-z0-9-]+$/.test(d.slug))return;document.querySelectorAll('iframe[data-brainitive-form="'+d.slug+'"]').forEach(function(f){f.style.height=Math.max(200,+d.height||0)+"px"})});</script>`;

    return (
        <div className="mx-auto max-w-3xl space-y-6 p-6">
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Globe className="size-4" />
                        Public page
                        {live ? <Badge className="bg-emerald-600 hover:bg-emerald-600">Live</Badge> : <Badge variant="secondary">Draft</Badge>}
                    </CardTitle>
                    <CardDescription>A page of its own to share in emails, ads and social posts.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="space-y-1.5">
                        <div className="flex items-center gap-2">
                            <div className="flex min-w-0 flex-1 items-center overflow-hidden rounded-md border bg-muted/40 focus-within:ring-2 focus-within:ring-ring">
                                <span className="shrink-0 pl-3 text-sm text-muted-foreground">{base}</span>
                                <Input value={slug} onChange={(e) => setSlug(slugify(e.target.value))} disabled={!canUpdate} className="border-0 bg-transparent pl-0 shadow-none focus-visible:ring-0" aria-label="Form address" />
                            </div>
                            {canUpdate && (
                                <Button onClick={publish} disabled={saving || slug.length < 3}>
                                    {live ? (slug === form.public_slug ? 'Update' : 'Change address') : 'Publish'}
                                </Button>
                            )}
                        </div>
                        {error && <p className="text-xs text-destructive">{error}</p>}
                        {live && slug !== form.public_slug && <p className="text-xs text-amber-600">Changing the address breaks links you already shared.</p>}
                    </div>

                    {live && form.public_url && (
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="flex min-w-0 flex-1 items-center gap-2 rounded-md border px-3 py-2 text-sm">
                                <Link2 className="size-4 shrink-0 text-muted-foreground" />
                                <span className="truncate">{form.public_url}</span>
                            </div>
                            <CopyButton value={form.public_url} label="Copy link" />
                            <Button variant="outline" size="sm" className="gap-1.5" asChild>
                                <a href={form.public_url} target="_blank" rel="noopener noreferrer">
                                    <ExternalLink className="size-4" />
                                    Open
                                </a>
                            </Button>
                        </div>
                    )}

                    {live && (
                        <p className="text-xs text-muted-foreground">
                            Tip: add <code className="rounded bg-muted px-1">?utm_source=newsletter</code> to the link to see where leads come from, and fill hidden questions with <code className="rounded bg-muted px-1">?key=value</code>.
                        </p>
                    )}

                    {live && canUpdate && (
                        <Button variant="ghost" size="sm" className="gap-1.5 text-muted-foreground" onClick={() => router.delete(route('lead-forms.unpublish', routeParams), { preserveScroll: true })}>
                            <PauseCircle className="size-4" />
                            Take offline
                        </Button>
                    )}
                </CardContent>
            </Card>

            <Card className={live ? '' : 'opacity-60'}>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Code2 className="size-4" />
                        Embed on your website
                    </CardTitle>
                    <CardDescription>Paste this where the form should appear (WordPress, Webflow, Wix, plain HTML). It resizes itself.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    <pre className="max-h-48 overflow-auto whitespace-pre-wrap break-all rounded-md bg-muted p-3 font-mono text-xs">{iframe}</pre>
                    <div className="flex items-center justify-between gap-2">
                        <p className="text-xs text-muted-foreground">{live ? 'The embed shows the form without page chrome.' : 'Publish the form first — drafts are not shown.'}</p>
                        <CopyButton value={iframe} label="Copy code" />
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
