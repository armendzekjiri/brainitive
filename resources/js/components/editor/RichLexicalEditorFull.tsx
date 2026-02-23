import { useEffect, useCallback, useState, useRef, useMemo } from 'react';
import { LexicalComposer } from '@lexical/react/LexicalComposer';
import { RichTextPlugin } from '@lexical/react/LexicalRichTextPlugin';
import { ContentEditable } from '@lexical/react/LexicalContentEditable';
import { HistoryPlugin } from '@lexical/react/LexicalHistoryPlugin';
import { LexicalErrorBoundary } from '@lexical/react/LexicalErrorBoundary';
import { ListPlugin } from '@lexical/react/LexicalListPlugin';
import { LinkPlugin } from '@lexical/react/LexicalLinkPlugin';
import { CheckListPlugin } from '@lexical/react/LexicalCheckListPlugin';
import { TablePlugin } from '@lexical/react/LexicalTablePlugin';
import { MarkdownShortcutPlugin } from '@lexical/react/LexicalMarkdownShortcutPlugin';
import { TabIndentationPlugin } from '@lexical/react/LexicalTabIndentationPlugin';
import { useLexicalComposerContext } from '@lexical/react/LexicalComposerContext';
import {
    $getRoot,
    $createParagraphNode,
    $createTextNode,
    $getSelection,
    $isRangeSelection,
    FORMAT_TEXT_COMMAND,
    UNDO_COMMAND,
    REDO_COMMAND,
    COMMAND_PRIORITY_LOW,
    COMMAND_PRIORITY_HIGH,
    KEY_DOWN_COMMAND,
    $isTextNode,
    createCommand,
    LexicalCommand,
} from 'lexical';
import { $generateHtmlFromNodes, $generateNodesFromDOM } from '@lexical/html';
import {
    HeadingNode,
    QuoteNode,
    $createHeadingNode,
    $createQuoteNode,
} from '@lexical/rich-text';
import {
    ListNode,
    ListItemNode,
    $createListNode,
    $createListItemNode,
    INSERT_ORDERED_LIST_COMMAND,
    INSERT_UNORDERED_LIST_COMMAND,
    INSERT_CHECK_LIST_COMMAND,
} from '@lexical/list';
import { LinkNode, AutoLinkNode, TOGGLE_LINK_COMMAND, $isLinkNode } from '@lexical/link';
import { CodeNode, CodeHighlightNode, $createCodeNode } from '@lexical/code';
import {
    TableNode,
    TableCellNode,
    TableRowNode,
    INSERT_TABLE_COMMAND,
} from '@lexical/table';
import { HorizontalRuleNode, INSERT_HORIZONTAL_RULE_COMMAND } from '@lexical/react/LexicalHorizontalRuleNode';
import { HorizontalRulePlugin } from '@lexical/react/LexicalHorizontalRulePlugin';
import { TRANSFORMERS } from '@lexical/markdown';
import { $setBlocksType } from '@lexical/selection';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Bold,
    Italic,
    Underline,
    Strikethrough,
    List,
    ListOrdered,
    Quote as QuoteIcon,
    Code,
    Undo2,
    Redo2,
    Link,
    Heading1,
    Heading2,
    Heading3,
    CheckSquare,
    Table,
    Minus,
    Type,
    FileCode,
    ListChecks,
    Image as ImageIcon,
    Youtube,
    FileText,
    StickyNote,
    BarChart3,
    Columns,
    ChevronDown,
    Calculator,
    Scissors,
    Palette,
    Twitter,
    Figma,
    Pencil,
    Calendar,
} from 'lucide-react';

// Import custom nodes
import {
    ImageNode,
    $createImageNode,
    GIFNode,
    $createGIFNode,
    YouTubeNode,
    $createYouTubeNode,
    FigmaNode,
    $createFigmaNode,
    TweetNode,
    $createTweetNode,
    ExcalidrawNode,
    $createExcalidrawNode,
    EquationNode,
    $createEquationNode,
    StickyNoteNode,
    $createStickyNoteNode,
    PollNode,
    $createPollNode,
    PageBreakNode,
    $createPageBreakNode,
    LayoutContainerNode,
    LayoutItemNode,
    $createLayoutContainerNode,
    $createLayoutItemNode,
    CollapsibleContainerNode,
    CollapsibleTitleNode,
    CollapsibleContentNode,
    $createCollapsibleContainerNode,
    $createCollapsibleTitleNode,
    $createCollapsibleContentNode,
} from './nodes';

import TableActionMenuPlugin from './plugins/TableActionMenuPlugin';

// Custom commands
export const INSERT_IMAGE_COMMAND: LexicalCommand<{ src: string; altText: string }> = createCommand();
export const INSERT_GIF_COMMAND: LexicalCommand<string> = createCommand();
export const INSERT_YOUTUBE_COMMAND: LexicalCommand<string> = createCommand();
export const INSERT_FIGMA_COMMAND: LexicalCommand<string> = createCommand();
export const INSERT_TWEET_COMMAND: LexicalCommand<string> = createCommand();
export const INSERT_EXCALIDRAW_COMMAND: LexicalCommand<void> = createCommand();
export const INSERT_EQUATION_COMMAND: LexicalCommand<{ equation: string; inline: boolean }> = createCommand();
export const INSERT_STICKY_NOTE_COMMAND: LexicalCommand<void> = createCommand();
export const INSERT_POLL_COMMAND: LexicalCommand<void> = createCommand();
export const INSERT_PAGE_BREAK_COMMAND: LexicalCommand<void> = createCommand();
export const INSERT_LAYOUT_COMMAND: LexicalCommand<string> = createCommand();
export const INSERT_COLLAPSIBLE_COMMAND: LexicalCommand<void> = createCommand();

// Theme for styling editor content
const theme = {
    paragraph: 'mb-2 leading-relaxed',
    heading: {
        h1: 'text-3xl font-bold mb-4 mt-6',
        h2: 'text-2xl font-bold mb-3 mt-5',
        h3: 'text-xl font-semibold mb-2 mt-4',
        h4: 'text-lg font-semibold mb-2 mt-3',
        h5: 'text-base font-semibold mb-1 mt-2',
    },
    list: {
        ul: 'list-disc ml-6 mb-3',
        ol: 'list-decimal ml-6 mb-3',
        listitem: 'mb-1',
        listitemChecked: 'lexical-listitem-checked line-through text-muted-foreground relative list-none pl-6',
        listitemUnchecked: 'lexical-listitem-unchecked relative list-none pl-6',
        checklist: 'list-none ml-0 mb-3',
        nested: {
            listitem: 'list-none',
        },
    },
    quote: 'border-l-4 border-primary/30 pl-4 italic text-muted-foreground my-4 py-2',
    code: 'bg-muted px-1.5 py-0.5 rounded font-mono text-sm',
    codeHighlight: {
        atrule: 'text-purple-500',
        attr: 'text-blue-500',
        boolean: 'text-orange-500',
        builtin: 'text-cyan-500',
        cdata: 'text-gray-500',
        char: 'text-green-500',
        class: 'text-yellow-500',
        'class-name': 'text-yellow-500',
        comment: 'text-gray-400 italic',
        constant: 'text-orange-500',
        deleted: 'text-red-500',
        doctype: 'text-gray-500',
        entity: 'text-red-500',
        function: 'text-blue-500',
        important: 'text-red-500 font-bold',
        inserted: 'text-green-500',
        keyword: 'text-purple-500',
        namespace: 'text-red-500',
        number: 'text-orange-500',
        operator: 'text-gray-600',
        prolog: 'text-gray-500',
        property: 'text-blue-500',
        punctuation: 'text-gray-600',
        regex: 'text-green-500',
        selector: 'text-yellow-500',
        string: 'text-green-500',
        symbol: 'text-orange-500',
        tag: 'text-red-500',
        url: 'text-blue-500 underline',
        variable: 'text-red-500',
    },
    link: 'text-primary underline cursor-pointer hover:text-primary/80',
    text: {
        bold: 'font-bold',
        italic: 'italic',
        underline: 'underline',
        strikethrough: 'line-through',
        code: 'bg-muted px-1.5 py-0.5 rounded font-mono text-sm',
    },
    table: 'border-collapse border border-border my-4 w-full',
    tableCell: 'border border-border p-2 min-w-[75px] relative',
    tableCellHeader: 'border border-border p-2 bg-muted font-semibold',
    horizontalRule: 'border-t border-border my-6',
    image: 'inline-block my-2',
};

// Slash command menu items interface
interface SlashMenuItem {
    title: string;
    description: string;
    icon: React.ReactNode;
    keywords: string[];
    onSelect: (editor: any) => void;
}

// Slash Commands Plugin
function SlashCommandsPlugin() {
    const [editor] = useLexicalComposerContext();
    const [showMenu, setShowMenu] = useState(false);
    const [menuPosition, setMenuPosition] = useState({ top: 0, left: 0 });
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedIndex, setSelectedIndex] = useState(0);
    const menuRef = useRef<HTMLDivElement>(null);

    // Modal states for various inserts
    const [showImageModal, setShowImageModal] = useState(false);
    const [showGIFModal, setShowGIFModal] = useState(false);
    const [showYouTubeModal, setShowYouTubeModal] = useState(false);
    const [showFigmaModal, setShowFigmaModal] = useState(false);
    const [showTweetModal, setShowTweetModal] = useState(false);
    const [showEquationModal, setShowEquationModal] = useState(false);
    const [showTableModal, setShowTableModal] = useState(false);
    const [showLayoutModal, setShowLayoutModal] = useState(false);

    const slashMenuItems: SlashMenuItem[] = useMemo(() => [
        // Basic blocks
        {
            title: 'Heading 1',
            description: 'Large section heading',
            icon: <Heading1 className="size-4" />,
            keywords: ['h1', 'heading', 'title', 'large'],
            onSelect: (editor: any) => {
                editor.update(() => {
                    const selection = $getSelection();
                    if ($isRangeSelection(selection)) {
                        $setBlocksType(selection, () => $createHeadingNode('h1'));
                    }
                });
            },
        },
        {
            title: 'Heading 2',
            description: 'Medium section heading',
            icon: <Heading2 className="size-4" />,
            keywords: ['h2', 'heading', 'subtitle'],
            onSelect: (editor: any) => {
                editor.update(() => {
                    const selection = $getSelection();
                    if ($isRangeSelection(selection)) {
                        $setBlocksType(selection, () => $createHeadingNode('h2'));
                    }
                });
            },
        },
        {
            title: 'Heading 3',
            description: 'Small section heading',
            icon: <Heading3 className="size-4" />,
            keywords: ['h3', 'heading', 'small'],
            onSelect: (editor: any) => {
                editor.update(() => {
                    const selection = $getSelection();
                    if ($isRangeSelection(selection)) {
                        $setBlocksType(selection, () => $createHeadingNode('h3'));
                    }
                });
            },
        },
        {
            title: 'Paragraph',
            description: 'Normal text block',
            icon: <Type className="size-4" />,
            keywords: ['text', 'paragraph', 'normal', 'p'],
            onSelect: (editor: any) => {
                editor.update(() => {
                    const selection = $getSelection();
                    if ($isRangeSelection(selection)) {
                        $setBlocksType(selection, () => $createParagraphNode());
                    }
                });
            },
        },
        // Lists
        {
            title: 'Bullet List',
            description: 'Unordered bullet list',
            icon: <List className="size-4" />,
            keywords: ['ul', 'list', 'bullet', 'unordered'],
            onSelect: (editor: any) => {
                editor.dispatchCommand(INSERT_UNORDERED_LIST_COMMAND, undefined);
            },
        },
        {
            title: 'Numbered List',
            description: 'Ordered numbered list',
            icon: <ListOrdered className="size-4" />,
            keywords: ['ol', 'list', 'numbered', 'ordered'],
            onSelect: (editor: any) => {
                editor.dispatchCommand(INSERT_ORDERED_LIST_COMMAND, undefined);
            },
        },
        {
            title: 'Checklist',
            description: 'Task list with checkboxes',
            icon: <ListChecks className="size-4" />,
            keywords: ['todo', 'task', 'check', 'checkbox'],
            onSelect: (editor: any) => {
                editor.dispatchCommand(INSERT_CHECK_LIST_COMMAND, undefined);
            },
        },
        // Quote and Code
        {
            title: 'Quote',
            description: 'Block quote',
            icon: <QuoteIcon className="size-4" />,
            keywords: ['quote', 'blockquote', 'citation'],
            onSelect: (editor: any) => {
                editor.update(() => {
                    const selection = $getSelection();
                    if ($isRangeSelection(selection)) {
                        $setBlocksType(selection, () => $createQuoteNode());
                    }
                });
            },
        },
        {
            title: 'Code Block',
            description: 'Code snippet',
            icon: <FileCode className="size-4" />,
            keywords: ['code', 'snippet', 'pre', 'programming'],
            onSelect: (editor: any) => {
                editor.update(() => {
                    const selection = $getSelection();
                    if ($isRangeSelection(selection)) {
                        $setBlocksType(selection, () => $createCodeNode());
                    }
                });
            },
        },
        // Dividers
        {
            title: 'Horizontal Rule',
            description: 'Horizontal line separator',
            icon: <Minus className="size-4" />,
            keywords: ['hr', 'divider', 'line', 'separator', 'horizontal'],
            onSelect: (editor: any) => {
                editor.dispatchCommand(INSERT_HORIZONTAL_RULE_COMMAND, undefined);
            },
        },
        {
            title: 'Page Break',
            description: 'Page break for printing',
            icon: <Scissors className="size-4" />,
            keywords: ['page', 'break', 'pagebreak', 'print'],
            onSelect: (editor: any) => {
                editor.dispatchCommand(INSERT_PAGE_BREAK_COMMAND, undefined);
            },
        },
        // Media
        {
            title: 'Image',
            description: 'Insert an image from URL',
            icon: <ImageIcon className="size-4" />,
            keywords: ['image', 'photo', 'picture', 'img'],
            onSelect: () => {
                setShowImageModal(true);
            },
        },
        {
            title: 'GIF',
            description: 'Insert an animated GIF',
            icon: <ImageIcon className="size-4" />,
            keywords: ['gif', 'animation', 'animated'],
            onSelect: () => {
                setShowGIFModal(true);
            },
        },
        {
            title: 'YouTube Video',
            description: 'Embed a YouTube video',
            icon: <Youtube className="size-4" />,
            keywords: ['youtube', 'video', 'embed'],
            onSelect: () => {
                setShowYouTubeModal(true);
            },
        },
        {
            title: 'Figma Document',
            description: 'Embed a Figma design',
            icon: <Figma className="size-4" />,
            keywords: ['figma', 'design', 'embed'],
            onSelect: () => {
                setShowFigmaModal(true);
            },
        },
        {
            title: 'X (Tweet)',
            description: 'Embed a tweet',
            icon: <Twitter className="size-4" />,
            keywords: ['twitter', 'tweet', 'x', 'embed'],
            onSelect: () => {
                setShowTweetModal(true);
            },
        },
        // Interactive
        {
            title: 'Excalidraw',
            description: 'Whiteboard drawing',
            icon: <Pencil className="size-4" />,
            keywords: ['excalidraw', 'draw', 'whiteboard', 'sketch'],
            onSelect: (editor: any) => {
                editor.dispatchCommand(INSERT_EXCALIDRAW_COMMAND, undefined);
            },
        },
        {
            title: 'Table',
            description: 'Insert a table',
            icon: <Table className="size-4" />,
            keywords: ['table', 'grid', 'rows', 'columns'],
            onSelect: () => {
                setShowTableModal(true);
            },
        },
        {
            title: 'Poll',
            description: 'Create a poll',
            icon: <BarChart3 className="size-4" />,
            keywords: ['poll', 'vote', 'survey'],
            onSelect: (editor: any) => {
                editor.dispatchCommand(INSERT_POLL_COMMAND, undefined);
            },
        },
        // Layout
        {
            title: 'Columns Layout',
            description: 'Multi-column layout',
            icon: <Columns className="size-4" />,
            keywords: ['columns', 'layout', 'grid', 'split'],
            onSelect: () => {
                setShowLayoutModal(true);
            },
        },
        // Math & Notes
        {
            title: 'Equation',
            description: 'LaTeX math equation',
            icon: <Calculator className="size-4" />,
            keywords: ['equation', 'math', 'latex', 'formula'],
            onSelect: () => {
                setShowEquationModal(true);
            },
        },
        {
            title: 'Sticky Note',
            description: 'Colored sticky note',
            icon: <StickyNote className="size-4" />,
            keywords: ['sticky', 'note', 'reminder'],
            onSelect: (editor: any) => {
                editor.dispatchCommand(INSERT_STICKY_NOTE_COMMAND, undefined);
            },
        },
        {
            title: 'Collapsible',
            description: 'Collapsible content section',
            icon: <ChevronDown className="size-4" />,
            keywords: ['collapsible', 'toggle', 'accordion', 'details'],
            onSelect: (editor: any) => {
                editor.dispatchCommand(INSERT_COLLAPSIBLE_COMMAND, undefined);
            },
        },
    ], []);

    const filteredItems = useMemo(() => {
        if (!searchTerm) return slashMenuItems;
        const lower = searchTerm.toLowerCase();
        return slashMenuItems.filter(item =>
            item.title.toLowerCase().includes(lower) ||
            item.keywords.some(k => k.includes(lower))
        );
    }, [searchTerm, slashMenuItems]);

    useEffect(() => {
        setSelectedIndex(0);
    }, [filteredItems.length]);

    // Auto-scroll selected item into view
    useEffect(() => {
        if (showMenu && menuRef.current) {
            const selectedElement = menuRef.current.querySelector(`[data-index="${selectedIndex}"]`);
            if (selectedElement) {
                selectedElement.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        }
    }, [selectedIndex, showMenu]);

    useEffect(() => {
        return editor.registerCommand(
            KEY_DOWN_COMMAND,
            (event: KeyboardEvent) => {
                if (showMenu) {
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        setSelectedIndex(prev =>
                            prev < filteredItems.length - 1 ? prev + 1 : 0
                        );
                        return true;
                    }
                    if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        setSelectedIndex(prev =>
                            prev > 0 ? prev - 1 : filteredItems.length - 1
                        );
                        return true;
                    }
                    if (event.key === 'Enter' && filteredItems[selectedIndex]) {
                        event.preventDefault();
                        // Remove the slash and any search text
                        editor.update(() => {
                            const selection = $getSelection();
                            if ($isRangeSelection(selection)) {
                                const anchor = selection.anchor;
                                const node = anchor.getNode();
                                if ($isTextNode(node)) {
                                    const text = node.getTextContent();
                                    const slashIndex = text.lastIndexOf('/');
                                    if (slashIndex !== -1) {
                                        const newText = text.substring(0, slashIndex);
                                        node.setTextContent(newText);
                                    }
                                }
                            }
                        });
                        filteredItems[selectedIndex].onSelect(editor);
                        setShowMenu(false);
                        setSearchTerm('');
                        return true;
                    }
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        setShowMenu(false);
                        setSearchTerm('');
                        return true;
                    }
                    if (event.key === 'Backspace') {
                        if (searchTerm.length === 0) {
                            setShowMenu(false);
                        } else {
                            setSearchTerm(prev => prev.slice(0, -1));
                        }
                    } else if (event.key.length === 1 && !event.ctrlKey && !event.metaKey) {
                        setSearchTerm(prev => prev + event.key);
                    }
                }
                return false;
            },
            COMMAND_PRIORITY_LOW
        );
    }, [editor, showMenu, filteredItems, selectedIndex, searchTerm]);

    useEffect(() => {
        return editor.registerUpdateListener(({ editorState }) => {
            editorState.read(() => {
                const selection = $getSelection();
                if (!$isRangeSelection(selection) || !selection.isCollapsed()) {
                    setShowMenu(false);
                    return;
                }

                const anchor = selection.anchor;
                const node = anchor.getNode();

                if ($isTextNode(node)) {
                    const text = node.getTextContent();
                    const offset = anchor.offset;

                    // Check if we just typed a slash
                    if (text.charAt(offset - 1) === '/' &&
                        (offset === 1 || text.charAt(offset - 2) === ' ' || text.charAt(offset - 2) === '\n')) {
                        const domSelection = window.getSelection();
                        if (domSelection && domSelection.rangeCount > 0) {
                            const range = domSelection.getRangeAt(0);
                            const rect = range.getBoundingClientRect();
                            setMenuPosition({
                                top: rect.bottom + window.scrollY + 5,
                                left: rect.left + window.scrollX,
                            });
                            setShowMenu(true);
                            setSearchTerm('');
                            setSelectedIndex(0);
                        }
                    } else if (showMenu) {
                        const slashIndex = text.lastIndexOf('/');
                        if (slashIndex !== -1 && slashIndex < offset) {
                            const term = text.substring(slashIndex + 1, offset);
                            setSearchTerm(term);
                        }
                    }
                }
            });
        });
    }, [editor, showMenu]);

    // Close menu when clicking outside
    useEffect(() => {
        const handleClickOutside = (e: MouseEvent) => {
            if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
                setShowMenu(false);
                setSearchTerm('');
            }
        };

        if (showMenu) {
            document.addEventListener('mousedown', handleClickOutside);
            return () => document.removeEventListener('mousedown', handleClickOutside);
        }
    }, [showMenu]);

    const handleSelectItem = (item: SlashMenuItem) => {
        editor.update(() => {
            const selection = $getSelection();
            if ($isRangeSelection(selection)) {
                const anchor = selection.anchor;
                const node = anchor.getNode();
                if ($isTextNode(node)) {
                    const text = node.getTextContent();
                    const slashIndex = text.lastIndexOf('/');
                    if (slashIndex !== -1) {
                        const newText = text.substring(0, slashIndex);
                        node.setTextContent(newText);
                    }
                }
            }
        });
        item.onSelect(editor);
        setShowMenu(false);
        setSearchTerm('');
    };

    return (
        <>
            {showMenu && (
                <div
                    ref={menuRef}
                    className="fixed z-50 w-80 bg-popover border rounded-lg shadow-lg overflow-hidden"
                    style={{
                        top: `${menuPosition.top}px`,
                        left: `${menuPosition.left}px`,
                    }}
                >
                    <div className="p-2 border-b bg-muted/50">
                        <span className="text-xs text-muted-foreground">
                            {searchTerm ? `Searching: ${searchTerm}` : 'Type to filter, ↑↓ to navigate, Enter to select'}
                        </span>
                    </div>
                    <div className="max-h-[400px] overflow-y-auto p-1">
                        {filteredItems.length === 0 ? (
                            <div className="p-3 text-sm text-muted-foreground text-center">
                                No matching blocks
                            </div>
                        ) : (
                            filteredItems.map((item, index) => (
                                <button
                                    key={item.title}
                                    data-index={index}
                                    className={cn(
                                        "w-full flex items-start gap-3 p-2 rounded-md text-left transition-colors",
                                        index === selectedIndex
                                            ? "bg-primary text-primary-foreground"
                                            : "hover:bg-muted"
                                    )}
                                    onClick={() => handleSelectItem(item)}
                                >
                                    <div className={cn(
                                        "p-1.5 rounded",
                                        index === selectedIndex
                                            ? "bg-primary-foreground/20"
                                            : "bg-muted"
                                    )}>
                                        {item.icon}
                                    </div>
                                    <div>
                                        <div className="font-medium text-sm">{item.title}</div>
                                        <div className={cn(
                                            "text-xs",
                                            index === selectedIndex
                                                ? "text-primary-foreground/70"
                                                : "text-muted-foreground"
                                        )}>
                                            {item.description}
                                        </div>
                                    </div>
                                </button>
                            ))
                        )}
                    </div>
                </div>
            )}

            {/* Image Modal */}
            <InsertImageModal
                open={showImageModal}
                onClose={() => setShowImageModal(false)}
                onInsert={(src, alt) => {
                    editor.dispatchCommand(INSERT_IMAGE_COMMAND, { src, altText: alt });
                    setShowImageModal(false);
                }}
            />

            {/* GIF Modal */}
            <InsertGIFModal
                open={showGIFModal}
                onClose={() => setShowGIFModal(false)}
                onInsert={(src) => {
                    editor.dispatchCommand(INSERT_GIF_COMMAND, src);
                    setShowGIFModal(false);
                }}
            />

            {/* YouTube Modal */}
            <InsertYouTubeModal
                open={showYouTubeModal}
                onClose={() => setShowYouTubeModal(false)}
                onInsert={(url) => {
                    editor.dispatchCommand(INSERT_YOUTUBE_COMMAND, url);
                    setShowYouTubeModal(false);
                }}
            />

            {/* Figma Modal */}
            <InsertFigmaModal
                open={showFigmaModal}
                onClose={() => setShowFigmaModal(false)}
                onInsert={(url) => {
                    editor.dispatchCommand(INSERT_FIGMA_COMMAND, url);
                    setShowFigmaModal(false);
                }}
            />

            {/* Tweet Modal */}
            <InsertTweetModal
                open={showTweetModal}
                onClose={() => setShowTweetModal(false)}
                onInsert={(url) => {
                    editor.dispatchCommand(INSERT_TWEET_COMMAND, url);
                    setShowTweetModal(false);
                }}
            />

            {/* Equation Modal */}
            <InsertEquationModal
                open={showEquationModal}
                onClose={() => setShowEquationModal(false)}
                onInsert={(equation, inline) => {
                    editor.dispatchCommand(INSERT_EQUATION_COMMAND, { equation, inline });
                    setShowEquationModal(false);
                }}
            />

            {/* Table Modal */}
            <InsertTableModal
                open={showTableModal}
                onClose={() => setShowTableModal(false)}
                onInsert={(rows, cols) => {
                    editor.dispatchCommand(INSERT_TABLE_COMMAND, { rows: String(rows), columns: String(cols) });
                    setShowTableModal(false);
                }}
            />

            {/* Layout Modal */}
            <InsertLayoutModal
                open={showLayoutModal}
                onClose={() => setShowLayoutModal(false)}
                onInsert={(template) => {
                    editor.dispatchCommand(INSERT_LAYOUT_COMMAND, template);
                    setShowLayoutModal(false);
                }}
            />
        </>
    );
}

// Modal Components
function InsertImageModal({ open, onClose, onInsert }: { open: boolean; onClose: () => void; onInsert: (src: string, alt: string) => void }) {
    const [src, setSrc] = useState('');
    const [alt, setAlt] = useState('');

    const handleSubmit = () => {
        if (src) {
            onInsert(src, alt || 'Image');
            setSrc('');
            setAlt('');
        }
    };

    return (
        <Dialog open={open} onOpenChange={onClose}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Insert Image</DialogTitle>
                </DialogHeader>
                <div className="space-y-4">
                    <div>
                        <Label>Image URL</Label>
                        <Input
                            value={src}
                            onChange={(e) => setSrc(e.target.value)}
                            placeholder="https://example.com/image.jpg"
                        />
                    </div>
                    <div>
                        <Label>Alt Text</Label>
                        <Input
                            value={alt}
                            onChange={(e) => setAlt(e.target.value)}
                            placeholder="Image description"
                        />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>Cancel</Button>
                    <Button onClick={handleSubmit} disabled={!src}>Insert</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function InsertGIFModal({ open, onClose, onInsert }: { open: boolean; onClose: () => void; onInsert: (src: string) => void }) {
    const [src, setSrc] = useState('');

    const handleSubmit = () => {
        if (src) {
            onInsert(src);
            setSrc('');
        }
    };

    return (
        <Dialog open={open} onOpenChange={onClose}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Insert GIF</DialogTitle>
                </DialogHeader>
                <div className="space-y-4">
                    <div>
                        <Label>GIF URL</Label>
                        <Input
                            value={src}
                            onChange={(e) => setSrc(e.target.value)}
                            placeholder="https://media.giphy.com/media/.../giphy.gif"
                        />
                    </div>
                    <p className="text-sm text-gray-500">
                        Paste a URL to an animated GIF from Giphy, Tenor, or any direct GIF link.
                    </p>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>Cancel</Button>
                    <Button onClick={handleSubmit} disabled={!src}>Insert</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function InsertYouTubeModal({ open, onClose, onInsert }: { open: boolean; onClose: () => void; onInsert: (url: string) => void }) {
    const [url, setUrl] = useState('');

    const handleSubmit = () => {
        if (url) {
            // Extract video ID from URL
            const match = url.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/);
            if (match) {
                onInsert(match[1]);
                setUrl('');
            }
        }
    };

    return (
        <Dialog open={open} onOpenChange={onClose}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Insert YouTube Video</DialogTitle>
                </DialogHeader>
                <div className="space-y-4">
                    <div>
                        <Label>YouTube URL</Label>
                        <Input
                            value={url}
                            onChange={(e) => setUrl(e.target.value)}
                            placeholder="https://www.youtube.com/watch?v=..."
                        />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>Cancel</Button>
                    <Button onClick={handleSubmit} disabled={!url}>Insert</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function InsertFigmaModal({ open, onClose, onInsert }: { open: boolean; onClose: () => void; onInsert: (url: string) => void }) {
    const [url, setUrl] = useState('');

    return (
        <Dialog open={open} onOpenChange={onClose}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Insert Figma Document</DialogTitle>
                </DialogHeader>
                <div className="space-y-4">
                    <div>
                        <Label>Figma URL</Label>
                        <Input
                            value={url}
                            onChange={(e) => setUrl(e.target.value)}
                            placeholder="https://www.figma.com/file/..."
                        />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>Cancel</Button>
                    <Button onClick={() => { onInsert(url); setUrl(''); }} disabled={!url}>Insert</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function InsertTweetModal({ open, onClose, onInsert }: { open: boolean; onClose: () => void; onInsert: (tweetId: string) => void }) {
    const [url, setUrl] = useState('');

    const handleSubmit = () => {
        if (url) {
            // Extract tweet ID from URL
            const match = url.match(/(?:twitter\.com|x\.com)\/\w+\/status\/(\d+)/);
            if (match) {
                onInsert(match[1]);
                setUrl('');
            }
        }
    };

    return (
        <Dialog open={open} onOpenChange={onClose}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Insert Tweet</DialogTitle>
                </DialogHeader>
                <div className="space-y-4">
                    <div>
                        <Label>Tweet URL</Label>
                        <Input
                            value={url}
                            onChange={(e) => setUrl(e.target.value)}
                            placeholder="https://twitter.com/user/status/123..."
                        />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>Cancel</Button>
                    <Button onClick={handleSubmit} disabled={!url}>Insert</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function InsertEquationModal({ open, onClose, onInsert }: { open: boolean; onClose: () => void; onInsert: (equation: string, inline: boolean) => void }) {
    const [equation, setEquation] = useState('');
    const [inline, setInline] = useState(true);

    return (
        <Dialog open={open} onOpenChange={onClose}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Insert Equation</DialogTitle>
                </DialogHeader>
                <div className="space-y-4">
                    <div>
                        <Label>LaTeX Equation</Label>
                        <Input
                            value={equation}
                            onChange={(e) => setEquation(e.target.value)}
                            placeholder="E = mc^2"
                            className="font-mono"
                        />
                    </div>
                    <div className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            id="inline"
                            checked={inline}
                            onChange={(e) => setInline(e.target.checked)}
                        />
                        <Label htmlFor="inline">Inline equation</Label>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>Cancel</Button>
                    <Button onClick={() => { onInsert(equation, inline); setEquation(''); }} disabled={!equation}>Insert</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function InsertTableModal({ open, onClose, onInsert }: { open: boolean; onClose: () => void; onInsert: (rows: number, cols: number) => void }) {
    const [rows, setRows] = useState(3);
    const [cols, setCols] = useState(3);

    return (
        <Dialog open={open} onOpenChange={onClose}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Insert Table</DialogTitle>
                </DialogHeader>
                <div className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <Label>Rows</Label>
                            <Input
                                type="number"
                                min={1}
                                max={20}
                                value={rows}
                                onChange={(e) => setRows(parseInt(e.target.value) || 1)}
                            />
                        </div>
                        <div>
                            <Label>Columns</Label>
                            <Input
                                type="number"
                                min={1}
                                max={10}
                                value={cols}
                                onChange={(e) => setCols(parseInt(e.target.value) || 1)}
                            />
                        </div>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>Cancel</Button>
                    <Button onClick={() => onInsert(rows, cols)}>Insert</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function InsertLayoutModal({ open, onClose, onInsert }: { open: boolean; onClose: () => void; onInsert: (template: string) => void }) {
    const layouts = [
        { name: '2 Columns (Equal)', template: '1fr 1fr' },
        { name: '3 Columns (Equal)', template: '1fr 1fr 1fr' },
        { name: '2 Columns (1/3 + 2/3)', template: '1fr 2fr' },
        { name: '2 Columns (2/3 + 1/3)', template: '2fr 1fr' },
        { name: '4 Columns', template: '1fr 1fr 1fr 1fr' },
    ];

    return (
        <Dialog open={open} onOpenChange={onClose}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Insert Column Layout</DialogTitle>
                </DialogHeader>
                <div className="space-y-2">
                    {layouts.map((layout) => (
                        <button
                            key={layout.template}
                            onClick={() => onInsert(layout.template)}
                            className="w-full p-3 text-left border rounded-md hover:bg-muted transition-colors"
                        >
                            <div className="font-medium text-sm">{layout.name}</div>
                            <div className="text-xs text-muted-foreground mt-1">
                                grid-template-columns: {layout.template}
                            </div>
                        </button>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}

// Commands Plugin - registers all custom commands
function CommandsPlugin() {
    const [editor] = useLexicalComposerContext();

    useEffect(() => {
        // Image command
        const removeImageCommand = editor.registerCommand(
            INSERT_IMAGE_COMMAND,
            (payload) => {
                const imageNode = $createImageNode(payload);
                const selection = $getSelection();
                if ($isRangeSelection(selection)) {
                    selection.insertNodes([imageNode]);
                }
                return true;
            },
            COMMAND_PRIORITY_HIGH
        );

        // GIF command
        const removeGIFCommand = editor.registerCommand(
            INSERT_GIF_COMMAND,
            (src) => {
                const gifNode = $createGIFNode(src);
                const selection = $getSelection();
                if ($isRangeSelection(selection)) {
                    selection.insertNodes([gifNode]);
                }
                return true;
            },
            COMMAND_PRIORITY_HIGH
        );

        // YouTube command
        const removeYouTubeCommand = editor.registerCommand(
            INSERT_YOUTUBE_COMMAND,
            (videoID) => {
                const youtubeNode = $createYouTubeNode(videoID);
                const selection = $getSelection();
                if ($isRangeSelection(selection)) {
                    selection.insertNodes([youtubeNode]);
                }
                return true;
            },
            COMMAND_PRIORITY_HIGH
        );

        // Figma command
        const removeFigmaCommand = editor.registerCommand(
            INSERT_FIGMA_COMMAND,
            (documentID) => {
                const figmaNode = $createFigmaNode(documentID);
                const selection = $getSelection();
                if ($isRangeSelection(selection)) {
                    selection.insertNodes([figmaNode]);
                }
                return true;
            },
            COMMAND_PRIORITY_HIGH
        );

        // Tweet command
        const removeTweetCommand = editor.registerCommand(
            INSERT_TWEET_COMMAND,
            (tweetID) => {
                const tweetNode = $createTweetNode(tweetID);
                const selection = $getSelection();
                if ($isRangeSelection(selection)) {
                    selection.insertNodes([tweetNode]);
                }
                return true;
            },
            COMMAND_PRIORITY_HIGH
        );

        // Excalidraw command
        const removeExcalidrawCommand = editor.registerCommand(
            INSERT_EXCALIDRAW_COMMAND,
            () => {
                const excalidrawNode = $createExcalidrawNode();
                const selection = $getSelection();
                if ($isRangeSelection(selection)) {
                    selection.insertNodes([excalidrawNode]);
                }
                return true;
            },
            COMMAND_PRIORITY_HIGH
        );

        // Equation command
        const removeEquationCommand = editor.registerCommand(
            INSERT_EQUATION_COMMAND,
            ({ equation, inline }) => {
                const equationNode = $createEquationNode(equation, inline);
                const selection = $getSelection();
                if ($isRangeSelection(selection)) {
                    selection.insertNodes([equationNode]);
                }
                return true;
            },
            COMMAND_PRIORITY_HIGH
        );

        // Sticky Note command
        const removeStickyNoteCommand = editor.registerCommand(
            INSERT_STICKY_NOTE_COMMAND,
            () => {
                const stickyNoteNode = $createStickyNoteNode();
                const selection = $getSelection();
                if ($isRangeSelection(selection)) {
                    selection.insertNodes([stickyNoteNode]);
                }
                return true;
            },
            COMMAND_PRIORITY_HIGH
        );

        // Poll command
        const removePollCommand = editor.registerCommand(
            INSERT_POLL_COMMAND,
            () => {
                const pollNode = $createPollNode();
                const selection = $getSelection();
                if ($isRangeSelection(selection)) {
                    selection.insertNodes([pollNode]);
                }
                return true;
            },
            COMMAND_PRIORITY_HIGH
        );

        // Page Break command
        const removePageBreakCommand = editor.registerCommand(
            INSERT_PAGE_BREAK_COMMAND,
            () => {
                const pageBreakNode = $createPageBreakNode();
                const selection = $getSelection();
                if ($isRangeSelection(selection)) {
                    selection.insertNodes([pageBreakNode]);
                }
                return true;
            },
            COMMAND_PRIORITY_HIGH
        );

        // Layout command
        const removeLayoutCommand = editor.registerCommand(
            INSERT_LAYOUT_COMMAND,
            (templateColumns) => {
                const layoutContainer = $createLayoutContainerNode(templateColumns);
                const columnCount = templateColumns.split(' ').length;

                for (let i = 0; i < columnCount; i++) {
                    const layoutItem = $createLayoutItemNode();
                    const paragraph = $createParagraphNode();
                    paragraph.append($createTextNode(''));
                    layoutItem.append(paragraph);
                    layoutContainer.append(layoutItem);
                }

                const selection = $getSelection();
                if ($isRangeSelection(selection)) {
                    selection.insertNodes([layoutContainer]);
                }
                return true;
            },
            COMMAND_PRIORITY_HIGH
        );

        // Collapsible command
        const removeCollapsibleCommand = editor.registerCommand(
            INSERT_COLLAPSIBLE_COMMAND,
            () => {
                const container = $createCollapsibleContainerNode(true);
                const title = $createCollapsibleTitleNode();
                const content = $createCollapsibleContentNode();

                const titleParagraph = $createParagraphNode();
                titleParagraph.append($createTextNode('Collapsible title'));
                title.append(titleParagraph);

                const contentParagraph = $createParagraphNode();
                contentParagraph.append($createTextNode('Collapsible content'));
                content.append(contentParagraph);

                container.append(title);
                container.append(content);

                const selection = $getSelection();
                if ($isRangeSelection(selection)) {
                    selection.insertNodes([container]);
                }
                return true;
            },
            COMMAND_PRIORITY_HIGH
        );

        return () => {
            removeImageCommand();
            removeGIFCommand();
            removeYouTubeCommand();
            removeFigmaCommand();
            removeTweetCommand();
            removeExcalidrawCommand();
            removeEquationCommand();
            removeStickyNoteCommand();
            removePollCommand();
            removePageBreakCommand();
            removeLayoutCommand();
            removeCollapsibleCommand();
        };
    }, [editor]);

    return null;
}

// Toolbar Plugin
function ToolbarPlugin({ showToolbar = true }: { showToolbar?: boolean }) {
    const [editor] = useLexicalComposerContext();
    const [isBold, setIsBold] = useState(false);
    const [isItalic, setIsItalic] = useState(false);
    const [isUnderline, setIsUnderline] = useState(false);
    const [isStrikethrough, setIsStrikethrough] = useState(false);
    const [isCode, setIsCode] = useState(false);
    const [isLink, setIsLink] = useState(false);
    const [linkUrl, setLinkUrl] = useState('');
    const [showLinkInput, setShowLinkInput] = useState(false);

    const updateToolbar = useCallback(() => {
        const selection = $getSelection();
        if ($isRangeSelection(selection)) {
            setIsBold(selection.hasFormat('bold'));
            setIsItalic(selection.hasFormat('italic'));
            setIsUnderline(selection.hasFormat('underline'));
            setIsStrikethrough(selection.hasFormat('strikethrough'));
            setIsCode(selection.hasFormat('code'));

            const node = selection.anchor.getNode();
            const parent = node.getParent();
            setIsLink($isLinkNode(parent) || $isLinkNode(node));
        }
    }, []);

    useEffect(() => {
        return editor.registerUpdateListener(({ editorState }) => {
            editorState.read(() => {
                updateToolbar();
            });
        });
    }, [editor, updateToolbar]);

    const insertLink = useCallback(() => {
        if (!linkUrl) {
            editor.dispatchCommand(TOGGLE_LINK_COMMAND, null);
        } else {
            editor.dispatchCommand(TOGGLE_LINK_COMMAND, linkUrl);
        }
        setShowLinkInput(false);
        setLinkUrl('');
    }, [editor, linkUrl]);

    if (!showToolbar) return null;

    return (
        <div className="flex items-center gap-0.5 p-1.5 border-b bg-muted/30 flex-wrap">
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className={cn("h-8 w-8 p-0", isBold && "bg-muted")}
                onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'bold')}
                title="Bold (Ctrl+B)"
            >
                <Bold className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className={cn("h-8 w-8 p-0", isItalic && "bg-muted")}
                onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'italic')}
                title="Italic (Ctrl+I)"
            >
                <Italic className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className={cn("h-8 w-8 p-0", isUnderline && "bg-muted")}
                onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'underline')}
                title="Underline (Ctrl+U)"
            >
                <Underline className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className={cn("h-8 w-8 p-0", isStrikethrough && "bg-muted")}
                onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'strikethrough')}
                title="Strikethrough"
            >
                <Strikethrough className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className={cn("h-8 w-8 p-0", isCode && "bg-muted")}
                onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'code')}
                title="Inline Code"
            >
                <Code className="h-4 w-4" />
            </Button>

            <div className="w-px h-5 bg-border mx-1" />

            <Popover open={showLinkInput} onOpenChange={setShowLinkInput}>
                <PopoverTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className={cn("h-8 w-8 p-0", isLink && "bg-muted")}
                        title="Insert Link"
                    >
                        <Link className="h-4 w-4" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-80 p-3" align="start">
                    <div className="space-y-2">
                        <label className="text-sm font-medium">URL</label>
                        <Input
                            value={linkUrl}
                            onChange={(e) => setLinkUrl(e.target.value)}
                            placeholder="https://example.com"
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    insertLink();
                                }
                            }}
                        />
                        <div className="flex justify-end gap-2">
                            {isLink && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                        editor.dispatchCommand(TOGGLE_LINK_COMMAND, null);
                                        setShowLinkInput(false);
                                    }}
                                >
                                    Remove Link
                                </Button>
                            )}
                            <Button size="sm" onClick={insertLink}>
                                {isLink ? 'Update' : 'Insert'}
                            </Button>
                        </div>
                    </div>
                </PopoverContent>
            </Popover>

            <div className="w-px h-5 bg-border mx-1" />

            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={() => editor.update(() => {
                    const selection = $getSelection();
                    if ($isRangeSelection(selection)) {
                        $setBlocksType(selection, () => $createHeadingNode('h1'));
                    }
                })}
                title="Heading 1"
            >
                <Heading1 className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={() => editor.update(() => {
                    const selection = $getSelection();
                    if ($isRangeSelection(selection)) {
                        $setBlocksType(selection, () => $createHeadingNode('h2'));
                    }
                })}
                title="Heading 2"
            >
                <Heading2 className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={() => editor.dispatchCommand(INSERT_UNORDERED_LIST_COMMAND, undefined)}
                title="Bullet List"
            >
                <List className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={() => editor.dispatchCommand(INSERT_ORDERED_LIST_COMMAND, undefined)}
                title="Numbered List"
            >
                <ListOrdered className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={() => editor.dispatchCommand(INSERT_CHECK_LIST_COMMAND, undefined)}
                title="Checklist"
            >
                <CheckSquare className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={() => editor.update(() => {
                    const selection = $getSelection();
                    if ($isRangeSelection(selection)) {
                        $setBlocksType(selection, () => $createQuoteNode());
                    }
                })}
                title="Quote"
            >
                <QuoteIcon className="h-4 w-4" />
            </Button>

            <div className="w-px h-5 bg-border mx-1" />

            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={() => editor.dispatchCommand(UNDO_COMMAND, undefined)}
                title="Undo (Ctrl+Z)"
            >
                <Undo2 className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={() => editor.dispatchCommand(REDO_COMMAND, undefined)}
                title="Redo (Ctrl+Y)"
            >
                <Redo2 className="h-4 w-4" />
            </Button>

            <div className="ml-auto text-xs text-muted-foreground">
                Type <kbd className="px-1 py-0.5 bg-muted rounded text-[10px]">/</kbd> for commands
            </div>
        </div>
    );
}

// Plugin to set initial content
function InitialContentPlugin({ content, editable, contentKey }: { content: string | null; editable: boolean; contentKey?: string }) {
    const [editor] = useLexicalComposerContext();
    const lastContentKeyRef = useRef<string | undefined>(undefined);
    const hasInitializedRef = useRef(false);

    useEffect(() => {
        // Only update if contentKey changed (indicating a different item) or on first render
        const shouldUpdate = !hasInitializedRef.current || contentKey !== lastContentKeyRef.current;

        if (shouldUpdate) {
            hasInitializedRef.current = true;
            lastContentKeyRef.current = contentKey;

            editor.update(() => {
                const root = $getRoot();
                root.clear();

                if (content) {
                    const parser = new DOMParser();
                    const dom = parser.parseFromString(content, 'text/html');
                    const nodes = $generateNodesFromDOM(editor, dom);

                    if (nodes.length === 0) {
                        const paragraph = $createParagraphNode();
                        paragraph.append($createTextNode(content));
                        root.append(paragraph);
                    } else {
                        nodes.forEach(node => {
                            root.append(node);
                        });
                    }
                } else {
                    const paragraph = $createParagraphNode();
                    root.append(paragraph);
                }
            });
        }
    }, [editor, content, contentKey]);

    useEffect(() => {
        editor.setEditable(editable);
    }, [editor, editable]);

    return null;
}

// Plugin to get HTML content on change
function HtmlOnChangePlugin({ onChange }: { onChange: (html: string) => void }) {
    const [editor] = useLexicalComposerContext();
    const skipCountRef = useRef(2); // Skip first 2 updates (initial + content load)

    useEffect(() => {
        // Reset skip count when component mounts
        skipCountRef.current = 2;

        return editor.registerUpdateListener(({ editorState, dirtyElements, dirtyLeaves, tags }) => {
            // Skip initial updates to allow InitialContentPlugin to load content first
            if (skipCountRef.current > 0) {
                skipCountRef.current--;
                return;
            }

            // Only trigger onChange if there are dirty elements or leaves (actual content changes)
            if (dirtyElements.size === 0 && dirtyLeaves.size === 0) {
                return;
            }

            editorState.read(() => {
                const html = $generateHtmlFromNodes(editor);
                onChange(html);
            });
        });
    }, [editor, onChange]);

    return null;
}

interface RichLexicalEditorProps {
    content: string | null;
    onChange: (content: string) => void;
    editable?: boolean;
    placeholder?: string;
    showToolbar?: boolean;
    minHeight?: string;
    className?: string;
    contentKey?: string;
}

export function RichLexicalEditor({
    content,
    onChange,
    editable = true,
    placeholder = 'Start typing, or press / for commands...',
    showToolbar = true,
    minHeight = '200px',
    className,
    contentKey,
}: RichLexicalEditorProps) {
    const [floatingAnchorElem, setFloatingAnchorElem] = useState<HTMLDivElement | null>(null);

    const onRef = (_floatingAnchorElem: HTMLDivElement) => {
        if (_floatingAnchorElem !== null) {
            setFloatingAnchorElem(_floatingAnchorElem);
        }
    };

    const initialConfig = {
        namespace: 'RichEditor',
        theme,
        onError: (error: Error) => {
            console.error('Lexical error:', error);
        },
        nodes: [
            HeadingNode,
            QuoteNode,
            ListNode,
            ListItemNode,
            LinkNode,
            AutoLinkNode,
            CodeNode,
            CodeHighlightNode,
            TableNode,
            TableCellNode,
            TableRowNode,
            HorizontalRuleNode,
            ImageNode,
            GIFNode,
            YouTubeNode,
            FigmaNode,
            TweetNode,
            ExcalidrawNode,
            EquationNode,
            StickyNoteNode,
            PollNode,
            PageBreakNode,
            LayoutContainerNode,
            LayoutItemNode,
            CollapsibleContainerNode,
            CollapsibleTitleNode,
            CollapsibleContentNode,
        ],
        editable,
    };

    return (
        <LexicalComposer initialConfig={initialConfig}>
            <div className={cn("border rounded-lg overflow-hidden bg-background", className)}>
                <ToolbarPlugin showToolbar={showToolbar && editable} />
                <div className="relative" ref={onRef}>
                    <RichTextPlugin
                        contentEditable={
                            <ContentEditable
                                className={cn(
                                    "outline-none p-4 text-sm prose prose-sm max-w-none",
                                    !editable && "cursor-default"
                                )}
                                style={{ minHeight }}
                                aria-placeholder={placeholder}
                                placeholder={
                                    <div className="absolute top-4 left-4 text-muted-foreground pointer-events-none text-sm">
                                        {placeholder}
                                    </div>
                                }
                            />
                        }
                        ErrorBoundary={LexicalErrorBoundary}
                    />
                </div>
                <HistoryPlugin />
                <ListPlugin />
                <CheckListPlugin />
                <LinkPlugin />
                <TablePlugin hasCellMerge hasCellBackgroundColor />
                <HorizontalRulePlugin />
                <TabIndentationPlugin />
                <MarkdownShortcutPlugin transformers={TRANSFORMERS} />
                <SlashCommandsPlugin />
                <CommandsPlugin />
                <InitialContentPlugin content={content} editable={editable} contentKey={contentKey} />
                <HtmlOnChangePlugin onChange={onChange} />
                {floatingAnchorElem && editable && (
                    <TableActionMenuPlugin anchorElem={floatingAnchorElem} />
                )}
            </div>
        </LexicalComposer>
    );
}
