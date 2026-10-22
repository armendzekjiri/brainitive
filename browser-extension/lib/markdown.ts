import { Readability } from '@mozilla/readability';
import TurndownService from 'turndown';

/**
 * Page → Markdown for clipping. Runs in the popup (it has a DOM; the service worker
 * doesn't). The server converts the Markdown into the editor format.
 */

const turndown = new TurndownService({
    headingStyle: 'atx',
    codeBlockStyle: 'fenced',
    bulletListMarker: '-',
    emDelimiter: '*',
});
turndown.remove(['script', 'style', 'noscript', 'iframe', 'form', 'button']);

export function htmlToMarkdown(html: string, baseUrl?: string): string {
    const doc = parse(`<body>${html}</body>`, baseUrl);
    absolutize(doc);
    return tidy(turndown.turndown(doc.body));
}

export interface Article {
    title: string;
    markdown: string;
    byline: string | null;
    siteName: string | null;
}

/** Mozilla Readability (Firefox Reader View) on the page's HTML; null if it finds no article. */
export function extractArticle(html: string, url: string): Article | null {
    const doc = parse(html, url);
    absolutize(doc);
    const article = new Readability(doc, { charThreshold: 300 }).parse();
    if (!article?.content) {
        return null;
    }
    return {
        title: article.title?.trim() || doc.title,
        markdown: tidy(turndown.turndown(article.content)),
        byline: article.byline?.trim() || null,
        siteName: article.siteName?.trim() || null,
    };
}

/** "Source: [Title](url)" — first line of every capture so it's clear where it came from. */
export function sourceLine(title: string, url: string): string {
    return `Source: [${escapeLinkText(title || url)}](${url})`;
}

export function quote(text: string): string {
    return text
        .trim()
        .split('\n')
        .map((line) => `> ${line}`)
        .join('\n');
}

function parse(html: string, baseUrl?: string): Document {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    if (baseUrl) {
        const base = doc.createElement('base');
        base.href = baseUrl;
        doc.head.prepend(base);
    }
    return doc;
}

/** Links and images point at the page, not at the extension. */
function absolutize(doc: Document): void {
    doc.querySelectorAll<HTMLAnchorElement>('a[href]').forEach((a) => a.setAttribute('href', a.href));
    doc.querySelectorAll<HTMLImageElement>('img[src]').forEach((img) => img.setAttribute('src', img.src));
    doc.querySelectorAll('a[href^="javascript:"]').forEach((a) => a.removeAttribute('href'));
}

function tidy(markdown: string): string {
    return markdown.replace(/\n{3,}/g, '\n\n').trim();
}

function escapeLinkText(text: string): string {
    return text.replace(/([[\]])/g, '\\$1');
}
