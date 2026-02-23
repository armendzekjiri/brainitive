import { useEffect, useState } from 'react';
import { Editor } from '@tiptap/core';
import { List, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

interface HeadingItem {
    id: string;
    level: number;
    text: string;
}

interface TableOfContentsProps {
    editor: Editor | null;
    className?: string;
}

export function TableOfContents({ editor, className }: TableOfContentsProps) {
    const [headings, setHeadings] = useState<HeadingItem[]>([]);
    const [activeId, setActiveId] = useState<string>('');

    useEffect(() => {
        if (!editor) return;

        const updateHeadings = () => {
            const items: HeadingItem[] = [];
            const doc = editor.state.doc;

            doc.descendants((node, pos) => {
                if (node.type.name === 'heading') {
                    const id = `heading-${pos}`;
                    items.push({
                        id,
                        level: node.attrs.level as number,
                        text: node.textContent,
                    });
                }
            });

            setHeadings(items);
        };

        // Initial update
        updateHeadings();

        // Listen to editor updates
        editor.on('update', updateHeadings);

        return () => {
            editor.off('update', updateHeadings);
        };
    }, [editor]);

    // Scroll spy to highlight active heading
    useEffect(() => {
        const handleScroll = () => {
            if (!editor) return;

            const editorElement = editor.view.dom;
            const headingElements = editorElement.querySelectorAll('h1, h2, h3, h4, h5, h6');

            let currentActiveId = '';
            const scrollTop = window.scrollY || document.documentElement.scrollTop;

            headingElements.forEach((heading, index) => {
                const rect = heading.getBoundingClientRect();
                if (rect.top <= 100) {
                    currentActiveId = headings[index]?.id || '';
                }
            });

            setActiveId(currentActiveId);
        };

        window.addEventListener('scroll', handleScroll, { passive: true });
        return () => window.removeEventListener('scroll', handleScroll);
    }, [editor, headings]);

    const scrollToHeading = (id: string) => {
        if (!editor) return;

        const pos = parseInt(id.replace('heading-', ''), 10);

        // Focus the editor at that position
        editor.chain().focus().setTextSelection(pos).run();

        // Scroll the element into view
        const editorElement = editor.view.dom;
        const headingElements = editorElement.querySelectorAll('h1, h2, h3, h4, h5, h6');
        const index = headings.findIndex(h => h.id === id);

        if (headingElements[index]) {
            headingElements[index].scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };

    if (headings.length === 0) {
        return null;
    }

    // Find the minimum heading level to calculate relative indentation
    const minLevel = Math.min(...headings.map(h => h.level));

    return (
        <div className={cn('w-64 shrink-0 relative', className)}>
            <div className="fixed top-24 w-64 max-h-[calc(100vh-8rem)] overflow-y-auto">
                <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground mb-3">
                    <List className="size-4" />
                    <span>On this page</span>
                </div>
                <nav className="space-y-1 pr-4">
                    {headings.map((heading) => {
                        const indent = (heading.level - minLevel) * 12;
                        const isActive = activeId === heading.id;

                        return (
                            <button
                                key={heading.id}
                                onClick={() => scrollToHeading(heading.id)}
                                className={cn(
                                    'block w-full text-left text-sm py-1 px-2 rounded-md transition-colors truncate',
                                    'hover:bg-accent hover:text-accent-foreground',
                                    isActive
                                        ? 'text-primary font-medium bg-primary/5'
                                        : 'text-muted-foreground'
                                )}
                                style={{ paddingLeft: `${8 + indent}px` }}
                                title={heading.text}
                            >
                                {heading.level > minLevel && (
                                    <ChevronRight className="size-3 inline-block mr-1 opacity-50" />
                                )}
                                {heading.text || 'Untitled'}
                            </button>
                        );
                    })}
                </nav>
            </div>
        </div>
    );
}

export default TableOfContents;
