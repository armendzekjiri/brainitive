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

// KaTeX CSS from our own build, loaded with the first equation (no CDN request — BRAI-156)
let katexCss: Promise<unknown> | null = null;
const loadKatexCSS = () => {
    katexCss ??= import('katex/dist/katex.min.css');
};

export interface EquationPayload {
    equation: string;
    inline: boolean;
    key?: NodeKey;
}

export type SerializedEquationNode = Spread<
    {
        equation: string;
        inline: boolean;
    },
    SerializedLexicalNode
>;

function EquationComponent({
    equation,
    inline,
    nodeKey,
}: {
    equation: string;
    inline: boolean;
    nodeKey: NodeKey;
}) {
    const [editor] = useLexicalComposerContext();
    const containerRef = useRef<HTMLDivElement>(null);
    const [isSelected, setSelected, clearSelection] = useLexicalNodeSelection(nodeKey);
    const [isEditing, setIsEditing] = useState(false);
    const [editValue, setEditValue] = useState(equation);
    const [renderedHtml, setRenderedHtml] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        loadKatexCSS();
    }, []);

    useEffect(() => {
        // Dynamically import katex to render the equation
        import('katex').then((katex) => {
            try {
                const html = katex.default.renderToString(equation, {
                    throwOnError: false,
                    displayMode: !inline,
                });
                setRenderedHtml(html);
            } catch (e) {
                setRenderedHtml(`<span class="text-red-500">Invalid equation</span>`);
            }
        });
    }, [equation, inline]);

    const onDelete = useCallback(
        (payload: KeyboardEvent) => {
            if (isSelected && $isNodeSelection($getSelection()) && !isEditing) {
                const event: KeyboardEvent = payload;
                event.preventDefault();
                const node = $getNodeByKey(nodeKey);
                if ($isEquationNode(node)) {
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
        if (isEditing && inputRef.current) {
            inputRef.current.focus();
        }
    }, [isEditing]);

    const handleSave = () => {
        editor.update(() => {
            const node = $getNodeByKey(nodeKey);
            if ($isEquationNode(node)) {
                node.setEquation(editValue);
            }
        });
        setIsEditing(false);
    };

    if (isEditing) {
        return (
            <span className={inline ? 'inline-flex items-center gap-1' : 'flex flex-col gap-2 my-2'}>
                <input
                    ref={inputRef}
                    type="text"
                    value={editValue}
                    onChange={(e) => setEditValue(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            handleSave();
                        }
                        if (e.key === 'Escape') {
                            setEditValue(equation);
                            setIsEditing(false);
                        }
                    }}
                    className="px-2 py-1 border rounded text-sm font-mono focus:outline-none focus:ring-2 focus:ring-primary"
                    placeholder="Enter LaTeX equation..."
                />
                <span className="flex gap-1">
                    <button
                        onClick={handleSave}
                        className="px-2 py-1 text-xs bg-primary text-primary-foreground rounded"
                    >
                        Save
                    </button>
                    <button
                        onClick={() => {
                            setEditValue(equation);
                            setIsEditing(false);
                        }}
                        className="px-2 py-1 text-xs bg-muted rounded"
                    >
                        Cancel
                    </button>
                </span>
            </span>
        );
    }

    return (
        <span
            ref={containerRef}
            className={`cursor-pointer ${inline ? 'inline' : 'block my-2 text-center'} ${isSelected ? 'ring-2 ring-primary ring-offset-1 rounded' : ''}`}
            onDoubleClick={() => setIsEditing(true)}
            title="Double-click to edit"
        >
            <span dangerouslySetInnerHTML={{ __html: renderedHtml }} />
        </span>
    );
}

export class EquationNode extends DecoratorNode<JSX.Element> {
    __equation: string;
    __inline: boolean;

    static getType(): string {
        return 'equation';
    }

    static clone(node: EquationNode): EquationNode {
        return new EquationNode(node.__equation, node.__inline, node.__key);
    }

    static importJSON(serializedNode: SerializedEquationNode): EquationNode {
        return $createEquationNode(serializedNode.equation, serializedNode.inline);
    }

    constructor(equation: string, inline: boolean = true, key?: NodeKey) {
        super(key);
        this.__equation = equation;
        this.__inline = inline;
    }

    exportJSON(): SerializedEquationNode {
        return {
            type: 'equation',
            version: 1,
            equation: this.__equation,
            inline: this.__inline,
        };
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement(this.__inline ? 'span' : 'div');
        element.className = 'equation';
        element.setAttribute('data-equation', this.__equation);
        element.setAttribute('data-inline', String(this.__inline));
        return { element };
    }

    static importDOM(): null {
        return null;
    }

    setEquation(equation: string): void {
        const writable = this.getWritable();
        writable.__equation = equation;
    }

    getEquation(): string {
        return this.__equation;
    }

    isInline(): boolean {
        return this.__inline;
    }

    createDOM(config: EditorConfig): HTMLElement {
        const element = document.createElement(this.__inline ? 'span' : 'div');
        element.className = 'equation-container';
        return element;
    }

    updateDOM(): false {
        return false;
    }

    decorate(): JSX.Element {
        return (
            <EquationComponent
                equation={this.__equation}
                inline={this.__inline}
                nodeKey={this.getKey()}
            />
        );
    }
}

export function $createEquationNode(equation: string, inline: boolean = true): EquationNode {
    return $applyNodeReplacement(new EquationNode(equation, inline));
}

export function $isEquationNode(
    node: LexicalNode | null | undefined
): node is EquationNode {
    return node instanceof EquationNode;
}
