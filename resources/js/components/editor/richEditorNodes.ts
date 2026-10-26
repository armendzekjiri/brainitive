/**
 * The nodes of the full rich editor (RichLexicalEditorFull) — shared with the headless editor that
 * loads a draft into an empty live document (collab/bootstrap.ts, BRAI-54), so both know the same
 * HTML.
 */
import type { Klass, LexicalNode } from 'lexical';
import { HeadingNode, QuoteNode } from '@lexical/rich-text';
import { ListNode, ListItemNode } from '@lexical/list';
import { LinkNode, AutoLinkNode } from '@lexical/link';
import { CodeNode, CodeHighlightNode } from '@lexical/code';
import { TableNode, TableCellNode, TableRowNode } from '@lexical/table';
import { HorizontalRuleNode } from '@lexical/react/LexicalHorizontalRuleNode';
import {
    ImageNode,
    GIFNode,
    YouTubeNode,
    FigmaNode,
    TweetNode,
    ExcalidrawNode,
    EquationNode,
    StickyNoteNode,
    PollNode,
    PageBreakNode,
    LayoutContainerNode,
    LayoutItemNode,
    CollapsibleContainerNode,
    CollapsibleTitleNode,
    CollapsibleContentNode,
} from './nodes';
import { MentionNode } from './nodes/MentionNode';
import { SmartLinkNode } from './nodes/SmartLinkNode';

export const RICH_EDITOR_NODES: Array<Klass<LexicalNode>> = [
    HeadingNode,
    QuoteNode,
    ListNode,
    ListItemNode,
    LinkNode,
    AutoLinkNode,
    CodeNode,
    CodeHighlightNode,
    TableNode,
    TableCellNode,
    TableRowNode,
    HorizontalRuleNode,
    ImageNode,
    GIFNode,
    YouTubeNode,
    FigmaNode,
    TweetNode,
    ExcalidrawNode,
    EquationNode,
    StickyNoteNode,
    PollNode,
    PageBreakNode,
    LayoutContainerNode,
    LayoutItemNode,
    CollapsibleContainerNode,
    CollapsibleTitleNode,
    CollapsibleContentNode,
    MentionNode,
    SmartLinkNode,
];

/**
 * Node properties Yjs must not sync: a sticky note's caption is a nested editor (a Yjs sub-document
 * Hocuspocus doesn't carry); its text travels as the `__captionState` string instead.
 */
export const COLLAB_EXCLUDED_PROPERTIES = new Map<Klass<LexicalNode>, Set<string>>([[StickyNoteNode, new Set(['__caption'])]]);
