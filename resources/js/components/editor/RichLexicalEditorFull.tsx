import React, { useEffect, useCallback, useState, useRef, useMemo, createContext, useContext } from 'react';
import { createPortal } from 'react-dom';
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
    $getNodeByKey,
    $createParagraphNode,
    $createTextNode,
    $getSelection,
    $isRangeSelection,
    $isParagraphNode,
    FORMAT_TEXT_COMMAND,
    FORMAT_ELEMENT_COMMAND,
    UNDO_COMMAND,
    REDO_COMMAND,
    COMMAND_PRIORITY_LOW,
    COMMAND_PRIORITY_HIGH,
    KEY_DOWN_COMMAND,
    $isTextNode,
    createCommand,
    LexicalCommand,
    RangeSelection,
    $getNearestNodeFromDOMNode,
} from 'lexical';
import { $generateHtmlFromNodes, $generateNodesFromDOM } from '@lexical/html';
import {
    HeadingNode,
    QuoteNode,
    $createHeadingNode,
    $createQuoteNode,
    $isHeadingNode,
    $isQuoteNode,
} from '@lexical/rich-text';
import {
    ListNode,
    ListItemNode,
    $createListNode,
    $createListItemNode,
    INSERT_ORDERED_LIST_COMMAND,
    INSERT_UNORDERED_LIST_COMMAND,
    INSERT_CHECK_LIST_COMMAND,
    $isListNode,
} from '@lexical/list';
import { LinkNode, AutoLinkNode, TOGGLE_LINK_COMMAND, $isLinkNode } from '@lexical/link';
import { CodeNode, CodeHighlightNode, $createCodeNode, $isCodeNode, registerCodeHighlighting, getLanguageFriendlyName, normalizeCodeLang, getCodeLanguageOptions } from '@lexical/code';
import {
    TableNode,
    TableCellNode,
    TableRowNode,
    INSERT_TABLE_COMMAND,
} from '@lexical/table';
import { HorizontalRuleNode, INSERT_HORIZONTAL_RULE_COMMAND } from '@lexical/react/LexicalHorizontalRuleNode';
import { HorizontalRulePlugin } from '@lexical/react/LexicalHorizontalRulePlugin';
import { TRANSFORMERS } from '@lexical/markdown';
import { $setBlocksType, $patchStyleText, $getSelectionStyleValueForProperty } from '@lexical/selection';
import { $getNearestNodeOfType } from '@lexical/utils';
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
    X,
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
    AlignLeft,
    AlignCenter,
    AlignRight,
    AlignJustify,
    GripVertical,
    Plus,
    Copy,
    Check,
    Wand2,
    Sparkles,
    Loader2,
    RotateCcw,
    MessageSquare,
    Languages,
    TextSelect,
    FileText as FileTextIcon,
    ArrowRight,
    SpellCheck,
    AlignEndHorizontal,
    ChevronsDownUp,
    MoreHorizontal,
    Briefcase,
    SmilePlus,
    Lightbulb,
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
import TableCellResizerPlugin from './plugins/TableCellResizerPlugin';

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
    code: 'lexical-code-block',
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
        operator: 'text-gray-400',
        prolog: 'text-gray-500',
        property: 'text-blue-400',
        punctuation: 'text-gray-400',
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
    table: 'border-collapse border border-border my-4',
    tableCell: 'lexical-table-cell',
    tableCellHeader: 'lexical-table-cell-header',
    tableScrollableWrapper: 'lexical-table-scrollable-wrapper',
    tableCellSelected: 'lexical-table-cell-selected',
    tableCellPrimarySelected: 'lexical-table-cell-primary-selected',
    tableCellSortedIndicator: 'lexical-table-cell-sorted',
    horizontalRule: 'border-t border-border my-6',
    image: 'inline-block my-2',
};

// ─── Toolbar constants ─────────────────────────────────────────────────────────

const FONT_FAMILIES = [
    { label: 'Default', value: '' },
    { label: 'Arial', value: 'Arial' },
    { label: 'Courier New', value: 'Courier New' },
    { label: 'Georgia', value: 'Georgia' },
    { label: 'Times New Roman', value: 'Times New Roman' },
    { label: 'Trebuchet MS', value: 'Trebuchet MS' },
    { label: 'Verdana', value: 'Verdana' },
];

const COLOR_PALETTE = [
    '#000000', '#262626', '#595959', '#8c8c8c', '#bfbfbf', '#d9d9d9', '#f0f0f0', '#ffffff',
    '#ff4d4f', '#ff7a45', '#ffa940', '#ffd666', '#95de64', '#5cdbd3', '#69c0ff', '#b37feb',
    '#ff85c2', '#ff9c6e', '#ffc069', '#ffe58f', '#b7eb8f', '#87e8de', '#91d5ff', '#d3adf7',
    '#ffa39e', '#ffbb96', '#ffd591', '#ffe7ba', '#d9f7be', '#b5f5ec', '#bae7ff', '#efdbff',
];

const ALIGN_OPTIONS = [
    { label: 'Left Align', value: 'left', icon: AlignLeft },
    { label: 'Center Align', value: 'center', icon: AlignCenter },
    { label: 'Right Align', value: 'right', icon: AlignRight },
    { label: 'Justify', value: 'justify', icon: AlignJustify },
];

// ─── ColorPicker component ─────────────────────────────────────────────────────

function ColorPicker({ color, onChange, label }: { color: string; onChange: (c: string) => void; label: string }) {
    const [custom, setCustom] = useState(color || '#000000');
    return (
        <div className="p-3 w-52">
            <div className="text-xs font-medium mb-2 text-muted-foreground">{label}</div>
            <div className="grid grid-cols-8 gap-1 mb-2">
                {COLOR_PALETTE.map((c) => (
                    <button
                        key={c}
                        type="button"
                        title={c}
                        className={cn(
                            'w-5 h-5 rounded-sm border border-border/60 hover:scale-110 transition-transform',
                            color === c && 'ring-2 ring-primary ring-offset-1'
                        )}
                        style={{ backgroundColor: c }}
                        onClick={() => onChange(c)}
                    />
                ))}
            </div>
            <div className="flex items-center gap-2">
                <div
                    className="w-5 h-5 rounded-sm border border-border shrink-0"
                    style={{ backgroundColor: custom }}
                />
                <input
                    type="text"
                    value={custom}
                    onChange={(e) => setCustom(e.target.value)}
                    onBlur={() => onChange(custom)}
                    onKeyDown={(e) => e.key === 'Enter' && onChange(custom)}
                    className="flex-1 text-xs border rounded px-1.5 py-1 bg-background outline-none focus:ring-1 ring-primary"
                    placeholder="#000000"
                />
            </div>
        </div>
    );
}

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
    const selectedIndexRef = useRef(0);
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

    const filteredItemsRef = useRef(filteredItems);
    useEffect(() => {
        filteredItemsRef.current = filteredItems;
    }, [filteredItems]);

    useEffect(() => {
        setSelectedIndex(0);
        selectedIndexRef.current = 0;
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
                        setSelectedIndex(prev => {
                            const next = prev < filteredItemsRef.current.length - 1 ? prev + 1 : 0;
                            selectedIndexRef.current = next;
                            return next;
                        });
                        return true;
                    }
                    if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        setSelectedIndex(prev => {
                            const next = prev > 0 ? prev - 1 : filteredItemsRef.current.length - 1;
                            selectedIndexRef.current = next;
                            return next;
                        });
                        return true;
                    }
                    if (event.key === 'Enter' && filteredItemsRef.current[selectedIndexRef.current]) {
                        event.preventDefault();
                        // Remove the slash and any search text, flushed synchronously before onSelect
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
                                        node.select(newText.length, newText.length);
                                    }
                                }
                            }
                        }, { discrete: true });
                        filteredItemsRef.current[selectedIndexRef.current].onSelect(editor);
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
                        node.select(newText.length, newText.length);
                    }
                }
            }
        }, { discrete: true });
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
    const [tab, setTab] = useState<'upload' | 'url'>('upload');
    const [src, setSrc] = useState('');
    const [alt, setAlt] = useState('');
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState('');
    const [dragOver, setDragOver] = useState(false);
    const [preview, setPreview] = useState<string | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const reset = () => {
        setSrc(''); setAlt(''); setUploadError(''); setPreview(null); setDragOver(false);
    };

    const handleClose = () => { reset(); onClose(); };

    const uploadFile = async (file: File) => {
        if (!file.type.startsWith('image/')) { setUploadError('Please select an image file.'); return; }
        setUploading(true); setUploadError('');
        const formData = new FormData();
        formData.append('image', file);
        const csrf = document.head.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        try {
            const res = await fetch('/upload/image', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: formData,
            });
            const data = await res.json();
            if (data.success && data.file?.url) {
                setPreview(data.file.url);
                setSrc(data.file.url);
                if (!alt) setAlt(file.name.replace(/\.[^.]+$/, ''));
            } else {
                setUploadError('Upload failed. Please try again.');
            }
        } catch {
            setUploadError('Upload failed. Please try again.');
        } finally {
            setUploading(false);
        }
    };

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) uploadFile(file);
    };

    const handleDrop = (e: React.DragEvent) => {
        e.preventDefault(); setDragOver(false);
        const file = e.dataTransfer.files?.[0];
        if (file) uploadFile(file);
    };

    const handleSubmit = () => {
        if (src) { onInsert(src, alt || 'Image'); reset(); }
    };

    return (
        <Dialog open={open} onOpenChange={handleClose}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Insert Image</DialogTitle>
                </DialogHeader>

                {/* Tabs */}
                <div className="flex border-b mb-4">
                    {(['upload', 'url'] as const).map(t => (
                        <button key={t} type="button"
                            className={cn('px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
                                tab === t ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground')}
                            onClick={() => { setTab(t); reset(); }}>
                            {t === 'upload' ? 'Upload File' : 'From URL'}
                        </button>
                    ))}
                </div>

                {tab === 'upload' ? (
                    <div className="space-y-3">
                        {/* Drop zone */}
                        <div
                            className={cn('border-2 border-dashed rounded-lg p-6 text-center cursor-pointer transition-colors',
                                dragOver ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/50 hover:bg-muted/30')}
                            onClick={() => fileInputRef.current?.click()}
                            onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                            onDragLeave={() => setDragOver(false)}
                            onDrop={handleDrop}
                        >
                            {uploading ? (
                                <div className="flex flex-col items-center gap-2 text-muted-foreground">
                                    <div className="size-8 border-2 border-primary border-t-transparent rounded-full animate-spin" />
                                    <p className="text-sm">Uploading…</p>
                                </div>
                            ) : preview ? (
                                <div className="flex flex-col items-center gap-2">
                                    <img src={preview} alt="preview" className="max-h-40 max-w-full rounded object-contain" />
                                    <p className="text-xs text-muted-foreground">Click or drop to replace</p>
                                </div>
                            ) : (
                                <div className="flex flex-col items-center gap-2 text-muted-foreground">
                                    <ImageIcon className="size-8 opacity-40" />
                                    <p className="text-sm">Drop an image here, or <span className="text-primary font-medium">click to browse</span></p>
                                    <p className="text-xs">JPEG, PNG, GIF, WebP — up to 10 MB</p>
                                </div>
                            )}
                        </div>
                        <input ref={fileInputRef} type="file" accept="image/*" className="hidden" onChange={handleFileChange} />
                        {uploadError && <p className="text-sm text-destructive">{uploadError}</p>}
                        <div>
                            <Label className="text-xs">Alt Text</Label>
                            <Input value={alt} onChange={(e) => setAlt(e.target.value)} placeholder="Image description" className="mt-1" />
                        </div>
                    </div>
                ) : (
                    <div className="space-y-3">
                        <div>
                            <Label className="text-xs">Image URL</Label>
                            <Input value={src} onChange={(e) => setSrc(e.target.value)} placeholder="https://example.com/image.jpg" className="mt-1" />
                        </div>
                        <div>
                            <Label className="text-xs">Alt Text</Label>
                            <Input value={alt} onChange={(e) => setAlt(e.target.value)} placeholder="Image description" className="mt-1" />
                        </div>
                    </div>
                )}

                <DialogFooter>
                    <Button variant="outline" onClick={handleClose}>Cancel</Button>
                    <Button onClick={handleSubmit} disabled={!src || uploading}>Insert</Button>
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
                editor.update(() => {
                    const root = $getRoot();
                    const stickyNoteNode = $createStickyNoteNode(0, 0);
                    root.append(stickyNoteNode);
                });
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

// ─── Draggable Block Plugin ────────────────────────────────────────────────────

function DraggableBlockPlugin({ anchorElem }: { anchorElem: HTMLElement | null }) {
    const [editor] = useLexicalComposerContext();
    const [hoveredKey, setHoveredKey] = useState<string | null>(null);
    // Viewport-relative coords (for position:fixed via portal)
    const [handlePos, setHandlePos] = useState({ top: 0, left: 0 });
    const [showAddMenu, setShowAddMenu] = useState(false);
    const [dropLine, setDropLine] = useState<{ top: number; left: number; width: number } | null>(null);
    const draggedKeyRef = useRef<string | null>(null);
    const isDraggingRef = useRef(false);
    const isOverHandleRef = useRef(false);
    const [draggedKey, setDraggedKey] = useState<string | null>(null);

    const getTopBlockUnderY = useCallback((clientY: number): { key: string; el: HTMLElement } | null => {
        if (!anchorElem) return null;
        let found: { key: string; el: HTMLElement } | null = null;
        editor.getEditorState().read(() => {
            const root = $getRoot();
            for (const child of root.getChildren()) {
                const key = child.getKey();
                const el = editor.getElementByKey(key) as HTMLElement | null;
                if (el) {
                    const r = el.getBoundingClientRect();
                    if (clientY >= r.top - 2 && clientY <= r.bottom + 2) {
                        found = { key, el };
                        break;
                    }
                }
            }
        });
        return found;
    }, [editor, anchorElem]);

    // Hide handle on any scroll so stale fixed positions don't linger
    useEffect(() => {
        const onScroll = () => { if (!isDraggingRef.current) setHoveredKey(null); };
        window.addEventListener('scroll', onScroll, true);
        return () => window.removeEventListener('scroll', onScroll, true);
    }, []);

    useEffect(() => {
        if (!anchorElem) return;
        const onMouseMove = (e: MouseEvent) => {
            if (isDraggingRef.current) return;
            const block = getTopBlockUnderY(e.clientY);
            if (block) {
                setHoveredKey(block.key);
                const r = block.el.getBoundingClientRect();
                // Store raw viewport coords — used with position:fixed
                setHandlePos({ top: r.top, left: r.left });
            } else {
                setHoveredKey(null);
            }
        };
        const onMouseLeave = () => { if (!isDraggingRef.current && !isOverHandleRef.current) setHoveredKey(null); };
        anchorElem.addEventListener('mousemove', onMouseMove);
        anchorElem.addEventListener('mouseleave', onMouseLeave);
        return () => {
            anchorElem.removeEventListener('mousemove', onMouseMove);
            anchorElem.removeEventListener('mouseleave', onMouseLeave);
        };
    }, [anchorElem, getTopBlockUnderY]);

    useEffect(() => {
        const onDragOver = (e: DragEvent) => {
            if (!isDraggingRef.current || !anchorElem) return;
            e.preventDefault();
            e.dataTransfer!.dropEffect = 'move';
            const block = getTopBlockUnderY(e.clientY);
            if (block && block.key !== draggedKeyRef.current) {
                const r = block.el.getBoundingClientRect();
                const isAbove = e.clientY < r.top + r.height / 2;
                // Viewport coords for fixed drop line
                setDropLine({ top: isAbove ? r.top : r.bottom, left: r.left, width: r.width });
            } else {
                setDropLine(null);
            }
        };
        const onDrop = (e: DragEvent) => {
            if (!isDraggingRef.current || !draggedKeyRef.current) return;
            e.preventDefault();
            const block = getTopBlockUnderY(e.clientY);
            if (block && block.key !== draggedKeyRef.current) {
                const r = block.el.getBoundingClientRect();
                const isAbove = e.clientY < r.top + r.height / 2;
                editor.update(() => {
                    const dragged = $getNodeByKey(draggedKeyRef.current!);
                    const target = $getNodeByKey(block.key);
                    if (dragged && target) {
                        if (isAbove) target.insertBefore(dragged);
                        else target.insertAfter(dragged);
                    }
                });
            }
            isDraggingRef.current = false;
            draggedKeyRef.current = null;
            setDraggedKey(null);
            setDropLine(null);
        };
        const onDragEnd = () => {
            isDraggingRef.current = false;
            draggedKeyRef.current = null;
            setDraggedKey(null);
            setDropLine(null);
        };
        document.addEventListener('dragover', onDragOver);
        document.addEventListener('drop', onDrop);
        document.addEventListener('dragend', onDragEnd);
        return () => {
            document.removeEventListener('dragover', onDragOver);
            document.removeEventListener('drop', onDrop);
            document.removeEventListener('dragend', onDragEnd);
        };
    }, [editor, anchorElem, getTopBlockUnderY]);

    const handleDragStart = (e: React.DragEvent) => {
        if (!hoveredKey) return;
        isDraggingRef.current = true;
        draggedKeyRef.current = hoveredKey;
        setDraggedKey(hoveredKey);
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', hoveredKey);
    };

    const insertBlockAfter = (type: string) => {
        if (!hoveredKey) return;
        editor.update(() => {
            const node = $getNodeByKey(hoveredKey);
            if (!node) return;
            let newNode: any;
            if (type === 'h1') newNode = $createHeadingNode('h1');
            else if (type === 'h2') newNode = $createHeadingNode('h2');
            else if (type === 'h3') newNode = $createHeadingNode('h3');
            else if (type === 'quote') newNode = $createQuoteNode();
            else if (type === 'code') newNode = $createCodeNode();
            else newNode = $createParagraphNode();
            const text = $createTextNode('');
            newNode.append(text);
            node.insertAfter(newNode);
            text.select();
        });
        setShowAddMenu(false);
    };

    // Render via portal into document.body so overflow:hidden on the editor wrapper never clips the handles
    const handles = hoveredKey ? createPortal(
        <>
            <div
                className="fixed z-[200] flex items-center gap-0"
                style={{ top: handlePos.top + 2, left: handlePos.left - 60 }}
                onMouseEnter={() => { isOverHandleRef.current = true; }}
                onMouseLeave={() => { isOverHandleRef.current = false; setHoveredKey(null); }}
            >
                <Popover open={showAddMenu} onOpenChange={setShowAddMenu}>
                    <PopoverTrigger asChild>
                        <button
                            type="button"
                            className="h-6 w-6 flex items-center justify-center rounded hover:bg-accent text-muted-foreground/60 hover:text-foreground transition-colors"
                            title="Add block below"
                        >
                            <Plus className="size-3.5" />
                        </button>
                    </PopoverTrigger>
                    <PopoverContent className="w-48 p-1" align="start">
                        {[
                            { label: 'Paragraph', value: 'paragraph', icon: <Type className="size-3.5" /> },
                            { label: 'Heading 1', value: 'h1', icon: <Heading1 className="size-3.5" /> },
                            { label: 'Heading 2', value: 'h2', icon: <Heading2 className="size-3.5" /> },
                            { label: 'Heading 3', value: 'h3', icon: <Heading3 className="size-3.5" /> },
                            { label: 'Quote', value: 'quote', icon: <QuoteIcon className="size-3.5" /> },
                            { label: 'Code Block', value: 'code', icon: <FileCode className="size-3.5" /> },
                            { label: 'Bullet List', value: 'bullet', icon: <List className="size-3.5" /> },
                            { label: 'Numbered List', value: 'number', icon: <ListOrdered className="size-3.5" /> },
                        ].map(bt => (
                            <button
                                key={bt.value}
                                type="button"
                                className="w-full flex items-center gap-2 px-2 py-1.5 rounded text-sm hover:bg-muted text-left"
                                onClick={() => {
                                    if (bt.value === 'bullet') {
                                        insertBlockAfter('paragraph');
                                        editor.dispatchCommand(INSERT_UNORDERED_LIST_COMMAND, undefined);
                                    } else if (bt.value === 'number') {
                                        insertBlockAfter('paragraph');
                                        editor.dispatchCommand(INSERT_ORDERED_LIST_COMMAND, undefined);
                                    } else {
                                        insertBlockAfter(bt.value);
                                    }
                                }}
                            >
                                {bt.icon}
                                {bt.label}
                            </button>
                        ))}
                    </PopoverContent>
                </Popover>

                <button
                    type="button"
                    className="h-6 w-6 flex items-center justify-center rounded hover:bg-accent text-muted-foreground/60 hover:text-foreground transition-colors cursor-grab active:cursor-grabbing"
                    draggable
                    onDragStart={handleDragStart}
                    title="Drag to reorder"
                >
                    <GripVertical className="size-3.5" />
                </button>
            </div>
            {dropLine && (
                <div
                    className="fixed bg-primary h-0.5 z-[200] pointer-events-none rounded"
                    style={{ top: dropLine.top, left: dropLine.left, width: dropLine.width }}
                />
            )}
        </>,
        document.body
    ) : (dropLine ? createPortal(
        <div
            className="fixed bg-primary h-0.5 z-[200] pointer-events-none rounded"
            style={{ top: dropLine.top, left: dropLine.left, width: dropLine.width }}
        />,
        document.body
    ) : null);

    return <>{handles}</>;
}
// Context so toolbar (e.g. AI Replace) can notify parent of content changes for save
const RichLexicalOnChangeContext = createContext<((html: string) => void) | null>(null);

// Toolbar Plugin
/** Strip HTML to plain text and optionally truncate. */
function htmlToPlainText(html: string, maxLength = 500): string {
    if (!html?.trim()) return '';
    const div = document.createElement('div');
    div.innerHTML = html;
    const text = (div.textContent || div.innerText || '').replace(/\s+/g, ' ').trim();
    return maxLength && text.length > maxLength ? text.slice(0, maxLength) + '…' : text;
}

function ToolbarPlugin({ showToolbar = true, workspaceSlug, itemTitle, projectName }: { showToolbar?: boolean; workspaceSlug?: string; itemTitle?: string; projectName?: string }) {
    const [editor] = useLexicalComposerContext();
    const onChangeFromContext = useContext(RichLexicalOnChangeContext);
    const [isBold, setIsBold] = useState(false);
    const [isItalic, setIsItalic] = useState(false);
    const [isUnderline, setIsUnderline] = useState(false);
    const [isStrikethrough, setIsStrikethrough] = useState(false);
    const [isCode, setIsCode] = useState(false);
    const [isLink, setIsLink] = useState(false);
    const [blockType, setBlockType] = useState('paragraph');
    const [fontSize, setFontSize] = useState('15');
    const [fontFamily, setFontFamily] = useState('');
    const [fontColor, setFontColor] = useState('#000000');
    const [bgColor, setBgColor] = useState('transparent');
    const [elementFormat, setElementFormat] = useState('left');
    const [linkUrl, setLinkUrl] = useState('');
    const [showLinkInput, setShowLinkInput] = useState(false);
    const [showFontColorPicker, setShowFontColorPicker] = useState(false);
    const [showBgColorPicker, setShowBgColorPicker] = useState(false);
    const [showInsertMenu, setShowInsertMenu] = useState(false);
    const [showAlignMenu, setShowAlignMenu] = useState(false);
    const [showCaseMenu, setShowCaseMenu] = useState(false);
    const [showBlockMenu, setShowBlockMenu] = useState(false);
    const [showFontFamilyMenu, setShowFontFamilyMenu] = useState(false);
    // Toolbar's own modal states (mirror of slash command modals)
    const [showImageModal, setShowImageModal] = useState(false);
    const [showTableModal, setShowTableModal] = useState(false);
    const [showYouTubeModal, setShowYouTubeModal] = useState(false);
    // AI rewrite states
    const [showAiPopover, setShowAiPopover] = useState(false);
    const [showAiDialog, setShowAiDialog] = useState(false);
    const [aiInstruction, setAiInstruction] = useState('');
    const [aiGenerating, setAiGenerating] = useState(false);
    const [aiResult, setAiResult] = useState<string | null>(null);
    const [aiError, setAiError] = useState<string | null>(null);
    const [aiSelectedText, setAiSelectedText] = useState<string | null>(null);
    const [aiMode, setAiMode] = useState<'selection' | 'full'>('full');
    const [aiConfigured, setAiConfigured] = useState<boolean | null>(null);
    const [showMorePresets, setShowMorePresets] = useState(false);
    const aiInputRef = useRef<HTMLInputElement>(null);

    // Check if AI is configured for this workspace
    useEffect(() => {
        if (!workspaceSlug) return;
        window.axios.get(route('ai.settings', workspaceSlug))
            .then((res: any) => setAiConfigured(!!res.data.ai_configured))
            .catch(() => setAiConfigured(false));
    }, [workspaceSlug]);

    // AI generate helper. When contentOverride is set, use it as content (e.g. for "Generate prompt"). When generatePrompt is true, backend returns a generated prompt.
    const handleAiGenerate = async (instruction: string, contentOverride?: string, generatePrompt?: boolean) => {
        setAiGenerating(true);
        setAiError(null);
        setAiResult(null);
        try {
            let contentToSend: string;
            if (contentOverride !== undefined) {
                contentToSend = contentOverride;
            } else if (aiMode === 'selection' && aiSelectedText) {
                contentToSend = aiSelectedText;
            } else {
                contentToSend = '';
                editor.getEditorState().read(() => {
                    contentToSend = $generateHtmlFromNodes(editor);
                });
            }
            const res = await window.axios.post(route('ai.generate', workspaceSlug), {
                content: contentToSend,
                instruction: instruction || undefined,
                title: itemTitle || undefined,
                is_selection: generatePrompt ? false : aiMode === 'selection',
                generate_prompt: generatePrompt ?? false,
            });
            if (res.data.success) {
                setAiResult(res.data.content);
            } else {
                setAiError(res.data.message || 'AI generation failed.');
            }
        } catch (err: any) {
            setAiError(err.response?.data?.message || 'Failed to generate AI content.');
        } finally {
            setAiGenerating(false);
        }
    };

    const BLOCK_TYPE_OPTIONS = [
        { label: 'Normal', value: 'paragraph', icon: <Type className="size-4" /> },
        { label: 'Heading 1', value: 'h1', icon: <Heading1 className="size-4" /> },
        { label: 'Heading 2', value: 'h2', icon: <Heading2 className="size-4" /> },
        { label: 'Heading 3', value: 'h3', icon: <Heading3 className="size-4" /> },
        { label: 'Quote', value: 'quote', icon: <QuoteIcon className="size-4" /> },
        { label: 'Code Block', value: 'code', icon: <FileCode className="size-4" /> },
        { label: 'Bullet List', value: 'bullet', icon: <List className="size-4" /> },
        { label: 'Numbered List', value: 'number', icon: <ListOrdered className="size-4" /> },
        { label: 'Check List', value: 'check', icon: <ListChecks className="size-4" /> },
    ];

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

            const rawSize = $getSelectionStyleValueForProperty(selection as RangeSelection, 'font-size', '15px');
            setFontSize(rawSize ? rawSize.replace('px', '') : '15');
            setFontFamily($getSelectionStyleValueForProperty(selection as RangeSelection, 'font-family', '').replace(/['"]/g, ''));
            setFontColor($getSelectionStyleValueForProperty(selection as RangeSelection, 'color', '#000000') || '#000000');
            setBgColor($getSelectionStyleValueForProperty(selection as RangeSelection, 'background-color', 'transparent') || 'transparent');

            const anchorNode = selection.anchor.getNode();
            const element = anchorNode.getKey() === 'root' ? anchorNode : anchorNode.getTopLevelElementOrThrow();
            if ($isHeadingNode(element)) {
                setBlockType(element.getTag());
            } else if ($isListNode(element)) {
                const parentList = $getNearestNodeOfType<ListNode>(anchorNode, ListNode);
                const listType = (parentList ?? element).getListType();
                setBlockType(listType === 'bullet' ? 'bullet' : listType === 'number' ? 'number' : 'check');
            } else if ($isCodeNode(element)) {
                setBlockType('code');
            } else if ($isQuoteNode(element)) {
                setBlockType('quote');
            } else {
                setBlockType('paragraph');
            }
            if ('getFormatType' in element) {
                setElementFormat((element as any).getFormatType() || 'left');
            }
        }
    }, []);

    useEffect(() => {
        return editor.registerUpdateListener(({ editorState }) => {
            editorState.read(() => updateToolbar());
        });
    }, [editor, updateToolbar]);

    const applyBlockType = (type: string) => {
        editor.update(() => {
            const sel = $getSelection();
            if (!$isRangeSelection(sel)) return;
            switch (type) {
                case 'paragraph': $setBlocksType(sel, () => $createParagraphNode()); break;
                case 'h1': case 'h2': case 'h3':
                    $setBlocksType(sel, () => $createHeadingNode(type as 'h1' | 'h2' | 'h3')); break;
                case 'quote': $setBlocksType(sel, () => $createQuoteNode()); break;
                case 'code': $setBlocksType(sel, () => $createCodeNode()); break;
                case 'bullet': editor.dispatchCommand(INSERT_UNORDERED_LIST_COMMAND, undefined); break;
                case 'number': editor.dispatchCommand(INSERT_ORDERED_LIST_COMMAND, undefined); break;
                case 'check': editor.dispatchCommand(INSERT_CHECK_LIST_COMMAND, undefined); break;
            }
        }, { discrete: true });
        setShowBlockMenu(false);
    };

    const applyFontSize = (size: string) => {
        const num = parseInt(size, 10);
        if (isNaN(num) || num < 6 || num > 96) return;
        editor.update(() => {
            const sel = $getSelection();
            if ($isRangeSelection(sel)) $patchStyleText(sel as RangeSelection, { 'font-size': num + 'px' });
        });
        setFontSize(String(num));
    };

    const insertLink = useCallback(() => {
        if (!linkUrl) editor.dispatchCommand(TOGGLE_LINK_COMMAND, null);
        else editor.dispatchCommand(TOGGLE_LINK_COMMAND, linkUrl);
        setShowLinkInput(false);
        setLinkUrl('');
    }, [editor, linkUrl]);

    if (!showToolbar) return null;

    const currentBlock = BLOCK_TYPE_OPTIONS.find(b => b.value === blockType) ?? BLOCK_TYPE_OPTIONS[0];
    const currentFont = FONT_FAMILIES.find(f => f.value === fontFamily) ?? FONT_FAMILIES[0];
    const currentAlign = ALIGN_OPTIONS.find(a => a.value === elementFormat) ?? ALIGN_OPTIONS[0];
    const AlignIcon = currentAlign.icon;

    const Divider = () => <div className="w-px h-5 bg-border mx-0.5 shrink-0" />;

    return (
        <React.Fragment>
            <div className="flex items-center gap-0.5 px-1.5 py-1 border-b bg-muted/30 flex-wrap min-h-[40px]">
                {/* Undo / Redo */}
                <Button type="button" variant="ghost" size="sm" className="h-8 w-8 p-0"
                    onClick={() => editor.dispatchCommand(UNDO_COMMAND, undefined)} title="Undo (Ctrl+Z)">
                    <Undo2 className="h-4 w-4" />
                </Button>
                <Button type="button" variant="ghost" size="sm" className="h-8 w-8 p-0"
                    onClick={() => editor.dispatchCommand(REDO_COMMAND, undefined)} title="Redo (Ctrl+Y)">
                    <Redo2 className="h-4 w-4" />
                </Button>

                <Divider />

                {/* Block type */}
                <Popover open={showBlockMenu} onOpenChange={setShowBlockMenu}>
                    <PopoverTrigger asChild>
                        <Button type="button" variant="ghost" size="sm" className="h-8 px-2 gap-1 text-xs max-w-[120px]">
                            {currentBlock.icon}
                            <span className="truncate">{currentBlock.label}</span>
                            <ChevronDown className="size-3 opacity-60 shrink-0" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-44 p-1" align="start">
                        {BLOCK_TYPE_OPTIONS.map(bt => (
                            <button key={bt.value} type="button"
                                className={cn('w-full flex items-center gap-2 px-2 py-1.5 rounded text-sm hover:bg-muted text-left', blockType === bt.value && 'bg-muted font-medium')}
                                onClick={() => applyBlockType(bt.value)}
                            >
                                {bt.icon} {bt.label}
                            </button>
                        ))}
                    </PopoverContent>
                </Popover>

                <Divider />

                {/* Font family */}
                <Popover open={showFontFamilyMenu} onOpenChange={setShowFontFamilyMenu}>
                    <PopoverTrigger asChild>
                        <Button type="button" variant="ghost" size="sm" className="h-8 px-2 gap-1 text-xs min-w-[72px] max-w-[100px]">
                            <span className="truncate" style={{ fontFamily: fontFamily || undefined }}>{currentFont.label}</span>
                            <ChevronDown className="size-3 opacity-60 shrink-0" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-44 p-1" align="start">
                        {FONT_FAMILIES.map(ff => (
                            <button key={ff.value} type="button"
                                className={cn('w-full flex items-center px-2 py-1.5 rounded text-sm hover:bg-muted text-left', fontFamily === ff.value && 'bg-muted font-medium')}
                                style={{ fontFamily: ff.value || undefined }}
                                onClick={() => {
                                    editor.update(() => {
                                        const sel = $getSelection();
                                        if ($isRangeSelection(sel)) $patchStyleText(sel as RangeSelection, { 'font-family': ff.value });
                                    });
                                    setFontFamily(ff.value);
                                    setShowFontFamilyMenu(false);
                                }}
                            >
                                {ff.label}
                            </button>
                        ))}
                    </PopoverContent>
                </Popover>

                {/* Font size */}
                <div className="flex items-center border rounded-md overflow-hidden h-8 shrink-0">
                    <button type="button"
                        className="h-8 w-6 flex items-center justify-center hover:bg-muted text-sm select-none"
                        onClick={() => applyFontSize(String(Math.max(6, Number(fontSize) - 1)))}
                    >-</button>
                    <input
                        type="text"
                        value={fontSize}
                        onChange={(e) => setFontSize(e.target.value)}
                        onBlur={(e) => applyFontSize(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && applyFontSize(fontSize)}
                        className="w-8 text-center text-xs bg-transparent outline-none border-x"
                    />
                    <button type="button"
                        className="h-8 w-6 flex items-center justify-center hover:bg-muted text-sm select-none"
                        onClick={() => applyFontSize(String(Math.min(96, Number(fontSize) + 1)))}
                    >+</button>
                </div>

                <Divider />

                {/* Text formats */}
                <Button type="button" variant="ghost" size="sm" className={cn('h-8 w-8 p-0', isBold && 'bg-accent')}
                    onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'bold')} title="Bold (Ctrl+B)">
                    <Bold className="h-4 w-4" />
                </Button>
                <Button type="button" variant="ghost" size="sm" className={cn('h-8 w-8 p-0', isItalic && 'bg-accent')}
                    onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'italic')} title="Italic (Ctrl+I)">
                    <Italic className="h-4 w-4" />
                </Button>
                <Button type="button" variant="ghost" size="sm" className={cn('h-8 w-8 p-0', isUnderline && 'bg-accent')}
                    onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'underline')} title="Underline (Ctrl+U)">
                    <Underline className="h-4 w-4" />
                </Button>
                <Button type="button" variant="ghost" size="sm" className={cn('h-8 w-8 p-0', isCode && 'bg-accent')}
                    onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'code')} title="Inline Code">
                    <Code className="h-4 w-4" />
                </Button>

                {/* Link */}
                <Popover open={showLinkInput} onOpenChange={setShowLinkInput}>
                    <PopoverTrigger asChild>
                        <Button type="button" variant="ghost" size="sm" className={cn('h-8 w-8 p-0', isLink && 'bg-accent')} title="Insert Link">
                            <Link className="h-4 w-4" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-72 p-3" align="start">
                        <div className="space-y-2">
                            <label className="text-xs font-medium">URL</label>
                            <Input value={linkUrl} onChange={(e) => setLinkUrl(e.target.value)}
                                placeholder="https://example.com" autoFocus
                                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); insertLink(); } }}
                            />
                            <div className="flex justify-end gap-2">
                                {isLink && (
                                    <Button size="sm" variant="outline" onClick={() => { editor.dispatchCommand(TOGGLE_LINK_COMMAND, null); setShowLinkInput(false); }}>Remove</Button>
                                )}
                                <Button size="sm" onClick={insertLink}>{isLink ? 'Update' : 'Insert'}</Button>
                            </div>
                        </div>
                    </PopoverContent>
                </Popover>

                <Divider />

                {/* Font color */}
                <Popover open={showFontColorPicker} onOpenChange={setShowFontColorPicker}>
                    <PopoverTrigger asChild>
                        <Button type="button" variant="ghost" size="sm" className="h-8 px-1.5 gap-0.5" title="Font Color">
                            <div className="flex flex-col items-center gap-px">
                                <span className="text-xs font-bold leading-none">A</span>
                                <div className="h-[3px] w-4 rounded-sm" style={{ backgroundColor: ['transparent', '', 'initial'].includes(fontColor) ? '#000000' : fontColor }} />
                            </div>
                            <ChevronDown className="size-3 opacity-60" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="p-0 w-auto" align="start">
                        <ColorPicker color={fontColor} label="Text Color" onChange={(c) => {
                            editor.update(() => {
                                const sel = $getSelection();
                                if ($isRangeSelection(sel)) $patchStyleText(sel as RangeSelection, { color: c });
                            });
                            setFontColor(c);
                            setShowFontColorPicker(false);
                        }} />
                    </PopoverContent>
                </Popover>

                {/* Background color */}
                <Popover open={showBgColorPicker} onOpenChange={setShowBgColorPicker}>
                    <PopoverTrigger asChild>
                        <Button type="button" variant="ghost" size="sm" className="h-8 px-1.5 gap-0.5" title="Background Color">
                            <div className="flex flex-col items-center gap-px">
                                <Palette className="size-3.5" />
                                <div className="h-[3px] w-4 rounded-sm border border-border/40" style={{ backgroundColor: ['transparent', '', 'initial'].includes(bgColor) ? 'transparent' : bgColor }} />
                            </div>
                            <ChevronDown className="size-3 opacity-60" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="p-0 w-auto" align="start">
                        <ColorPicker color={bgColor} label="Background Color" onChange={(c) => {
                            editor.update(() => {
                                const sel = $getSelection();
                                if ($isRangeSelection(sel)) $patchStyleText(sel as RangeSelection, { 'background-color': c });
                            });
                            setBgColor(c);
                            setShowBgColorPicker(false);
                        }} />
                    </PopoverContent>
                </Popover>

                <Divider />

                {/* Text case */}
                <Popover open={showCaseMenu} onOpenChange={setShowCaseMenu}>
                    <PopoverTrigger asChild>
                        <Button type="button" variant="ghost" size="sm" className="h-8 px-1.5 gap-0.5 text-xs" title="Text Case">
                            <span className="font-medium text-sm">Aa</span>
                            <ChevronDown className="size-3 opacity-60" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-40 p-1" align="start">
                        {[
                            { label: 'lowercase', value: 'lowercase' },
                            { label: 'UPPERCASE', value: 'uppercase' },
                            { label: 'Capitalize', value: 'capitalize' },
                            { label: 'Normal', value: 'none' },
                        ].map(opt => (
                            <button key={opt.value} type="button"
                                className="w-full flex items-center px-2 py-1.5 rounded text-sm hover:bg-muted text-left"
                                style={{ textTransform: opt.value === 'none' ? undefined : (opt.value as any) }}
                                onClick={() => {
                                    editor.update(() => {
                                        const sel = $getSelection();
                                        if ($isRangeSelection(sel)) $patchStyleText(sel as RangeSelection, { 'text-transform': opt.value === 'none' ? '' : opt.value });
                                    });
                                    setShowCaseMenu(false);
                                }}
                            >
                                {opt.label}
                            </button>
                        ))}
                    </PopoverContent>
                </Popover>

                <Divider />

                {/* Insert */}
                <Popover open={showInsertMenu} onOpenChange={setShowInsertMenu}>
                    <PopoverTrigger asChild>
                        <Button type="button" variant="ghost" size="sm" className="h-8 px-2 gap-1 text-xs">
                            <Plus className="size-3.5" />
                            Insert
                            <ChevronDown className="size-3 opacity-60" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-52 p-1 max-h-80 overflow-y-auto" align="start">
                        {[
                            { label: 'Image', icon: <ImageIcon className="size-4" />, action: () => setShowImageModal(true) },
                            { label: 'YouTube Video', icon: <Youtube className="size-4" />, action: () => setShowYouTubeModal(true) },
                            { label: 'Table', icon: <Table className="size-4" />, action: () => setShowTableModal(true) },
                            { label: 'Horizontal Rule', icon: <Minus className="size-4" />, action: () => editor.dispatchCommand(INSERT_HORIZONTAL_RULE_COMMAND, undefined) },
                            { label: 'Code Block', icon: <FileCode className="size-4" />, action: () => editor.update(() => { const s = $getSelection(); if ($isRangeSelection(s)) $setBlocksType(s, () => $createCodeNode()); }) },
                            { label: 'Bullet List', icon: <List className="size-4" />, action: () => editor.dispatchCommand(INSERT_UNORDERED_LIST_COMMAND, undefined) },
                            { label: 'Numbered List', icon: <ListOrdered className="size-4" />, action: () => editor.dispatchCommand(INSERT_ORDERED_LIST_COMMAND, undefined) },
                            { label: 'Check List', icon: <ListChecks className="size-4" />, action: () => editor.dispatchCommand(INSERT_CHECK_LIST_COMMAND, undefined) },
                            { label: 'Columns Layout', icon: <Columns className="size-4" />, action: () => editor.dispatchCommand(INSERT_LAYOUT_COMMAND, '1fr 1fr') },
                            { label: 'Collapsible', icon: <ChevronDown className="size-4" />, action: () => editor.dispatchCommand(INSERT_COLLAPSIBLE_COMMAND, undefined) },
                            { label: 'Sticky Note', icon: <StickyNote className="size-4" />, action: () => editor.dispatchCommand(INSERT_STICKY_NOTE_COMMAND, undefined) },
                            { label: 'Poll', icon: <BarChart3 className="size-4" />, action: () => editor.dispatchCommand(INSERT_POLL_COMMAND, undefined) },
                            { label: 'Page Break', icon: <Scissors className="size-4" />, action: () => editor.dispatchCommand(INSERT_PAGE_BREAK_COMMAND, undefined) },
                        ].map(item => (
                            <button key={item.label} type="button"
                                className="w-full flex items-center gap-2 px-2 py-1.5 rounded text-sm hover:bg-muted text-left"
                                onClick={() => { item.action(); setShowInsertMenu(false); }}
                            >
                                {item.icon} {item.label}
                            </button>
                        ))}
                    </PopoverContent>
                </Popover>

                <Divider />

                {/* Alignment */}
                <Popover open={showAlignMenu} onOpenChange={setShowAlignMenu}>
                    <PopoverTrigger asChild>
                        <Button type="button" variant="ghost" size="sm" className="h-8 px-2 gap-1 text-xs">
                            <AlignIcon className="size-4" />
                            <span className="text-xs hidden sm:inline">{currentAlign.label}</span>
                            <ChevronDown className="size-3 opacity-60" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-44 p-1" align="start">
                        {ALIGN_OPTIONS.map(opt => {
                            const Icon = opt.icon;
                            return (
                                <button key={opt.value} type="button"
                                    className={cn('w-full flex items-center gap-2 px-2 py-1.5 rounded text-sm hover:bg-muted text-left', elementFormat === opt.value && 'bg-muted font-medium')}
                                    onClick={() => { editor.dispatchCommand(FORMAT_ELEMENT_COMMAND, opt.value as any); setShowAlignMenu(false); }}
                                >
                                    <Icon className="size-4" /> {opt.label}
                                </button>
                            );
                        })}
                    </PopoverContent>
                </Popover>

                {/* AI button — opens modal for AI assistant */}
                {workspaceSlug && aiConfigured && (
                    <>
                        <Divider />
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-8 px-2 gap-1.5 text-xs text-purple-600 dark:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-950/30"
                            title="AI Assistant"
                            onClick={() => {
                                setShowAiPopover(true);
                                setAiResult(null);
                                setAiError(null);
                                setAiInstruction('');
                                setShowMorePresets(false);
                                let selectedText = '';
                                editor.getEditorState().read(() => {
                                    const selection = $getSelection();
                                    if ($isRangeSelection(selection) && !selection.isCollapsed()) {
                                        selectedText = selection.getTextContent();
                                    }
                                });
                                if (selectedText.trim()) {
                                    setAiSelectedText(selectedText);
                                    setAiMode('selection');
                                } else {
                                    setAiSelectedText(null);
                                    setAiMode('full');
                                }
                                setTimeout(() => aiInputRef.current?.focus(), 100);
                            }}
                        >
                            <Sparkles className="size-4" />
                            <span className="hidden sm:inline">AI</span>
                        </Button>
                        <Dialog open={showAiPopover} onOpenChange={(open) => {
                            if (!aiGenerating) {
                                setShowAiPopover(open);
                            }
                        }}>
                            <DialogContent
                                                    overlayClassName="bg-black/10 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0"
                                                    className="left-1/2 -translate-x-1/2 right-auto top-auto bottom-0 w-full max-w-6xl h-[55vh] max-h-[55vh] rounded-t-xl overflow-hidden p-0 shadow-2xl focus:outline-none focus-visible:outline-none focus-visible:ring-0 data-[state=open]:slide-in-from-bottom data-[state=closed]:slide-out-to-bottom flex flex-col"
                                                    style={{ minWidth: 420, minHeight: 200 }}
                                                >
                                                    <div className="flex flex-col min-h-0 flex-1">
                                                    <div className="flex items-center gap-2 border-b px-4 py-3 shrink-0">
                                                        <Sparkles className="size-4 shrink-0 text-purple-500" />
                                                        <input
                                                            ref={aiInputRef}
                                                            type="text"
                                                            value={aiInstruction}
                                                            onChange={(e) => setAiInstruction(e.target.value)}
                                                            placeholder="Tell AI what to do..."
                                                            className="flex-1 bg-transparent text-sm outline-none ring-0 border-0 shadow-none placeholder:text-muted-foreground/50 focus:outline-none focus:ring-0"
                                                            onKeyDown={(e) => {
                                                                if (e.key === 'Enter' && aiInstruction.trim() && !aiGenerating) {
                                                                    e.preventDefault();
                                                                    handleAiGenerate(aiInstruction);
                                                                }
                                                            }}
                                                            disabled={aiGenerating}
                                                        />
                                                        {!aiGenerating && aiInstruction.trim() && (
                                                            <button
                                                                type="button"
                                                                className="text-purple-600 hover:text-purple-700 p-0.5"
                                                                onClick={() => handleAiGenerate(aiInstruction)}
                                                            >
                                                                <ArrowRight className="size-4" />
                                                            </button>
                                                        )}
                                                    </div>
                                                    {aiMode === 'selection' && aiSelectedText && (
                                                        <div className="px-4 py-1.5 bg-purple-50 dark:bg-purple-950/20 border-b text-xs text-purple-600 dark:text-purple-400 flex items-center gap-1.5">
                                                            <TextSelect className="size-3 shrink-0" />
                                                            <span className="truncate">Selected: &ldquo;{aiSelectedText.length > 50 ? aiSelectedText.slice(0, 50) + '…' : aiSelectedText}&rdquo;</span>
                                                        </div>
                                                    )}
                                                    {aiError && (
                                                        <div className="px-4 py-2 bg-destructive/10 text-destructive text-xs flex items-center gap-1.5 border-b">
                                                            <X className="size-3 shrink-0" />
                                                            {aiError}
                                                        </div>
                                                    )}
                                                    {!aiGenerating && !aiResult && (
                                                    <div className="py-1 flex-1 min-h-0 overflow-y-auto">
                                                        <div className="px-4 py-1 text-[11px] font-medium text-muted-foreground uppercase tracking-wider">Actions</div>
                                                        <button
                                                            type="button"
                                                            className="w-full flex items-center gap-3 px-4 py-2 text-sm hover:bg-muted text-left transition-colors border-b border-border/50"
                                                            onClick={() => {
                                                                let contentHtml = '';
                                                                editor.getEditorState().read(() => {
                                                                    contentHtml = $generateHtmlFromNodes(editor);
                                                                });
                                                                const descriptionPlain = htmlToPlainText(contentHtml, 500);
                                                                const lines: string[] = [];
                                                                if (itemTitle?.trim()) lines.push(`Title: ${itemTitle.trim()}`);
                                                                if (projectName?.trim()) lines.push(`Project: ${projectName.trim()}`);
                                                                if (descriptionPlain) lines.push(`Description: ${descriptionPlain}`);
                                                                const contextText = lines.join('\n\n') || 'No context provided.';
                                                                handleAiGenerate('', contextText, true);
                                                            }}
                                                        >
                                                            <Lightbulb className="size-4 text-amber-500 shrink-0" />
                                                            Generate prompt
                                                        </button>
                                                        {[
                                                            { label: 'Improve description', instruction: 'Rewrite to be clearer, more professional, and better structured. Improve the overall quality.', icon: Wand2 },
                                                            { label: 'Summarize writing', instruction: 'Summarize this text into a concise version, keeping only the most important points.', icon: AlignEndHorizontal },
                                                            { label: 'Improve writing', instruction: 'Improve the writing quality — better word choices, sentence flow, and readability.', icon: Pencil },
                                                            { label: 'Fix spelling & grammar', instruction: 'Fix all spelling, grammar, and punctuation errors. Keep the same meaning and style.', icon: SpellCheck },
                                                        ].map((preset) => {
                                                            const Icon = preset.icon;
                                                            return (
                                                                <button
                                                                    key={preset.label}
                                                                    type="button"
                                                                    className="w-full flex items-center gap-3 px-4 py-2 text-sm hover:bg-muted text-left transition-colors"
                                                                    onClick={() => handleAiGenerate(preset.instruction)}
                                                                >
                                                                    <Icon className="size-4 text-muted-foreground shrink-0" />
                                                                    {preset.label}
                                                                </button>
                                                            );
                                                        })}
                                                        {!showMorePresets ? (
                                                            <button
                                                                type="button"
                                                                className="w-full flex items-center gap-3 px-4 py-2 text-sm hover:bg-muted text-left transition-colors text-muted-foreground"
                                                                onClick={() => setShowMorePresets(true)}
                                                            >
                                                                <MoreHorizontal className="size-4 shrink-0" />
                                                                Show more
                                                            </button>
                                                        ) : (
                                                            <>
                                                                {[
                                                                    { label: 'Make concise', instruction: 'Make this text more concise while preserving all key information.', icon: ChevronsDownUp },
                                                                    { label: 'Make formal', instruction: 'Rewrite this in a formal, professional tone suitable for business communication.', icon: Briefcase },
                                                                    { label: 'Simplify language', instruction: 'Simplify the language to make it easy to understand for anyone.', icon: SmilePlus },
                                                                    { label: 'Translate to English', instruction: 'Translate this text to English.', icon: Languages },
                                                                    { label: 'Translate to German', instruction: 'Translate this text to German.', icon: Languages },
                                                                ].map((preset) => {
                                                                    const Icon = preset.icon;
                                                                    return (
                                                                        <button
                                                                            key={preset.label}
                                                                            type="button"
                                                                            className="w-full flex items-center gap-3 px-4 py-2 text-sm hover:bg-muted text-left transition-colors"
                                                                            onClick={() => handleAiGenerate(preset.instruction)}
                                                                        >
                                                                            <Icon className="size-4 text-muted-foreground shrink-0" />
                                                                            {preset.label}
                                                                        </button>
                                                                    );
                                                                })}
                                                            </>
                                                        )}
                                                    </div>
                                                    )}
                                                    {aiResult ? (
                                                        <div className="flex flex-col flex-1 min-h-0 border-t">
                                                            <div className="px-4 pt-3 pb-2 shrink-0">
                                                                <div className="flex items-center gap-2 text-sm font-medium text-purple-700 dark:text-purple-300">
                                                                    <Sparkles className="size-4" />
                                                                    AI Suggestion
                                                                </div>
                                                            </div>
                                                            <div
                                                                className="px-4 py-3 prose prose-sm max-w-none text-sm flex-1 min-h-0 overflow-y-auto"
                                                                dangerouslySetInnerHTML={{ __html: aiResult }}
                                                            />
                                                            <div className="flex gap-1.5 px-4 py-3 border-t bg-muted/30 shrink-0">
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    className="h-7 text-xs gap-1 bg-green-600 hover:bg-green-700 text-white flex-1"
                                                                    onClick={() => {
                                                                        const newHtml = aiResult!;
                                                                        editor.update(() => {
                                                                            const root = $getRoot();
                                                                            root.clear();
                                                                            const parser = new DOMParser();
                                                                            const dom = parser.parseFromString(newHtml, 'text/html');
                                                                            const nodes = $generateNodesFromDOM(editor, dom);
                                                                            if (nodes.length === 0) {
                                                                                const paragraph = $createParagraphNode();
                                                                                paragraph.append($createTextNode(newHtml));
                                                                                root.append(paragraph);
                                                                            } else {
                                                                                nodes.forEach(node => root.append(node));
                                                                            }
                                                                        }, { tag: 'ai-replace' });
                                                                        // Ensure parent state is updated so Save persists (Lexical listener may not fire in time)
                                                                        onChangeFromContext?.(newHtml);
                                                                        setTimeout(() => setShowAiPopover(false), 100);
                                                                    }}
                                                                >
                                                                    <Check className="size-3" />
                                                                    Replace
                                                                </Button>
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="outline"
                                                                    className="h-7 text-xs gap-1"
                                                                    onClick={() => { setAiResult(null); setAiError(null); }}
                                                                >
                                                                    <RotateCcw className="size-3" />
                                                                    Retry
                                                                </Button>
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    className="h-7 text-xs"
                                                                    onClick={() => setShowAiPopover(false)}
                                                                >
                                                                    Discard
                                                                </Button>
                                                            </div>
                                                        </div>
                                                    ) : aiGenerating ? (
                                                        <div className="flex flex-1 min-h-0 items-center justify-center gap-3 px-4 py-6">
                                                            <Loader2 className="size-5 animate-spin text-purple-600" />
                                                            <span className="text-sm text-muted-foreground">Generating with AI...</span>
                                                        </div>
                                                    ) : null}
                                                    </div>
                                                </DialogContent>
                                            </Dialog>
                                        </>
                                    )}
                </div>
                                </React.Fragment>
                            );
}

function CodeActionMenuPlugin({ anchorElem }: { anchorElem: HTMLElement | null }) {
    const [editor] = useLexicalComposerContext();
    const codeSetRef = useRef<Set<string>>(new Set());
    const [shouldListen, setShouldListen] = useState(false);
    const codeDOMNodeRef = useRef<HTMLElement | null>(null);
    const [isShown, setIsShown] = useState(false);
    const [position, setPosition] = useState({ top: '0', right: '0' });
    const [lang, setLang] = useState('');
    const [isCopied, setIsCopied] = useState(false);
    const copyTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const [isFormatted, setIsFormatted] = useState(false);
    const fmtTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        return editor.registerMutationListener(CodeNode, (mutations) => {
            for (const [key, type] of mutations) {
                if (type === 'created') codeSetRef.current.add(key);
                else if (type === 'destroyed') codeSetRef.current.delete(key);
            }
            setShouldListen(codeSetRef.current.size > 0);
        }, { skipInitialization: false });
    }, [editor]);

    useEffect(() => {
        if (!shouldListen || !anchorElem) return;

        let moveTimer: ReturnType<typeof setTimeout> | null = null;

        const onMouseMove = (e: MouseEvent) => {
            if (moveTimer) clearTimeout(moveTimer);
            moveTimer = setTimeout(() => {
                const target = e.target as HTMLElement;
                const codeDOMNode = target.closest<HTMLElement>('code.lexical-code-block');
                const isOutside = !(codeDOMNode || target.closest('[data-code-menu]'));

                if (isOutside) { setIsShown(false); return; }
                if (!codeDOMNode) return;

                codeDOMNodeRef.current = codeDOMNode;

                let _lang = '';
                editor.getEditorState().read(() => {
                    const node = $getNearestNodeFromDOMNode(codeDOMNode);
                    if ($isCodeNode(node)) _lang = node.getLanguage() ?? '';
                });

                const ar = anchorElem.getBoundingClientRect();
                const cr = codeDOMNode.getBoundingClientRect();
                setLang(_lang);
                setIsShown(true);
                setPosition({
                    top: `${cr.top - ar.top + anchorElem.scrollTop + 6}px`,
                    right: `${ar.right - cr.right + 10}px`,
                });
            }, 50);
        };

        document.addEventListener('mousemove', onMouseMove);
        return () => {
            setIsShown(false);
            if (moveTimer) clearTimeout(moveTimer);
            document.removeEventListener('mousemove', onMouseMove);
        };
    }, [shouldListen, anchorElem, editor]);

    const handleCopy = async () => {
        const codeDOMNode = codeDOMNodeRef.current;
        if (!codeDOMNode) return;
        let content = '';
        editor.getEditorState().read(() => {
            const node = $getNearestNodeFromDOMNode(codeDOMNode);
            if ($isCodeNode(node)) content = node.getTextContent();
        });
        try {
            await navigator.clipboard.writeText(content);
            setIsCopied(true);
            if (copyTimerRef.current) clearTimeout(copyTimerRef.current);
            copyTimerRef.current = setTimeout(() => setIsCopied(false), 2000);
        } catch {}
    };

    const handleLangChange = (newLang: string) => {
        const codeDOMNode = codeDOMNodeRef.current;
        if (!codeDOMNode) return;
        editor.update(() => {
            const node = $getNearestNodeFromDOMNode(codeDOMNode);
            if ($isCodeNode(node)) node.setLanguage(newLang);
        });
        setLang(newLang);
    };

    const handleFormat = () => {
        const codeDOMNode = codeDOMNodeRef.current;
        if (!codeDOMNode) return;
        editor.update(() => {
            const node = $getNearestNodeFromDOMNode(codeDOMNode);
            if ($isCodeNode(node)) {
                const currentLang = node.getLanguage() ?? '';
                node.setLanguage('');
                node.setLanguage(currentLang);
            }
        });
        setIsFormatted(true);
        if (fmtTimerRef.current) clearTimeout(fmtTimerRef.current);
        fmtTimerRef.current = setTimeout(() => setIsFormatted(false), 2000);
    };

    const friendlyName = lang ? getLanguageFriendlyName(lang) : 'Plain text';
    const langOptions = getCodeLanguageOptions();

    if (!isShown) return null;

    const btnCls = 'flex items-center justify-center w-7 h-7 rounded border border-transparent hover:border-border/60 text-muted-foreground hover:text-foreground bg-transparent hover:bg-muted/60 transition-colors cursor-pointer';

    return (
        <div
            data-code-menu
            className="absolute z-40 flex items-center gap-1 select-none"
            style={{ top: position.top, right: position.right }}
        >
            <select
                className="text-[11px] bg-transparent text-muted-foreground border border-transparent hover:border-border/60 hover:bg-muted/60 rounded px-1.5 py-1 cursor-pointer outline-none transition-colors"
                value={lang}
                onChange={(e) => handleLangChange(e.target.value)}
                title="Change language"
            >
                <option value="">{friendlyName}</option>
                {langOptions.map(([val, label]) => (
                    <option key={val} value={val}>{label}</option>
                ))}
            </select>
            <button type="button" className={btnCls} onClick={handleFormat} title="Format code">
                {isFormatted ? <Check className="size-3.5" /> : <Wand2 className="size-3.5" />}
            </button>
            <button type="button" className={btnCls} onClick={handleCopy} title="Copy code">
                {isCopied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
            </button>
        </div>
    );
}

// Plugin to register code syntax highlighting
function CodeHighlightPlugin() {
    const [editor] = useLexicalComposerContext();
    useEffect(() => {
        return registerCodeHighlighting(editor);
    }, [editor]);
    return null;
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

            // Trigger onChange on actual content changes, or when content was replaced by AI (tagged update)
            const isAiReplace = typeof tags?.has === 'function' && tags.has('ai-replace');
            if (!isAiReplace && dirtyElements.size === 0 && dirtyLeaves.size === 0) {
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
    maxHeight?: string;
    className?: string;
    contentKey?: string;
    workspaceSlug?: string;
    itemTitle?: string;
    projectName?: string;
}

export function RichLexicalEditor({
    content,
    onChange,
    editable = true,
    placeholder = 'Start typing, or press / for commands...',
    showToolbar = true,
    minHeight = '200px',
    maxHeight,
    className,
    contentKey,
    workspaceSlug,
    itemTitle,
    projectName,
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
            <RichLexicalOnChangeContext.Provider value={onChange}>
            <div className={cn("border rounded-lg overflow-hidden bg-white dark:bg-card", className)}>
                <ToolbarPlugin showToolbar={showToolbar && editable} workspaceSlug={workspaceSlug} itemTitle={itemTitle} projectName={projectName} />
                <div
                    className={cn("relative", maxHeight && "overflow-y-auto")}
                    style={maxHeight ? { maxHeight } : undefined}
                    ref={onRef}
                >
                    <RichTextPlugin
                        contentEditable={
                            <ContentEditable
                                className={cn(
                                    "outline-none p-4 text-sm prose prose-sm max-w-none",
                                    editable && "pl-16",
                                    !editable && "cursor-default"
                                )}
                                style={{ minHeight }}
                                aria-placeholder={placeholder}
                                placeholder={
                                    <div className="absolute top-4 left-16 text-muted-foreground pointer-events-none text-sm">
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
                <TablePlugin hasCellMerge hasCellBackgroundColor hasHorizontalScroll />
                <HorizontalRulePlugin />
                <TabIndentationPlugin />
                <MarkdownShortcutPlugin transformers={TRANSFORMERS} />
                <SlashCommandsPlugin />
                <CommandsPlugin />
                <CodeHighlightPlugin />
                <InitialContentPlugin content={content} editable={editable} contentKey={contentKey} />
                <HtmlOnChangePlugin onChange={onChange} />
                {floatingAnchorElem && (
                    <CodeActionMenuPlugin anchorElem={floatingAnchorElem} />
                )}
                {floatingAnchorElem && editable && (
                    <>
                        <TableActionMenuPlugin anchorElem={floatingAnchorElem} />
                        <TableCellResizerPlugin />
                        <DraggableBlockPlugin anchorElem={floatingAnchorElem} />
                    </>
                )}
            </div>
            </RichLexicalOnChangeContext.Provider>
        </LexicalComposer>
    );
}
