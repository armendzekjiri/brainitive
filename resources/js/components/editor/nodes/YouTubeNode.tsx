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
import { Suspense, lazy, useCallback, useRef, useState, useEffect } from 'react';
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

export interface YouTubePayload {
    videoID: string;
    key?: NodeKey;
}

export type SerializedYouTubeNode = Spread<
    {
        videoID: string;
    },
    SerializedLexicalNode
>;

function YouTubeComponent({
    videoID,
    nodeKey,
}: {
    videoID: string;
    nodeKey: NodeKey;
}) {
    const [editor] = useLexicalComposerContext();
    const iframeRef = useRef<HTMLIFrameElement>(null);
    const [isSelected, setSelected, clearSelection] = useLexicalNodeSelection(nodeKey);

    const onDelete = useCallback(
        (payload: KeyboardEvent) => {
            if (isSelected && $isNodeSelection($getSelection())) {
                const event: KeyboardEvent = payload;
                event.preventDefault();
                const node = $getNodeByKey(nodeKey);
                if ($isYouTubeNode(node)) {
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
                    if (iframeRef.current && iframeRef.current.contains(event.target as Node)) {
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
        <div className="relative my-4" draggable="false">
            <div className={`relative aspect-video max-w-2xl mx-auto rounded-lg overflow-hidden ${isSelected ? 'ring-2 ring-primary ring-offset-2' : ''}`}>
                <iframe
                    ref={iframeRef}
                    className="absolute inset-0 w-full h-full"
                    src={`https://www.youtube-nocookie.com/embed/${videoID}`}
                    frameBorder="0"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowFullScreen
                    title="YouTube video"
                />
            </div>
        </div>
    );
}

export class YouTubeNode extends DecoratorNode<JSX.Element> {
    __id: string;

    static getType(): string {
        return 'youtube';
    }

    static clone(node: YouTubeNode): YouTubeNode {
        return new YouTubeNode(node.__id, node.__key);
    }

    static importJSON(serializedNode: SerializedYouTubeNode): YouTubeNode {
        const node = $createYouTubeNode(serializedNode.videoID);
        return node;
    }

    constructor(id: string, key?: NodeKey) {
        super(key);
        this.__id = id;
    }

    exportJSON(): SerializedYouTubeNode {
        return {
            type: 'youtube',
            version: 1,
            videoID: this.__id,
        };
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement('iframe');
        element.setAttribute('src', `https://www.youtube-nocookie.com/embed/${this.__id}`);
        element.setAttribute('frameborder', '0');
        element.setAttribute('allowfullscreen', 'true');
        element.setAttribute('width', '560');
        element.setAttribute('height', '315');
        return { element };
    }

    static importDOM(): null {
        return null;
    }

    getVideoId(): string {
        return this.__id;
    }

    createDOM(config: EditorConfig): HTMLElement {
        const div = document.createElement('div');
        div.className = 'youtube-embed';
        return div;
    }

    updateDOM(): false {
        return false;
    }

    decorate(): JSX.Element {
        return (
            <Suspense fallback={<div className="aspect-video max-w-2xl mx-auto bg-muted rounded-lg animate-pulse" />}>
                <YouTubeComponent videoID={this.__id} nodeKey={this.getKey()} />
            </Suspense>
        );
    }
}

export function $createYouTubeNode(videoID: string): YouTubeNode {
    return $applyNodeReplacement(new YouTubeNode(videoID));
}

export function $isYouTubeNode(
    node: LexicalNode | null | undefined
): node is YouTubeNode {
    return node instanceof YouTubeNode;
}
