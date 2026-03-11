import { useEffect, useCallback, useState } from 'react';
import { LexicalComposer } from '@lexical/react/LexicalComposer';
import { RichTextPlugin } from '@lexical/react/LexicalRichTextPlugin';
import { ContentEditable } from '@lexical/react/LexicalContentEditable';
import { HistoryPlugin } from '@lexical/react/LexicalHistoryPlugin';
import { LexicalErrorBoundary } from '@lexical/react/LexicalErrorBoundary';
import { OnChangePlugin } from '@lexical/react/LexicalOnChangePlugin';
import { ListPlugin } from '@lexical/react/LexicalListPlugin';
import { LinkPlugin } from '@lexical/react/LexicalLinkPlugin';
import { MarkdownShortcutPlugin } from '@lexical/react/LexicalMarkdownShortcutPlugin';
import { useLexicalComposerContext } from '@lexical/react/LexicalComposerContext';
import {
    $getRoot,
    $createParagraphNode,
    $createTextNode,
    EditorState,
    LexicalEditor as LexicalEditorType,
    $getSelection,
    $isRangeSelection,
    FORMAT_TEXT_COMMAND,
    UNDO_COMMAND,
    REDO_COMMAND,
} from 'lexical';
import { $generateHtmlFromNodes, $generateNodesFromDOM } from '@lexical/html';
import { HeadingNode, QuoteNode, $createHeadingNode, $createQuoteNode } from '@lexical/rich-text';
import { ListNode, ListItemNode, $createListNode, $createListItemNode } from '@lexical/list';
import { LinkNode, AutoLinkNode, $createLinkNode } from '@lexical/link';
import { CodeNode, CodeHighlightNode } from '@lexical/code';
import { TRANSFORMERS } from '@lexical/markdown';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
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
} from 'lucide-react';

// Theme for styling editor content
const theme = {
    paragraph: 'mb-1',
    heading: {
        h1: 'text-2xl font-bold mb-3',
        h2: 'text-xl font-bold mb-2',
        h3: 'text-lg font-semibold mb-2',
        h4: 'text-base font-semibold mb-1',
        h5: 'text-sm font-semibold mb-1',
    },
    list: {
        ul: 'list-disc list-inside ml-4 mb-2',
        ol: 'list-decimal list-inside ml-4 mb-2',
        listitem: 'mb-1',
        nested: {
            listitem: 'list-none',
        },
    },
    quote: 'border-l-4 border-border pl-4 italic text-muted-foreground my-2',
    code: 'bg-muted px-1 py-0.5 rounded font-mono text-sm',
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
    link: 'text-primary underline cursor-pointer',
    text: {
        bold: 'font-bold',
        italic: 'italic',
        underline: 'underline',
        strikethrough: 'line-through',
        code: 'bg-muted px-1 py-0.5 rounded font-mono text-sm',
    },
};

// Toolbar component
function ToolbarPlugin({ showToolbar = true }: { showToolbar?: boolean }) {
    const [editor] = useLexicalComposerContext();
    const [isBold, setIsBold] = useState(false);
    const [isItalic, setIsItalic] = useState(false);
    const [isUnderline, setIsUnderline] = useState(false);
    const [isStrikethrough, setIsStrikethrough] = useState(false);

    const updateToolbar = useCallback(() => {
        const selection = $getSelection();
        if ($isRangeSelection(selection)) {
            setIsBold(selection.hasFormat('bold'));
            setIsItalic(selection.hasFormat('italic'));
            setIsUnderline(selection.hasFormat('underline'));
            setIsStrikethrough(selection.hasFormat('strikethrough'));
        }
    }, []);

    useEffect(() => {
        return editor.registerUpdateListener(({ editorState }) => {
            editorState.read(() => {
                updateToolbar();
            });
        });
    }, [editor, updateToolbar]);

    if (!showToolbar) return null;

    return (
        <div className="flex items-center gap-0.5 p-1 border-b bg-muted/30">
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className={cn("h-7 w-7 p-0", isBold && "bg-muted")}
                onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'bold')}
            >
                <Bold className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className={cn("h-7 w-7 p-0", isItalic && "bg-muted")}
                onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'italic')}
            >
                <Italic className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className={cn("h-7 w-7 p-0", isUnderline && "bg-muted")}
                onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'underline')}
            >
                <Underline className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className={cn("h-7 w-7 p-0", isStrikethrough && "bg-muted")}
                onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'strikethrough')}
            >
                <Strikethrough className="h-4 w-4" />
            </Button>
            <div className="w-px h-5 bg-border mx-1" />
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-7 w-7 p-0"
                onClick={() => editor.dispatchCommand(UNDO_COMMAND, undefined)}
            >
                <Undo2 className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-7 w-7 p-0"
                onClick={() => editor.dispatchCommand(REDO_COMMAND, undefined)}
            >
                <Redo2 className="h-4 w-4" />
            </Button>
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
                    // If no nodes, create a paragraph with the content as text
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

interface LexicalEditorProps {
    content: string | null;
    onChange: (content: string) => void;
    editable?: boolean;
    placeholder?: string;
    showToolbar?: boolean;
    minHeight?: string;
    className?: string;
}

export function LexicalEditor({
    content,
    onChange,
    editable = true,
    placeholder = 'Start typing...',
    showToolbar = true,
    minHeight = '100px',
    className,
}: LexicalEditorProps) {
    const initialConfig = {
        namespace: 'BranitiveEditor',
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
                                    "outline-none p-3 text-sm",
                                    !editable && "cursor-default"
                                )}
                                style={{ minHeight }}
                                aria-placeholder={placeholder}
                                placeholder={
                                    <div className="absolute top-3 left-3 text-muted-foreground pointer-events-none text-sm">
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
                <LinkPlugin />
                <MarkdownShortcutPlugin transformers={TRANSFORMERS} />
                <InitialContentPlugin content={content} editable={editable} />
                <HtmlOnChangePlugin onChange={onChange} />
            </div>
        </LexicalComposer>
    );
}

// Simple editor for comments (no toolbar, smaller)
interface CommentEditorProps {
    value: string;
    onChange: (value: string) => void;
    onSubmit?: () => void;
    placeholder?: string;
    className?: string;
}

export function CommentEditor({
    value,
    onChange,
    onSubmit,
    placeholder = 'Write a comment...',
    className,
}: CommentEditorProps) {
    const initialConfig = {
        namespace: 'CommentEditor',
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
        ],
        editable: true,
    };

    return (
        <LexicalComposer initialConfig={initialConfig}>
            <div className={cn("border rounded-lg overflow-hidden bg-background relative", className)}>
                <RichTextPlugin
                    contentEditable={
                        <ContentEditable
                            className="outline-none p-2 text-sm min-h-[60px]"
                            aria-placeholder={placeholder}
                            placeholder={
                                <div className="absolute top-2 left-2 text-muted-foreground pointer-events-none text-sm">
                                    {placeholder}
                                </div>
                            }
                        />
                    }
                    ErrorBoundary={LexicalErrorBoundary}
                />
                <HistoryPlugin />
                <ListPlugin />
                <MarkdownShortcutPlugin transformers={TRANSFORMERS} />
                <CommentOnChangePlugin onChange={onChange} />
            </div>
        </LexicalComposer>
    );
}

// Simple onChange plugin for comments that works with plain text
function CommentOnChangePlugin({ onChange }: { onChange: (value: string) => void }) {
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
