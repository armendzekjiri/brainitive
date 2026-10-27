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
import { Suspense, useCallback, useRef, useEffect, useState } from 'react';
import { useLexicalComposerContext } from '@lexical/react/LexicalComposerContext';
import { useLexicalNodeSelection } from '@lexical/react/useLexicalNodeSelection';
import { mergeRegister } from '@lexical/utils';
import {
    $getNodeByKey,
    $getSelection,
    $isNodeSelection,
    CLICK_COMMAND,
    COMMAND_PRIORITY_LOW,
    KEY_BACKSPACE_COMMAND,
    KEY_DELETE_COMMAND,
} from 'lexical';
import { ExternalEmbedGate } from '@/components/legal/ExternalEmbedGate';

export interface FigmaPayload {
    documentID: string;
    key?: NodeKey;
}

export type SerializedFigmaNode = Spread<
    {
        documentID: string;
    },
    SerializedLexicalNode
>;

function FigmaComponent({
    documentID,
    nodeKey,
}: {
    documentID: string;
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
                if ($isFigmaNode(node)) {
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
        <div ref={containerRef} className="relative my-4" draggable="false">
            <div className={`relative aspect-[4/3] max-w-3xl mx-auto rounded-lg overflow-hidden ${isSelected ? 'ring-2 ring-primary ring-offset-2' : ''}`}>
                <iframe
                    className="absolute inset-0 w-full h-full border-0"
                    src={`https://www.figma.com/embed?embed_host=lexical&url=${encodeURIComponent(documentID)}`}
                    allowFullScreen
                    title="Figma document"
                />
            </div>
        </div>
    );
}

export class FigmaNode extends DecoratorNode<JSX.Element> {
    __id: string;

    static getType(): string {
        return 'figma';
    }

    static clone(node: FigmaNode): FigmaNode {
        return new FigmaNode(node.__id, node.__key);
    }

    static importJSON(serializedNode: SerializedFigmaNode): FigmaNode {
        const node = $createFigmaNode(serializedNode.documentID);
        return node;
    }

    constructor(id: string, key?: NodeKey) {
        super(key);
        this.__id = id;
    }

    exportJSON(): SerializedFigmaNode {
        return {
            type: 'figma',
            version: 1,
            documentID: this.__id,
        };
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement('iframe');
        element.setAttribute('src', `https://www.figma.com/embed?embed_host=lexical&url=${encodeURIComponent(this.__id)}`);
        element.setAttribute('width', '100%');
        element.setAttribute('height', '450');
        element.setAttribute('allowfullscreen', 'true');
        return { element };
    }

    static importDOM(): null {
        return null;
    }

    createDOM(config: EditorConfig): HTMLElement {
        const div = document.createElement('div');
        div.className = 'figma-embed';
        return div;
    }

    updateDOM(): false {
        return false;
    }

    decorate(): JSX.Element {
        return (
            <Suspense fallback={<div className="aspect-[4/3] max-w-3xl mx-auto bg-muted rounded-lg animate-pulse" />}>
                <ExternalEmbedGate providerId="figma" providerName="Figma" url={this.__id}>
                    <FigmaComponent documentID={this.__id} nodeKey={this.getKey()} />
                </ExternalEmbedGate>
            </Suspense>
        );
    }
}

export function $createFigmaNode(documentID: string): FigmaNode {
    return $applyNodeReplacement(new FigmaNode(documentID));
}

export function $isFigmaNode(
    node: LexicalNode | null | undefined
): node is FigmaNode {
    return node instanceof FigmaNode;
}
