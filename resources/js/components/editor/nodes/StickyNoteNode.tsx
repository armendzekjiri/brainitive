/**
 * Sticky note node — matches the Lexical playground's StickyNode approach:
 *  • Rendered via createPortal to document.body (absolutely positioned)
 *  • Draggable by pointer events
 *  • Caption is a LexicalNestedComposer + PlainTextPlugin
 *  • Colors: 'yellow' | 'pink'
 */

import type {
    EditorConfig,
    LexicalEditor,
    LexicalNode,
    LexicalUpdateJSON,
    NodeKey,
    SerializedEditor,
    SerializedLexicalNode,
    Spread,
} from 'lexical';
import type { JSX } from 'react';

import * as React from 'react';
import { Suspense, useEffect, useLayoutEffect, useMemo, useRef } from 'react';
import { createPortal } from 'react-dom';
import { $applyNodeReplacement, $setSelection, createEditor, DecoratorNode } from 'lexical';
import { useLexicalComposerContext } from '@lexical/react/LexicalComposerContext';
import { LexicalNestedComposer } from '@lexical/react/LexicalNestedComposer';
import { PlainTextPlugin } from '@lexical/react/LexicalPlainTextPlugin';
import { ContentEditable } from '@lexical/react/LexicalContentEditable';
import { LexicalErrorBoundary } from '@lexical/react/LexicalErrorBoundary';
import { HistoryPlugin } from '@lexical/react/LexicalHistoryPlugin';
import { createEmptyHistoryState } from '@lexical/history';
import { calculateZoomLevel } from '@lexical/utils';
import { $getNodeByKey } from 'lexical';

// ─── Theme for the nested sticky editor ──────────────────────────────────────
const StickyEditorTheme = {
    paragraph: 'StickyNode__contentEditable',
};

// ─── Types ────────────────────────────────────────────────────────────────────
export type StickyNoteColor = 'pink' | 'yellow';

export type SerializedStickyNoteNode = Spread<
    {
        xOffset: number;
        yOffset: number;
        color: StickyNoteColor;
        caption: SerializedEditor;
    },
    SerializedLexicalNode
>;

type Positioning = {
    isDragging: boolean;
    offsetX: number;
    offsetY: number;
    rootElementRect: null | DOMRect;
    x: number;
    y: number;
};

// ─── Positioning helpers ──────────────────────────────────────────────────────
function positionSticky(stickyElem: HTMLElement, positioning: Positioning): void {
    const style = stickyElem.style;
    const rootElementRect = positioning.rootElementRect;
    const rectLeft = rootElementRect !== null ? rootElementRect.left : 0;
    const rectTop = rootElementRect !== null ? rootElementRect.top : 0;
    style.top = rectTop + positioning.y + 'px';
    style.left = rectLeft + positioning.x + 'px';
}

// ─── StickyComponent (React) ─────────────────────────────────────────────────
function StickyComponent({
    x,
    y,
    nodeKey,
    color,
    caption,
}: {
    caption: LexicalEditor;
    color: StickyNoteColor;
    nodeKey: NodeKey;
    x: number;
    y: number;
}): JSX.Element {
    const [editor] = useLexicalComposerContext();
    const stickyContainerRef = useRef<null | HTMLDivElement>(null);
    const positioningRef = useRef<Positioning>({
        isDragging: false,
        offsetX: 0,
        offsetY: 0,
        rootElementRect: null,
        x: 0,
        y: 0,
    });

    // Sync x/y props into positioning ref whenever they change
    useEffect(() => {
        const position = positioningRef.current;
        position.x = x;
        position.y = y;
        const stickyContainer = stickyContainerRef.current;
        if (stickyContainer !== null) {
            positionSticky(stickyContainer, position);
        }
    }, [x, y]);

    // Track root element resize and reposition on window resize
    useLayoutEffect(() => {
        const position = positioningRef.current;
        const resizeObserver = new ResizeObserver((entries) => {
            for (let i = 0; i < entries.length; i++) {
                const { target } = entries[i];
                position.rootElementRect = (target as HTMLElement).getBoundingClientRect();
                const stickyContainer = stickyContainerRef.current;
                if (stickyContainer !== null) {
                    positionSticky(stickyContainer, position);
                }
            }
        });

        const removeRootListener = editor.registerRootListener(
            (nextRootElem, prevRootElem) => {
                if (prevRootElem !== null) {
                    resizeObserver.unobserve(prevRootElem);
                }
                if (nextRootElem !== null) {
                    resizeObserver.observe(nextRootElem);
                }
            },
        );

        const handleWindowResize = () => {
            const rootElement = editor.getRootElement();
            const stickyContainer = stickyContainerRef.current;
            if (rootElement !== null && stickyContainer !== null) {
                position.rootElementRect = rootElement.getBoundingClientRect();
                positionSticky(stickyContainer, position);
            }
        };

        window.addEventListener('resize', handleWindowResize);
        return () => {
            window.removeEventListener('resize', handleWindowResize);
            removeRootListener();
        };
    }, [editor]);

    // Add CSS transition after initial mount (so it doesn't animate on load)
    useEffect(() => {
        const stickyContainer = stickyContainerRef.current;
        if (stickyContainer !== null) {
            setTimeout(() => {
                stickyContainer.style.setProperty(
                    'transition',
                    'top 0.3s ease 0s, left 0.3s ease 0s',
                );
            }, 500);
        }
    }, []);

    // ── Pointer drag handlers ──────────────────────────────────────────────
    const handlePointerMove = (event: PointerEvent) => {
        const stickyContainer = stickyContainerRef.current;
        const positioning = positioningRef.current;
        const rootElementRect = positioning.rootElementRect;
        const zoom = calculateZoomLevel(stickyContainer);
        if (
            stickyContainer !== null &&
            positioning.isDragging &&
            rootElementRect !== null
        ) {
            positioning.x =
                event.pageX / zoom - positioning.offsetX - rootElementRect.left;
            positioning.y =
                event.pageY / zoom - positioning.offsetY - rootElementRect.top;
            positionSticky(stickyContainer, positioning);
        }
    };

    const handlePointerUp = (_event: PointerEvent) => {
        const stickyContainer = stickyContainerRef.current;
        const positioning = positioningRef.current;
        if (stickyContainer !== null) {
            positioning.isDragging = false;
            stickyContainer.classList.remove('dragging');
            editor.update(() => {
                const node = $getNodeByKey(nodeKey);
                if ($isStickyNoteNode(node)) {
                    node.setPosition(positioning.x, positioning.y);
                }
            });
        }
        document.removeEventListener('pointermove', handlePointerMove);
        document.removeEventListener('pointerup', handlePointerUp);
    };

    // ── Action handlers ────────────────────────────────────────────────────
    const handleDelete = () => {
        editor.update(() => {
            const node = $getNodeByKey(nodeKey);
            if ($isStickyNoteNode(node)) {
                node.remove();
            }
        });
    };

    const handleColorChange = () => {
        editor.update(() => {
            const node = $getNodeByKey(nodeKey);
            if ($isStickyNoteNode(node)) {
                node.toggleColor();
            }
        });
    };

    const historyState = useMemo(() => createEmptyHistoryState(), []);

    return (
        <div ref={stickyContainerRef} className="sticky-note-container">
            <div
                className={`sticky-note ${color}`}
                onPointerDown={(event) => {
                    const stickyContainer = stickyContainerRef.current;
                    if (
                        stickyContainer == null ||
                        event.button === 2 ||
                        event.target !== stickyContainer.firstChild
                    ) {
                        // Right-click or click inside editor — don't drag
                        return;
                    }
                    const positioning = positioningRef.current;
                    const { top, left } = stickyContainer.getBoundingClientRect();
                    const zoom = calculateZoomLevel(stickyContainer);
                    positioning.offsetX = event.clientX / zoom - left;
                    positioning.offsetY = event.clientY / zoom - top;
                    positioning.isDragging = true;
                    stickyContainer.classList.add('dragging');
                    document.addEventListener('pointermove', handlePointerMove);
                    document.addEventListener('pointerup', handlePointerUp);
                    event.preventDefault();
                }}>
                <button
                    onClick={handleDelete}
                    className="delete"
                    aria-label="Delete sticky note"
                    title="Delete">
                    X
                </button>
                <button
                    onClick={handleColorChange}
                    className="color"
                    aria-label="Change sticky note color"
                    title="Toggle color">
                    <i className="bucket" />
                </button>
                <LexicalNestedComposer
                    initialEditor={caption}
                    initialTheme={StickyEditorTheme}>
                    <HistoryPlugin externalHistoryState={historyState} />
                    <PlainTextPlugin
                        contentEditable={
                            <ContentEditable
                                placeholder={
                                    <div className="StickyNode__placeholder">
                                        What's up?
                                    </div>
                                }
                                className="StickyNode__contentEditable"
                                aria-placeholder="What's up?"
                            />
                        }
                        ErrorBoundary={LexicalErrorBoundary}
                    />
                </LexicalNestedComposer>
            </div>
        </div>
    );
}

// ─── StickyNoteNode ───────────────────────────────────────────────────────────
export class StickyNoteNode extends DecoratorNode<JSX.Element> {
    __x: number;
    __y: number;
    __color: StickyNoteColor;
    __caption: LexicalEditor;

    static getType(): string {
        return 'sticky';
    }

    static clone(node: StickyNoteNode): StickyNoteNode {
        return new StickyNoteNode(
            node.__x,
            node.__y,
            node.__color,
            node.__caption,
            node.__key,
        );
    }

    static importJSON(serializedNode: SerializedStickyNoteNode): StickyNoteNode {
        return new StickyNoteNode(
            serializedNode.xOffset,
            serializedNode.yOffset,
            serializedNode.color,
        ).updateFromJSON(serializedNode);
    }

    updateFromJSON(serializedNode: LexicalUpdateJSON<SerializedStickyNoteNode>): this {
        const stickyNode = super.updateFromJSON(serializedNode);
        const captionData = serializedNode.caption;
        const nestedEditor = stickyNode.__caption;
        if (captionData?.editorState) {
            const editorState = nestedEditor.parseEditorState(captionData.editorState);
            if (!editorState.isEmpty()) {
                nestedEditor.setEditorState(editorState);
            }
        }
        return stickyNode;
    }

    constructor(
        x: number,
        y: number,
        color: StickyNoteColor,
        caption?: LexicalEditor,
        key?: NodeKey,
    ) {
        super(key);
        this.__x = x;
        this.__y = y;
        this.__caption = caption || createEditor();
        this.__color = color;
    }

    exportJSON(): SerializedStickyNoteNode {
        return {
            ...super.exportJSON(),
            caption: this.__caption.toJSON(),
            color: this.__color,
            xOffset: this.__x,
            yOffset: this.__y,
        };
    }

    createDOM(_config: EditorConfig): HTMLElement {
        const div = document.createElement('div');
        div.style.display = 'contents';
        return div;
    }

    updateDOM(): false {
        return false;
    }

    setPosition(x: number, y: number): void {
        const writable = this.getWritable();
        writable.__x = x;
        writable.__y = y;
        $setSelection(null);
    }

    toggleColor(): void {
        const writable = this.getWritable();
        writable.__color = writable.__color === 'pink' ? 'yellow' : 'pink';
    }

    decorate(_editor: LexicalEditor, _config: EditorConfig): JSX.Element {
        return createPortal(
            <Suspense fallback={null}>
                <StickyComponent
                    color={this.__color}
                    x={this.__x}
                    y={this.__y}
                    nodeKey={this.getKey()}
                    caption={this.__caption}
                />
            </Suspense>,
            document.body,
        );
    }

    isIsolated(): true {
        return true;
    }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
export function $createStickyNoteNode(
    xOffset: number = 0,
    yOffset: number = 0,
): StickyNoteNode {
    return $applyNodeReplacement(new StickyNoteNode(xOffset, yOffset, 'yellow'));
}

export function $isStickyNoteNode(
    node: LexicalNode | null | undefined,
): node is StickyNoteNode {
    return node instanceof StickyNoteNode;
}
