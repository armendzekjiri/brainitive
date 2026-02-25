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

export interface StickyNotePayload {
    color: string;
    text: string;
    x?: number;
    y?: number;
    key?: NodeKey;
}

export type SerializedStickyNoteNode = Spread<
    {
        color: string;
        text: string;
        x: number;
        y: number;
    },
    SerializedLexicalNode
>;

const STICKY_COLORS = [
    { name: 'yellow', bg: 'bg-yellow-100', border: 'border-yellow-300', header: 'bg-yellow-200' },
    { name: 'pink', bg: 'bg-pink-100', border: 'border-pink-300', header: 'bg-pink-200' },
    { name: 'blue', bg: 'bg-blue-100', border: 'border-blue-300', header: 'bg-blue-200' },
    { name: 'green', bg: 'bg-green-100', border: 'border-green-300', header: 'bg-green-200' },
    { name: 'purple', bg: 'bg-purple-100', border: 'border-purple-300', header: 'bg-purple-200' },
];

function StickyNoteComponent({
    color,
    text,
    nodeKey,
}: {
    color: string;
    text: string;
    nodeKey: NodeKey;
}) {
    const [editor] = useLexicalComposerContext();
    const containerRef = useRef<HTMLDivElement>(null);
    const [isSelected, setSelected, clearSelection] = useLexicalNodeSelection(nodeKey);
    const [isEditing, setIsEditing] = useState(false);
    const [editText, setEditText] = useState(text);
    const [currentColor, setCurrentColor] = useState(color);
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    const colorConfig = STICKY_COLORS.find(c => c.name === currentColor) || STICKY_COLORS[0];

    const onDelete = useCallback(
        (payload: KeyboardEvent) => {
            if (isSelected && $isNodeSelection($getSelection()) && !isEditing) {
                const event: KeyboardEvent = payload;
                event.preventDefault();
                const node = $getNodeByKey(nodeKey);
                if ($isStickyNoteNode(node)) {
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
                        if (!isEditing) {
                            if (event.shiftKey) {
                                setSelected(!isSelected);
                            } else {
                                clearSelection();
                                setSelected(true);
                            }
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
    }, [clearSelection, editor, isSelected, nodeKey, onDelete, setSelected, isEditing]);

    useEffect(() => {
        if (isEditing && textareaRef.current) {
            textareaRef.current.focus();
        }
    }, [isEditing]);

    // Block keyboard events from reaching Lexical's root element listeners while editing.
    // Lexical attaches its keydown handler to the contentEditable root in the bubble phase
    // and calls preventDefault() on arrow keys for cursor management, which prevents the
    // textarea from moving its own cursor. The fix: register a bubble-phase listener on
    // the sticky note container div. It fires as the event bubbles UP from the textarea
    // but before it reaches the Lexical root, so stopPropagation() here ensures Lexical
    // never sees it while the textarea handles it natively first.
    useEffect(() => {
        if (!isEditing) return;
        const container = containerRef.current;
        if (!container) return;

        const blockForLexical = (e: KeyboardEvent) => {
            if (e.target instanceof HTMLTextAreaElement || e.target instanceof HTMLInputElement) {
                e.stopPropagation();
                if (e.key === 'Escape') {
                    setEditText(text);
                    setIsEditing(false);
                }
            }
        };

        container.addEventListener('keydown', blockForLexical);
        return () => container.removeEventListener('keydown', blockForLexical);
    }, [isEditing, text]);

    const handleSave = () => {
        editor.update(() => {
            const node = $getNodeByKey(nodeKey);
            if ($isStickyNoteNode(node)) {
                node.setText(editText);
                node.setColor(currentColor);
            }
        });
        setIsEditing(false);
    };

    const handleColorChange = (newColor: string) => {
        setCurrentColor(newColor);
        editor.update(() => {
            const node = $getNodeByKey(nodeKey);
            if ($isStickyNoteNode(node)) {
                node.setColor(newColor);
            }
        });
    };

    return (
        <div
            ref={containerRef}
            className={`relative inline-block w-64 rounded-lg shadow-lg ${colorConfig.bg} ${colorConfig.border} border-2 ${isSelected ? 'ring-2 ring-primary ring-offset-2' : ''}`}
            draggable="false"
        >
            {/* Header with color picker */}
            <div className={`${colorConfig.header} px-3 py-2 rounded-t-md flex justify-between items-center`}>
                <span className="text-xs font-medium text-gray-700">Sticky Note</span>
                <div className="flex gap-1">
                    {STICKY_COLORS.map(c => (
                        <button
                            key={c.name}
                            onClick={() => handleColorChange(c.name)}
                            className={`w-4 h-4 rounded-full ${c.bg} ${c.border} border ${currentColor === c.name ? 'ring-2 ring-gray-600 ring-offset-1' : ''}`}
                            title={c.name}
                        />
                    ))}
                </div>
            </div>

            {/* Content */}
            <div className="p-3 min-h-[100px]">
                {isEditing ? (
                    <div>
                        <textarea
                            ref={textareaRef}
                            value={editText}
                            onChange={(e) => setEditText(e.target.value)}
                            className="w-full min-h-[80px] bg-transparent border-none resize-none focus:outline-none text-sm"
                            placeholder="Write your note..."
                        />
                        <div className="flex justify-end gap-1 mt-2">
                            <button
                                onClick={() => {
                                    setEditText(text);
                                    setIsEditing(false);
                                }}
                                className="px-2 py-1 text-xs bg-white/50 rounded"
                            >
                                Cancel
                            </button>
                            <button
                                onClick={handleSave}
                                className="px-2 py-1 text-xs bg-gray-800 text-white rounded"
                            >
                                Save
                            </button>
                        </div>
                    </div>
                ) : (
                    <div
                        className="text-sm whitespace-pre-wrap cursor-pointer min-h-[60px]"
                        onDoubleClick={() => setIsEditing(true)}
                        title="Double-click to edit"
                    >
                        {text || <span className="text-gray-400 italic">Double-click to add text...</span>}
                    </div>
                )}
            </div>
        </div>
    );
}

export class StickyNoteNode extends DecoratorNode<JSX.Element> {
    __color: string;
    __text: string;
    __x: number;
    __y: number;

    static getType(): string {
        return 'sticky-note';
    }

    static clone(node: StickyNoteNode): StickyNoteNode {
        return new StickyNoteNode(node.__color, node.__text, node.__x, node.__y, node.__key);
    }

    static importJSON(serializedNode: SerializedStickyNoteNode): StickyNoteNode {
        return $createStickyNoteNode(serializedNode.color, serializedNode.text);
    }

    constructor(
        color: string = 'yellow',
        text: string = '',
        x: number = 0,
        y: number = 0,
        key?: NodeKey
    ) {
        super(key);
        this.__color = color;
        this.__text = text;
        this.__x = x;
        this.__y = y;
    }

    exportJSON(): SerializedStickyNoteNode {
        return {
            type: 'sticky-note',
            version: 1,
            color: this.__color,
            text: this.__text,
            x: this.__x,
            y: this.__y,
        };
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement('div');
        element.setAttribute('data-lexical-sticky-note', JSON.stringify({
            color: this.__color,
            text: this.__text,
            x: this.__x,
            y: this.__y,
        }));
        return { element };
    }

    static importDOM(): DOMConversionMap | null {
        return {
            div: (node: Node) => {
                if (!(node instanceof HTMLElement)) return null;
                if (!node.hasAttribute('data-lexical-sticky-note')) return null;
                return {
                    conversion: (domNode: Node): DOMConversionOutput | null => {
                        if (!(domNode instanceof HTMLElement)) return null;
                        const raw = domNode.getAttribute('data-lexical-sticky-note');
                        if (!raw) return null;
                        try {
                            const { color, text, x, y } = JSON.parse(raw);
                            return { node: $createStickyNoteNode({ color, text, x, y }) };
                        } catch {
                            return null;
                        }
                    },
                    priority: 2,
                };
            },
        };
    }

    setColor(color: string): void {
        const writable = this.getWritable();
        writable.__color = color;
    }

    setText(text: string): void {
        const writable = this.getWritable();
        writable.__text = text;
    }

    getColor(): string {
        return this.__color;
    }

    getText(): string {
        return this.__text;
    }

    createDOM(config: EditorConfig): HTMLElement {
        const div = document.createElement('div');
        div.className = 'sticky-note-container inline-block';
        return div;
    }

    updateDOM(): false {
        return false;
    }

    decorate(): JSX.Element {
        return (
            <StickyNoteComponent
                color={this.__color}
                text={this.__text}
                nodeKey={this.getKey()}
            />
        );
    }
}

export function $createStickyNoteNode(color: string = 'yellow', text: string = ''): StickyNoteNode {
    return $applyNodeReplacement(new StickyNoteNode(color, text));
}

export function $isStickyNoteNode(
    node: LexicalNode | null | undefined
): node is StickyNoteNode {
    return node instanceof StickyNoteNode;
}
