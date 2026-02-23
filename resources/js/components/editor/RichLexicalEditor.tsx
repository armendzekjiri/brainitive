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
    KEY_DOWN_COMMAND,
    TextNode,
    $isTextNode,
    $getNodeByKey,
    LexicalNode,
    RangeSelection,
    $setSelection,
    $createRangeSelection,
    ElementNode,
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
import { LinkNode, AutoLinkNode, $createLinkNode, TOGGLE_LINK_COMMAND, $isLinkNode } from '@lexical/link';
import { CodeNode, CodeHighlightNode, $createCodeNode } from '@lexical/code';
import {
    TableNode,
    TableCellNode,
    TableRowNode,
    $createTableNodeWithDimensions,
    INSERT_TABLE_COMMAND,
} from '@lexical/table';
import { HorizontalRuleNode, $createHorizontalRuleNode, INSERT_HORIZONTAL_RULE_COMMAND } from '@lexical/react/LexicalHorizontalRuleNode';
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
    Image,
} from 'lucide-react';

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
        listitemChecked: 'line-through text-muted-foreground',
        listitemUnchecked: '',
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
    tableCell: 'border border-border p-2 min-w-[75px]',
    tableCellHeader: 'border border-border p-2 bg-muted font-semibold',
    horizontalRule: 'border-t border-border my-6',
};

// Slash command menu items
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

    const slashMenuItems: SlashMenuItem[] = useMemo(() => [
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
        {
            title: 'Divider',
            description: 'Horizontal line separator',
            icon: <Minus className="size-4" />,
            keywords: ['hr', 'divider', 'line', 'separator', 'horizontal'],
            onSelect: (editor: any) => {
                editor.dispatchCommand(INSERT_HORIZONTAL_RULE_COMMAND, undefined);
            },
        },
        {
            title: 'Table',
            description: 'Insert a table',
            icon: <Table className="size-4" />,
            keywords: ['table', 'grid', 'rows', 'columns'],
            onSelect: (editor: any) => {
                editor.dispatchCommand(INSERT_TABLE_COMMAND, { columns: '3', rows: '3' });
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

                    // Check if we just typed a slash at the start of a line or after a space
                    if (text.charAt(offset - 1) === '/' &&
                        (offset === 1 || text.charAt(offset - 2) === ' ' || text.charAt(offset - 2) === '\n')) {
                        // Get caret position
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
                        // Update search term based on text after slash
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

    if (!showMenu) return null;

    return (
        <div
            ref={menuRef}
            className="fixed z-50 w-72 bg-popover border rounded-lg shadow-lg overflow-hidden"
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
            <div className="max-h-[300px] overflow-y-auto p-1">
                {filteredItems.length === 0 ? (
                    <div className="p-3 text-sm text-muted-foreground text-center">
                        No matching blocks
                    </div>
                ) : (
                    filteredItems.map((item, index) => (
                        <button
                            key={item.title}
                            className={cn(
                                "w-full flex items-start gap-3 p-2 rounded-md text-left transition-colors",
                                index === selectedIndex
                                    ? "bg-primary text-primary-foreground"
                                    : "hover:bg-muted"
                            )}
                            onClick={() => {
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
                            }}
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
    );
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

            // Check for link
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
function InitialContentPlugin({ content, editable }: { content: string | null; editable: boolean }) {
    const [editor] = useLexicalComposerContext();
    const [isFirstRender, setIsFirstRender] = useState(true);

    useEffect(() => {
        if (isFirstRender && content) {
            setIsFirstRender(false);
            editor.update(() => {
                const root = $getRoot();
                root.clear();

                // Parse HTML content
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
            });
        }
    }, [editor, content, isFirstRender]);

    useEffect(() => {
        editor.setEditable(editable);
    }, [editor, editable]);

    return null;
}

// Plugin to get HTML content on change
function HtmlOnChangePlugin({ onChange }: { onChange: (html: string) => void }) {
    const [editor] = useLexicalComposerContext();

    useEffect(() => {
        return editor.registerUpdateListener(({ editorState }) => {
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
}

export function RichLexicalEditor({
    content,
    onChange,
    editable = true,
    placeholder = 'Start typing, or press / for commands...',
    showToolbar = true,
    minHeight = '200px',
    className,
}: RichLexicalEditorProps) {
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
        ],
        editable,
    };

    return (
        <LexicalComposer initialConfig={initialConfig}>
            <div className={cn("border rounded-lg overflow-hidden bg-background", className)}>
                <ToolbarPlugin showToolbar={showToolbar && editable} />
                <div className="relative">
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
                <TablePlugin />
                <HorizontalRulePlugin />
                <TabIndentationPlugin />
                <MarkdownShortcutPlugin transformers={TRANSFORMERS} />
                <SlashCommandsPlugin />
                <InitialContentPlugin content={content} editable={editable} />
                <HtmlOnChangePlugin onChange={onChange} />
            </div>
        </LexicalComposer>
    );
}
