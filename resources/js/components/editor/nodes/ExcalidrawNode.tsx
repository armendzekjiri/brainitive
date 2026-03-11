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
import '@excalidraw/excalidraw/index.css';

// exportToBlob loaded alongside Excalidraw
const getExportToBlob = () =>
    import('@excalidraw/excalidraw').then((m) => m.exportToBlob);

// Lazy load Excalidraw to reduce bundle size
const Excalidraw = lazy(() =>
    import('@excalidraw/excalidraw').then((module) => ({
        default: module.Excalidraw,
    }))
);

// ─── Types ────────────────────────────────────────────────────────────────────

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

// ─── Full-screen editor ───────────────────────────────────────────────────────

function ExcalidrawFullEditor({
    isOpen,
    initialElements,
    initialAppState,
    onSave,
    onDiscard,
}: {
    isOpen: boolean;
    initialElements: any[];
    initialAppState: any;
    onSave: (newData: string, previewDataUrl: string) => void;
    onDiscard: () => void;
}) {
    const excalidrawApiRef = useRef<any>(null);
    const [isSaving, setIsSaving] = useState(false);
    const [hasChanges, setHasChanges] = useState(false);

    // Block Escape from propagating to the parent Lexical editor while open
    useEffect(() => {
        if (!isOpen) return;
        const block = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.stopImmediatePropagation();
                e.preventDefault();
            }
        };
        document.addEventListener('keydown', block, true);
        return () => document.removeEventListener('keydown', block, true);
    }, [isOpen]);

    const handleChange = useCallback((_elements: readonly any[], _appState: any) => {
        setHasChanges(true);
    }, []);

    const handleSave = useCallback(async () => {
        const api = excalidrawApiRef.current;
        if (!api) return;
        setIsSaving(true);
        try {
            const elements = api.getSceneElements();
            const appState = api.getAppState();
            const files = api.getFiles();

            // Export a PNG preview
            const exportFn = await getExportToBlob();
            const blob = await exportFn({
                elements,
                appState: { ...appState, exportBackground: true },
                files,
                mimeType: 'image/png',
                quality: 0.85,
            });

            const previewDataUrl = await new Promise<string>((resolve) => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result as string);
                reader.readAsDataURL(blob);
            });

            const newData = JSON.stringify({
                elements,
                appState: { viewBackgroundColor: appState.viewBackgroundColor ?? '#ffffff' },
                previewDataUrl,
            });

            onSave(newData, previewDataUrl);
        } catch (err) {
            console.error('Excalidraw export failed', err);
        } finally {
            setIsSaving(false);
        }
    }, [onSave]);

    if (!isOpen) return null;

    return createPortal(
        <div className="fixed inset-0 z-[9999] flex flex-col bg-background">
            {/* Toolbar — mirrors the blade page whiteboard */}
            <div className="flex items-center justify-between px-4 py-2 border-b bg-background shrink-0" style={{ height: '52px' }}>
                <div className="flex items-center gap-2">
                    <svg className="w-5 h-5 text-purple-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M12 19l7-7 3 3-7 7-3-3z" />
                        <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z" />
                        <path d="M2 2l7.586 7.586" />
                        <circle cx="11" cy="11" r="2" />
                    </svg>
                    <span className="font-semibold text-sm">Excalidraw Whiteboard</span>
                </div>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={onDiscard}
                        className="px-3 py-1.5 text-sm border border-border rounded-md bg-background hover:bg-muted transition-colors text-foreground"
                    >
                        Discard
                    </button>
                    <button
                        type="button"
                        onClick={handleSave}
                        disabled={isSaving || !hasChanges}
                        className="px-3 py-1.5 text-sm rounded-md bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                        {isSaving ? 'Saving…' : 'Save & close'}
                    </button>
                </div>
            </div>

            {/* Drawing canvas */}
            <div className="flex-1 relative overflow-hidden">
                <Suspense fallback={
                    <div className="absolute inset-0 flex items-center justify-center bg-muted">
                        <div className="text-center">
                            <div className="w-10 h-10 border-4 border-primary border-t-transparent rounded-full animate-spin mx-auto mb-3" />
                            <p className="text-sm text-muted-foreground">Loading Excalidraw…</p>
                        </div>
                    </div>
                }>
                    <div className="absolute inset-0">
                        <Excalidraw
                            excalidrawAPI={(api: any) => { excalidrawApiRef.current = api; }}
                            initialData={{
                                elements: initialElements,
                                appState: initialAppState,
                                scrollToContent: true,
                            }}
                            onChange={handleChange}
                        />
                    </div>
                </Suspense>
            </div>
        </div>,
        document.body
    );
}

// ─── Thumbnail preview component ─────────────────────────────────────────────

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
    const [appState, setAppState] = useState<any>({});
    const [previewDataUrl, setPreviewDataUrl] = useState<string | null>(null);

    useEffect(() => {
        if (data) {
            try {
                const parsed = JSON.parse(data);
                setElements(parsed.elements || []);
                setAppState(parsed.appState || {});
                setPreviewDataUrl(parsed.previewDataUrl || null);
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
            editor.registerCommand(KEY_DELETE_COMMAND, onDelete, COMMAND_PRIORITY_LOW),
            editor.registerCommand(KEY_BACKSPACE_COMMAND, onDelete, COMMAND_PRIORITY_LOW)
        );
    }, [clearSelection, editor, isSelected, nodeKey, onDelete, setSelected]);

    const handleSave = useCallback((newData: string, newPreviewDataUrl: string) => {
        try {
            const parsed = JSON.parse(newData);
            setElements(parsed.elements || []);
            setAppState(parsed.appState || {});
        } catch { /* ignore */ }
        setPreviewDataUrl(newPreviewDataUrl);

        editor.update(() => {
            const node = $getNodeByKey(nodeKey);
            if ($isExcalidrawNode(node)) {
                node.setData(newData);
            }
        });

        setIsEditing(false);
    }, [editor, nodeKey]);

    return (
        <>
            <div ref={containerRef} className="relative my-4" draggable="false">
                {/* Thumbnail */}
                <div
                    className={`relative rounded-lg overflow-hidden border bg-card dark:bg-card cursor-pointer hover:border-purple-400 transition-colors group ${isSelected ? 'ring-2 ring-purple-500 ring-offset-2' : ''}`}
                    style={{ minHeight: '180px' }}
                    onClick={() => setIsEditing(true)}
                >
                    {previewDataUrl ? (
                        <>
                            <img
                                src={previewDataUrl}
                                alt="Excalidraw drawing preview"
                                className="w-full object-contain bg-background dark:bg-card block"
                                style={{ maxHeight: '400px' }}
                            />
                            {/* Edit overlay on hover */}
                            <div className="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity bg-black/10">
                                <span className="px-3 py-1.5 bg-card rounded-md shadow text-sm font-medium text-foreground flex items-center gap-1.5">
                                    <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                    </svg>
                                    Edit drawing
                                </span>
                            </div>
                        </>
                    ) : (
                        <div className="w-full flex items-center justify-center bg-gradient-to-br from-purple-50 to-blue-50" style={{ minHeight: '180px' }}>
                            <div className="text-center text-muted-foreground">
                                <svg className="w-14 h-14 mx-auto mb-3 text-purple-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                                    <path d="M12 19l7-7 3 3-7 7-3-3z" />
                                    <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z" />
                                    <path d="M2 2l7.586 7.586" />
                                    <circle cx="11" cy="11" r="2" />
                                </svg>
                                {elements.length > 0 ? (
                                    <>
                                        <p className="text-sm font-medium text-foreground">Click to edit drawing</p>
                                        <p className="text-xs text-muted-foreground mt-1">{elements.length} element(s) — save to generate preview</p>
                                    </>
                                ) : (
                                    <>
                                        <p className="text-sm font-medium text-foreground">Click to start drawing</p>
                                        <p className="text-xs text-muted-foreground mt-1">Opens full-screen Excalidraw whiteboard</p>
                                    </>
                                )}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <ExcalidrawFullEditor
                isOpen={isEditing}
                initialElements={elements}
                initialAppState={appState}
                onSave={handleSave}
                onDiscard={() => setIsEditing(false)}
            />
        </>
    );
}

// ─── Lexical node ─────────────────────────────────────────────────────────────

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
        element.setAttribute('data-lexical-excalidraw', this.__data);
        // No inner HTML — prevents Lexical from creating stray paragraph nodes on re-import
        return { element };
    }

    static importDOM(): DOMConversionMap | null {
        return {
            div: (node: Node) => {
                if (!(node instanceof HTMLElement)) return null;
                if (!node.hasAttribute('data-lexical-excalidraw')) return null;
                return {
                    conversion: (domNode: Node): DOMConversionOutput | null => {
                        if (!(domNode instanceof HTMLElement)) return null;
                        const data = domNode.getAttribute('data-lexical-excalidraw');
                        if (data === null) return null;
                        return { node: $createExcalidrawNode(data) };
                    },
                    priority: 2,
                };
            },
        };
    }

    setData(data: string): void {
        const writable = this.getWritable();
        writable.__data = data;
    }

    getData(): string {
        return this.__data;
    }

    createDOM(_config: EditorConfig): HTMLElement {
        const div = document.createElement('div');
        div.className = 'excalidraw-container';
        return div;
    }

    updateDOM(): false {
        return false;
    }

    decorate(): JSX.Element {
        return <ExcalidrawComponent data={this.__data} nodeKey={this.getKey()} />;
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
