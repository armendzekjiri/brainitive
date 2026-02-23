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
import { Suspense, useCallback, useRef, useEffect, useState, lazy } from 'react';
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
import { createPortal } from 'react-dom';

// Lazy load Excalidraw to reduce bundle size
const Excalidraw = lazy(() =>
    import('@excalidraw/excalidraw').then((module) => ({
        default: module.Excalidraw,
    }))
);

export interface ExcalidrawPayload {
    data: string;
    key?: NodeKey;
}

export type SerializedExcalidrawNode = Spread<
    {
        data: string;
    },
    SerializedLexicalNode
>;

// Modal component for Excalidraw
function ExcalidrawModal({
    isOpen,
    onClose,
    elements,
    onChange,
}: {
    isOpen: boolean;
    onClose: () => void;
    elements: any[];
    onChange: (elements: readonly any[], appState: any) => void;
}) {
    // Handle escape key to close modal
    useEffect(() => {
        if (!isOpen) return;

        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                onClose();
            }
        };

        document.addEventListener('keydown', handleKeyDown, true);
        return () => document.removeEventListener('keydown', handleKeyDown, true);
    }, [isOpen, onClose]);

    if (!isOpen) return null;

    const handleBackdropClick = (e: React.MouseEvent) => {
        e.preventDefault();
        e.stopPropagation();
        onClose();
    };

    const handleModalClick = (e: React.MouseEvent) => {
        e.stopPropagation();
    };

    return createPortal(
        <div
            className="fixed inset-0 z-[9999] flex items-center justify-center"
            onClick={handleBackdropClick}
        >
            {/* Backdrop */}
            <div className="absolute inset-0 bg-black/50" />

            {/* Modal */}
            <div
                className="relative bg-white rounded-lg shadow-2xl w-[95vw] h-[90vh] max-w-[1600px] flex flex-col overflow-hidden"
                onClick={handleModalClick}
            >
                {/* Header */}
                <div className="flex items-center justify-between px-4 py-3 border-b bg-gray-50">
                    <div className="flex items-center gap-2">
                        <svg className="w-6 h-6 text-purple-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <path d="M12 19l7-7 3 3-7 7-3-3z" />
                            <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z" />
                            <path d="M2 2l7.586 7.586" />
                            <circle cx="11" cy="11" r="2" />
                        </svg>
                        <h2 className="text-lg font-semibold">Excalidraw Whiteboard</h2>
                    </div>
                    <button
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            onClose();
                        }}
                        className="px-4 py-2 text-sm font-medium bg-primary text-primary-foreground rounded-md hover:bg-primary/90 transition-colors"
                    >
                        Done
                    </button>
                </div>

                {/* Excalidraw Container */}
                <div className="flex-1 relative">
                    <Suspense fallback={
                        <div className="w-full h-full bg-muted animate-pulse flex items-center justify-center">
                            <div className="text-center">
                                <div className="w-10 h-10 border-4 border-primary border-t-transparent rounded-full animate-spin mx-auto mb-2"></div>
                                <p className="text-muted-foreground">Loading Excalidraw...</p>
                            </div>
                        </div>
                    }>
                        <div className="absolute inset-0">
                            <Excalidraw
                                initialData={{
                                    elements: elements,
                                    scrollToContent: true,
                                }}
                                onChange={onChange}
                            />
                        </div>
                    </Suspense>
                </div>
            </div>
        </div>,
        document.body
    );
}

function ExcalidrawComponent({
    data,
    nodeKey,
}: {
    data: string;
    nodeKey: NodeKey;
}) {
    const [editor] = useLexicalComposerContext();
    const containerRef = useRef<HTMLDivElement>(null);
    const [isSelected, setSelected, clearSelection] = useLexicalNodeSelection(nodeKey);
    const [isEditing, setIsEditing] = useState(false);
    const [elements, setElements] = useState<any[]>([]);

    useEffect(() => {
        if (data) {
            try {
                const parsed = JSON.parse(data);
                setElements(parsed.elements || []);
            } catch {
                setElements([]);
            }
        }
    }, [data]);

    const onDelete = useCallback(
        (payload: KeyboardEvent) => {
            if (isSelected && $isNodeSelection($getSelection()) && !isEditing) {
                const event: KeyboardEvent = payload;
                event.preventDefault();
                const node = $getNodeByKey(nodeKey);
                if ($isExcalidrawNode(node)) {
                    node.remove();
                }
            }
            return false;
        },
        [isSelected, nodeKey, isEditing]
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

    const handleChange = useCallback((excalidrawElements: readonly any[], appState: any) => {
        const newData = JSON.stringify({
            elements: excalidrawElements,
            appState: { viewBackgroundColor: appState.viewBackgroundColor },
        });

        // Update local elements state for preview
        setElements([...excalidrawElements]);

        editor.update(() => {
            const node = $getNodeByKey(nodeKey);
            if ($isExcalidrawNode(node)) {
                node.setData(newData);
            }
        });
    }, [editor, nodeKey]);

    return (
        <>
            <div ref={containerRef} className="relative my-4" draggable="false">
                <div
                    className={`relative rounded-lg overflow-hidden border bg-white cursor-pointer hover:border-primary/50 transition-colors ${isSelected ? 'ring-2 ring-primary ring-offset-2' : ''}`}
                    style={{ height: '200px' }}
                    onClick={() => setIsEditing(true)}
                >
                    <div className="w-full h-full flex items-center justify-center bg-gradient-to-br from-purple-50 to-blue-50">
                        {elements.length > 0 ? (
                            <div className="text-center text-muted-foreground">
                                <svg className="w-16 h-16 mx-auto mb-3 text-purple-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                                    <path d="M12 19l7-7 3 3-7 7-3-3z" />
                                    <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z" />
                                    <path d="M2 2l7.586 7.586" />
                                    <circle cx="11" cy="11" r="2" />
                                </svg>
                                <p className="text-sm font-medium text-foreground">Click to edit drawing</p>
                                <p className="text-xs text-muted-foreground mt-1">{elements.length} element(s)</p>
                            </div>
                        ) : (
                            <div className="text-center text-muted-foreground">
                                <svg className="w-16 h-16 mx-auto mb-3 text-purple-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                                    <path d="M12 19l7-7 3 3-7 7-3-3z" />
                                    <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z" />
                                    <path d="M2 2l7.586 7.586" />
                                    <circle cx="11" cy="11" r="2" />
                                </svg>
                                <p className="text-sm font-medium text-foreground">Click to start drawing</p>
                                <p className="text-xs text-muted-foreground mt-1">Opens Excalidraw whiteboard</p>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Excalidraw Modal */}
            <ExcalidrawModal
                isOpen={isEditing}
                onClose={() => setIsEditing(false)}
                elements={elements}
                onChange={handleChange}
            />
        </>
    );
}

export class ExcalidrawNode extends DecoratorNode<JSX.Element> {
    __data: string;

    static getType(): string {
        return 'excalidraw';
    }

    static clone(node: ExcalidrawNode): ExcalidrawNode {
        return new ExcalidrawNode(node.__data, node.__key);
    }

    static importJSON(serializedNode: SerializedExcalidrawNode): ExcalidrawNode {
        return $createExcalidrawNode(serializedNode.data);
    }

    constructor(data: string = '{"elements":[],"appState":{}}', key?: NodeKey) {
        super(key);
        this.__data = data;
    }

    exportJSON(): SerializedExcalidrawNode {
        return {
            type: 'excalidraw',
            version: 1,
            data: this.__data,
        };
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement('div');
        element.className = 'excalidraw-embed';
        element.setAttribute('data-excalidraw', this.__data);
        element.innerHTML = '<p>[Excalidraw Drawing]</p>';
        return { element };
    }

    static importDOM(): null {
        return null;
    }

    setData(data: string): void {
        const writable = this.getWritable();
        writable.__data = data;
    }

    getData(): string {
        return this.__data;
    }

    createDOM(config: EditorConfig): HTMLElement {
        const div = document.createElement('div');
        div.className = 'excalidraw-container';
        return div;
    }

    updateDOM(): false {
        return false;
    }

    decorate(): JSX.Element {
        return (
            <ExcalidrawComponent data={this.__data} nodeKey={this.getKey()} />
        );
    }
}

export function $createExcalidrawNode(data: string = '{"elements":[],"appState":{}}'): ExcalidrawNode {
    return $applyNodeReplacement(new ExcalidrawNode(data));
}

export function $isExcalidrawNode(
    node: LexicalNode | null | undefined
): node is ExcalidrawNode {
    return node instanceof ExcalidrawNode;
}
