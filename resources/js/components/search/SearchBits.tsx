/**
 * Shared pieces of "Find anything" (BRAI-55): the icon of a result type and highlighted text.
 * Used by the search page and the command palette.
 */
import {
    Bookmark as BookmarkIcon, CheckSquare, Code, FileText, GanttChart, LayoutGrid, LifeBuoy, Lightbulb, MessageSquare, Paperclip, PenTool,
} from 'lucide-react';
import { safeHtmlProp } from '@/lib/sanitize';
import { cn } from '@/lib/utils';
import type { SearchResult, SearchType } from '@/types';

const TYPE_ICONS: Record<SearchType, { Icon: typeof FileText; color: string }> = {
    item: { Icon: LayoutGrid, color: '#0E83DC' },
    comment: { Icon: MessageSquare, color: '#0ea5e9' },
    page: { Icon: FileText, color: '#22c55e' },
    ticket: { Icon: LifeBuoy, color: '#e11d48' },
    plan: { Icon: GanttChart, color: '#f97316' },
    task: { Icon: CheckSquare, color: '#14b8a6' },
    snippet: { Icon: Code, color: '#8b5cf6' },
    bookmark: { Icon: BookmarkIcon, color: '#0969da' },
    capture: { Icon: Lightbulb, color: '#f59e0b' },
    attachment: { Icon: Paperclip, color: '#64748b' },
    ticket_attachment: { Icon: Paperclip, color: '#64748b' },
};

export function SearchTypeIcon({ result, className }: { result: Pick<SearchResult, 'type' | 'label'>; className?: string }) {
    const { Icon: TypeIcon, color } = TYPE_ICONS[result.type] ?? TYPE_ICONS.item;
    const Icon = result.label === 'Whiteboard' ? PenTool : TypeIcon;
    return (
        <div className={cn('flex size-8 shrink-0 items-center justify-center rounded-lg text-white', className)} style={{ background: color }} aria-hidden>
            <Icon className="size-4" />
        </div>
    );
}

/**
 * The server escapes all text and adds only <mark> tags. Anything else that looks like a tag is
 * dropped here too, so a bug elsewhere can never turn a result into markup.
 */
export function onlyMarks(html: string): string {
    return html.replace(/<(?!\/?mark>)[^>]*>/gi, '');
}

/** Text with its matches highlighted (light and dark). */
export function Highlighted({ html, className }: { html: string; className?: string }) {
    return (
        <span
            className={cn('[&_mark]:rounded-sm [&_mark]:bg-yellow-200 [&_mark]:px-0.5 [&_mark]:text-foreground dark:[&_mark]:bg-yellow-500/30', className)}
            dangerouslySetInnerHTML={safeHtmlProp(onlyMarks(html))}
        />
    );
}
