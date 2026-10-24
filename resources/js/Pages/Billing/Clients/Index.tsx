import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Archive, ArchiveRestore, Building2, FolderKanban, Mail, Pencil, Plus } from 'lucide-react';
import BillingLayout from '@/Layouts/BillingLayout';
import { AssignProjectsDialog, ClientFormDialog, firstError, flashSuccess } from '@/components/billing/ClientDialogs';
import { ProjectIcon } from '@/components/ProjectIcon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { BillingClient, BillingClientProject } from '@/types';

interface Props {
    workspace: { id: number; name: string; slug: string };
    clients: BillingClient[];
    /** Every project of the workspace (for "Assign projects"). */
    projects: BillingClientProject[];
    /** Names of every client of the workspace, archived ones too, by id. */
    clientNames: Record<number, string>;
    showArchived: boolean;
    archivedCount: number;
    /** The workspace's default VAT in percent. */
    defaultTaxRate: number;
}

/**
 * Billing → Clients (BRAI-100, workspace admins): who the workspace bills and which projects belong
 * to them. Archived clients are hidden unless asked for.
 */
export default function ClientsIndex({ workspace, clients, projects, clientNames, showArchived, archivedCount, defaultTaxRate }: Props) {
    const [editing, setEditing] = useState<BillingClient | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [assigningId, setAssigningId] = useState<number | null>(null);
    const assigning = clients.find((c) => c.id === assigningId) ?? null;

    const openForm = (client: BillingClient | null) => {
        setEditing(client);
        setFormOpen(true);
    };
    const setArchived = (client: BillingClient, archive: boolean) =>
        router.post(route(archive ? 'billing.clients.archive' : 'billing.clients.restore', { workspace: workspace.slug, client: client.id }), {}, {
            preserveScroll: true,
            onSuccess: (page) => flashSuccess(page, archive ? 'Client archived.' : 'Client restored.'),
            onError: (errors) => firstError(errors),
        });

    return (
        <BillingLayout
            title="Clients"
            description="Who you bill, and which projects belong to them."
            actions={
                <>
                    {(archivedCount > 0 || showArchived) && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={route('billing.clients.index', { workspace: workspace.slug, ...(showArchived ? {} : { archived: 1 }) })} preserveScroll>
                                {showArchived ? 'Hide archived' : `Show archived (${archivedCount})`}
                            </Link>
                        </Button>
                    )}
                    <Button size="sm" onClick={() => openForm(null)}>
                        <Plus className="size-4" />
                        New client
                    </Button>
                </>
            }
        >
            {clients.length === 0 ? (
                <Card>
                    <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
                        <Building2 className="size-10 text-muted-foreground" />
                        <div>
                            <p className="font-medium">No clients yet</p>
                            <p className="text-sm text-muted-foreground">Add the companies you bill, then assign their projects.</p>
                        </div>
                        <Button size="sm" onClick={() => openForm(null)}>
                            <Plus className="size-4" />
                            New client
                        </Button>
                    </CardContent>
                </Card>
            ) : (
                <ul className="grid gap-4 md:grid-cols-2" aria-label="Clients">
                    {clients.map((client) => (
                        <li key={client.id}>
                            <Card className={cn('h-full', client.archived && 'opacity-75')} aria-label={client.name} data-testid="billing-client">
                                <CardContent className="flex h-full flex-col gap-4 p-4 sm:p-5">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <h2 className="flex flex-wrap items-center gap-2 text-base font-semibold">
                                                <span className="break-words">{client.name}</span>
                                                {client.archived && <Badge variant="secondary">Archived</Badge>}
                                            </h2>
                                            <dl className="mt-1 space-y-0.5 text-sm text-muted-foreground">
                                                {client.email && (
                                                    <div className="flex items-center gap-1.5">
                                                        <Mail className="size-3.5 shrink-0" />
                                                        <dt className="sr-only">Email</dt>
                                                        <dd className="truncate">{client.email}</dd>
                                                    </div>
                                                )}
                                                <div>
                                                    <dt className="sr-only">VAT</dt>
                                                    <dd>
                                                        {client.tax_rate === null ? `Default VAT (${defaultTaxRate} %)` : `${client.tax_rate} % VAT`}
                                                        {client.vat_id && <span> · VAT ID {client.vat_id}</span>}
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-1">
                                            <Button variant="ghost" size="icon" className="size-8" aria-label={`Edit ${client.name}`} onClick={() => openForm(client)}>
                                                <Pencil className="size-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-8"
                                                aria-label={client.archived ? `Restore ${client.name}` : `Archive ${client.name}`}
                                                title={client.archived ? 'Restore' : 'Archive'}
                                                onClick={() => setArchived(client, !client.archived)}
                                            >
                                                {client.archived ? <ArchiveRestore className="size-4" /> : <Archive className="size-4" />}
                                            </Button>
                                        </div>
                                    </div>

                                    {client.address && <p className="whitespace-pre-line text-sm">{client.address}</p>}
                                    {client.notes && <p className="whitespace-pre-line rounded-md bg-muted/50 px-3 py-2 text-xs text-muted-foreground">{client.notes}</p>}

                                    <div className="mt-auto space-y-2 border-t pt-3">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                                <FolderKanban className="size-3.5" />
                                                Projects
                                            </span>
                                            {!client.archived && (
                                                <Button variant="outline" size="sm" onClick={() => setAssigningId(client.id)}>
                                                    Assign projects
                                                </Button>
                                            )}
                                        </div>
                                        {client.projects.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">No projects assigned.</p>
                                        ) : (
                                            <ul className="flex flex-wrap gap-1.5" aria-label={`Projects of ${client.name}`}>
                                                {client.projects.map((project) => (
                                                    <li key={project.id}>
                                                        <Link
                                                            href={route('projects.show', [workspace.slug, project.slug])}
                                                            className="inline-flex max-w-[16rem] items-center gap-1.5 rounded-full border bg-background px-2 py-0.5 text-xs font-medium hover:bg-accent"
                                                        >
                                                            <ProjectIcon project={project} size="xs" className="!size-4" />
                                                            <span className="truncate">{project.name}</span>
                                                        </Link>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        </li>
                    ))}
                </ul>
            )}

            <ClientFormDialog workspaceSlug={workspace.slug} client={editing} open={formOpen} onOpenChange={setFormOpen} defaultTaxRate={defaultTaxRate} />
            <AssignProjectsDialog
                workspaceSlug={workspace.slug}
                client={assigning}
                projects={projects}
                clientNames={clientNames}
                open={assigning !== null}
                onOpenChange={(open) => !open && setAssigningId(null)}
            />
        </BillingLayout>
    );
}
