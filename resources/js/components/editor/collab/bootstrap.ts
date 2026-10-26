/**
 * Loading the stored draft into an empty live document (BRAI-54). The HTML goes through a headless
 * Lexical editor bound to a scratch Y.Doc; the result is applied to the live document as one update
 * with its own origin. So the load isn't on anybody's undo stack (Ctrl+Z can't wipe the document)
 * and the visible editor just receives it like any remote change.
 */
import { $createParagraphNode, $getRoot, $isDecoratorNode, $isElementNode, createEditor, type LexicalNode } from 'lexical';
import { $generateNodesFromDOM } from '@lexical/html';
import { createBinding, syncLexicalUpdateToYjs, type Provider } from '@lexical/yjs';
import * as Y from 'yjs';
import { COLLAB_EXCLUDED_PROPERTIES, RICH_EDITOR_NODES } from '../richEditorNodes';

export const BOOTSTRAP_ORIGIN = 'brainitive-bootstrap';

/** Awareness-less stand-in: the scratch binding never shows carets. */
const silentProvider = {
    awareness: {
        getLocalState: () => null,
        getStates: () => new Map(),
        on: () => undefined,
        off: () => undefined,
        setLocalState: () => undefined,
        setLocalStateField: () => undefined,
    },
    connect: () => undefined,
    disconnect: () => undefined,
    on: () => undefined,
    off: () => undefined,
} as unknown as Provider;

/** Yjs update holding the draft: Lexical's `root` + the `meta` map (name, icon). */
export function buildBootstrapUpdate(id: string, draft: { html: string | null; name: string; icon: string | null }): Uint8Array {
    const scratch = new Y.Doc();
    const editor = createEditor({
        namespace: 'RichEditorBootstrap',
        nodes: RICH_EDITOR_NODES,
        onError: (error) => { throw error; },
    });
    const binding = createBinding(editor, silentProvider, id, scratch, new Map([[id, scratch]]), COLLAB_EXCLUDED_PROPERTIES);
    const stop = editor.registerUpdateListener(({ prevEditorState, editorState, dirtyElements, dirtyLeaves, normalizedNodes, tags }) => {
        syncLexicalUpdateToYjs(binding, silentProvider, prevEditorState, editorState, dirtyElements, dirtyLeaves, normalizedNodes, tags);
    });

    editor.update(() => {
        const root = $getRoot();
        root.clear();
        const nodes: LexicalNode[] = draft.html
            ? $generateNodesFromDOM(editor, new DOMParser().parseFromString(draft.html, 'text/html'))
            : [];
        // like InitialContentPlugin, but the root can't take text: wrap loose text / inline links in paragraphs
        let paragraph: ReturnType<typeof $createParagraphNode> | null = null;
        for (const node of nodes) {
            if ($isDecoratorNode(node) || ($isElementNode(node) && !node.isInline())) {
                paragraph = null;
                root.append(node);
            } else {
                if (!paragraph) {
                    paragraph = $createParagraphNode();
                    root.append(paragraph);
                }
                paragraph.append(node);
            }
        }
        if (root.getChildrenSize() === 0) root.append($createParagraphNode());
    }, { discrete: true });

    scratch.transact(() => {
        const meta = scratch.getMap('meta');
        meta.set('name', draft.name);
        meta.set('icon', draft.icon);
    });

    stop();
    const update = Y.encodeStateAsUpdate(scratch);
    scratch.destroy();
    return update;
}
