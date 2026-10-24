import { marked } from 'marked';

/** Block-level tags: when the AI answer has none, it is plain text or Markdown. */
const BLOCK_TAG = /<(p|h[1-6]|ul|ol|li|blockquote|pre|table|div|hr)\b/i;

/**
 * Turn an AI answer into editor-ready HTML (BRAI-30). The endpoint asks for HTML, but prompts
 * ("Generate prompt") may come back as plain text or Markdown, sometimes inside ``` fences.
 * Lexical can't put loose text at the document root, so "Replace" silently did nothing for them.
 */
export function normalizeAiHtml(raw: string): string {
    let text = (raw ?? '').trim();
    const fenced = text.match(/^```[a-zA-Z]*\s*\n([\s\S]*?)\n?```$/);
    if (fenced) text = fenced[1].trim();
    if (text === '') return '';
    if (BLOCK_TAG.test(text)) return text;

    return (marked.parse(text, { async: false, gfm: true, breaks: true }) as string).trim();
}
