import { DragEvent, useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { formatDistanceToNow } from 'date-fns';
import { Check, Mail, MoreHorizontal, Phone, Plus, Search, Trash2, Webhook, Inbox } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { cn } from '@/lib/utils';
import { LeadCard, LeadColumn, LeadMember, leadTitle } from '@/lib/leadForms';

const COLUMN_COLORS = ['#64748b', '#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#ef4444', '#0ea5e9'];

interface Props {
    columns: LeadColumn[];
    members: LeadMember[];
    routeParams: [string, string, string];
    canManage: boolean;
    canEditColumns: boolean;
    onGoToShare: () => void;
    isPublished: boolean;
}

function firstError(errors: Record<string, string>, fallback: string): string {
    return Object.values(errors)[0] ?? fallback;
}

function initials(name: string): string {
    return name.split(/\s+/).map((p) => p[0]).join('').slice(0, 2).toUpperCase();
}

export default function LeadsBoard({ columns: initial, members, routeParams, canManage, canEditColumns, onGoToShare, isPublished }: Props) {
    const [columns, setColumns] = useState(initial);
    const [query, setQuery] = useState('');
    const [assignee, setAssignee] = useState('any');
    const [dragged, setDragged] = useState<LeadCard | null>(null);
    const [dropTarget, setDropTarget] = useState<{ column: number; before: number | null } | null>(null);
    const [openUuid, setOpenUuid] = useState<string | null>(() => new URLSearchParams(window.location.search).get('lead'));
    const [newColumn, setNewColumn] = useState<string | null>(null);

    useEffect(() => setColumns(initial), [initial]);

    const allLeads = useMemo(() => columns.flatMap((c) => c.leads), [columns]);
    const openLead = allLeads.find((l) => l.uuid === openUuid) ?? null;
    const total = allLeads.length;

    const matches = (lead: LeadCard) => {
        const q = query.trim().toLowerCase();
        if (q && ![lead.name, lead.email, lead.company, lead.phone, `#${lead.number}`, ...lead.answers.map((a) => a.display)].some((v) => v?.toLowerCase().includes(q))) return false;
        if (assignee === 'none') return !lead.assignee;
        if (assignee !== 'any') return lead.assignee?.id === Number(assignee);
        return true;
    };

    const move = (lead: LeadCard, columnId: number, beforeId: number | null) => {
        const target = columns.find((c) => c.id === columnId);
        if (!target) return;
        const without = target.leads.filter((l) => l.id !== lead.id);
        const at = beforeId === null ? without.length : Math.max(0, without.findIndex((l) => l.id === beforeId));
        const ordered = [...without.slice(0, at), { ...lead, column_id: columnId }, ...without.slice(at)];

        setColumns((prev) => prev.map((c) => (c.id === columnId ? { ...c, leads: ordered } : { ...c, leads: c.leads.filter((l) => l.id !== lead.id) })));
        router.patch(
            route('leads.move', [...routeParams, lead.id]),
            { column_id: columnId, ids: ordered.map((l) => l.id) },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    setColumns(initial);
                    toast.error(firstError(errors, 'Could not move the lead.'));
                },
            },
        );
    };

    const onDrop = (e: DragEvent, columnId: number) => {
        e.preventDefault();
        if (dragged) move(dragged, columnId, dropTarget?.column === columnId ? dropTarget.before : null);
        setDragged(null);
        setDropTarget(null);
    };

    const columnRoute = (name: string, column?: LeadColumn) => route(name, column ? [...routeParams, column.id] : routeParams);

    const addColumn = () => {
        if (!newColumn?.trim()) return setNewColumn(null);
        router.post(columnRoute('lead-forms.columns.store'), { name: newColumn.trim(), color: COLUMN_COLORS[columns.length % COLUMN_COLORS.length] }, {
            preserveScroll: true,
            onSuccess: () => setNewColumn(null),
            onError: (errors) => toast.error(firstError(errors, 'Could not add the column.')),
        });
    };

    return (
        <div className="flex h-full flex-col">
            <div className="flex flex-wrap items-center gap-2 border-b px-6 py-2.5">
                <div className="relative w-full max-w-xs">
                    <Search className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search leads…" className="h-9 pl-8" />
                </div>
                <Select value={assignee} onValueChange={setAssignee}>
                    <SelectTrigger className="h-9 w-44">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="any">Anyone</SelectItem>
                        <SelectItem value="none">Unassigned</SelectItem>
                        {members.map((m) => (
                            <SelectItem key={m.id} value={String(m.id)}>
                                {m.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <span className="ml-auto text-xs text-muted-foreground">
                    {total} {total === 1 ? 'lead' : 'leads'}
                </span>
            </div>

            {total === 0 && !query && (
                <div className="mx-6 mt-4 flex items-center gap-3 rounded-lg border border-dashed bg-muted/40 px-4 py-3 text-sm">
                    <Inbox className="size-5 shrink-0 text-muted-foreground" />
                    <span className="text-muted-foreground">
                        No leads yet. {isPublished ? 'Share the form to start collecting them.' : 'Publish the form to start collecting leads — every submission lands here as a card.'}
                    </span>
                    <Button size="sm" variant="outline" className="ml-auto" onClick={onGoToShare}>
                        {isPublished ? 'Share' : 'Publish'}
                    </Button>
                </div>
            )}

            <div className="flex-1 overflow-x-auto overflow-y-hidden bg-muted/50 dark:bg-muted/30">
                <div className="flex h-full gap-4 p-6">
                    {columns.map((column) => {
                        const visible = column.leads.filter(matches);
                        return (
                            <div
                                key={column.id}
                                className={cn('flex h-full w-[272px] shrink-0 flex-col rounded-xl bg-background/60 transition-colors', dropTarget?.column === column.id && 'bg-primary/5 ring-2 ring-primary/30')}
                                onDragOver={(e) => {
                                    if (!dragged) return;
                                    e.preventDefault();
                                    if (dropTarget?.column !== column.id) setDropTarget({ column: column.id, before: null });
                                }}
                                onDrop={(e) => onDrop(e, column.id)}
                            >
                                <div className="flex items-center gap-2 px-3 pb-2 pt-3">
                                    <span className="size-2.5 rounded-full" style={{ background: column.color || '#94a3b8' }} />
                                    <span className="truncate text-sm font-semibold">{column.name}</span>
                                    {column.is_done && <Check className="size-3.5 text-muted-foreground" aria-label="Done column" />}
                                    <span className="rounded-full bg-muted px-1.5 text-xs text-muted-foreground">{Math.max(column.total, column.leads.length)}</span>
                                    {canEditColumns && <ColumnMenu column={column} canDelete={columns.length > 1} routeFor={(name) => columnRoute(name, column)} />}
                                </div>
                                <div className="flex-1 space-y-2 overflow-y-auto px-2 pb-3">
                                    {visible.map((lead) => (
                                        <div
                                            key={lead.id}
                                            draggable={canManage}
                                            onDragStart={() => setDragged(lead)}
                                            onDragEnd={() => {
                                                setDragged(null);
                                                setDropTarget(null);
                                            }}
                                            onDragOver={(e) => {
                                                if (!dragged || dragged.id === lead.id) return;
                                                e.preventDefault();
                                                e.stopPropagation();
                                                setDropTarget({ column: column.id, before: lead.id });
                                            }}
                                            onClick={() => setOpenUuid(lead.uuid)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter' || e.key === ' ') {
                                                    e.preventDefault();
                                                    setOpenUuid(lead.uuid);
                                                }
                                            }}
                                            role="button"
                                            tabIndex={0}
                                            aria-label={`Lead #${lead.number}: ${leadTitle(lead)}`}
                                            className={cn(
                                                'group cursor-pointer rounded-lg border bg-card p-3 shadow-sm transition hover:border-primary/40 hover:shadow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                                dragged?.id === lead.id && 'opacity-40',
                                                dropTarget?.before === lead.id && 'border-t-2 border-t-primary',
                                            )}
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <p className="min-w-0 truncate text-sm font-medium">{leadTitle(lead)}</p>
                                                <span className="shrink-0 text-[11px] text-muted-foreground">#{lead.number}</span>
                                            </div>
                                            {(lead.company || (lead.name && lead.email)) && (
                                                <p className="mt-0.5 truncate text-xs text-muted-foreground">{lead.company || lead.email}</p>
                                            )}
                                            {lead.answers
                                                .filter((a) => !['short_text', 'email', 'phone', 'long_text', 'consent', 'hidden', 'removed'].includes(a.type))
                                                .slice(0, 2)
                                                .map((a) => (
                                                    <p key={a.id} className="mt-1.5 truncate text-xs">
                                                        <span className="text-muted-foreground">{a.label.replace(/[?:]\s*$/, '')}: </span>
                                                        {a.display}
                                                    </p>
                                                ))}
                                            <div className="mt-2.5 flex items-center gap-2 text-[11px] text-muted-foreground">
                                                <span>{formatDistanceToNow(new Date(lead.created_at), { addSuffix: true })}</span>
                                                {lead.source === 'webhook' && (
                                                    <span className="inline-flex items-center gap-0.5" title="Pushed by another tool">
                                                        <Webhook className="size-3" />
                                                    </span>
                                                )}
                                                {lead.assignee && (
                                                    <Avatar className="ml-auto size-5">
                                                        {lead.assignee.avatar && <AvatarImage src={lead.assignee.avatar} alt={lead.assignee.name} />}
                                                        <AvatarFallback className="text-[9px]">{initials(lead.assignee.name)}</AvatarFallback>
                                                    </Avatar>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                    {column.total > column.leads.length && !query && (
                                        <p className="px-2 py-2 text-center text-[11px] text-muted-foreground">Showing {column.leads.length} of {column.total} leads.</p>
                                    )}
                                    {visible.length === 0 && <p className="px-2 py-6 text-center text-xs text-muted-foreground">{query ? 'No matches' : 'Drop leads here'}</p>}
                                </div>
                            </div>
                        );
                    })}

                    {canEditColumns && (
                        <div className="w-[240px] shrink-0">
                            {newColumn === null ? (
                                <Button variant="ghost" className="h-11 w-full gap-2 border-2 border-dashed border-muted-foreground/20 text-muted-foreground hover:border-muted-foreground/40" onClick={() => setNewColumn('')}>
                                    <Plus className="size-4" />
                                    Add column
                                </Button>
                            ) : (
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        addColumn();
                                    }}
                                    className="space-y-2 rounded-xl bg-background/60 p-2"
                                >
                                    <Input autoFocus value={newColumn} onChange={(e) => setNewColumn(e.target.value)} placeholder="Column name" maxLength={60} onKeyDown={(e) => e.key === 'Escape' && setNewColumn(null)} />
                                    <div className="flex gap-2">
                                        <Button type="submit" size="sm">Add</Button>
                                        <Button type="button" size="sm" variant="ghost" onClick={() => setNewColumn(null)}>
                                            Cancel
                                        </Button>
                                    </div>
                                </form>
                            )}
                        </div>
                    )}
                </div>
            </div>

            <LeadSheet
                lead={openLead}
                columns={columns}
                members={members}
                routeParams={routeParams}
                canManage={canManage}
                onClose={() => {
                    setOpenUuid(null);
                    const url = new URL(window.location.href);
                    if (url.searchParams.has('lead')) {
                        url.searchParams.delete('lead');
                        window.history.replaceState(window.history.state, '', url);
                    }
                }}
                onMove={(lead, columnId) => move(lead, columnId, null)}
            />
        </div>
    );
}

function ColumnMenu({ column, canDelete, routeFor }: { column: LeadColumn; canDelete: boolean; routeFor: (name: string) => string }) {
    const [renaming, setRenaming] = useState(false);
    const [name, setName] = useState(column.name);
    const [confirm, setConfirm] = useState(false);
    const update = (data: Record<string, string | number | boolean | null>) =>
        router.put(routeFor('lead-forms.columns.update'), data, { preserveScroll: true, onError: (errors) => toast.error(firstError(errors, 'Could not change the column.')) });

    if (renaming) {
        return (
            <form
                className="ml-auto"
                onSubmit={(e) => {
                    e.preventDefault();
                    if (name.trim()) update({ name: name.trim() });
                    setRenaming(false);
                }}
            >
                <Input autoFocus value={name} onChange={(e) => setName(e.target.value)} onBlur={() => setRenaming(false)} maxLength={60} className="h-7 w-32 text-xs" />
            </form>
        );
    }

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button type="button" className="ml-auto rounded p-1 text-muted-foreground hover:bg-muted" aria-label={`Column ${column.name} options`}>
                        <MoreHorizontal className="size-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-48">
                    <DropdownMenuItem onSelect={() => setRenaming(true)}>Rename</DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => update({ is_done: !column.is_done })}>{column.is_done ? 'Not a done column' : 'Mark as done column'}</DropdownMenuItem>
                    <div className="flex flex-wrap gap-1.5 px-2 py-2">
                        {COLUMN_COLORS.map((color) => (
                            <button
                                key={color}
                                type="button"
                                aria-label={`Colour ${color}`}
                                onClick={() => update({ color })}
                                className={cn('size-5 rounded-full ring-offset-2 ring-offset-popover', column.color === color && 'ring-2 ring-foreground/40')}
                                style={{ background: color }}
                            />
                        ))}
                    </div>
                    {canDelete && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem className="text-destructive focus:text-destructive" onSelect={() => setConfirm(true)}>
                                Delete column
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
            <AlertDialog open={confirm} onOpenChange={setConfirm}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete “{column.name}”?</AlertDialogTitle>
                        <AlertDialogDescription>Its {column.leads.length} leads move to the first remaining column.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={() => router.delete(routeFor('lead-forms.columns.destroy'), { preserveScroll: true, onError: (errors) => toast.error(firstError(errors, 'Could not delete the column.')) })}>
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

function LeadSheet({
    lead,
    columns,
    members,
    routeParams,
    canManage,
    onClose,
    onMove,
}: {
    lead: LeadCard | null;
    columns: LeadColumn[];
    members: LeadMember[];
    routeParams: [string, string, string];
    canManage: boolean;
    onClose: () => void;
    onMove: (lead: LeadCard, columnId: number) => void;
}) {
    const [confirm, setConfirm] = useState(false);
    const [contact, setContact] = useState({ name: '', email: '', phone: '', company: '' });

    useEffect(() => {
        if (lead) setContact({ name: lead.name ?? '', email: lead.email ?? '', phone: lead.phone ?? '', company: lead.company ?? '' });
    }, [lead?.id]); // eslint-disable-line react-hooks/exhaustive-deps

    if (!lead) return <Sheet open={false} onOpenChange={onClose} />;

    const update = (data: Record<string, string | number | boolean | null>) =>
        router.patch(route('leads.update', [...routeParams, lead.id]), data, {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                toast.error(firstError(errors, 'Could not save the lead.'));
                setContact({ name: lead.name ?? '', email: lead.email ?? '', phone: lead.phone ?? '', company: lead.company ?? '' });
            },
        });
    const saveContact = (key: keyof typeof contact) => {
        const value = contact[key].trim();
        if (value !== (lead[key] ?? '')) update({ [key]: value || null });
    };

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle className="flex items-center gap-2">
                        {leadTitle(lead)}
                        <span className="text-sm font-normal text-muted-foreground">#{lead.number}</span>
                    </SheetTitle>
                    <SheetDescription>
                        {new Date(lead.created_at).toLocaleString()} · {lead.source === 'webhook' ? 'via webhook' : 'via form'}
                    </SheetDescription>
                </SheetHeader>

                <div className="mt-6 space-y-6">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label className="text-xs text-muted-foreground">Status</Label>
                            <Select value={String(lead.column_id)} onValueChange={(v) => onMove(lead, Number(v))} disabled={!canManage}>
                                <SelectTrigger className="h-9">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {columns.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>
                                            <span className="flex items-center gap-2">
                                                <span className="size-2 rounded-full" style={{ background: c.color || '#94a3b8' }} />
                                                {c.name}
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs text-muted-foreground">Owner</Label>
                            <Select value={lead.assignee ? String(lead.assignee.id) : 'none'} onValueChange={(v) => update({ assigned_to: v === 'none' ? null : Number(v) })} disabled={!canManage}>
                                <SelectTrigger className="h-9">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Unassigned</SelectItem>
                                    {members.map((m) => (
                                        <SelectItem key={m.id} value={String(m.id)}>
                                            {m.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="space-y-2 rounded-lg border p-3">
                        {(
                            [
                                ['name', 'Name', null],
                                ['email', 'Email', Mail],
                                ['phone', 'Phone', Phone],
                                ['company', 'Company', null],
                            ] as const
                        ).map(([key, label, Icon]) => (
                            <div key={key} className="flex items-center gap-2">
                                <span className="w-20 shrink-0 text-xs text-muted-foreground">{label}</span>
                                <Input
                                    value={contact[key]}
                                    onChange={(e) => setContact((c) => ({ ...c, [key]: e.target.value }))}
                                    onBlur={() => saveContact(key)}
                                    disabled={!canManage}
                                    className="h-8"
                                />
                                {Icon && contact[key] && (
                                    <a
                                        href={key === 'email' ? `mailto:${contact[key]}` : `tel:${contact[key]}`}
                                        className="rounded p-1.5 text-muted-foreground hover:bg-muted"
                                        aria-label={`${label} ${contact[key]}`}
                                    >
                                        <Icon className="size-4" />
                                    </a>
                                )}
                            </div>
                        ))}
                    </div>

                    <div>
                        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Answers</h3>
                        {lead.answers.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No answers.</p>
                        ) : (
                            <dl className="divide-y rounded-lg border">
                                {lead.answers.map((a) => (
                                    <div key={a.id} className="px-3 py-2.5">
                                        <dt className="text-xs text-muted-foreground">
                                            {a.label}
                                            {a.type === 'removed' && <Badge variant="outline" className="ml-2 px-1 py-0 text-[10px]">removed</Badge>}
                                        </dt>
                                        <dd className="mt-0.5 whitespace-pre-line break-words text-sm">{a.display}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </div>

                    {(lead.utm || lead.referrer) && (
                        <div>
                            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Source</h3>
                            <div className="flex flex-wrap gap-1.5">
                                {Object.entries(lead.utm ?? {}).map(([k, v]) => (
                                    <Badge key={k} variant="secondary" className="font-normal">
                                        utm_{k}: {v}
                                    </Badge>
                                ))}
                            </div>
                            {lead.referrer && <p className="mt-2 break-all text-xs text-muted-foreground">From {lead.referrer}</p>}
                        </div>
                    )}

                    {lead.consent_at && (
                        <div data-testid="lead-consent-record">
                            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Consent</h3>
                            <p className="text-xs text-muted-foreground">Given {new Date(lead.consent_at).toLocaleString()}</p>
                            {lead.consent_text && <p className="mt-1 whitespace-pre-line break-words text-sm">“{lead.consent_text}”</p>}
                        </div>
                    )}

                    {canManage && (
                        <Button variant="ghost" size="sm" className="text-destructive hover:text-destructive" onClick={() => setConfirm(true)}>
                            <Trash2 className="mr-1.5 size-4" />
                            Delete lead
                        </Button>
                    )}
                </div>

                <AlertDialog open={confirm} onOpenChange={setConfirm}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Delete lead #{lead.number}?</AlertDialogTitle>
                            <AlertDialogDescription>The lead and its answers are removed from the board.</AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={() => {
                                    router.delete(route('leads.destroy', [...routeParams, lead.id]), { preserveScroll: true });
                                    onClose();
                                }}
                            >
                                Delete
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </SheetContent>
        </Sheet>
    );
}
