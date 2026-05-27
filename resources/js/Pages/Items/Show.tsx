import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PageProps, Workspace, Project, Board, BoardColumn, Item, ItemType, ItemPriority, User, Label as LabelType } from '@/types';
import { Button } from '@/components/ui/button';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Sheet, SheetContent, SheetTrigger } from '@/components/ui/sheet';
import { ArrowLeft, SlidersHorizontal } from 'lucide-react';
import { ProjectIcon } from '@/components/ProjectIcon';
import { ItemTypeIcon } from '@/components/ItemTypeIcon';
import {
    useItemDetail,
    ItemDetailMain,
    ItemDetailSidebar,
    ItemFilePreviewDialog,
    type ProjectItemLite,
} from '@/components/item/ItemDetail';

interface Props extends PageProps {
    workspace: Workspace;
    project: Project;
    board: Board;
    item: Item;
    itemTypes: ItemType[];
    priorities: ItemPriority[];
    projectUsers: User[];
    columns: BoardColumn[];
    projectLabels: LabelType[];
    projectItems: ProjectItemLite[];
}

export default function ItemShow({
    workspace,
    project,
    board,
    item,
    itemTypes,
    priorities,
    projectUsers,
    columns,
    projectLabels,
    projectItems,
}: Props) {
    const currentUser = usePage<PageProps>().props.auth.user;
    const [detailsSheetOpen, setDetailsSheetOpen] = useState(false);

    const d = useItemDetail({
        item,
        workspace,
        project,
        board,
        itemTypes,
        priorities,
        projectUsers,
        projectLabels,
        projectItems,
        columns,
        currentUserId: currentUser.id,
        onOpenItem: (it) =>
            router.visit(route('boards.items.show', [workspace.slug, project.slug, board.slug, it.id])),
    });

    return (
        <AppLayout>
            <Head title={`${item.key} - ${item.title}`} />

            <div className="h-full flex flex-col bg-background">
                {/* Accent strip (priority → type) — mirrors the board card / popup */}
                <span className="h-1 w-full shrink-0" style={{ background: d.accent ?? 'transparent' }} aria-hidden />

                {/* Header – fixed height, never scrolls */}
                <div className="border-b bg-background shadow-sm shrink-0">
                    <div className="px-6 py-3 flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <Link href={route('boards.show', [workspace.slug, project.slug, board.slug])}>
                                <Button variant="ghost" size="icon" className="size-8">
                                    <ArrowLeft className="size-4" />
                                </Button>
                            </Link>
                            <Breadcrumb>
                                <BreadcrumbList>
                                    <BreadcrumbItem>
                                        <BreadcrumbLink asChild>
                                            <Link href={route('projects.show', [workspace.slug, project.slug])} className="flex items-center gap-2">
                                                <ProjectIcon project={project} size="xs" />
                                                {project.name}
                                            </Link>
                                        </BreadcrumbLink>
                                    </BreadcrumbItem>
                                    <BreadcrumbSeparator />
                                    <BreadcrumbItem>
                                        <BreadcrumbLink asChild>
                                            <Link href={route('boards.show', [workspace.slug, project.slug, board.slug])}>
                                                {board.name}
                                            </Link>
                                        </BreadcrumbLink>
                                    </BreadcrumbItem>
                                    <BreadcrumbSeparator />
                                    <BreadcrumbItem>
                                        <BreadcrumbPage className="flex items-center gap-2">
                                            <ItemTypeIcon type={item.item_type} />
                                            {item.key}
                                        </BreadcrumbPage>
                                    </BreadcrumbItem>
                                </BreadcrumbList>
                            </Breadcrumb>
                        </div>

                        {/* Mobile: open details sheet */}
                        <div className="lg:hidden">
                            <Sheet open={detailsSheetOpen} onOpenChange={setDetailsSheetOpen}>
                                <SheetTrigger asChild>
                                    <Button variant="ghost" size="icon" className="size-8">
                                        <SlidersHorizontal className="size-4" />
                                    </Button>
                                </SheetTrigger>
                                <SheetContent side="right" className="w-[300px] sm:w-[360px] p-0">
                                    <div className="px-5 py-5 h-full overflow-y-auto">
                                        <h2 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">Details</h2>
                                        <ItemDetailSidebar d={d} />
                                    </div>
                                </SheetContent>
                            </Sheet>
                        </div>
                    </div>
                </div>

                {/* Two-panel body – each column scrolls independently */}
                <div className="flex-1 overflow-hidden flex">
                    <div className="flex-1 overflow-y-auto min-w-0">
                        <ItemDetailMain d={d} />
                    </div>
                    <div className="hidden lg:block w-[300px] shrink-0 overflow-y-auto border-l bg-background">
                        <div className="px-5 py-5">
                            <h2 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">Details</h2>
                            <ItemDetailSidebar d={d} />
                        </div>
                    </div>
                </div>
            </div>

            <ItemFilePreviewDialog d={d} />
        </AppLayout>
    );
}
