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
import { useCallback, useEffect, useRef, useState } from 'react';
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

export interface PollOption {
    uid: string;
    text: string;
    votes: string[];
}

export interface PollPayload {
    question: string;
    options: PollOption[];
    key?: NodeKey;
}

export type SerializedPollNode = Spread<
    {
        question: string;
        options: PollOption[];
    },
    SerializedLexicalNode
>;

function generateUID(): string {
    return Math.random().toString(36).substring(2, 9);
}

function PollComponent({
    question,
    options,
    nodeKey,
}: {
    question: string;
    options: PollOption[];
    nodeKey: NodeKey;
}) {
    const [editor] = useLexicalComposerContext();
    const containerRef = useRef<HTMLDivElement>(null);
    const [isSelected, setSelected, clearSelection] = useLexicalNodeSelection(nodeKey);
    const [isEditing, setIsEditing] = useState(false);
    const [editQuestion, setEditQuestion] = useState(question);
    const [editOptions, setEditOptions] = useState<PollOption[]>(options);
    const [votedOption, setVotedOption] = useState<string | null>(null);

    const totalVotes = options.reduce((sum, opt) => sum + opt.votes.length, 0);

    const onDelete = useCallback(
        (payload: KeyboardEvent) => {
            if (isSelected && $isNodeSelection($getSelection()) && !isEditing) {
                const event: KeyboardEvent = payload;
                event.preventDefault();
                const node = $getNodeByKey(nodeKey);
                if ($isPollNode(node)) {
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

    const handleVote = (optionUid: string) => {
        if (votedOption) return; // Already voted

        setVotedOption(optionUid);
        editor.update(() => {
            const node = $getNodeByKey(nodeKey);
            if ($isPollNode(node)) {
                const newOptions = options.map(opt =>
                    opt.uid === optionUid
                        ? { ...opt, votes: [...opt.votes, 'user'] }
                        : opt
                );
                node.setOptions(newOptions);
            }
        });
    };

    const handleSave = () => {
        editor.update(() => {
            const node = $getNodeByKey(nodeKey);
            if ($isPollNode(node)) {
                node.setQuestion(editQuestion);
                node.setOptions(editOptions.filter(opt => opt.text.trim()));
            }
        });
        setIsEditing(false);
    };

    const addOption = () => {
        setEditOptions([...editOptions, { uid: generateUID(), text: '', votes: [] }]);
    };

    const removeOption = (uid: string) => {
        setEditOptions(editOptions.filter(opt => opt.uid !== uid));
    };

    const updateOptionText = (uid: string, text: string) => {
        setEditOptions(editOptions.map(opt =>
            opt.uid === uid ? { ...opt, text } : opt
        ));
    };

    if (isEditing) {
        return (
            <div
                ref={containerRef}
                className={`my-4 p-4 border rounded-lg bg-muted/30 ${isSelected ? 'ring-2 ring-primary ring-offset-2' : ''}`}
            >
                <div className="space-y-3">
                    <div>
                        <label className="text-sm font-medium mb-1 block">Question</label>
                        <input
                            type="text"
                            value={editQuestion}
                            onChange={(e) => setEditQuestion(e.target.value)}
                            className="w-full px-3 py-2 border rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            placeholder="Ask a question..."
                        />
                    </div>
                    <div>
                        <label className="text-sm font-medium mb-1 block">Options</label>
                        <div className="space-y-2">
                            {editOptions.map((opt, index) => (
                                <div key={opt.uid} className="flex gap-2">
                                    <input
                                        type="text"
                                        value={opt.text}
                                        onChange={(e) => updateOptionText(opt.uid, e.target.value)}
                                        className="flex-1 px-3 py-2 border rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                        placeholder={`Option ${index + 1}`}
                                    />
                                    {editOptions.length > 2 && (
                                        <button
                                            onClick={() => removeOption(opt.uid)}
                                            className="px-2 text-red-500 hover:bg-red-50 rounded"
                                        >
                                            ×
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                        <button
                            onClick={addOption}
                            className="mt-2 text-sm text-primary hover:underline"
                        >
                            + Add option
                        </button>
                    </div>
                    <div className="flex justify-end gap-2">
                        <button
                            onClick={() => {
                                setEditQuestion(question);
                                setEditOptions(options);
                                setIsEditing(false);
                            }}
                            className="px-3 py-1.5 text-sm bg-muted rounded-md"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={handleSave}
                            className="px-3 py-1.5 text-sm bg-primary text-primary-foreground rounded-md"
                        >
                            Save Poll
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div
            ref={containerRef}
            className={`my-4 p-4 border rounded-lg bg-muted/30 ${isSelected ? 'ring-2 ring-primary ring-offset-2' : ''}`}
            draggable="false"
        >
            <div className="flex justify-between items-start mb-3">
                <h4 className="font-medium">{question || 'Poll Question'}</h4>
                <button
                    onClick={() => setIsEditing(true)}
                    className="text-xs text-muted-foreground hover:text-foreground"
                >
                    Edit
                </button>
            </div>
            <div className="space-y-2">
                {options.map((opt) => {
                    const percentage = totalVotes > 0 ? (opt.votes.length / totalVotes) * 100 : 0;
                    const isVoted = votedOption === opt.uid;

                    return (
                        <button
                            key={opt.uid}
                            onClick={() => handleVote(opt.uid)}
                            disabled={!!votedOption}
                            className={`w-full relative overflow-hidden text-left px-3 py-2 border rounded-md transition-all ${
                                isVoted ? 'border-primary bg-primary/5' : 'hover:border-primary/50'
                            } ${votedOption ? 'cursor-default' : 'cursor-pointer'}`}
                        >
                            {votedOption && (
                                <div
                                    className="absolute inset-0 bg-primary/10 transition-all"
                                    style={{ width: `${percentage}%` }}
                                />
                            )}
                            <div className="relative flex justify-between items-center">
                                <span className="text-sm">{opt.text}</span>
                                {votedOption && (
                                    <span className="text-xs text-muted-foreground">
                                        {opt.votes.length} vote{opt.votes.length !== 1 ? 's' : ''} ({Math.round(percentage)}%)
                                    </span>
                                )}
                            </div>
                        </button>
                    );
                })}
            </div>
            {totalVotes > 0 && (
                <p className="text-xs text-muted-foreground mt-2">
                    {totalVotes} total vote{totalVotes !== 1 ? 's' : ''}
                </p>
            )}
        </div>
    );
}

export class PollNode extends DecoratorNode<JSX.Element> {
    __question: string;
    __options: PollOption[];

    static getType(): string {
        return 'poll';
    }

    static clone(node: PollNode): PollNode {
        return new PollNode(node.__question, node.__options, node.__key);
    }

    static importJSON(serializedNode: SerializedPollNode): PollNode {
        return $createPollNode(serializedNode.question, serializedNode.options);
    }

    constructor(
        question: string = 'What do you think?',
        options: PollOption[] = [
            { uid: generateUID(), text: 'Option 1', votes: [] },
            { uid: generateUID(), text: 'Option 2', votes: [] },
        ],
        key?: NodeKey
    ) {
        super(key);
        this.__question = question;
        this.__options = options;
    }

    exportJSON(): SerializedPollNode {
        return {
            type: 'poll',
            version: 1,
            question: this.__question,
            options: this.__options,
        };
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement('div');
        element.className = 'poll';
        element.innerHTML = `<p><strong>${this.__question}</strong></p><ul>${this.__options.map(o => `<li>${o.text}</li>`).join('')}</ul>`;
        return { element };
    }

    static importDOM(): null {
        return null;
    }

    setQuestion(question: string): void {
        const writable = this.getWritable();
        writable.__question = question;
    }

    setOptions(options: PollOption[]): void {
        const writable = this.getWritable();
        writable.__options = options;
    }

    getQuestion(): string {
        return this.__question;
    }

    getOptions(): PollOption[] {
        return this.__options;
    }

    createDOM(config: EditorConfig): HTMLElement {
        const div = document.createElement('div');
        div.className = 'poll-container';
        return div;
    }

    updateDOM(): false {
        return false;
    }

    decorate(): JSX.Element {
        return (
            <PollComponent
                question={this.__question}
                options={this.__options}
                nodeKey={this.getKey()}
            />
        );
    }
}

export function $createPollNode(
    question: string = 'What do you think?',
    options?: PollOption[]
): PollNode {
    return $applyNodeReplacement(new PollNode(question, options));
}

export function $isPollNode(
    node: LexicalNode | null | undefined
): node is PollNode {
    return node instanceof PollNode;
}
