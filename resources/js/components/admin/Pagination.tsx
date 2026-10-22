import { Button } from '@/components/ui/button';
import { Paginated } from '@/types';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

export default function Pagination<T>({ page, noun }: { page: Paginated<T>; noun: string }) {
    if (page.total === 0) return null;

    return (
        <div className="flex items-center justify-between gap-3 pt-4 text-sm text-muted-foreground">
            <span>
                {page.from}–{page.to} of {page.total} {noun}
            </span>
            {page.last_page > 1 && (
                <div className="flex gap-2">
                    <Button variant="outline" size="sm" disabled={!page.prev_page_url} asChild={!!page.prev_page_url}>
                        {page.prev_page_url ? (
                            <Link href={page.prev_page_url} preserveScroll>
                                <ChevronLeft className="size-4" /> Previous
                            </Link>
                        ) : (
                            <span><ChevronLeft className="size-4" /> Previous</span>
                        )}
                    </Button>
                    <Button variant="outline" size="sm" disabled={!page.next_page_url} asChild={!!page.next_page_url}>
                        {page.next_page_url ? (
                            <Link href={page.next_page_url} preserveScroll>
                                Next <ChevronRight className="size-4" />
                            </Link>
                        ) : (
                            <span>Next <ChevronRight className="size-4" /></span>
                        )}
                    </Button>
                </div>
            )}
        </div>
    );
}
