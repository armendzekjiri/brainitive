import {
    $applyNodeReplacement,
    DecoratorNode,
    DOMExportOutput,
    EditorConfig,
    LexicalNode,
    NodeKey,
    SerializedLexicalNode,
    Spread,
} from 'lexical';
import { useLexicalComposerContext } from '@lexical/react/LexicalComposerContext';
import { useLexicalNodeSelection } from '@lexical/react/useLexicalNodeSelection';
import { mergeRegister } from '@lexical/utils';
import { useCallback, useRef, useEffect, useState } from 'react';
import {
    $getNodeByKey,
    $getSelection,
    $isNodeSelection,
    CLICK_COMMAND,
    COMMAND_PRIORITY_LOW,
    KEY_BACKSPACE_COMMAND,
    KEY_DELETE_COMMAND,
} from 'lexical';

export type SerializedPageBreakNode = SerializedLexicalNode;

function PageBreakComponent({
    nodeKey,
}: {
    nodeKey: NodeKey;
}) {
    const [editor] = useLexicalComposerContext();
    const containerRef = useRef<HTMLDivElement>(null);
    const [isSelected, setSelected, clearSelection] = useLexicalNodeSelection(nodeKey);

    const onDelete = useCallback(
        (payload: KeyboardEvent) => {
            if (isSelected && $isNodeSelection($getSelection())) {
                const event: KeyboardEvent = payload;
                event.preventDefault();
                const node = $getNodeByKey(nodeKey);
                if ($isPageBreakNode(node)) {
                    node.remove();
                }
            }
            return false;
        },
        [isSelected, nodeKey]
    );

    useEffect(() => {
        return mergeRegister(
            editor.registerCommand<MouseEvent>(
                CLICK_COMMAND,
                (payload) => {
                    const event = payload;
                    if (containerRef.current && containerRef.current.contains(event.target as Node)) {
                        if (event.shiftKey) {
                            setSelected(!isSelected);
                        } else {
                            clearSelection();
                            setSelected(true);
                        }
                        return true;
                    }
                    return false;
                },
                COMMAND_PRIORITY_LOW
            ),
            editor.registerCommand(
                KEY_DELETE_COMMAND,
                onDelete,
                COMMAND_PRIORITY_LOW
            ),
            editor.registerCommand(
                KEY_BACKSPACE_COMMAND,
                onDelete,
                COMMAND_PRIORITY_LOW
            )
        );
    }, [clearSelection, editor, isSelected, nodeKey, onDelete, setSelected]);

    return (
        <div
            ref={containerRef}
            className={`my-6 cursor-pointer ${isSelected ? 'ring-2 ring-primary ring-offset-4 rounded' : ''}`}
            draggable="false"
        >
            <div className="flex items-center gap-4">
                <div className="flex-1 border-t-2 border-dashed border-muted-foreground/30" />
                <span className="text-xs text-muted-foreground uppercase tracking-wider font-medium px-2 py-1 bg-muted rounded">
                    Page Break
                </span>
                <div className="flex-1 border-t-2 border-dashed border-muted-foreground/30" />
            </div>
        </div>
    );
}

export class PageBreakNode extends DecoratorNode<JSX.Element> {
    static getType(): string {
        return 'page-break';
    }

    static clone(node: PageBreakNode): PageBreakNode {
        return new PageBreakNode(node.__key);
    }

    static importJSON(): PageBreakNode {
        return $createPageBreakNode();
    }

    constructor(key?: NodeKey) {
        super(key);
    }

    exportJSON(): SerializedPageBreakNode {
        return {
            type: 'page-break',
            version: 1,
        };
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement('div');
        element.className = 'page-break';
        element.style.pageBreakAfter = 'always';
        return { element };
    }

    static importDOM(): null {
        return null;
    }

    createDOM(config: EditorConfig): HTMLElement {
        const div = document.createElement('div');
        div.className = 'page-break-container';
        return div;
    }

    updateDOM(): false {
        return false;
    }

    decorate(): JSX.Element {
        return <PageBreakComponent nodeKey={this.getKey()} />;
    }
}

export function $createPageBreakNode(): PageBreakNode {
    return $applyNodeReplacement(new PageBreakNode());
}

export function $isPageBreakNode(
    node: LexicalNode | null | undefined
): node is PageBreakNode {
    return node instanceof PageBreakNode;
}
