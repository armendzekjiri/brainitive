import { Extension } from '@tiptap/core';
import { ReactRenderer } from '@tiptap/react';
import Suggestion from '@tiptap/suggestion';
import tippy, { Instance as TippyInstance } from 'tippy.js';
import { forwardRef, useEffect, useImperativeHandle, useState } from 'react';
import {
    Heading1,
    Heading2,
    Heading3,
    List,
    ListOrdered,
    CheckSquare,
    Quote,
    Code,
    Table,
    Image,
    Minus,
    AlertCircle,
    Info,
    AlertTriangle,
    CheckCircle2,
    FileText,
    Type,
    Columns,
} from 'lucide-react';

export interface CommandItem {
    title: string;
    description: string;
    icon: React.ComponentType<{ className?: string }>;
    command: (props: { editor: any; range: any }) => void;
}

// Slash command items
export const getSuggestionItems = ({ query }: { query: string }): CommandItem[] => {
    const items: CommandItem[] = [
        {
            title: 'Text',
            description: 'Just start writing with plain text.',
            icon: Type,
            command: ({ editor, range }) => {
                editor.chain().focus().deleteRange(range).setParagraph().run();
            },
        },
        {
            title: 'Heading 1',
            description: 'Large section heading.',
            icon: Heading1,
            command: ({ editor, range }) => {
                editor.chain().focus().deleteRange(range).setNode('heading', { level: 1 }).run();
            },
        },
        {
            title: 'Heading 2',
            description: 'Medium section heading.',
            icon: Heading2,
            command: ({ editor, range }) => {
                editor.chain().focus().deleteRange(range).setNode('heading', { level: 2 }).run();
            },
        },
        {
            title: 'Heading 3',
            description: 'Small section heading.',
            icon: Heading3,
            command: ({ editor, range }) => {
                editor.chain().focus().deleteRange(range).setNode('heading', { level: 3 }).run();
            },
        },
        {
            title: 'Bullet List',
            description: 'Create a simple bullet list.',
            icon: List,
            command: ({ editor, range }) => {
                editor.chain().focus().deleteRange(range).toggleBulletList().run();
            },
        },
        {
            title: 'Numbered List',
            description: 'Create a numbered list.',
            icon: ListOrdered,
            command: ({ editor, range }) => {
                editor.chain().focus().deleteRange(range).toggleOrderedList().run();
            },
        },
        {
            title: 'Task List',
            description: 'Track tasks with a to-do list.',
            icon: CheckSquare,
            command: ({ editor, range }) => {
                editor.chain().focus().deleteRange(range).toggleTaskList().run();
            },
        },
        {
            title: 'Quote',
            description: 'Capture a quote.',
            icon: Quote,
            command: ({ editor, range }) => {
                editor.chain().focus().deleteRange(range).toggleBlockquote().run();
            },
        },
        {
            title: 'Code Block',
            description: 'Display code with syntax highlighting.',
            icon: Code,
            command: ({ editor, range }) => {
                editor.chain().focus().deleteRange(range).toggleCodeBlock().run();
            },
        },
        {
            title: 'Table',
            description: 'Insert a table.',
            icon: Table,
            command: ({ editor, range }) => {
                editor.chain().focus().deleteRange(range).insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
            },
        },
        {
            title: 'Image',
            description: 'Upload or embed an image.',
            icon: Image,
            command: ({ editor, range }) => {
                const url = window.prompt('Enter image URL:');
                if (url) {
                    editor.chain().focus().deleteRange(range).setImage({ src: url }).run();
                }
            },
        },
        {
            title: 'Divider',
            description: 'Visually divide content.',
            icon: Minus,
            command: ({ editor, range }) => {
                editor.chain().focus().deleteRange(range).setHorizontalRule().run();
            },
        },
        {
            title: 'Info Panel',
            description: 'Highlight important info.',
            icon: Info,
            command: ({ editor, range }) => {
                editor.chain().focus().deleteRange(range).insertCallout({ type: 'info' }).run();
            },
        },
        {
            title: 'Warning Panel',
            description: 'Highlight warnings.',
            icon: AlertTriangle,
            command: ({ editor, range }) => {
                editor.chain().focus().deleteRange(range).insertCallout({ type: 'warning' }).run();
            },
        },
        {
            title: 'Success Panel',
            description: 'Highlight success messages.',
            icon: CheckCircle2,
            command: ({ editor, range }) => {
                editor.chain().focus().deleteRange(range).insertCallout({ type: 'success' }).run();
            },
        },
        {
            title: 'Error Panel',
            description: 'Highlight errors.',
            icon: AlertCircle,
            command: ({ editor, range }) => {
                editor.chain().focus().deleteRange(range).insertCallout({ type: 'error' }).run();
            },
        },
    ];

    return items.filter((item) =>
        item.title.toLowerCase().includes(query.toLowerCase()) ||
        item.description.toLowerCase().includes(query.toLowerCase())
    );
};

// Command List Component
interface CommandListProps {
    items: CommandItem[];
    command: (item: CommandItem) => void;
}

export const CommandList = forwardRef<{ onKeyDown: (props: { event: KeyboardEvent }) => boolean }, CommandListProps>(
    ({ items, command }, ref) => {
        const [selectedIndex, setSelectedIndex] = useState(0);

        useEffect(() => {
            setSelectedIndex(0);
        }, [items]);

        useImperativeHandle(ref, () => ({
            onKeyDown: ({ event }: { event: KeyboardEvent }) => {
                if (event.key === 'ArrowUp') {
                    setSelectedIndex((selectedIndex + items.length - 1) % items.length);
                    return true;
                }

                if (event.key === 'ArrowDown') {
                    setSelectedIndex((selectedIndex + 1) % items.length);
                    return true;
                }

                if (event.key === 'Enter') {
                    if (items[selectedIndex]) {
                        command(items[selectedIndex]);
                    }
                    return true;
                }

                return false;
            },
        }));

        if (items.length === 0) {
            return (
                <div className="bg-popover text-popover-foreground rounded-lg border shadow-lg p-3 text-sm text-muted-foreground">
                    No results found
                </div>
            );
        }

        return (
            <div className="bg-popover text-popover-foreground rounded-lg border shadow-lg overflow-hidden w-72 max-h-80 overflow-y-auto">
                <div className="p-1">
                    {items.map((item, index) => {
                        const Icon = item.icon;
                        return (
                            <button
                                key={item.title}
                                onClick={() => command(item)}
                                className={`flex items-start gap-3 w-full p-2 rounded-md text-left transition-colors ${
                                    index === selectedIndex ? 'bg-accent' : 'hover:bg-accent/50'
                                }`}
                            >
                                <div className="size-10 rounded-md bg-muted flex items-center justify-center shrink-0">
                                    <Icon className="size-5 text-muted-foreground" />
                                </div>
                                <div className="flex-1 min-w-0">
                                    <div className="font-medium text-sm">{item.title}</div>
                                    <div className="text-xs text-muted-foreground truncate">{item.description}</div>
                                </div>
                            </button>
                        );
                    })}
                </div>
            </div>
        );
    }
);

CommandList.displayName = 'CommandList';

// Render function for tippy
const renderItems = () => {
    let component: ReactRenderer | null = null;
    let popup: TippyInstance[] | null = null;

    return {
        onStart: (props: any) => {
            component = new ReactRenderer(CommandList, {
                props,
                editor: props.editor,
            });

            if (!props.clientRect) {
                return;
            }

            popup = tippy('body', {
                getReferenceClientRect: props.clientRect,
                appendTo: () => document.body,
                content: component.element,
                showOnCreate: true,
                interactive: true,
                trigger: 'manual',
                placement: 'bottom-start',
            });
        },

        onUpdate(props: any) {
            component?.updateProps(props);

            if (!props.clientRect) {
                return;
            }

            popup?.[0]?.setProps({
                getReferenceClientRect: props.clientRect,
            });
        },

        onKeyDown(props: any) {
            if (props.event.key === 'Escape') {
                popup?.[0]?.hide();
                return true;
            }

            return (component?.ref as any)?.onKeyDown(props);
        },

        onExit() {
            popup?.[0]?.destroy();
            component?.destroy();
        },
    };
};

// Slash Command Extension
export const SlashCommands = Extension.create({
    name: 'slashCommands',

    addOptions() {
        return {
            suggestion: {
                char: '/',
                command: ({ editor, range, props }: { editor: any; range: any; props: CommandItem }) => {
                    props.command({ editor, range });
                },
            },
        };
    },

    addProseMirrorPlugins() {
        return [
            Suggestion({
                editor: this.editor,
                ...this.options.suggestion,
                items: getSuggestionItems,
                render: renderItems,
            }),
        ];
    },
});
