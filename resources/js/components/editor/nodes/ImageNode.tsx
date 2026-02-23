import {
    $applyNodeReplacement,
    DecoratorNode,
    DOMConversionMap,
    DOMConversionOutput,
    DOMExportOutput,
    EditorConfig,
    LexicalNode,
    NodeKey,
    SerializedLexicalNode,
    Spread,
} from 'lexical';
import { Suspense, lazy, useCallback, useRef, useState } from 'react';
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
import { useEffect } from 'react';

export interface ImagePayload {
    altText: string;
    height?: number;
    key?: NodeKey;
    maxWidth?: number;
    src: string;
    width?: number;
    caption?: string;
}

export type SerializedImageNode = Spread<
    {
        altText: string;
        height?: number;
        maxWidth?: number;
        src: string;
        width?: number;
        caption?: string;
    },
    SerializedLexicalNode
>;

function ImageComponent({
    src,
    altText,
    nodeKey,
    width,
    height,
    maxWidth,
    caption,
}: {
    src: string;
    altText: string;
    nodeKey: NodeKey;
    width?: number;
    height?: number;
    maxWidth?: number;
    caption?: string;
}) {
    const [editor] = useLexicalComposerContext();
    const imageRef = useRef<HTMLImageElement>(null);
    const [isSelected, setSelected, clearSelection] = useLexicalNodeSelection(nodeKey);
    const [isResizing, setIsResizing] = useState(false);

    const onDelete = useCallback(
        (payload: KeyboardEvent) => {
            if (isSelected && $isNodeSelection($getSelection())) {
                const event: KeyboardEvent = payload;
                event.preventDefault();
                const node = $getNodeByKey(nodeKey);
                if ($isImageNode(node)) {
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
        <div className="relative inline-block" draggable="false">
            <img
                ref={imageRef}
                src={src}
                alt={altText}
                style={{
                    width: width ? `${width}px` : 'auto',
                    height: height ? `${height}px` : 'auto',
                    maxWidth: maxWidth ? `${maxWidth}px` : '100%',
                }}
                className={`rounded-md ${isSelected ? 'ring-2 ring-primary ring-offset-2' : ''}`}
                draggable="false"
            />
            {caption && (
                <div className="text-center text-sm text-muted-foreground mt-2 italic">
                    {caption}
                </div>
            )}
        </div>
    );
}

function $convertImageElement(domNode: Node): null | DOMConversionOutput {
    if (domNode instanceof HTMLImageElement) {
        const { alt: altText, src, width, height } = domNode;
        const node = $createImageNode({ altText, height, src, width });
        return { node };
    }
    return null;
}

export class ImageNode extends DecoratorNode<JSX.Element> {
    __src: string;
    __altText: string;
    __width: number | undefined;
    __height: number | undefined;
    __maxWidth: number | undefined;
    __caption: string | undefined;

    static getType(): string {
        return 'image';
    }

    static clone(node: ImageNode): ImageNode {
        return new ImageNode(
            node.__src,
            node.__altText,
            node.__maxWidth,
            node.__width,
            node.__height,
            node.__caption,
            node.__key
        );
    }

    static importJSON(serializedNode: SerializedImageNode): ImageNode {
        const { altText, height, width, maxWidth, src, caption } = serializedNode;
        const node = $createImageNode({
            altText,
            height,
            maxWidth,
            src,
            width,
            caption,
        });
        return node;
    }

    static importDOM(): DOMConversionMap | null {
        return {
            img: () => ({
                conversion: $convertImageElement,
                priority: 0,
            }),
        };
    }

    constructor(
        src: string,
        altText: string,
        maxWidth?: number,
        width?: number,
        height?: number,
        caption?: string,
        key?: NodeKey
    ) {
        super(key);
        this.__src = src;
        this.__altText = altText;
        this.__maxWidth = maxWidth;
        this.__width = width;
        this.__height = height;
        this.__caption = caption;
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement('img');
        element.setAttribute('src', this.__src);
        element.setAttribute('alt', this.__altText);
        if (this.__width) {
            element.setAttribute('width', this.__width.toString());
        }
        if (this.__height) {
            element.setAttribute('height', this.__height.toString());
        }
        return { element };
    }

    exportJSON(): SerializedImageNode {
        return {
            altText: this.__altText,
            height: this.__height,
            maxWidth: this.__maxWidth,
            src: this.__src,
            type: 'image',
            version: 1,
            width: this.__width,
            caption: this.__caption,
        };
    }

    getSrc(): string {
        return this.__src;
    }

    getAltText(): string {
        return this.__altText;
    }

    createDOM(config: EditorConfig): HTMLElement {
        const span = document.createElement('span');
        const className = config.theme.image;
        if (className !== undefined) {
            span.className = className;
        }
        return span;
    }

    updateDOM(): false {
        return false;
    }

    decorate(): JSX.Element {
        return (
            <Suspense fallback={null}>
                <ImageComponent
                    src={this.__src}
                    altText={this.__altText}
                    width={this.__width}
                    height={this.__height}
                    maxWidth={this.__maxWidth}
                    nodeKey={this.getKey()}
                    caption={this.__caption}
                />
            </Suspense>
        );
    }
}

export function $createImageNode({
    altText,
    height,
    maxWidth,
    src,
    width,
    caption,
    key,
}: ImagePayload): ImageNode {
    return $applyNodeReplacement(
        new ImageNode(src, altText, maxWidth, width, height, caption, key)
    );
}

export function $isImageNode(
    node: LexicalNode | null | undefined
): node is ImageNode {
    return node instanceof ImageNode;
}
