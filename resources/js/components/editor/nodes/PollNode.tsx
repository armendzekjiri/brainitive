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
import axios from 'axios';
import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
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
        pollId?: string;
    },
    SerializedLexicalNode
>;

/**
 * Where the poll lives (set by the editor host): votes are stored per doc / issue on the
 * server, so readers can vote without editing the document. Null = voting not available
 * (e.g. comments, ticket replies).
 */
export const PollScopeContext = createContext<{ type: 'page' | 'item'; id: string } | null>(null);

interface PollResults {
    counts: Record<string, number>;
    total: number;
    my_vote: string | null;
}

/** Polls saved before pollId existed: derive a stable id from their option uids. */
function stablePollId(pollId: string | undefined, options: PollOption[]): string {
    return pollId || `o-${options.map((o) => o.uid).join('-')}`.slice(0, 120);
}

function generateUID(): string {
    return Math.random().toString(36).substring(2, 9);
}

function escapeHtml(text: string): string {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function $convertPollElement(domNode: Node): DOMConversionOutput | null {
    const el = domNode as HTMLElement;
    const stateRaw = el.getAttribute('data-poll-state');
    if (!stateRaw) return null;
    try {
        const { question, options, pollId } = JSON.parse(stateRaw) as { question: string; options: PollOption[]; pollId?: string };
        const node = $createPollNode(question ?? 'What do you think?', options, pollId);
        return { node };
    } catch {
        return null;
    }
}

function PollComponent({
    question,
    options,
    pollId,
    nodeKey,
}: {
    question: string;
    options: PollOption[];
    pollId: string;
    nodeKey: NodeKey;
}) {
    const [editor] = useLexicalComposerContext();
    const containerRef = useRef<HTMLDivElement>(null);
    const [isSelected, setSelected, clearSelection] = useLexicalNodeSelection(nodeKey);
    const [isEditing, setIsEditing] = useState(false);
    const [editQuestion, setEditQuestion] = useState(question);
    const [editOptions, setEditOptions] = useState<PollOption[]>(options);
    const scope = useContext(PollScopeContext);
    const [results, setResults] = useState<PollResults | null>(null);
    const [voting, setVoting] = useState(false);
    const [voteError, setVoteError] = useState<string | null>(null);
    const [editable, setEditable] = useState(editor.isEditable());

    useEffect(() => editor.registerEditableListener(setEditable), [editor]);

    const pollUrl = scope ? `/polls/${scope.type}/${encodeURIComponent(scope.id)}/${encodeURIComponent(pollId)}` : null;

    useEffect(() => {
        if (!pollUrl) return;
        let cancelled = false;
        axios
            .get<PollResults>(pollUrl)
            .then(({ data }) => !cancelled && setResults(data))
            .catch(() => !cancelled && setVoteError('Couldn’t load the votes.'));
        return () => {
            cancelled = true;
        };
    }, [pollUrl]);

    const votedOption = results?.my_vote && options.some((o) => o.uid === results.my_vote) ? results.my_vote : null;
    const countFor = (uid: string) => results?.counts?.[uid] ?? 0;
    const totalVotes = options.reduce((sum, opt) => sum + countFor(opt.uid), 0);

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
                    const target = event.target as HTMLElement;
                    if (target && (target.closest('button') || target.closest('input'))) {
                        return false;
                    }
                    if (containerRef.current && containerRef.current.contains(target as Node)) {
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

    /** Vote, change the vote (another option) or take it back (same option again). */
    const handleVote = (optionUid: string) => {
        if (!pollUrl || voting) return;
        setVoting(true);
        setVoteError(null);
        axios
            .post<PollResults>(pollUrl, { option_uid: votedOption === optionUid ? null : optionUid })
            .then(({ data }) => setResults(data))
            .catch(() => setVoteError('Your vote wasn’t saved. Try again.'))
            .finally(() => setVoting(false));
    };

    const handleSave = () => {
        editor.update(() => {
            const node = $getNodeByKey(nodeKey);
            if ($isPollNode(node)) {
                node.setQuestion(editQuestion);
                // Votes live on the server now; never store them in the document.
                node.setOptions(editOptions.filter(opt => opt.text.trim()).map(opt => ({ ...opt, votes: [] })));
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
                                            type="button"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                e.stopPropagation();
                                                removeOption(opt.uid);
                                            }}
                                            className="px-2 text-red-500 hover:bg-red-50 rounded"
                                        >
                                            ×
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                        <button
                            type="button"
                            onClick={(e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                addOption();
                            }}
                            className="mt-2 text-sm text-primary hover:underline"
                        >
                            + Add option
                        </button>
                    </div>
                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={(e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                setEditQuestion(question);
                                setEditOptions(options);
                                setIsEditing(false);
                            }}
                            className="px-3 py-1.5 text-sm bg-muted rounded-md"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={(e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                handleSave();
                            }}
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
                {editable && (
                <button
                    type="button"
                    onClick={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        setIsEditing(true);
                    }}
                    className="text-xs text-muted-foreground hover:text-foreground"
                >
                    Edit
                </button>
                )}
            </div>
            <div className="space-y-2">
                {options.map((opt) => {
                    const count = countFor(opt.uid);
                    const percentage = totalVotes > 0 ? (count / totalVotes) * 100 : 0;
                    const isVoted = votedOption === opt.uid;
                    const showResults = !!votedOption;

                    return (
                        <button
                            key={opt.uid}
                            type="button"
                            onClick={(e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                handleVote(opt.uid);
                            }}
                            disabled={!pollUrl || voting}
                            title={isVoted ? 'Click again to take your vote back' : undefined}
                            className={`w-full relative overflow-hidden text-left px-3 py-2 border rounded-md transition-all ${
                                isVoted ? 'border-primary bg-primary/5' : 'hover:border-primary/50'
                            } ${pollUrl ? 'cursor-pointer' : 'cursor-default'}`}
                        >
                            {showResults && (
                                <div
                                    className="absolute inset-0 bg-primary/10 transition-all"
                                    style={{ width: `${percentage}%` }}
                                />
                            )}
                            <div className="relative flex justify-between items-center">
                                <span className="text-sm">
                                    {opt.text}
                                    {isVoted && <span className="ml-2 text-xs text-primary">Your vote</span>}
                                </span>
                                {showResults && (
                                    <span className="text-xs text-muted-foreground">
                                        {count} vote{count !== 1 ? 's' : ''} ({Math.round(percentage)}%)
                                    </span>
                                )}
                            </div>
                        </button>
                    );
                })}
            </div>
            <p className="text-xs text-muted-foreground mt-2">
                {!pollUrl
                    ? 'Voting works in docs and issue descriptions.'
                    : votedOption
                      ? `${totalVotes} vote${totalVotes !== 1 ? 's' : ''} · click another option to change your vote`
                      : totalVotes > 0
                        ? `${totalVotes} vote${totalVotes !== 1 ? 's' : ''} · vote to see the results`
                        : 'No votes yet'}
            </p>
            {voteError && <p className="text-xs text-destructive mt-1">{voteError}</p>}
        </div>
    );
}

export class PollNode extends DecoratorNode<JSX.Element> {
    __question: string;
    __options: PollOption[];
    __pollId: string;

    static getType(): string {
        return 'poll';
    }

    static clone(node: PollNode): PollNode {
        return new PollNode(node.__question, node.__options, node.__key, node.__pollId);
    }

    static importJSON(serializedNode: SerializedPollNode): PollNode {
        return $createPollNode(serializedNode.question, serializedNode.options, serializedNode.pollId);
    }

    constructor(
        question: string = 'What do you think?',
        options: PollOption[] = [
            { uid: generateUID(), text: 'Option 1', votes: [] },
            { uid: generateUID(), text: 'Option 2', votes: [] },
        ],
        key?: NodeKey,
        pollId?: string,
    ) {
        super(key);
        this.__question = question;
        this.__options = options;
        this.__pollId = stablePollId(pollId, options);
    }

    exportJSON(): SerializedPollNode {
        return {
            type: 'poll',
            version: 1,
            question: this.__question,
            options: this.__options,
            pollId: this.__pollId,
        };
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement('div');
        element.setAttribute('data-lexical-poll', 'true');
        element.setAttribute(
            'data-poll-state',
            JSON.stringify({ question: this.__question, options: this.__options, pollId: this.__pollId })
        );
        element.className = 'poll';
        // Fallback for non-Lexical viewers
        element.innerHTML = `<p><strong>${escapeHtml(this.__question)}</strong></p><ul>${this.__options.map(o => `<li>${escapeHtml(o.text)}</li>`).join('')}</ul>`;
        return { element };
    }

    static importDOM(): DOMConversionMap | null {
        return {
            div: (domNode: Node) => {
                const el = domNode as HTMLElement;
                if (el.getAttribute('data-lexical-poll') !== 'true') return null;
                return { conversion: $convertPollElement, priority: 1 };
            },
        };
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
                pollId={this.__pollId}
                nodeKey={this.getKey()}
            />
        );
    }
}

export function $createPollNode(
    question: string = 'What do you think?',
    options?: PollOption[],
    pollId?: string,
): PollNode {
    // New polls get their own id; old ones derive it from their option uids.
    return $applyNodeReplacement(new PollNode(question, options, undefined, pollId ?? (options ? undefined : `p-${generateUID()}${generateUID()}`)));
}

export function $isPollNode(
    node: LexicalNode | null | undefined
): node is PollNode {
    return node instanceof PollNode;
}
