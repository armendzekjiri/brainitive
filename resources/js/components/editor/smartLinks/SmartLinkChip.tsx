import { useCallback, useEffect, useRef, useState, type MouseEvent as ReactMouseEvent } from 'react';
import { useLexicalComposerContext } from '@lexical/react/LexicalComposerContext';
import { useLexicalEditable } from '@lexical/react/useLexicalEditable';
import { useLexicalNodeSelection } from '@lexical/react/useLexicalNodeSelection';
import { $createLinkNode } from '@lexical/link';
import { mergeRegister } from '@lexical/utils';
import {
    $createTextNode,
    $getNodeByKey,
    $getSelection,
    $isNodeSelection,
    CLICK_COMMAND,
    COMMAND_PRIORITY_LOW,
    KEY_BACKSPACE_COMMAND,
    KEY_DELETE_COMMAND,
    type NodeKey,
} from 'lexical';
import {
    CalendarDays, ExternalLink, FileText, GanttChart, LayoutList, LifeBuoy, Link2, Lock, PencilRuler, RectangleHorizontal, Trash2, Unlink,
} from 'lucide-react';
import { ItemTypeIcon } from '@/components/ItemTypeIcon';
import { StatusLozenge } from '@/components/board/ItemPrimitives';
import { cn } from '@/lib/utils';
import type { SmartLinkData, SmartLinkView } from '@/types';
import { $isSmartLinkNode } from '../nodes/SmartLinkNode';
import { safeSmartLinkHref, smartLinkText, useSmartLinkConfig } from './smartLinkRefs';
import { useSmartLink } from './smartLinkStore';

/**
 * What a smart link shows (BRAI-143): an inline chip (type icon, key, title, status, due date) or a
 * card (title, key, status, assignee, due date, priority, project), with live data. Without a
 * workspace (comments, public pages) it's the plain link it was saved as.
 */
export default function SmartLinkChip({ nodeKey, refValue, url, text, view }: {
    nodeKey: NodeKey;
    refValue: string;
    url: string;
    text: string;
    view: SmartLinkView;
}) {
    const [editor] = useLexicalComposerContext();
    const editable = useLexicalEditable();
    const config = useSmartLinkConfig();
    const entry = useSmartLink(config?.workspaceSlug ?? null, refValue);
    const [isSelected, setSelected, clearSelection] = useLexicalNodeSelection(nodeKey);
    const [hovered, setHovered] = useState(false);
    const rootRef = useRef<HTMLSpanElement>(null);

    const live = entry?.state === 'ok' ? (entry as SmartLinkData) : null;

    // While editing: a fresh paste (text = URL) gets the key + title as its text, and a pasted plan
    // URL its canonical ref. Never on plain viewing, and never just because a title changed later.
    useEffect(() => {
        if (!editable || !live) return;
        const needsRef = live.ref !== refValue;
        const needsText = !text.trim() || text === url;
        if (!needsRef && !needsText) return;
        editor.update(() => {
            const node = $getNodeByKey(nodeKey);
            if ($isSmartLinkNode(node)) {
                node.setData({
                    ref: live.ref,
                    url: needsRef || needsText ? live.url : node.getUrl(),
                    text: needsText ? smartLinkText(live) : node.getText(),
                });
            }
        });
    }, [editable, live, refValue, text, url, editor, nodeKey]);

    const removeNode = useCallback(() => {
        editor.update(() => {
            $getNodeByKey(nodeKey)?.remove();
        });
    }, [editor, nodeKey]);

    const setView = (next: SmartLinkView) => {
        editor.update(() => {
            const node = $getNodeByKey(nodeKey);
            if ($isSmartLinkNode(node)) node.setView(next);
        });
    };

    const toPlainLink = () => {
        editor.update(() => {
            const node = $getNodeByKey(nodeKey);
            if (!$isSmartLinkNode(node)) return;
            const href = live?.url ?? node.getUrl();
            const link = $createLinkNode(href);
            link.append($createTextNode(href));
            node.replace(link);
            link.selectEnd();
        });
    };

    useEffect(() => {
        if (!editable) return;
        const onDelete = (event: KeyboardEvent) => {
            if (isSelected && $isNodeSelection($getSelection())) {
                event.preventDefault();
                $getNodeByKey(nodeKey)?.remove();
                return true;
            }
            return false;
        };
        return mergeRegister(
            editor.registerCommand<MouseEvent>(CLICK_COMMAND, (event) => {
                if (rootRef.current?.contains(event.target as Node)) {
                    if (!event.shiftKey) clearSelection();
                    setSelected(true);
                    return true;
                }
                return false;
            }, COMMAND_PRIORITY_LOW),
            editor.registerCommand(KEY_DELETE_COMMAND, onDelete, COMMAND_PRIORITY_LOW),
            editor.registerCommand(KEY_BACKSPACE_COMMAND, onDelete, COMMAND_PRIORITY_LOW),
        );
    }, [editor, editable, isSelected, nodeKey, clearSelection, setSelected]);

    const href = safeSmartLinkHref(live?.url ?? url);
    // in the editor a click selects the link (the toolbar has "Open"); reading, it navigates
    const onLinkClick = (event: ReactMouseEvent) => {
        if (editable) event.preventDefault();
    };

    // no workspace context: the plain link it was saved as
    if (!config || entry === null) {
        return (
            <a href={href} className="text-primary underline underline-offset-2" data-smart-link-fallback="">
                {text || url}
            </a>
        );
    }

    const showToolbar = editable && (hovered || isSelected);
    const state = entry.state;

    return (
        <span
            ref={rootRef}
            className={cn('relative', view === 'card' ? 'block max-w-xl' : 'inline')}
            onMouseEnter={() => setHovered(true)}
            onMouseLeave={() => setHovered(false)}
            data-smart-link-chip={refValue}
            data-smart-link-state={state}
            data-smart-link-view={view}
        >
            {showToolbar && (
                <SmartLinkToolbar
                    view={view}
                    href={href}
                    onView={setView}
                    onPlain={toPlainLink}
                    onRemove={removeNode}
                />
            )}
            {state === 'restricted' || state === 'not_found' ? (
                <span
                    className={cn(
                        'inline-flex items-center gap-1 rounded-md border border-dashed px-1.5 py-px align-baseline text-[0.85em] text-muted-foreground',
                        isSelected && 'ring-2 ring-primary/40',
                    )}
                    title={state === 'restricted' ? 'You don’t have access to this content' : 'This content was deleted or moved'}
                >
                    {state === 'restricted' ? <Lock className="size-3" aria-hidden /> : <Unlink className="size-3" aria-hidden />}
                    {state === 'restricted' ? 'Restricted' : 'Not found'}
                </span>
            ) : view === 'card' ? (
                <SmartLinkCard live={live} fallback={text || url} href={href} loading={state === 'loading'} selected={isSelected} onClick={onLinkClick} />
            ) : (
                <a
                    href={href}
                    onClick={onLinkClick}
                    className={cn(
                        'smart-link-anchor not-prose inline-flex max-w-full items-center gap-1 rounded-md border bg-muted/40 px-1.5 py-px align-baseline text-[0.875em] font-normal leading-snug text-foreground no-underline transition-colors hover:bg-muted',
                        state === 'loading' && 'animate-pulse',
                        isSelected && 'ring-2 ring-primary/40',
                    )}
                    title={live ? `${live.label}: ${live.title}` : undefined}
                >
                    <TypeIcon live={live} />
                    {live?.key && <span className="shrink-0 font-mono text-[0.85em] text-muted-foreground">{live.key}</span>}
                    <span className="min-w-0 max-w-[32ch] truncate text-primary">{live?.title ?? (text || url)}</span>
                    {live?.status && <StatusLozenge name={live.status.name} category={live.status.category} className="h-4 px-1 text-[10px]" />}
                    {live?.due_date && <DueDate date={live.due_date} done={live.status?.category === 'done'} compact />}
                </a>
            )}
        </span>
    );
}

function SmartLinkToolbar({ view, href, onView, onPlain, onRemove }: {
    view: SmartLinkView;
    href: string;
    onView: (view: SmartLinkView) => void;
    onPlain: () => void;
    onRemove: () => void;
}) {
    // keep the editor selection while clicking the toolbar
    const keep = (event: ReactMouseEvent) => event.preventDefault();
    const button = 'inline-flex h-6 items-center gap-1 rounded px-1.5 text-xs text-popover-foreground hover:bg-accent';

    return (
        <span
            role="toolbar"
            aria-label="Smart link"
            contentEditable={false}
            onMouseDown={keep}
            className="absolute bottom-full left-0 z-30 mb-1 inline-flex items-center gap-0.5 whitespace-nowrap rounded-md border bg-popover p-0.5 shadow-md"
        >
            <button type="button" className={cn(button, view === 'inline' && 'bg-accent')} aria-pressed={view === 'inline'} onClick={() => onView('inline')}>
                <RectangleHorizontal className="size-3.5" aria-hidden />Inline
            </button>
            <button type="button" className={cn(button, view === 'card' && 'bg-accent')} aria-pressed={view === 'card'} onClick={() => onView('card')}>
                <LayoutList className="size-3.5" aria-hidden />Card
            </button>
            <span className="mx-0.5 h-4 w-px bg-border" aria-hidden />
            <a href={href} className={cn(button, 'smart-link-anchor')} title="Open">
                <ExternalLink className="size-3.5" aria-hidden />Open
            </a>
            <button type="button" className={button} title="Undo to plain link" onClick={onPlain}>
                <Link2 className="size-3.5" aria-hidden />Plain link
            </button>
            <button type="button" className={cn(button, 'text-destructive')} title="Remove" aria-label="Remove smart link" onClick={onRemove}>
                <Trash2 className="size-3.5" aria-hidden />
            </button>
        </span>
    );
}

function SmartLinkCard({ live, fallback, href, loading, selected, onClick }: {
    live: SmartLinkData | null;
    fallback: string;
    href: string;
    loading: boolean;
    selected: boolean;
    onClick: (event: ReactMouseEvent) => void;
}) {
    return (
        <a
            href={href}
            onClick={onClick}
            className={cn(
                'smart-link-anchor not-prose flex w-full flex-col gap-1.5 rounded-lg border bg-card p-3 text-sm font-normal text-card-foreground no-underline shadow-sm transition-colors hover:bg-muted/40',
                loading && 'animate-pulse',
                selected && 'ring-2 ring-primary/40',
            )}
        >
            <span className="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
                <TypeIcon live={live} />
                {live?.key && <span className="font-mono">{live.key}</span>}
                {live && <span className="truncate">· {live.label} in {live.project.name}</span>}
            </span>
            <span className="truncate text-[0.95rem] font-medium leading-snug">{live?.title ?? fallback}</span>
            {live && (
                <span className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    {live.status && <StatusLozenge name={live.status.name} category={live.status.category} />}
                    {live.priority && (
                        <span className="inline-flex items-center gap-1">
                            <span className="size-2 rounded-full" style={{ backgroundColor: live.priority.color ?? 'currentColor' }} aria-hidden />
                            {live.priority.name}
                        </span>
                    )}
                    {live.due_date && <DueDate date={live.due_date} done={live.status?.category === 'done'} />}
                    {live.person && (
                        <span className="inline-flex min-w-0 items-center gap-1.5" title={live.person.role}>
                            <PersonAvatar name={live.person.name} avatar={live.person.avatar} />
                            <span className="truncate">{live.person.name}</span>
                        </span>
                    )}
                </span>
            )}
        </a>
    );
}

function TypeIcon({ live }: { live: SmartLinkData | null }) {
    if (live?.type === 'item' && live.item_type) {
        return <ItemTypeIcon type={live.item_type} className="size-3.5 shrink-0" />;
    }
    const Icon = !live ? Link2
        : live.type === 'ticket' ? LifeBuoy
            : live.type === 'plan' ? GanttChart
                : live.label === 'Whiteboard' ? PencilRuler : FileText;

    return <Icon className="size-3.5 shrink-0 text-muted-foreground" aria-label={live?.label} />;
}

function DueDate({ date, done, compact = false }: { date: string; done: boolean; compact?: boolean }) {
    const due = new Date(`${date}T00:00:00`);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const overdue = !done && due < today;
    const label = due.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        ...(due.getFullYear() !== today.getFullYear() ? { year: 'numeric' } : {}),
    });

    return (
        <span className={cn('inline-flex shrink-0 items-center gap-0.5', compact && 'text-[0.85em]', overdue ? 'text-destructive' : 'text-muted-foreground')} title={`Due ${label}`}>
            <CalendarDays className="size-3" aria-hidden />
            {label}
        </span>
    );
}

function PersonAvatar({ name, avatar }: { name: string; avatar: string | null }) {
    if (avatar) {
        return <img src={avatar} alt="" className="size-5 shrink-0 rounded-full object-cover" />;
    }
    const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase()).join('');

    return (
        <span className="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/15 text-[10px] font-semibold text-primary" aria-hidden>
            {initials}
        </span>
    );
}
