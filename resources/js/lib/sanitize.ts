import DOMPurify from 'dompurify';

/**
 * Sanitize stored HTML before `dangerouslySetInnerHTML`. Covers content from the public
 * helpdesk, comments and AI output, including rows saved before the server sanitized them.
 * Use it for every raw-HTML render.
 */
export function safeHtml(html: string | null | undefined): string {
    if (!html) return '';
    return DOMPurify.sanitize(html, {
        FORBID_TAGS: ['style', 'form', 'input', 'button', 'textarea', 'select'],
        FORBID_ATTR: ['style'],
    });
}

/** `{ __html }` for React, sanitized. */
export function safeHtmlProp(html: string | null | undefined): { __html: string } {
    return { __html: safeHtml(html) };
}
