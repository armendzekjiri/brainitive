import { Head, Link, useForm } from '@inertiajs/react';
import { CalendarClock, Check, Copy, ExternalLink, Plus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import AppLayout from '@/Layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { BookingPageListItem, BookingTemplateData } from '@/types';

interface Props {
    pages: BookingPageListItem[];
    templates: BookingTemplateData[];
    workspaces: { id: number; name: string }[];
}

/** Your booking pages (BRAI-158): one per purpose ("Clients", "Emergency", …), each with its own link. */
export default function Index({ pages, templates, workspaces }: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm({ workspace_id: workspaces[0]?.id ?? 0, template: 'blank', title: '' });

    const copy = async (url: string) => {
        try {
            await navigator.clipboard.writeText(url);
            toast.success('Link copied');
        } catch {
            toast.error('Couldn’t copy the link');
        }
    };

    return (
        <AppLayout>
            <Head title="Booking pages" />
            <div className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight"><CalendarClock className="size-6" />Booking pages</h1>
                        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                            Share a link and let people book a time with you. Free times come from your working hours minus what’s in your calendars.
                        </p>
                    </div>
                    <Button onClick={() => setOpen(true)} disabled={workspaces.length === 0}><Plus className="mr-1 h-4 w-4" />New booking page</Button>
                </div>

                {pages.length === 0 ? (
                    <Card><CardContent className="py-10 text-center text-sm text-muted-foreground">No booking pages yet. Start from a template — you can change everything afterwards.</CardContent></Card>
                ) : (
                    <ul className="space-y-3" aria-label="Your booking pages">
                        {pages.map((p) => (
                            <li key={p.uuid}>
                                <Card>
                                    <CardContent className="flex flex-wrap items-center gap-4 p-4">
                                        <div className="min-w-0 flex-1">
                                            <Link href={route('booking-pages.show', p.uuid)} className="font-medium hover:underline">{p.title}</Link>
                                            <p className="text-sm text-muted-foreground">
                                                {p.workspace} · {p.types_count} meeting {p.types_count === 1 ? 'type' : 'types'} · {p.upcoming_count} upcoming
                                            </p>
                                        </div>
                                        {!p.enabled && <Badge variant="secondary">Off</Badge>}
                                        <Button variant="outline" size="sm" onClick={() => copy(p.public_url)}><Copy className="mr-1 h-3.5 w-3.5" />Copy link</Button>
                                        <Button variant="ghost" size="icon" asChild aria-label={`Open ${p.title}`}>
                                            <a href={p.public_url} target="_blank" rel="noopener noreferrer"><ExternalLink className="h-4 w-4" /></a>
                                        </Button>
                                    </CardContent>
                                </Card>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>New booking page</DialogTitle>
                        <DialogDescription>A template fills in meeting types, hours and questions. Everything stays editable.</DialogDescription>
                    </DialogHeader>
                    <form
                        id="new-booking-page"
                        onSubmit={(e) => { e.preventDefault(); form.post(route('booking-pages.store')); }}
                        className="space-y-4"
                    >
                        <div role="radiogroup" aria-label="Template" className="grid gap-2 sm:grid-cols-2">
                            {templates.map((t) => (
                                <button
                                    key={t.key}
                                    type="button"
                                    role="radio"
                                    aria-checked={form.data.template === t.key}
                                    onClick={() => form.setData('template', t.key)}
                                    className={cn('rounded-xl border p-3 text-left transition-colors hover:border-primary', form.data.template === t.key && 'border-primary bg-primary/5')}
                                >
                                    <span className="flex items-center justify-between font-medium">{t.name}{form.data.template === t.key && <Check className="h-4 w-4 text-primary" />}</span>
                                    <span className="mt-1 block text-xs text-muted-foreground">{t.description}</span>
                                    <span className="mt-1 block text-xs">{t.types.join(' · ')}</span>
                                </button>
                            ))}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="page-title">Title (optional)</Label>
                                <Input id="page-title" value={form.data.title} placeholder="From the template" onChange={(e) => form.setData('title', e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="page-workspace">Workspace</Label>
                                <select id="page-workspace" className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm" value={form.data.workspace_id} onChange={(e) => form.setData('workspace_id', Number(e.target.value))}>
                                    {workspaces.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                                </select>
                                <p className="text-xs text-muted-foreground">Shown as who runs the page; cards for bookings go to its boards.</p>
                            </div>
                        </div>
                        {Object.values(form.errors)[0] && <p className="text-sm text-destructive">{Object.values(form.errors)[0]}</p>}
                    </form>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" form="new-booking-page" disabled={form.processing}>Create</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
