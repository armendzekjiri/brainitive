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

/** Tweet ids are numbers — anything else (pasted / imported JSON) is dropped. */
function safeTweetId(id: unknown): string {
    const value = String(id ?? '');
    return /^\d{1,25}$/.test(value) ? value : '';
}
import {
    $getNodeByKey,
    $getSelection,
    $isNodeSelection,
    CLICK_COMMAND,
    COMMAND_PRIORITY_LOW,
    KEY_BACKSPACE_COMMAND,
    KEY_DELETE_COMMAND,
} from 'lexical';

export interface TweetPayload {
    tweetID: string;
    key?: NodeKey;
}

export type SerializedTweetNode = Spread<
    {
        tweetID: string;
    },
    SerializedLexicalNode
>;

function TweetComponent({
    tweetID,
    nodeKey,
}: {
    tweetID: string;
    nodeKey: NodeKey;
}) {
    const [editor] = useLexicalComposerContext();
    const containerRef = useRef<HTMLDivElement>(null);
    const [isSelected, setSelected, clearSelection] = useLexicalNodeSelection(nodeKey);
    const [isLoading, setIsLoading] = useState(true);

    const onDelete = useCallback(
        (payload: KeyboardEvent) => {
            if (isSelected && $isNodeSelection($getSelection())) {
                const event: KeyboardEvent = payload;
                event.preventDefault();
                const node = $getNodeByKey(nodeKey);
                if ($isTweetNode(node)) {
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

    // Load Twitter widget script
    useEffect(() => {
        const script = document.getElementById('twitter-widget');
        if (!script) {
            const newScript = document.createElement('script');
            newScript.id = 'twitter-widget';
            newScript.src = 'https://platform.twitter.com/widgets.js';
            newScript.async = true;
            document.body.appendChild(newScript);
            newScript.onload = () => {
                // @ts-ignore
                if (window.twttr) {
                    // @ts-ignore
                    window.twttr.widgets.load(containerRef.current);
                    setIsLoading(false);
                }
            };
        } else {
            // @ts-ignore
            if (window.twttr) {
                // @ts-ignore
                window.twttr.widgets.load(containerRef.current);
                setIsLoading(false);
            }
        }
    }, [tweetID]);

    return (
        <div ref={containerRef} className={`my-4 flex justify-center ${isSelected ? 'ring-2 ring-primary ring-offset-2 rounded-lg' : ''}`} draggable="false">
            {isLoading && (
                <div className="w-[550px] h-[200px] bg-muted rounded-lg animate-pulse" />
            )}
            <blockquote className="twitter-tweet" data-theme="light">
                <a href={`https://twitter.com/x/status/${tweetID}`}>Loading Tweet...</a>
            </blockquote>
        </div>
    );
}

export class TweetNode extends DecoratorNode<JSX.Element> {
    __id: string;

    static getType(): string {
        return 'tweet';
    }

    static clone(node: TweetNode): TweetNode {
        return new TweetNode(node.__id, node.__key);
    }

    static importJSON(serializedNode: SerializedTweetNode): TweetNode {
        const node = $createTweetNode(serializedNode.tweetID);
        return node;
    }

    constructor(id: string, key?: NodeKey) {
        super(key);
        this.__id = safeTweetId(id);
    }

    exportJSON(): SerializedTweetNode {
        return {
            type: 'tweet',
            version: 1,
            tweetID: this.__id,
        };
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement('div');
        const quote = document.createElement('blockquote');
        quote.className = 'twitter-tweet';
        const link = document.createElement('a');
        link.setAttribute('href', `https://twitter.com/x/status/${safeTweetId(this.__id)}`);
        quote.appendChild(link);
        element.appendChild(quote);
        return { element };
    }

    static importDOM(): null {
        return null;
    }

    createDOM(config: EditorConfig): HTMLElement {
        const div = document.createElement('div');
        div.className = 'tweet-embed';
        return div;
    }

    updateDOM(): false {
        return false;
    }

    decorate(): JSX.Element {
        return (
            <Suspense fallback={<div className="w-[550px] h-[200px] mx-auto bg-muted rounded-lg animate-pulse" />}>
                <TweetComponent tweetID={this.__id} nodeKey={this.getKey()} />
            </Suspense>
        );
    }
}

export function $createTweetNode(tweetID: string): TweetNode {
    return $applyNodeReplacement(new TweetNode(tweetID));
}

export function $isTweetNode(
    node: LexicalNode | null | undefined
): node is TweetNode {
    return node instanceof TweetNode;
}
