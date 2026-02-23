import { useEffect, useRef, useCallback, useState } from 'react';
import EditorJS, { OutputData, API } from '@editorjs/editorjs';
import Header from '@editorjs/header';
import NestedList from '@editorjs/nested-list';
import Checklist from '@editorjs/checklist';
import Quote from '@editorjs/quote';
import Code from '@editorjs/code';
import Delimiter from '@editorjs/delimiter';
import Table from '@editorjs/table';
import ImageTool from '@editorjs/image';
import LinkTool from '@editorjs/link';
import Marker from '@editorjs/marker';
import InlineCode from '@editorjs/inline-code';
import Warning from '@editorjs/warning';
import Embed from '@editorjs/embed';
import Paragraph from '@editorjs/paragraph';
import Attaches from '@editorjs/attaches';
import Alert from 'editorjs-alert';
import RawTool from '@editorjs/raw';
import Underline from '@editorjs/underline';
import TextVariantTune from '@editorjs/text-variant-tune';
import Undo from 'editorjs-undo';
import DragDrop from 'editorjs-drag-drop';
import { cn } from '@/lib/utils';

// Get CSRF token from meta tag
const getCsrfToken = (): string => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') || '' : '';
};

interface EditorJSComponentProps {
    content: OutputData | null;
    onChange: (content: OutputData) => void;
    onHeadingsChange?: (headings: HeadingItem[]) => void;
    editable?: boolean;
    placeholder?: string;
}

export interface HeadingItem {
    id: string;
    level: number;
    text: string;
}

export function EditorJSComponent({
    content,
    onChange,
    onHeadingsChange,
    editable = true,
    placeholder = 'Click here and start typing, or press / for commands...',
}: EditorJSComponentProps) {
    const editorRef = useRef<EditorJS | null>(null);
    const holderRef = useRef<HTMLDivElement>(null);
    const [isReady, setIsReady] = useState(false);
    const isInternalChange = useRef(false);
    const initialContentRef = useRef<OutputData | null>(null);

    // Extract headings from content
    const extractHeadings = useCallback((data: OutputData | null) => {
        if (!data?.blocks) return [];

        return data.blocks
            .filter(block => block.type === 'header')
            .map((block, index) => ({
                id: block.id || `heading-${index}`,
                level: block.data?.level as number || 2,
                text: (block.data?.text as string) || '',
            }));
    }, []);

    // Assign IDs to heading elements in the DOM for TOC scrolling
    const assignHeadingIds = useCallback(() => {
        if (!holderRef.current) return;

        const headingElements = holderRef.current.querySelectorAll('.ce-header');
        const blocks = holderRef.current.querySelectorAll('.ce-block');

        headingElements.forEach((headingEl, index) => {
            // Find the parent block to get its ID
            const parentBlock = headingEl.closest('.ce-block');
            if (parentBlock) {
                const blockId = parentBlock.getAttribute('data-id');
                if (blockId) {
                    headingEl.setAttribute('id', blockId);
                } else {
                    headingEl.setAttribute('id', `heading-${index}`);
                }
            }
        });
    }, []);

    // Validate and clean block data to prevent "saved data is invalid" errors
    const validateBlockData = useCallback((data: OutputData | null): OutputData => {
        if (!data || !data.blocks) {
            return { time: Date.now(), blocks: [], version: '2.31.0' };
        }

        const validBlocks = data.blocks.filter(block => {
            // Skip blocks without type
            if (!block.type) return false;

            // Validate specific block types
            switch (block.type) {
                case 'paragraph':
                    return block.data && typeof block.data.text === 'string';
                case 'header':
                    return block.data && typeof block.data.text === 'string' && typeof block.data.level === 'number';
                case 'list':
                    return block.data && Array.isArray(block.data.items);
                case 'checklist':
                    return block.data && Array.isArray(block.data.items);
                case 'quote':
                    return block.data && typeof block.data.text === 'string';
                case 'code':
                    return block.data && typeof block.data.code === 'string';
                case 'warning':
                    return block.data && typeof block.data.title === 'string';
                case 'alert':
                    return block.data && typeof block.data.message === 'string';
                case 'table':
                    return block.data && Array.isArray(block.data.content);
                case 'image':
                    return block.data && block.data.file && block.data.file.url;
                case 'delimiter':
                    return true;
                case 'embed':
                    return block.data && block.data.service && block.data.source;
                case 'raw':
                    return block.data && typeof block.data.html === 'string';
                case 'attaches':
                    return block.data && block.data.file;
                default:
                    // Allow unknown block types with data
                    return block.data !== undefined;
            }
        }).map(block => ({
            ...block,
            id: block.id || `block-${Math.random().toString(36).substr(2, 9)}`,
        }));

        return {
            time: data.time || Date.now(),
            blocks: validBlocks,
            version: data.version || '2.31.0',
        };
    }, []);

    useEffect(() => {
        if (!holderRef.current) return;

        // Store the initial content
        const validatedContent = validateBlockData(content);
        initialContentRef.current = validatedContent;

        const editor = new EditorJS({
            holder: holderRef.current,
            readOnly: !editable,
            placeholder,
            data: validatedContent.blocks.length > 0 ? validatedContent : undefined,
            minHeight: 200,
            logLevel: 'ERROR' as any,

            // Block tunes for all blocks
            tunes: ['textVariant'],

            tools: {
                // Text blocks
                paragraph: {
                    class: Paragraph,
                    inlineToolbar: true,
                    tunes: ['textVariant'],
                },
                header: {
                    class: Header,
                    config: {
                        placeholder: 'Enter a heading',
                        levels: [1, 2, 3, 4, 5, 6],
                        defaultLevel: 2,
                    },
                    inlineToolbar: true,
                    shortcut: 'CMD+SHIFT+H',
                },

                // Lists
                list: {
                    class: NestedList,
                    inlineToolbar: true,
                    config: {
                        defaultStyle: 'unordered',
                    },
                    shortcut: 'CMD+SHIFT+L',
                },
                checklist: {
                    class: Checklist,
                    inlineToolbar: true,
                    shortcut: 'CMD+SHIFT+C',
                },

                // Callouts and alerts
                alert: {
                    class: Alert,
                    inlineToolbar: true,
                    config: {
                        alertTypes: ['primary', 'secondary', 'info', 'success', 'warning', 'danger', 'light', 'dark'],
                        defaultType: 'info',
                        messagePlaceholder: 'Enter a message',
                    },
                    shortcut: 'CMD+SHIFT+A',
                },
                warning: {
                    class: Warning,
                    inlineToolbar: true,
                    config: {
                        titlePlaceholder: 'Title',
                        messagePlaceholder: 'Message',
                    },
                },

                // Quote
                quote: {
                    class: Quote,
                    inlineToolbar: true,
                    config: {
                        quotePlaceholder: 'Enter a quote',
                        captionPlaceholder: 'Quote author',
                    },
                    shortcut: 'CMD+SHIFT+Q',
                },

                // Code
                code: {
                    class: Code,
                    config: {
                        placeholder: 'Enter code here...',
                    },
                    shortcut: 'CMD+SHIFT+K',
                },

                // Raw HTML
                raw: {
                    class: RawTool,
                    config: {
                        placeholder: 'Enter raw HTML here...',
                    },
                },

                // Layout
                delimiter: {
                    class: Delimiter,
                    shortcut: 'CMD+SHIFT+D',
                },

                // Table
                table: {
                    class: Table,
                    inlineToolbar: true,
                    config: {
                        rows: 3,
                        cols: 3,
                        withHeadings: true,
                    },
                    shortcut: 'CMD+SHIFT+T',
                },

                // Media
                image: {
                    class: ImageTool,
                    config: {
                        endpoints: {
                            byFile: '/upload/image',
                            byUrl: '/upload/image-url',
                        },
                        field: 'image',
                        types: 'image/*',
                        captionPlaceholder: 'Image caption',
                        additionalRequestHeaders: {
                            'X-CSRF-TOKEN': getCsrfToken(),
                        },
                    },
                },
                attaches: {
                    class: Attaches,
                    config: {
                        endpoint: '/upload/document',
                        field: 'document',
                        buttonText: 'Select file to upload',
                        additionalRequestHeaders: {
                            'X-CSRF-TOKEN': getCsrfToken(),
                        },
                    },
                },
                embed: {
                    class: Embed,
                    config: {
                        services: {
                            youtube: true,
                            vimeo: true,
                            codepen: true,
                            github: true,
                            twitter: true,
                            instagram: true,
                            twitch: true,
                            miro: true,
                            figma: true,
                        },
                    },
                },
                linkTool: {
                    class: LinkTool,
                    config: {
                        endpoint: '/api/link-preview',
                    },
                },

                // Inline tools
                marker: {
                    class: Marker,
                    shortcut: 'CMD+SHIFT+M',
                },
                inlineCode: {
                    class: InlineCode,
                    shortcut: 'CMD+E',
                },
                underline: {
                    class: Underline,
                    shortcut: 'CMD+U',
                },

                // Block tunes
                textVariant: {
                    class: TextVariantTune,
                },
            },

            onChange: async (api: API) => {
                try {
                    isInternalChange.current = true;
                    const outputData = await api.saver.save();
                    onChange(outputData);

                    // Update headings and assign IDs for TOC scrolling
                    const headings = extractHeadings(outputData);
                    onHeadingsChange?.(headings);

                    // Small delay to ensure DOM is updated
                    setTimeout(assignHeadingIds, 50);
                } catch (error) {
                    console.error('Editor.js save error:', error);
                }
            },

            onReady: () => {
                setIsReady(true);

                // Initialize Undo plugin
                if (editable && editorRef.current) {
                    try {
                        new Undo({ editor: editorRef.current });
                        new DragDrop(editorRef.current);
                    } catch (e) {
                        console.warn('Failed to initialize editor plugins:', e);
                    }
                }

                if (content) {
                    onHeadingsChange?.(extractHeadings(content));
                    // Assign IDs after initial render
                    setTimeout(assignHeadingIds, 100);
                }
            },
        });

        editorRef.current = editor;

        return () => {
            if (editorRef.current && editorRef.current.destroy) {
                editorRef.current.destroy();
                editorRef.current = null;
            }
        };
    }, [editable]); // Only recreate when editable changes

    // Update content when it changes externally (not from our own onChange)
    useEffect(() => {
        if (editorRef.current && isReady && content && !isInternalChange.current) {
            // Compare with initial content to avoid unnecessary re-renders
            const currentTime = initialContentRef.current?.time;
            if (content.time && content.time !== currentTime) {
                const validatedContent = validateBlockData(content);
                editorRef.current.isReady.then(() => {
                    if (validatedContent.blocks.length > 0) {
                        editorRef.current?.render(validatedContent);
                    }
                    onHeadingsChange?.(extractHeadings(validatedContent));
                    initialContentRef.current = validatedContent;
                    // Assign IDs after render
                    setTimeout(assignHeadingIds, 100);
                }).catch(console.error);
            }
        }
        // Reset the flag after external check
        isInternalChange.current = false;
    }, [content?.time]); // Only update when content.time changes

    return (
        <div
            ref={holderRef}
            className={cn(
                'editorjs-container prose prose-slate dark:prose-invert max-w-none min-h-[200px]',
                !editable && 'pointer-events-none opacity-90'
            )}
        />
    );
}

export default EditorJSComponent;
