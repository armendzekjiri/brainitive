// Log lines of the realtime server (journalctl): values a client sent (a document name, a reason) must never
// be able to start a new line or forge one — Codex realtime review, finding 6.

/** C0 / C1 control characters (newlines, escape sequences …) and the Unicode line / paragraph separators */
const UNSAFE = /[\u0000-\u001f\u007f-\u009f\u2028\u2029]/g;

/** A client-supplied value as one safe, short token: control characters → `?`, at most `max` characters. */
export function clean(value, max = 100) {
    const text = String(value ?? '').replace(UNSAFE, '?');
    return text.length > max ? `${text.slice(0, max)}…` : text;
}

/**
 * Structured fields for a log line: `document="page.…" user=4 reason="access-denied"`. Strings are cleaned, cut
 * (100 characters, `detail` 300) and quoted (JSON); numbers stay bare; empty fields are left out.
 */
export function fields(values) {
    return Object.entries(values)
        .filter(([, value]) => value !== null && value !== undefined && value !== '')
        .map(([key, value]) => `${key}=${typeof value === 'number' || typeof value === 'boolean' ? value : JSON.stringify(clean(value, key === 'detail' ? 300 : 100))}`)
        .join(' ');
}
