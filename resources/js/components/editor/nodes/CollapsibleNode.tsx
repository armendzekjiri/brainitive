import {
    $applyNodeReplacement,
    $createParagraphNode,
    $isElementNode,
    DOMConversionMap,
    DOMConversionOutput,
    DOMExportOutput,
    EditorConfig,
    ElementNode,
    LexicalEditor,
    LexicalNode,
    NodeKey,
    RangeSelection,
    SerializedElementNode,
    Spread,
} from 'lexical';

export type SerializedCollapsibleContainerNode = Spread<
    {
        open: boolean;
    },
    SerializedElementNode
>;

export class CollapsibleContainerNode extends ElementNode {
    __open: boolean;

    static getType(): string {
        return 'collapsible-container';
    }

    static clone(node: CollapsibleContainerNode): CollapsibleContainerNode {
        return new CollapsibleContainerNode(node.__open, node.__key);
    }

    constructor(open: boolean = true, key?: NodeKey) {
        super(key);
        this.__open = open;
    }

    static importJSON(serializedNode: SerializedCollapsibleContainerNode): CollapsibleContainerNode {
        const node = $createCollapsibleContainerNode(serializedNode.open);
        return node;
    }

    static importDOM(): DOMConversionMap | null {
        return {
            details: () => ({
                conversion: $convertDetailsElement,
                priority: 1,
            }),
        };
    }

    exportJSON(): SerializedCollapsibleContainerNode {
        return {
            ...super.exportJSON(),
            type: 'collapsible-container',
            open: this.__open,
            version: 1,
        };
    }

    exportDOM(editor: LexicalEditor): DOMExportOutput {
        const element = document.createElement('details');
        element.open = this.__open;
        return { element };
    }

    createDOM(config: EditorConfig): HTMLDetailsElement {
        const dom = document.createElement('details');
        dom.open = this.__open;
        dom.className = 'my-4 border rounded-lg overflow-hidden';
        return dom;
    }

    updateDOM(
        prevNode: CollapsibleContainerNode,
        dom: HTMLDetailsElement
    ): boolean {
        if (prevNode.__open !== this.__open) {
            dom.open = this.__open;
        }
        return false;
    }

    setOpen(open: boolean): void {
        const writable = this.getWritable();
        writable.__open = open;
    }

    getOpen(): boolean {
        return this.__open;
    }

    toggleOpen(): void {
        this.setOpen(!this.getOpen());
    }
}

export class CollapsibleTitleNode extends ElementNode {
    static getType(): string {
        return 'collapsible-title';
    }

    static clone(node: CollapsibleTitleNode): CollapsibleTitleNode {
        return new CollapsibleTitleNode(node.__key);
    }

    constructor(key?: NodeKey) {
        super(key);
    }

    static importJSON(): CollapsibleTitleNode {
        return $createCollapsibleTitleNode();
    }

    static importDOM(): DOMConversionMap | null {
        return {
            summary: () => ({
                conversion: () => ({ node: $createCollapsibleTitleNode() }),
                priority: 1,
            }),
        };
    }

    exportJSON(): SerializedElementNode {
        return {
            ...super.exportJSON(),
            type: 'collapsible-title',
            version: 1,
        };
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement('summary');
        return { element };
    }

    createDOM(config: EditorConfig): HTMLElement {
        const dom = document.createElement('summary');
        dom.className = 'p-3 bg-muted/50 cursor-pointer font-medium hover:bg-muted transition-colors select-none';
        return dom;
    }

    updateDOM(): boolean {
        return false;
    }

    collapseAtStart(): boolean {
        return true;
    }

    insertNewAfter(): null {
        return null;
    }
}

export class CollapsibleContentNode extends ElementNode {
    static getType(): string {
        return 'collapsible-content';
    }

    static clone(node: CollapsibleContentNode): CollapsibleContentNode {
        return new CollapsibleContentNode(node.__key);
    }

    constructor(key?: NodeKey) {
        super(key);
    }

    static importJSON(): CollapsibleContentNode {
        return $createCollapsibleContentNode();
    }

    static importDOM(): DOMConversionMap | null {
        return {
            div: (domNode: HTMLElement) => {
                // Only convert divs that are direct children of details elements
                if (domNode.parentElement?.tagName === 'DETAILS' &&
                    domNode.previousElementSibling?.tagName === 'SUMMARY') {
                    return {
                        conversion: () => ({ node: $createCollapsibleContentNode() }),
                        priority: 1,
                    };
                }
                return null;
            },
        };
    }

    exportJSON(): SerializedElementNode {
        return {
            ...super.exportJSON(),
            type: 'collapsible-content',
            version: 1,
        };
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement('div');
        return { element };
    }

    createDOM(config: EditorConfig): HTMLElement {
        const dom = document.createElement('div');
        dom.className = 'p-3 border-t';
        return dom;
    }

    updateDOM(): boolean {
        return false;
    }
}

function $convertDetailsElement(domNode: Node): DOMConversionOutput | null {
    const isOpen = domNode instanceof HTMLDetailsElement ? domNode.open : true;
    const node = $createCollapsibleContainerNode(isOpen);
    return { node };
}

export function $createCollapsibleContainerNode(open: boolean = true): CollapsibleContainerNode {
    return $applyNodeReplacement(new CollapsibleContainerNode(open));
}

export function $isCollapsibleContainerNode(
    node: LexicalNode | null | undefined
): node is CollapsibleContainerNode {
    return node instanceof CollapsibleContainerNode;
}

export function $createCollapsibleTitleNode(): CollapsibleTitleNode {
    return $applyNodeReplacement(new CollapsibleTitleNode());
}

export function $isCollapsibleTitleNode(
    node: LexicalNode | null | undefined
): node is CollapsibleTitleNode {
    return node instanceof CollapsibleTitleNode;
}

export function $createCollapsibleContentNode(): CollapsibleContentNode {
    return $applyNodeReplacement(new CollapsibleContentNode());
}

export function $isCollapsibleContentNode(
    node: LexicalNode | null | undefined
): node is CollapsibleContentNode {
    return node instanceof CollapsibleContentNode;
}
