/**
 * Text-quote anchors for inline review comments (BRAI-80). The same rule as
 * app/Support/PageReviews/QuoteAnchor.php — keep both in sync:
 *
 * - text = the snapshot's text content, every whitespace run collapsed to one space;
 * - no occurrence of the quote → outdated; exactly one → there;
 * - several → the first whose prefix AND suffix match, else the only one matching either,
 *   else outdated (an anchor never attaches to the wrong text).
 *
 * The page HTML is never changed: highlights are <mark> elements added to the rendered copy.
 */

/** Characters of context stored on each side of a quote. */
export const CONTEXT = 32;

export const normalize = (text: string): string => text.replace(/\s+/g, ' ');

/** Normalized text of `raw` plus, for each raw index, the normalized index it maps to. */
function indexText(raw: string): { text: string; rawToNorm: number[]; normToRaw: number[] } {
    let text = '';
    const rawToNorm: number[] = new Array(raw.length + 1);
    const normToRaw: number[] = [];
    let previousSpace = false;
    for (let i = 0; i < raw.length; i++) {
        const isSpace = /\s/.test(raw[i]);
        if (isSpace && previousSpace) {
            rawToNorm[i] = text.length - 1;
            continue;
        }
        rawToNorm[i] = text.length;
        normToRaw.push(i);
        text += isSpace ? ' ' : raw[i];
        previousSpace = isSpace;
    }
    rawToNorm[raw.length] = text.length;
    normToRaw.push(raw.length);
    return { text, rawToNorm, normToRaw };
}

/** Index of the quote in the normalized text, or null when it can't be placed unambiguously. */
export function locate(text: string, quote: string, prefix: string | null, suffix: string | null): number | null {
    const q = normalize(quote);
    if (q.trim() === '') return null;

    const positions: number[] = [];
    for (let at = text.indexOf(q); at !== -1; at = text.indexOf(q, at + 1)) positions.push(at);
    if (positions.length <= 1) return positions[0] ?? null;

    const p = normalize(prefix ?? '');
    const s = normalize(suffix ?? '');
    const prefixMatches = (at: number) => (p === '' ? at === 0 : text.slice(0, at).endsWith(p));
    const suffixMatches = (at: number) => (s === '' ? at + q.length === text.length : text.slice(at + q.length).startsWith(s));

    const both = positions.find((at) => prefixMatches(at) && suffixMatches(at));
    if (both !== undefined) return both;
    const either = positions.filter((at) => prefixMatches(at) || suffixMatches(at));
    return either.length === 1 ? either[0] : null;
}

export interface QuoteSelection {
    quote: string;
    prefix: string;
    suffix: string;
}

/** Turn the current selection inside `root` into a quote anchor (null when empty / outside). */
export function selectionAnchor(root: HTMLElement, selection: Selection | null): QuoteSelection | null {
    if (!selection || selection.rangeCount === 0 || selection.isCollapsed) return null;
    const range = selection.getRangeAt(0);
    if (!root.contains(range.commonAncestorContainer)) return null;

    const before = document.createRange();
    before.selectNodeContents(root);
    before.setEnd(range.startContainer, range.startOffset);
    const rawStart = before.toString().length;
    const rawEnd = rawStart + range.toString().length;

    const { text, rawToNorm } = indexText(root.textContent ?? '');
    let start = rawToNorm[rawStart] ?? text.length;
    let end = rawToNorm[rawEnd] ?? text.length;
    while (start < end && text[start] === ' ') start++;
    while (end > start && text[end - 1] === ' ') end--;
    if (end <= start) return null;

    // cut by code points, never inside an emoji (a lone surrogate breaks the JSON)
    const quote = Array.from(text.slice(start, end)).slice(0, 1000).join('');
    return {
        quote,
        prefix: Array.from(text.slice(0, start)).slice(-CONTEXT).join(''),
        suffix: Array.from(text.slice(start + quote.length)).slice(0, CONTEXT).join(''),
    };
}

/**
 * Wrap the anchored text of each thread in a <mark data-thread="…"> inside `root`.
 * Returns the uuids that could NOT be placed (shown as outdated).
 */
export function highlight(
    root: HTMLElement,
    anchors: { uuid: string; quote: string; prefix: string | null; suffix: string | null }[],
    markClass: string,
): Set<string> {
    const missing = new Set<string>();
    for (const anchor of anchors) {
        const { text, normToRaw } = indexText(root.textContent ?? '');
        const at = locate(text, anchor.quote, anchor.prefix, anchor.suffix);
        if (at === null) {
            missing.add(anchor.uuid);
            continue;
        }
        const q = normalize(anchor.quote);
        wrapRawRange(root, normToRaw[at], normToRaw[at + q.length - 1] + 1, anchor.uuid, markClass);
    }
    return missing;
}

function wrapRawRange(root: HTMLElement, rawStart: number, rawEnd: number, uuid: string, markClass: string) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const nodes: Text[] = [];
    for (let node = walker.nextNode(); node; node = walker.nextNode()) nodes.push(node as Text);

    let offset = 0;
    for (const node of nodes) {
        const length = node.data.length;
        const from = Math.max(rawStart, offset);
        const to = Math.min(rawEnd, offset + length);
        if (from < to) {
            let target = node;
            if (from > offset) target = target.splitText(from - offset);
            if (to < offset + length) target.splitText(to - from);
            const mark = document.createElement('mark');
            mark.dataset.thread = uuid;
            mark.className = markClass;
            target.parentNode?.insertBefore(mark, target);
            mark.appendChild(target);
        }
        offset += length;
        if (offset >= rawEnd) break;
    }
}
