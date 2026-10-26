import {
    $applyNodeReplacement,
    DecoratorNode,
    type DOMConversionMap,
    type DOMConversionOutput,
    type DOMExportOutput,
    type LexicalNode,
    type NodeKey,
    type SerializedLexicalNode,
    type Spread,
} from 'lexical';
import type { JSX } from 'react';
import type { SmartLinkView } from '@/types';
import { isSmartLinkRef, normalizeView } from '../smartLinks/smartLinkRefs';
import SmartLinkChip from '../smartLinks/SmartLinkChip';

/**
 * Smart link (BRAI-143): an issue, doc / whiteboard, ticket or plan shown as a live inline chip or
 * card. Saved as a plain anchor, so everything outside the editor sees a normal link:
 * `<a href="<url>" data-smart-link="item:12" data-smart-link-view="inline">WEB-12 Fix login</a>`.
 */
export interface SmartLinkPayload {
    ref: string;
    url: string;
    /** What the anchor shows outside the editor (key + title when it was linked). */
    text: string;
    view?: SmartLinkView;
}

export type SerializedSmartLinkNode = Spread<{ ref: string; url: string; text: string; view: SmartLinkView }, SerializedLexicalNode>;

/** Only http(s) or app-relative hrefs are written back. */
function safeHref(url: string): string {
    const value = url.trim();
    return /^https?:\/\//i.test(value) || (value.startsWith('/') && !value.startsWith('//')) ? value : '#';
}

function $convertSmartLinkElement(domNode: HTMLElement): DOMConversionOutput | null {
    const ref = domNode.getAttribute('data-smart-link');
    if (!isSmartLinkRef(ref)) return null;
    return {
        node: $createSmartLinkNode({
            ref,
            url: domNode.getAttribute('href') ?? '',
            text: (domNode.textContent ?? '').trim(),
            view: normalizeView(domNode.getAttribute('data-smart-link-view')),
        }),
    };
}

export class SmartLinkNode extends DecoratorNode<JSX.Element> {
    __ref: string;
    __url: string;
    __text: string;
    __view: SmartLinkView;

    static getType(): string {
        return 'smart-link';
    }

    static clone(node: SmartLinkNode): SmartLinkNode {
        return new SmartLinkNode({ ref: node.__ref, url: node.__url, text: node.__text, view: node.__view }, node.__key);
    }

    static importJSON(serialized: SerializedSmartLinkNode): SmartLinkNode {
        return $createSmartLinkNode({ ref: serialized.ref, url: serialized.url, text: serialized.text, view: serialized.view });
    }

    static importDOM(): DOMConversionMap | null {
        return {
            // above LinkNode's `a` (priority 1)
            a: (domNode: HTMLElement) => (domNode.hasAttribute('data-smart-link') ? { conversion: $convertSmartLinkElement, priority: 3 } : null),
        };
    }

    // the default: live co-editing (Yjs, BRAI-54) creates nodes without arguments, then syncs the fields
    constructor(payload: SmartLinkPayload = { ref: '', url: '', text: '' }, key?: NodeKey) {
        super(key);
        this.__ref = payload.ref;
        this.__url = payload.url;
        this.__text = payload.text;
        this.__view = normalizeView(payload.view);
    }

    exportJSON(): SerializedSmartLinkNode {
        return { type: 'smart-link', version: 1, ref: this.__ref, url: this.__url, text: this.__text, view: this.__view };
    }

    exportDOM(): DOMExportOutput {
        const anchor = document.createElement('a');
        anchor.setAttribute('href', safeHref(this.__url));
        anchor.setAttribute('data-smart-link', this.__ref);
        anchor.setAttribute('data-smart-link-view', this.__view);
        anchor.textContent = this.__text || this.__url;
        return { element: anchor };
    }

    createDOM(): HTMLElement {
        const span = document.createElement('span');
        span.className = hostClass(this.__view);
        span.setAttribute('data-smart-link-host', this.__ref);
        return span;
    }

    updateDOM(prevNode: SmartLinkNode, dom: HTMLElement): boolean {
        if (prevNode.__view !== this.__view) dom.className = hostClass(this.__view);
        if (prevNode.__ref !== this.__ref) dom.setAttribute('data-smart-link-host', this.__ref);
        return false;
    }

    isInline(): boolean {
        return true;
    }

    isKeyboardSelectable(): boolean {
        return true;
    }

    getTextContent(): string {
        return this.__text || this.__url;
    }

    getRef(): string {
        return this.getLatest().__ref;
    }

    getUrl(): string {
        return this.getLatest().__url;
    }

    getText(): string {
        return this.getLatest().__text;
    }

    getView(): SmartLinkView {
        return this.getLatest().__view;
    }

    setView(view: SmartLinkView): void {
        this.getWritable().__view = view;
    }

    setData(data: { ref: string; url: string; text: string }): void {
        const writable = this.getWritable();
        writable.__ref = data.ref;
        writable.__url = data.url;
        writable.__text = data.text;
    }

    decorate(): JSX.Element {
        return <SmartLinkChip nodeKey={this.getKey()} refValue={this.__ref} url={this.__url} text={this.__text} view={this.__view} />;
    }
}

function hostClass(view: SmartLinkView): string {
    return view === 'card' ? 'smart-link-host smart-link-host--card' : 'smart-link-host';
}

export function $createSmartLinkNode(payload: SmartLinkPayload): SmartLinkNode {
    return $applyNodeReplacement(new SmartLinkNode(payload));
}

export function $isSmartLinkNode(node: LexicalNode | null | undefined): node is SmartLinkNode {
    return node instanceof SmartLinkNode;
}
