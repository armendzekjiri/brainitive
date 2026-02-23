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

export interface GIFPayload {
    src: string;
    altText?: string;
    key?: NodeKey;
}

export type SerializedGIFNode = Spread<
    {
        src: string;
        altText: string;
    },
    SerializedLexicalNode
>;

function GIFComponent({
    src,
    altText,
    nodeKey,
}: {
    src: string;
    altText: string;
    nodeKey: NodeKey;
}) {
    const [editor] = useLexicalComposerContext();
    const imageRef = useRef<HTMLImageElement>(null);
    const [isSelected, setSelected, clearSelection] = useLexicalNodeSelection(nodeKey);

    const onDelete = useCallback(
        (payload: KeyboardEvent) => {
            if (isSelected && $isNodeSelection($getSelection())) {
                const event: KeyboardEvent = payload;
                event.preventDefault();
                const node = $getNodeByKey(nodeKey);
                if ($isGIFNode(node)) {
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
                    if (event.target === imageRef.current) {
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
        <div className="relative inline-block my-2" draggable="false">
            <img
                ref={imageRef}
                src={src}
                alt={altText}
                className={`max-w-full rounded-md ${isSelected ? 'ring-2 ring-primary ring-offset-2' : ''}`}
                draggable="false"
            />
            <div className="absolute bottom-2 right-2 bg-black/50 text-white text-xs px-2 py-0.5 rounded">
                GIF
            </div>
        </div>
    );
}

export class GIFNode extends DecoratorNode<JSX.Element> {
    __src: string;
    __altText: string;

    static getType(): string {
        return 'gif';
    }

    static clone(node: GIFNode): GIFNode {
        return new GIFNode(node.__src, node.__altText, node.__key);
    }

    static importJSON(serializedNode: SerializedGIFNode): GIFNode {
        return $createGIFNode(serializedNode.src, serializedNode.altText);
    }

    constructor(src: string, altText: string = 'GIF', key?: NodeKey) {
        super(key);
        this.__src = src;
        this.__altText = altText;
    }

    exportJSON(): SerializedGIFNode {
        return {
            type: 'gif',
            version: 1,
            src: this.__src,
            altText: this.__altText,
        };
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement('img');
        element.setAttribute('src', this.__src);
        element.setAttribute('alt', this.__altText);
        element.setAttribute('data-gif', 'true');
        return { element };
    }

    static importDOM(): null {
        return null;
    }

    createDOM(config: EditorConfig): HTMLElement {
        const div = document.createElement('div');
        div.className = 'gif-container inline-block';
        return div;
    }

    updateDOM(): false {
        return false;
    }

    decorate(): JSX.Element {
        return (
            <Suspense fallback={<div className="w-40 h-40 bg-muted rounded-md animate-pulse" />}>
                <GIFComponent src={this.__src} altText={this.__altText} nodeKey={this.getKey()} />
            </Suspense>
        );
    }
}

export function $createGIFNode(src: string, altText: string = 'GIF'): GIFNode {
    return $applyNodeReplacement(new GIFNode(src, altText));
}

export function $isGIFNode(
    node: LexicalNode | null | undefined
): node is GIFNode {
    return node instanceof GIFNode;
}
