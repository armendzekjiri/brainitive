import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import { Table } from '@tiptap/extension-table';
import { TableRow } from '@tiptap/extension-table-row';
import { TableCell } from '@tiptap/extension-table-cell';
import { TableHeader } from '@tiptap/extension-table-header';
import { CodeBlockLowlight } from '@tiptap/extension-code-block-lowlight';
import { Link } from '@tiptap/extension-link';
import { Image } from '@tiptap/extension-image';
import TaskList from '@tiptap/extension-task-list';
import TaskItem from '@tiptap/extension-task-item';
import { Highlight } from '@tiptap/extension-highlight';
import { Underline } from '@tiptap/extension-underline';
import { TextAlign } from '@tiptap/extension-text-align';
import { TextStyle } from '@tiptap/extension-text-style';
import { Color } from '@tiptap/extension-color';
import { HorizontalRule } from '@tiptap/extension-horizontal-rule';
import { Typography } from '@tiptap/extension-typography';
import { common, createLowlight } from 'lowlight';
import { SlashCommands } from './SlashCommands';
import { Callout } from './extensions/Callout';
import { useEffect, useCallback, useState, useRef } from 'react';
import {
    Bold,
    Italic,
    Underline as UnderlineIcon,
    Strikethrough,
    Code,
    Link as LinkIcon,
    Highlighter,
    AlignLeft,
    AlignCenter,
    AlignRight,
    AlignJustify,
    List,
    ListOrdered,
    CheckSquare,
    Quote,
    Heading1,
    Heading2,
    Heading3,
    Undo,
    Redo,
    Table as TableIcon,
    Image as ImageIcon,
    Minus,
    Type,
    MoreHorizontal,
    Trash2,
    Plus,
    Columns,
    Rows,
    Maximize2,
    Minimize2,
    Paperclip,
    Upload,
    FileText,
    X,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Input } from '@/components/ui/input';
import { TableOfContents } from './TableOfContents';
import { cn } from '@/lib/utils';
import axios from 'axios';

const lowlight = createLowlight(common);

interface PageEditorProps {
    content: Record<string, any> | null;
    onChange: (content: Record<string, any>) => void;
    editable?: boolean;
    placeholder?: string;
    showTableOfContents?: boolean;
    onFullscreenChange?: (isFullscreen: boolean) => void;
}

interface Attachment {
    url: string;
    name: string;
    size?: number;
    type?: string;
}

// Toolbar Button Component
function ToolbarButton({
    onClick,
    active,
    disabled,
    children,
    title,
}: {
    onClick: () => void;
    active?: boolean;
    disabled?: boolean;
    children: React.ReactNode;
    title?: string;
}) {
    return (
        <button
            onClick={onClick}
            disabled={disabled}
            title={title}
            className={`p-1.5 rounded-md transition-colors ${
                active
                    ? 'bg-primary/10 text-primary'
                    : 'hover:bg-muted text-muted-foreground hover:text-foreground'
            } ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
        >
            {children}
        </button>
    );
}

// Link Popover Component
function LinkPopover({ editor }: { editor: any }) {
    const setLink = useCallback((url: string) => {
        if (url === '') {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
            return;
        }
        editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    }, [editor]);

    const currentUrl = editor.getAttributes('link').href || '';

    return (
        <Popover>
            <PopoverTrigger asChild>
                <button
                    className={`p-1.5 rounded-md transition-colors ${
                        editor.isActive('link')
                            ? 'bg-primary/10 text-primary'
                            : 'hover:bg-muted text-muted-foreground hover:text-foreground'
                    }`}
                    title="Link"
                >
                    <LinkIcon className="size-4" />
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-80">
                <div className="space-y-2">
                    <label className="text-sm font-medium">Link URL</label>
                    <div className="flex gap-2">
                        <Input
                            placeholder="https://example.com"
                            defaultValue={currentUrl}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    setLink((e.target as HTMLInputElement).value);
                                }
                            }}
                        />
                        <Button
                            size="sm"
                            onClick={(e) => {
                                const input = (e.target as HTMLElement).parentElement?.querySelector('input');
                                if (input) setLink(input.value);
                            }}
                        >
                            Set
                        </Button>
                    </div>
                    {editor.isActive('link') && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-destructive"
                            onClick={() => editor.chain().focus().unsetLink().run()}
                        >
                            Remove link
                        </Button>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}

// Editor Toolbar Component
function EditorToolbar({
    editor,
    onUploadImage,
    onUploadDocument,
    isFullscreen,
    onToggleFullscreen
}: {
    editor: any;
    onUploadImage: () => void;
    onUploadDocument: () => void;
    isFullscreen: boolean;
    onToggleFullscreen: () => void;
}) {
    if (!editor) return null;

    return (
        <div className="flex items-center gap-0.5 p-2 border-b bg-background/50 flex-wrap">
            {/* History */}
            <ToolbarButton onClick={() => editor.chain().focus().undo().run()} disabled={!editor.can().undo()} title="Undo">
                <Undo className="size-4" />
            </ToolbarButton>
            <ToolbarButton onClick={() => editor.chain().focus().redo().run()} disabled={!editor.can().redo()} title="Redo">
                <Redo className="size-4" />
            </ToolbarButton>

            <Separator orientation="vertical" className="h-6 mx-1" />

            {/* Text Formatting */}
            <ToolbarButton onClick={() => editor.chain().focus().toggleBold().run()} active={editor.isActive('bold')} title="Bold">
                <Bold className="size-4" />
            </ToolbarButton>
            <ToolbarButton onClick={() => editor.chain().focus().toggleItalic().run()} active={editor.isActive('italic')} title="Italic">
                <Italic className="size-4" />
            </ToolbarButton>
            <ToolbarButton onClick={() => editor.chain().focus().toggleUnderline().run()} active={editor.isActive('underline')} title="Underline">
                <UnderlineIcon className="size-4" />
            </ToolbarButton>
            <ToolbarButton onClick={() => editor.chain().focus().toggleStrike().run()} active={editor.isActive('strike')} title="Strikethrough">
                <Strikethrough className="size-4" />
            </ToolbarButton>
            <ToolbarButton onClick={() => editor.chain().focus().toggleCode().run()} active={editor.isActive('code')} title="Inline Code">
                <Code className="size-4" />
            </ToolbarButton>
            <ToolbarButton onClick={() => editor.chain().focus().toggleHighlight().run()} active={editor.isActive('highlight')} title="Highlight">
                <Highlighter className="size-4" />
            </ToolbarButton>
            <LinkPopover editor={editor} />

            <Separator orientation="vertical" className="h-6 mx-1" />

            {/* Headings */}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button className="flex items-center gap-1 px-2 py-1.5 rounded-md hover:bg-muted text-sm">
                        <Type className="size-4" />
                        <span className="hidden sm:inline">Heading</span>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                    <DropdownMenuItem onClick={() => editor.chain().focus().setParagraph().run()}>
                        <Type className="size-4 mr-2" /> Paragraph
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()}>
                        <Heading1 className="size-4 mr-2" /> Heading 1
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}>
                        <Heading2 className="size-4 mr-2" /> Heading 2
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}>
                        <Heading3 className="size-4 mr-2" /> Heading 3
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Separator orientation="vertical" className="h-6 mx-1" />

            {/* Text Alignment */}
            <ToolbarButton onClick={() => editor.chain().focus().setTextAlign('left').run()} active={editor.isActive({ textAlign: 'left' })} title="Align Left">
                <AlignLeft className="size-4" />
            </ToolbarButton>
            <ToolbarButton onClick={() => editor.chain().focus().setTextAlign('center').run()} active={editor.isActive({ textAlign: 'center' })} title="Align Center">
                <AlignCenter className="size-4" />
            </ToolbarButton>
            <ToolbarButton onClick={() => editor.chain().focus().setTextAlign('right').run()} active={editor.isActive({ textAlign: 'right' })} title="Align Right">
                <AlignRight className="size-4" />
            </ToolbarButton>

            <Separator orientation="vertical" className="h-6 mx-1" />

            {/* Lists */}
            <ToolbarButton onClick={() => editor.chain().focus().toggleBulletList().run()} active={editor.isActive('bulletList')} title="Bullet List">
                <List className="size-4" />
            </ToolbarButton>
            <ToolbarButton onClick={() => editor.chain().focus().toggleOrderedList().run()} active={editor.isActive('orderedList')} title="Numbered List">
                <ListOrdered className="size-4" />
            </ToolbarButton>
            <ToolbarButton onClick={() => editor.chain().focus().toggleTaskList().run()} active={editor.isActive('taskList')} title="Task List">
                <CheckSquare className="size-4" />
            </ToolbarButton>

            <Separator orientation="vertical" className="h-6 mx-1" />

            {/* Blocks */}
            <ToolbarButton onClick={() => editor.chain().focus().toggleBlockquote().run()} active={editor.isActive('blockquote')} title="Quote">
                <Quote className="size-4" />
            </ToolbarButton>
            <ToolbarButton onClick={() => editor.chain().focus().toggleCodeBlock().run()} active={editor.isActive('codeBlock')} title="Code Block">
                <Code className="size-4" />
            </ToolbarButton>
            <ToolbarButton onClick={() => editor.chain().focus().setHorizontalRule().run()} title="Divider">
                <Minus className="size-4" />
            </ToolbarButton>

            <Separator orientation="vertical" className="h-6 mx-1" />

            {/* Table & Image */}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button className="p-1.5 rounded-md hover:bg-muted text-muted-foreground hover:text-foreground" title="Table">
                        <TableIcon className="size-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                    <DropdownMenuItem onClick={() => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()}>
                        <Plus className="size-4 mr-2" /> Insert Table
                    </DropdownMenuItem>
                    {editor.isActive('table') && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onClick={() => editor.chain().focus().addColumnAfter().run()}>
                                <Columns className="size-4 mr-2" /> Add Column After
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={() => editor.chain().focus().addColumnBefore().run()}>
                                <Columns className="size-4 mr-2" /> Add Column Before
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={() => editor.chain().focus().deleteColumn().run()}>
                                <Trash2 className="size-4 mr-2" /> Delete Column
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onClick={() => editor.chain().focus().addRowAfter().run()}>
                                <Rows className="size-4 mr-2" /> Add Row After
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={() => editor.chain().focus().addRowBefore().run()}>
                                <Rows className="size-4 mr-2" /> Add Row Before
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={() => editor.chain().focus().deleteRow().run()}>
                                <Trash2 className="size-4 mr-2" /> Delete Row
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onClick={() => editor.chain().focus().deleteTable().run()} className="text-destructive">
                                <Trash2 className="size-4 mr-2" /> Delete Table
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            {/* Image & Attachments */}
            <ToolbarButton onClick={onUploadImage} title="Upload Image">
                <ImageIcon className="size-4" />
            </ToolbarButton>
            <ToolbarButton onClick={onUploadDocument} title="Attach Document">
                <Paperclip className="size-4" />
            </ToolbarButton>

            <div className="flex-1" />

            {/* Fullscreen Toggle */}
            <ToolbarButton onClick={onToggleFullscreen} title={isFullscreen ? "Exit Fullscreen" : "Fullscreen"}>
                {isFullscreen ? <Minimize2 className="size-4" /> : <Maximize2 className="size-4" />}
            </ToolbarButton>
        </div>
    );
}

// Main Page Editor Component
export function PageEditor({ content, onChange, editable = true, placeholder, showTableOfContents = true, onFullscreenChange }: PageEditorProps) {
    const [isFullscreen, setIsFullscreen] = useState(false);
    const [attachments, setAttachments] = useState<Attachment[]>([]);
    const [isUploading, setIsUploading] = useState(false);
    const imageInputRef = useRef<HTMLInputElement>(null);
    const documentInputRef = useRef<HTMLInputElement>(null);

    const editor = useEditor({
        extensions: [
            StarterKit.configure({
                heading: {
                    levels: [1, 2, 3, 4, 5, 6],
                },
                codeBlock: false, // We use CodeBlockLowlight instead
                horizontalRule: false, // We use the separate extension
            }),
            Placeholder.configure({
                placeholder: placeholder || 'Type "/" for commands...',
                emptyEditorClass: 'is-editor-empty',
            }),
            Table.configure({
                resizable: true,
            }),
            TableRow,
            TableCell,
            TableHeader,
            CodeBlockLowlight.configure({
                lowlight,
            }),
            Link.configure({
                openOnClick: false,
                HTMLAttributes: {
                    class: 'text-primary underline cursor-pointer',
                },
            }),
            Image.configure({
                HTMLAttributes: {
                    class: 'max-w-full rounded-lg border',
                },
            }),
            TaskList,
            TaskItem.configure({
                nested: true,
            }),
            Highlight.configure({
                multicolor: true,
            }),
            Underline,
            TextAlign.configure({
                types: ['heading', 'paragraph'],
            }),
            TextStyle,
            Color,
            HorizontalRule,
            Typography,
            Callout,
            SlashCommands,
        ],
        content: content || {
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                },
            ],
        },
        editable,
        onUpdate: ({ editor }) => {
            onChange(editor.getJSON());
        },
        editorProps: {
            attributes: {
                class: 'prose prose-slate dark:prose-invert max-w-none focus:outline-none min-h-[400px] px-6 py-4',
            },
        },
    });

    useEffect(() => {
        if (editor && content) {
            const currentContent = editor.getJSON();
            if (JSON.stringify(currentContent) !== JSON.stringify(content)) {
                editor.commands.setContent(content);
            }
        }
    }, [content, editor]);

    useEffect(() => {
        if (editor) {
            editor.setEditable(editable);
        }
    }, [editable, editor]);

    // Toggle fullscreen
    const toggleFullscreen = useCallback(() => {
        setIsFullscreen(prev => {
            const newValue = !prev;
            onFullscreenChange?.(newValue);
            return newValue;
        });
    }, [onFullscreenChange]);

    // Handle ESC key to exit fullscreen
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && isFullscreen) {
                setIsFullscreen(false);
                onFullscreenChange?.(false);
            }
        };
        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [isFullscreen, onFullscreenChange]);

    // Upload image
    const handleUploadImage = useCallback(async (file: File) => {
        if (!editor) return;
        setIsUploading(true);
        try {
            const formData = new FormData();
            formData.append('image', file);
            const response = await axios.post('/upload/image', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            editor.chain().focus().setImage({ src: response.data.url, alt: response.data.name }).run();
        } catch (error) {
            console.error('Image upload failed:', error);
            alert('Failed to upload image. Please try again.');
        } finally {
            setIsUploading(false);
        }
    }, [editor]);

    // Upload document
    const handleUploadDocument = useCallback(async (file: File) => {
        if (!editor) return;
        setIsUploading(true);
        try {
            const formData = new FormData();
            formData.append('document', file);
            const response = await axios.post('/upload/document', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            // Insert a link to the document
            const attachment: Attachment = response.data;
            setAttachments(prev => [...prev, attachment]);
            // Insert link in editor
            editor.chain().focus().insertContent({
                type: 'paragraph',
                content: [{
                    type: 'text',
                    marks: [{ type: 'link', attrs: { href: attachment.url, target: '_blank' } }],
                    text: `📎 ${attachment.name}`
                }]
            }).run();
        } catch (error) {
            console.error('Document upload failed:', error);
            alert('Failed to upload document. Please try again.');
        } finally {
            setIsUploading(false);
        }
    }, [editor]);

    const triggerImageUpload = () => imageInputRef.current?.click();
    const triggerDocumentUpload = () => documentInputRef.current?.click();

    if (!editor) {
        return (
            <div className="min-h-[400px] flex items-center justify-center">
                <div className="text-muted-foreground">Loading editor...</div>
            </div>
        );
    }

    return (
        <div className={cn(
            "w-full transition-all duration-300",
            isFullscreen && "fixed inset-0 z-50 bg-background overflow-auto p-8"
        )}>
            {/* Hidden file inputs */}
            <input
                ref={imageInputRef}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) handleUploadImage(file);
                    e.target.value = '';
                }}
            />
            <input
                ref={documentInputRef}
                type="file"
                accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar"
                className="hidden"
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) handleUploadDocument(file);
                    e.target.value = '';
                }}
            />

            {/* Loading overlay */}
            {isUploading && (
                <div className="fixed inset-0 z-50 bg-background/80 flex items-center justify-center">
                    <div className="flex items-center gap-2 text-muted-foreground">
                        <div className="animate-spin h-5 w-5 border-2 border-current border-t-transparent rounded-full"></div>
                        <span>Uploading...</span>
                    </div>
                </div>
            )}

            {editable && (
                <div className={cn(
                    "sticky z-10 bg-background/95 backdrop-blur border-b mb-6",
                    isFullscreen ? "top-0" : "top-16"
                )}>
                    <EditorToolbar
                        editor={editor}
                        onUploadImage={triggerImageUpload}
                        onUploadDocument={triggerDocumentUpload}
                        isFullscreen={isFullscreen}
                        onToggleFullscreen={toggleFullscreen}
                    />
                </div>
            )}
            <div className={cn(
                "flex gap-8",
                isFullscreen && "max-w-6xl mx-auto"
            )}>
                <div className="flex-1 min-w-0">
                    <EditorContent editor={editor} />

                    {/* Attachments list */}
                    {attachments.length > 0 && (
                        <div className="mt-8 border-t pt-6">
                            <h4 className="text-sm font-medium text-muted-foreground mb-3">Attachments</h4>
                            <div className="space-y-2">
                                {attachments.map((attachment, index) => (
                                    <div key={index} className="flex items-center gap-2 text-sm">
                                        <FileText className="h-4 w-4 text-muted-foreground" />
                                        <a
                                            href={attachment.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-primary hover:underline"
                                        >
                                            {attachment.name}
                                        </a>
                                        {attachment.size && (
                                            <span className="text-muted-foreground">
                                                ({(attachment.size / 1024).toFixed(1)} KB)
                                            </span>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
                {showTableOfContents && (
                    <TableOfContents
                        editor={editor}
                        className="hidden lg:block"
                    />
                )}
            </div>
        </div>
    );
}

export default PageEditor;
