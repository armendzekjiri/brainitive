import { useEffect, useState, useCallback, useRef, useMemo } from 'react';
import { createPortal } from 'react-dom';
import { useLexicalComposerContext } from '@lexical/react/LexicalComposerContext';
import {
    $getSelection,
    $isRangeSelection,
    $createTextNode,
    KEY_ARROW_DOWN_COMMAND,
    KEY_ARROW_UP_COMMAND,
    KEY_ENTER_COMMAND,
    KEY_ESCAPE_COMMAND,
    KEY_TAB_COMMAND,
    COMMAND_PRIORITY_HIGH,
    TextNode,
    RangeSelection,
} from 'lexical';
import { mergeRegister } from '@lexical/utils';
import { $createMentionNode } from '../nodes/MentionNode';

interface MemberResult {
    id: number;
    name: string;
    email: string;
    avatar_display: string;
    role: string;
}

interface MentionsPluginProps {
    projectId: number;
}

// Simple session cache to avoid re-fetching identical queries
const mentionCache = new Map<string, { data: MemberResult[]; ts: number }>();
const CACHE_TTL = 60_000; // 60 seconds

function getCacheKey(projectId: number, query: string): string {
    return `${projectId}:${query.toLowerCase()}`;
}

export default function MentionsPlugin({ projectId }: MentionsPluginProps) {
    const [editor] = useLexicalComposerContext();
    const [query, setQuery] = useState<string | null>(null); // null = closed
    const [results, setResults] = useState<MemberResult[]>([]);
    const [selectedIndex, setSelectedIndex] = useState(0);
    const [menuPosition, setMenuPosition] = useState<{ top: number; left: number } | null>(null);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const triggerOffsetRef = useRef<number | null>(null); // character offset of the '@'
    const containerRef = useRef<HTMLDivElement | null>(null);

    // Resolve the workspace + project slugs from the current URL
    // URL pattern: /workspaces/{ws}/projects/{proj}/...
    const { workspaceSlug, projectSlug } = useMemo(() => {
        const parts = window.location.pathname.split('/');
        const wsIdx = parts.indexOf('workspaces');
        const pjIdx = parts.indexOf('projects');
        return {
            workspaceSlug: wsIdx >= 0 ? parts[wsIdx + 1] : '',
            projectSlug: pjIdx >= 0 ? parts[pjIdx + 1] : '',
        };
    }, []);

    // Fetch members
    const fetchMembers = useCallback(
        async (q: string) => {
            if (!workspaceSlug || !projectSlug) return;

            const cacheKey = getCacheKey(projectId, q);
            const cached = mentionCache.get(cacheKey);
            if (cached && Date.now() - cached.ts < CACHE_TTL) {
                setResults(cached.data);
                setSelectedIndex(0);
                return;
            }

            try {
                const url = `/workspaces/${workspaceSlug}/projects/${projectSlug}/members/search?query=${encodeURIComponent(q)}&limit=10`;
                const res = await fetch(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!res.ok) return;
                const data: MemberResult[] = await res.json();
                mentionCache.set(cacheKey, { data, ts: Date.now() });
                setResults(data);
                setSelectedIndex(0);
            } catch {
                // Silently fail — UI just shows no results
            }
        },
        [projectId, workspaceSlug, projectSlug],
    );

    // Insert mention and close menu
    const insertMention = useCallback(
        (member: MemberResult) => {
            editor.update(() => {
                const selection = $getSelection();
                if (!$isRangeSelection(selection)) return;

                const anchor = selection.anchor;
                const anchorNode = anchor.getNode();
                if (!(anchorNode instanceof TextNode)) return;

                const textContent = anchorNode.getTextContent();
                const triggerOffset = triggerOffsetRef.current;
                if (triggerOffset === null) return;

                // Split around the @query text
                const before = textContent.slice(0, triggerOffset);
                const after = textContent.slice(anchor.offset);

                // Replace the text node content
                anchorNode.setTextContent(before);

                // Create mention node
                const mentionNode = $createMentionNode(member.id, member.name);

                // Insert after the before-text
                if (before === '') {
                    anchorNode.replace(mentionNode);
                } else {
                    anchorNode.insertAfter(mentionNode);
                }

                // Add a trailing space
                const spaceNode = $createTextNode(' ');
                mentionNode.insertAfter(spaceNode);

                // Move cursor after the space
                spaceNode.select(1, 1);
            });

            setQuery(null);
            setResults([]);
            setMenuPosition(null);
            triggerOffsetRef.current = null;
        },
        [editor],
    );

    // Watch for text changes to detect @trigger
    useEffect(() => {
        return editor.registerTextContentListener(() => {
            editor.getEditorState().read(() => {
                const selection = $getSelection();
                if (!$isRangeSelection(selection) || !selection.isCollapsed()) {
                    setQuery(null);
                    return;
                }

                const anchor = selection.anchor;
                const anchorNode = anchor.getNode();
                if (!(anchorNode instanceof TextNode)) {
                    setQuery(null);
                    return;
                }

                const text = anchorNode.getTextContent();
                const offset = anchor.offset;
                const textBefore = text.slice(0, offset);

                // Find the last @ that's either at the start or preceded by whitespace
                const match = textBefore.match(/(^|[\s])@([^\s@]*)$/);
                if (match) {
                    const atIndex = textBefore.lastIndexOf('@');
                    triggerOffsetRef.current = atIndex;
                    const q = match[2];
                    setQuery(q);

                    // Position the menu at the caret
                    const domSelection = window.getSelection();
                    if (domSelection && domSelection.rangeCount > 0) {
                        const range = domSelection.getRangeAt(0);
                        const rect = range.getBoundingClientRect();
                        setMenuPosition({
                            top: rect.bottom + window.scrollY + 4,
                            left: rect.left + window.scrollX,
                        });
                    }
                } else {
                    setQuery(null);
                    triggerOffsetRef.current = null;
                }
            });
        });
    }, [editor]);

    // Debounced fetch when query changes
    useEffect(() => {
        if (query === null) return;
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => fetchMembers(query), 250);
        return () => {
            if (debounceRef.current) clearTimeout(debounceRef.current);
        };
    }, [query, fetchMembers]);

    // Keyboard navigation
    useEffect(() => {
        if (query === null || results.length === 0) return;

        return mergeRegister(
            editor.registerCommand(
                KEY_ARROW_DOWN_COMMAND,
                (event) => {
                    event.preventDefault();
                    setSelectedIndex((prev) => (prev + 1) % results.length);
                    return true;
                },
                COMMAND_PRIORITY_HIGH,
            ),
            editor.registerCommand(
                KEY_ARROW_UP_COMMAND,
                (event) => {
                    event.preventDefault();
                    setSelectedIndex((prev) => (prev - 1 + results.length) % results.length);
                    return true;
                },
                COMMAND_PRIORITY_HIGH,
            ),
            editor.registerCommand(
                KEY_ENTER_COMMAND,
                (event) => {
                    event?.preventDefault();
                    insertMention(results[selectedIndex]);
                    return true;
                },
                COMMAND_PRIORITY_HIGH,
            ),
            editor.registerCommand(
                KEY_TAB_COMMAND,
                (event) => {
                    event.preventDefault();
                    insertMention(results[selectedIndex]);
                    return true;
                },
                COMMAND_PRIORITY_HIGH,
            ),
            editor.registerCommand(
                KEY_ESCAPE_COMMAND,
                () => {
                    setQuery(null);
                    setResults([]);
                    setMenuPosition(null);
                    return true;
                },
                COMMAND_PRIORITY_HIGH,
            ),
        );
    }, [editor, query, results, selectedIndex, insertMention]);

    // Click outside to close
    useEffect(() => {
        if (query === null) return;
        const handleClick = (e: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                setQuery(null);
                setResults([]);
                setMenuPosition(null);
            }
        };
        document.addEventListener('mousedown', handleClick);
        return () => document.removeEventListener('mousedown', handleClick);
    }, [query]);

    if (query === null || !menuPosition || results.length === 0) return null;

    const highlightText = (text: string, q: string) => {
        if (!q) return text;
        const idx = text.toLowerCase().indexOf(q.toLowerCase());
        if (idx === -1) return text;
        return (
            <>
                {text.slice(0, idx)}
                <span className="font-bold text-primary">{text.slice(idx, idx + q.length)}</span>
                {text.slice(idx + q.length)}
            </>
        );
    };

    return createPortal(
        <div
            ref={containerRef}
            className="fixed z-[9999] bg-popover border border-border rounded-lg shadow-xl overflow-hidden"
            style={{ top: menuPosition.top, left: menuPosition.left, minWidth: 240, maxWidth: 320 }}
        >
            <div className="py-1 max-h-60 overflow-y-auto">
                {results.map((member, index) => (
                    <button
                        key={member.id}
                        type="button"
                        className={`w-full flex items-center gap-3 px-3 py-2 text-sm text-left transition-colors
                            ${index === selectedIndex ? 'bg-accent text-accent-foreground' : 'hover:bg-muted'}
                        `}
                        onMouseEnter={() => setSelectedIndex(index)}
                        onMouseDown={(e) => {
                            e.preventDefault(); // Prevent editor blur
                            insertMention(member);
                        }}
                        aria-label={`Mention ${member.name}`}
                    >
                        {/* Avatar */}
                        {member.avatar_display ? (
                            <img
                                src={member.avatar_display}
                                alt=""
                                className="size-7 rounded-full object-cover shrink-0"
                            />
                        ) : (
                            <div className="size-7 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-xs font-medium shrink-0">
                                {member.name.charAt(0).toUpperCase()}
                            </div>
                        )}
                        {/* Name + role */}
                        <div className="flex-1 min-w-0">
                            <div className="font-medium truncate">{highlightText(member.name, query)}</div>
                        </div>
                        <span className="text-[10px] text-muted-foreground capitalize shrink-0">
                            {member.role}
                        </span>
                    </button>
                ))}
            </div>
        </div>,
        document.body,
    );
}
