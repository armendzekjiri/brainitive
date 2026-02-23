import {
    $applyNodeReplacement,
    ElementNode,
    DOMConversionMap,
    DOMConversionOutput,
    DOMExportOutput,
    EditorConfig,
    LexicalNode,
    NodeKey,
    SerializedElementNode,
    Spread,
} from 'lexical';

export type SerializedLayoutContainerNode = Spread<
    {
        templateColumns: string;
    },
    SerializedElementNode
>;

export class LayoutContainerNode extends ElementNode {
    __templateColumns: string;

    static getType(): string {
        return 'layout-container';
    }

    static clone(node: LayoutContainerNode): LayoutContainerNode {
        return new LayoutContainerNode(node.__templateColumns, node.__key);
    }

    constructor(templateColumns: string = '1fr 1fr', key?: NodeKey) {
        super(key);
        this.__templateColumns = templateColumns;
    }

    static importJSON(serializedNode: SerializedLayoutContainerNode): LayoutContainerNode {
        return $createLayoutContainerNode(serializedNode.templateColumns);
    }

    static importDOM(): DOMConversionMap | null {
        return {
            div: (domNode: HTMLElement) => {
                if (domNode.getAttribute('data-layout-container')) {
                    return {
                        conversion: $convertLayoutContainerElement,
                        priority: 1,
                    };
                }
                return null;
            },
        };
    }

    exportJSON(): SerializedLayoutContainerNode {
        return {
            ...super.exportJSON(),
            type: 'layout-container',
            templateColumns: this.__templateColumns,
            version: 1,
        };
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement('div');
        element.setAttribute('data-layout-container', 'true');
        element.style.display = 'grid';
        element.style.gridTemplateColumns = this.__templateColumns;
        element.style.gap = '1rem';
        return { element };
    }

    createDOM(config: EditorConfig): HTMLElement {
        const dom = document.createElement('div');
        dom.setAttribute('data-layout-container', 'true');
        dom.className = 'grid gap-4 my-4';
        dom.style.gridTemplateColumns = this.__templateColumns;
        return dom;
    }

    updateDOM(prevNode: LayoutContainerNode, dom: HTMLElement): boolean {
        if (prevNode.__templateColumns !== this.__templateColumns) {
            dom.style.gridTemplateColumns = this.__templateColumns;
        }
        return false;
    }

    setTemplateColumns(templateColumns: string): void {
        const writable = this.getWritable();
        writable.__templateColumns = templateColumns;
    }

    getTemplateColumns(): string {
        return this.__templateColumns;
    }
}

export class LayoutItemNode extends ElementNode {
    static getType(): string {
        return 'layout-item';
    }

    static clone(node: LayoutItemNode): LayoutItemNode {
        return new LayoutItemNode(node.__key);
    }

    constructor(key?: NodeKey) {
        super(key);
    }

    static importJSON(): LayoutItemNode {
        return $createLayoutItemNode();
    }

    static importDOM(): DOMConversionMap | null {
        return {
            div: (domNode: HTMLElement) => {
                if (domNode.getAttribute('data-layout-item')) {
                    return {
                        conversion: () => ({ node: $createLayoutItemNode() }),
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
            type: 'layout-item',
            version: 1,
        };
    }

    exportDOM(): DOMExportOutput {
        const element = document.createElement('div');
        element.setAttribute('data-layout-item', 'true');
        return { element };
    }

    createDOM(config: EditorConfig): HTMLElement {
        const dom = document.createElement('div');
        dom.setAttribute('data-layout-item', 'true');
        dom.className = 'p-3 border rounded-md bg-muted/30 min-h-[100px]';
        return dom;
    }

    updateDOM(): boolean {
        return false;
    }
}

function $convertLayoutContainerElement(domNode: Node): DOMConversionOutput | null {
    const templateColumns = (domNode as HTMLElement).style.gridTemplateColumns || '1fr 1fr';
    return { node: $createLayoutContainerNode(templateColumns) };
}

export function $createLayoutContainerNode(templateColumns: string = '1fr 1fr'): LayoutContainerNode {
    return $applyNodeReplacement(new LayoutContainerNode(templateColumns));
}

export function $isLayoutContainerNode(
    node: LexicalNode | null | undefined
): node is LayoutContainerNode {
    return node instanceof LayoutContainerNode;
}

export function $createLayoutItemNode(): LayoutItemNode {
    return $applyNodeReplacement(new LayoutItemNode());
}

export function $isLayoutItemNode(
    node: LexicalNode | null | undefined
): node is LayoutItemNode {
    return node instanceof LayoutItemNode;
}
