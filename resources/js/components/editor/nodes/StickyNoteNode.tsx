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
        width: number;
        fontSize: number;
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

const MIN_WIDTH = 150;
const MAX_FONT_SIZE = 40;
const MIN_FONT_SIZE = 10;

// ─── StickyComponent (React) ─────────────────────────────────────────────────
function StickyComponent({
    x,
    y,
    nodeKey,
    color,
    caption,
    captionState,
    width,
    fontSize,
}: {
    caption: LexicalEditor;
    captionState: string;
    color: StickyNoteColor;
    nodeKey: NodeKey;
    x: number;
    y: number;
    width: number;
    fontSize: number;
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
    const resizeRef = useRef<{ isResizing: boolean; startX: number; startWidth: number }>({
        isResizing: false,
        startX: 0,
        startWidth: width,
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

    // ── Resize handlers ────────────────────────────────────────────────────
    const handleResizePointerMove = (event: PointerEvent) => {
        const resize = resizeRef.current;
        if (!resize.isResizing) return;
        const zoom = calculateZoomLevel(stickyContainerRef.current);
        const newWidth = Math.max(MIN_WIDTH, resize.startWidth + (event.clientX / zoom - resize.startX));
        const noteEl = stickyContainerRef.current?.firstElementChild as HTMLElement | null;
        if (noteEl) noteEl.style.width = newWidth + 'px';
    };

    const handleResizePointerUp = (event: PointerEvent) => {
        const resize = resizeRef.current;
        resize.isResizing = false;
        const zoom = calculateZoomLevel(stickyContainerRef.current);
        const newWidth = Math.max(MIN_WIDTH, resize.startWidth + (event.clientX / zoom - resize.startX));
        document.removeEventListener('pointermove', handleResizePointerMove);
        document.removeEventListener('pointerup', handleResizePointerUp);
        editor.update(() => {
            const node = $getNodeByKey(nodeKey);
            if ($isStickyNoteNode(node)) {
                node.setWidth(Math.round(newWidth));
            }
        });
    };

    // ── Font size handlers ─────────────────────────────────────────────────
    const changeFontSize = (delta: number) => {
        editor.update(() => {
            const node = $getNodeByKey(nodeKey);
            if ($isStickyNoteNode(node)) {
                const next = Math.min(MAX_FONT_SIZE, Math.max(MIN_FONT_SIZE, node.__fontSize + delta));
                node.setFontSize(next);
            }
        });
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

    // Propagate nested-editor changes to the outer editor so the outer
    // editor becomes "dirty" and HtmlOnChangePlugin re-exports HTML with
    // the updated caption content via exportDOM.
    // Live co-editing (BRAI-54): the caption also lives in the node as a JSON string, which Yjs syncs
    // (the nested editor itself can't travel). Typing updates the string; a remote string is loaded here.
    useEffect(() => {
        return caption.registerUpdateListener(({ tags }) => {
            if (tags.has(STICKY_REMOTE_TAG)) return;
            const serialized = JSON.stringify(caption.getEditorState().toJSON());
            editor.update(() => {
                const node = $getNodeByKey(nodeKey);
                if ($isStickyNoteNode(node)) {
                    // getWritable() marks the node dirty in the outer editor
                    const writable = node.getWritable();
                    if (writable.__captionState !== serialized) writable.__captionState = serialized;
                }
            });
        });
    }, [caption, editor, nodeKey]);

    useEffect(() => {
        if (!captionState || captionState === JSON.stringify(caption.getEditorState().toJSON())) return;
        try {
            caption.setEditorState(caption.parseEditorState(captionState), { tag: STICKY_REMOTE_TAG });
        } catch {
            /* malformed remote state: keep what we have */
        }
    }, [caption, captionState]);

    return (
        <div ref={stickyContainerRef} className="sticky-note-container">
            <div
                className={`sticky-note ${color}`}
                style={{
                    width,
                    // Expose font size as CSS custom property so contentEditable and placeholder pick it up
                    ['--sticky-font-size' as string]: `${fontSize}px`,
                }}
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
                {/* Top toolbar */}
                <div className="sticky-toolbar">
                    <button
                        onPointerDown={(e) => e.stopPropagation()}
                        onClick={() => changeFontSize(-2)}
                        className="sticky-font-btn"
                        title="Decrease font size">
                        A−
                    </button>
                    <span className="sticky-font-size">{fontSize}</span>
                    <button
                        onPointerDown={(e) => e.stopPropagation()}
                        onClick={() => changeFontSize(2)}
                        className="sticky-font-btn"
                        title="Increase font size">
                        A+
                    </button>
                    <button
                        onPointerDown={(e) => e.stopPropagation()}
                        onClick={handleColorChange}
                        className="sticky-color-btn"
                        title="Toggle color">
                        🎨
                    </button>
                    <button
                        onPointerDown={(e) => e.stopPropagation()}
                        onClick={handleDelete}
                        className="sticky-delete-btn"
                        title="Delete">
                        ✕
                    </button>
                </div>
                {/* Wrapper gives placeholder its own positioning context */}
                <div style={{ position: 'relative' }}>
                <LexicalNestedComposer
                    initialEditor={caption}
                    initialTheme={StickyEditorTheme}
                    skipCollabChecks>
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
                {/* Resize handle (bottom-right corner) */}
                <div
                    className="sticky-resize-handle"
                    title="Drag to resize"
                    onPointerDown={(event) => {
                        event.stopPropagation();
                        event.preventDefault();
                        const zoom = calculateZoomLevel(stickyContainerRef.current);
                        const noteEl = stickyContainerRef.current?.firstElementChild as HTMLElement | null;
                        const currentWidth = noteEl ? noteEl.offsetWidth : width;
                        resizeRef.current = {
                            isResizing: true,
                            startX: event.clientX / zoom,
                            startWidth: currentWidth,
                        };
                        document.addEventListener('pointermove', handleResizePointerMove);
                        document.addEventListener('pointerup', handleResizePointerUp);
                    }}
                />
            </div>
        </div>
    );
}

const STICKY_REMOTE_TAG = 'sticky-remote-caption';

/** The caption as stored: the synced JSON string when there is one, else the nested editor. */
function captionJSON(node: StickyNoteNode): SerializedEditor {
    if (node.__captionState) {
        try {
            return { editorState: JSON.parse(node.__captionState) } as SerializedEditor;
        } catch {
            /* fall back to the nested editor */
        }
    }
    return node.__caption.toJSON();
}

// ─── StickyNoteNode ───────────────────────────────────────────────────────────
export class StickyNoteNode extends DecoratorNode<JSX.Element> {
    __x: number;
    __y: number;
    __color: StickyNoteColor;
    __caption: LexicalEditor;
    /** Serialized caption editor state (live co-editing, BRAI-54); '' = not set yet. */
    __captionState: string;
    __width: number;
    __fontSize: number;

    static getType(): string {
        return 'sticky';
    }

    static clone(node: StickyNoteNode): StickyNoteNode {
        return new StickyNoteNode(
            node.__x,
            node.__y,
            node.__color,
            node.__width,
            node.__fontSize,
            node.__caption,
            node.__key,
        );
    }

    afterCloneFrom(prevNode: this): void {
        super.afterCloneFrom(prevNode);
        this.__captionState = prevNode.__captionState;
    }

    static importJSON(serializedNode: SerializedStickyNoteNode): StickyNoteNode {
        return new StickyNoteNode(
            serializedNode.xOffset,
            serializedNode.yOffset,
            serializedNode.color,
            serializedNode.width ?? 220,
            serializedNode.fontSize ?? 20,
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
                stickyNode.__captionState = JSON.stringify(editorState.toJSON());
            }
        }
        return stickyNode;
    }

    constructor(
        x: number,
        y: number,
        color: StickyNoteColor,
        width: number = 220,
        fontSize: number = 20,
        caption?: LexicalEditor,
        key?: NodeKey,
    ) {
        super(key);
        this.__x = x;
        this.__y = y;
        this.__caption = caption || createEditor();
        this.__captionState = '';
        this.__color = color;
        this.__width = width;
        this.__fontSize = fontSize;
    }

    exportJSON(): SerializedStickyNoteNode {
        return {
            ...super.exportJSON(),
            caption: captionJSON(this),
            color: this.__color,
            xOffset: this.__x,
            yOffset: this.__y,
            width: this.__width,
            fontSize: this.__fontSize,
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

    // Encode full node data as a JSON attribute so HTML round-trips work.
    // The editor saves as HTML (not Lexical JSON), so without this the
    // sticky note is lost on every save.
    exportDOM(): { element: HTMLElement } {
        const el = document.createElement('div');
        el.setAttribute('data-lexical-sticky', JSON.stringify({
            color: this.__color,
            xOffset: this.__x,
            yOffset: this.__y,
            width: this.__width,
            fontSize: this.__fontSize,
            caption: captionJSON(this),
        }));
        el.style.display = 'none';
        return { element: el };
    }

    static importDOM(): { div: (node: Node) => { conversion: (domNode: Node) => { node: StickyNoteNode } | null; priority: 2 } | null } | null {
        return {
            div: (node: Node) => {
                if (!(node instanceof HTMLElement)) return null;
                // Support both new attribute and old 'data-lexical-sticky-note' format
                const hasNew = node.hasAttribute('data-lexical-sticky');
                const hasOld = node.hasAttribute('data-lexical-sticky-note');
                if (!hasNew && !hasOld) return null;
                return {
                    conversion: (domNode: Node): { node: StickyNoteNode } | null => {
                        if (!(domNode instanceof HTMLElement)) return null;
                        const raw = domNode.getAttribute('data-lexical-sticky')
                            ?? domNode.getAttribute('data-lexical-sticky-note');
                        if (!raw) return null;
                        try {
                            const parsed = JSON.parse(raw);
                            // New format uses xOffset/yOffset/caption; old format used x/y/text
                            const color = parsed.color ?? 'yellow';
                            const xOffset = parsed.xOffset ?? parsed.x ?? 0;
                            const yOffset = parsed.yOffset ?? parsed.y ?? 0;
                            const width = parsed.width ?? 220;
                            const fontSize = parsed.fontSize ?? 20;
                            const stickyNode = new StickyNoteNode(xOffset, yOffset, color, width, fontSize);
                            if (parsed.caption?.editorState) {
                                const editorState = stickyNode.__caption.parseEditorState(parsed.caption.editorState);
                                if (!editorState.isEmpty()) {
                                    stickyNode.__caption.setEditorState(editorState);
                                    stickyNode.__captionState = JSON.stringify(editorState.toJSON());
                                }
                            }
                            return { node: $applyNodeReplacement(stickyNode) };
                        } catch {
                            return null;
                        }
                    },
                    priority: 2 as const,
                };
            },
        };
    }

    setPosition(x: number, y: number): void {
        const writable = this.getWritable();
        writable.__x = x;
        writable.__y = y;
        $setSelection(null);
    }

    setWidth(width: number): void {
        const writable = this.getWritable();
        writable.__width = width;
    }

    setFontSize(fontSize: number): void {
        const writable = this.getWritable();
        writable.__fontSize = fontSize;
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
                    width={this.__width}
                    fontSize={this.__fontSize}
                    nodeKey={this.getKey()}
                    caption={this.__caption}
                    captionState={this.__captionState}
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
    width: number = 220,
    fontSize: number = 20,
): StickyNoteNode {
    return $applyNodeReplacement(new StickyNoteNode(xOffset, yOffset, 'yellow', width, fontSize));
}

export function $isStickyNoteNode(
    node: LexicalNode | null | undefined,
): node is StickyNoteNode {
    return node instanceof StickyNoteNode;
}
