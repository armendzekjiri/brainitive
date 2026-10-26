import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useLexicalComposerContext } from '@lexical/react/LexicalComposerContext';
import { $createLinkNode } from '@lexical/link';
import { mergeRegister } from '@lexical/utils';
import {
    $createParagraphNode,
    $createTextNode,
    $getNodeByKey,
    $getRoot,
    $getSelection,
    $isRangeSelection,
    $isTextNode,
    $setSelection,
    COMMAND_PRIORITY_CRITICAL,
    COMMAND_PRIORITY_EDITOR,
    KEY_DOWN_COMMAND,
    PASTE_COMMAND,
    type BaseSelection,
    type NodeKey,
} from 'lexical';
import type { SmartLinkSearchResult } from '@/types';
import { $createSmartLinkNode, $isSmartLinkNode, type SmartLinkNode, type SmartLinkPayload } from '../nodes/SmartLinkNode';
import { INSERT_SMART_LINK_COMMAND, OPEN_SMART_LINK_PICKER_COMMAND } from './smartLinkCommands';
import { parseSmartLinkUrl, smartLinkText, type SmartLinkEditorConfig } from './smartLinkRefs';
import SmartLinkPicker from './SmartLinkPicker';

/**
 * Smart links while editing (BRAI-143):
 * - pasting a URL of an issue, doc, ticket or plan of this workspace inserts a smart link
 *   (Ctrl/Cmd+Shift+V, or "Undo to plain link" in the toast, keeps / makes it a plain link);
 * - "/link" and the side "+" menu open the picker (OPEN_SMART_LINK_PICKER_COMMAND).
 */
export default function SmartLinkPlugin({ config }: { config: SmartLinkEditorConfig }) {
    const [editor] = useLexicalComposerContext();
    const [picker, setPicker] = useState<{ afterBlockKey?: string } | null>(null);
    const savedSelection = useRef<BaseSelection | null>(null);
    const plainPasteUntil = useRef(0);

    const undoToPlainLink = useCallback((key: NodeKey, url: string) => {
        editor.update(() => {
            const node = $getNodeByKey(key);
            if (!$isSmartLinkNode(node)) return;
            const link = $createLinkNode(url);
            link.append($createTextNode(url));
            node.replace(link);
            link.selectEnd();
        });
    }, [editor]);

    useEffect(() => {
        return mergeRegister(
            // Ctrl/Cmd+Shift+V: the next paste stays plain
            editor.registerCommand<KeyboardEvent>(KEY_DOWN_COMMAND, (event) => {
                if ((event.metaKey || event.ctrlKey) && event.shiftKey && event.key.toLowerCase() === 'v') {
                    plainPasteUntil.current = Date.now() + 1500;
                }
                return false;
            }, COMMAND_PRIORITY_CRITICAL),
            editor.registerCommand<ClipboardEvent | InputEvent | KeyboardEvent>(PASTE_COMMAND, (event) => {
                if (!(event instanceof ClipboardEvent) || !event.clipboardData) return false;
                if (Date.now() < plainPasteUntil.current) {
                    plainPasteUntil.current = 0;
                    return false;
                }
                const url = event.clipboardData.getData('text/plain').trim();
                const ref = parseSmartLinkUrl(url, config.workspaceSlug);
                if (!ref) return false;
                const selection = $getSelection();
                // pasting a URL over selected text makes that text a link (Lexical's default)
                if (!$isRangeSelection(selection) || !selection.isCollapsed()) return false;

                event.preventDefault();
                const node = $insertSmartLink({ ref, url, text: url, view: 'inline' });
                const key = node.getKey();
                setTimeout(() => {
                    toast('Pasted as a smart link', {
                        action: { label: 'Undo to plain link', onClick: () => undoToPlainLink(key, url) },
                    });
                }, 0);
                return true;
            }, COMMAND_PRIORITY_CRITICAL),
            editor.registerCommand(OPEN_SMART_LINK_PICKER_COMMAND, (payload) => {
                savedSelection.current = $getSelection()?.clone() ?? null;
                setPicker(payload ?? {});
                return true;
            }, COMMAND_PRIORITY_EDITOR),
            editor.registerCommand(INSERT_SMART_LINK_COMMAND, (payload) => {
                $insertSmartLink(payload);
                return true;
            }, COMMAND_PRIORITY_EDITOR),
        );
    }, [editor, config.workspaceSlug, undoToPlainLink]);

    const onPick = (result: SmartLinkSearchResult) => {
        const target = picker;
        setPicker(null);
        const payload: SmartLinkPayload = {
            ref: result.ref,
            url: result.url,
            text: smartLinkText({ key: result.subtitle, title: result.title }),
            view: 'inline',
        };
        editor.focus();
        editor.update(() => {
            if (target?.afterBlockKey) {
                const block = $getNodeByKey(target.afterBlockKey);
                const paragraph = $createParagraphNode();
                if (block) block.insertAfter(paragraph);
                else $getRoot().append(paragraph);
                paragraph.select();
            } else if (!$isRangeSelection($getSelection()) && savedSelection.current) {
                $setSelection(savedSelection.current.clone());
            }
            $insertSmartLink(payload);
        });
        savedSelection.current = null;
    };

    return (
        <SmartLinkPicker
            open={picker !== null}
            onOpenChange={(open) => {
                if (!open) setPicker(null);
            }}
            workspaceSlug={config.workspaceSlug}
            projectSlug={config.projectSlug}
            onPick={onPick}
        />
    );
}

/** Insert a smart link at the selection (or at the end), followed by a space to keep typing. */
function $insertSmartLink(payload: SmartLinkPayload): SmartLinkNode {
    const node = $createSmartLinkNode(payload);
    let selection = $getSelection();
    if (!$isRangeSelection(selection)) {
        const paragraph = $createParagraphNode();
        $getRoot().append(paragraph);
        paragraph.select();
        selection = $getSelection();
    }
    if ($isRangeSelection(selection)) {
        selection.insertNodes([node]);
    }
    const next = node.getNextSibling();
    if ($isTextNode(next) && /^\s/.test(next.getTextContent())) {
        next.select(1, 1);
    } else {
        const space = $createTextNode(' ');
        node.insertAfter(space);
        space.select(1, 1);
    }

    return node;
}
