import {
    $applyNodeReplacement,
    DOMConversionMap,
    DOMConversionOutput,
    DOMExportOutput,
    EditorConfig,
    LexicalNode,
    NodeKey,
    SerializedTextNode,
    Spread,
    TextNode,
} from 'lexical';

export type SerializedMentionNode = Spread<
    {
        userId: number;
        displayName: string;
    },
    SerializedTextNode
>;

function $convertMentionElement(domNode: Node): DOMConversionOutput | null {
    const el = domNode as HTMLElement;
    const userId = el.getAttribute('data-mention-user-id');
    const displayName = el.getAttribute('data-mention-name') || el.textContent || '';
    if (userId) {
        const node = $createMentionNode(Number(userId), displayName.replace(/^@/, ''));
        return { node };
    }
    return null;
}

export class MentionNode extends TextNode {
    __userId: number;
    __displayName: string;

    static getType(): string {
        return 'mention';
    }

    static clone(node: MentionNode): MentionNode {
        return new MentionNode(node.__userId, node.__displayName, node.__key);
    }

    static importJSON(serializedNode: SerializedMentionNode): MentionNode {
        return $createMentionNode(serializedNode.userId, serializedNode.displayName);
    }

    static importDOM(): DOMConversionMap | null {
        return {
            span: (domNode: HTMLElement) => {
                if (domNode.hasAttribute('data-mention-user-id')) {
                    return {
                        conversion: $convertMentionElement,
                        priority: 1,
                    };
                }
                return null;
            },
        };
    }

    constructor(userId: number, displayName: string, key?: NodeKey) {
        super(`@${displayName}`, key);
        this.__userId = userId;
        this.__displayName = displayName;
    }

    exportJSON(): SerializedMentionNode {
        return {
            ...super.exportJSON(),
            type: 'mention',
            userId: this.__userId,
            displayName: this.__displayName,
        };
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement('span');
        element.setAttribute('data-mention-user-id', String(this.__userId));
        element.setAttribute('data-mention-name', this.__displayName);
        element.className = 'mention';
        element.textContent = `@${this.__displayName}`;
        element.style.cssText =
            'background: hsl(var(--primary) / 0.1); color: hsl(var(--primary)); border-radius: 4px; padding: 1px 4px; font-weight: 500; cursor: pointer; text-decoration: none;';
        return { element };
    }

    createDOM(config: EditorConfig): HTMLElement {
        const el = super.createDOM(config);
        el.style.cssText =
            'background: hsl(var(--primary) / 0.1); color: hsl(var(--primary)); border-radius: 4px; padding: 1px 4px; font-weight: 500; cursor: pointer; text-decoration: none;';
        el.setAttribute('data-mention-user-id', String(this.__userId));
        el.setAttribute('data-mention-name', this.__displayName);
        el.setAttribute('aria-label', `Mention: ${this.__displayName}`);
        el.setAttribute('role', 'link');
        return el;
    }

    updateDOM(): boolean {
        return false;
    }

    canInsertTextBefore(): boolean {
        return false;
    }

    canInsertTextAfter(): boolean {
        return false;
    }

    isTextEntity(): boolean {
        return true;
    }
}

export function $createMentionNode(userId: number, displayName: string): MentionNode {
    return $applyNodeReplacement(new MentionNode(userId, displayName));
}

export function $isMentionNode(node: LexicalNode | null | undefined): node is MentionNode {
    return node instanceof MentionNode;
}
