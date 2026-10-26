import { createContext, useContext } from 'react';
import type { SmartLinkView } from '@/types';

/**
 * Smart links (BRAI-143): refs, URL parsing and the editor context.
 *
 * A smart link is saved as `<a href="<url>" data-smart-link="<ref>" data-smart-link-view="inline|card">text</a>`.
 * Refs: `item:<id>`, `page:<uuid>`, `ticket:<id>`, `plan:<id>` — and `plan:<project>/<slug>` right
 * after pasting a plan URL (the server answers with the canonical `plan:<id>`).
 */

/** Where smart links of an editor resolve: the workspace (live data) and the project the picker starts in. */
export interface SmartLinkEditorConfig {
    workspaceSlug: string;
    projectSlug: string;
}

export const SmartLinkEditorContext = createContext<SmartLinkEditorConfig | null>(null);

export function useSmartLinkConfig(): SmartLinkEditorConfig | null {
    return useContext(SmartLinkEditorContext);
}

const REF = /^(?:(?:item|ticket|plan):[1-9]\d{0,17}|page:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|plan:[a-z0-9][a-z0-9-]{0,99}\/[a-z0-9][a-z0-9-]{0,199})$/i;

export function isSmartLinkRef(ref: string | null | undefined): ref is string {
    return !!ref && REF.test(ref);
}

export function normalizeView(view: string | null | undefined): SmartLinkView {
    return view === 'card' ? 'card' : 'inline';
}

/**
 * The ref of a URL of this app that points at an issue, doc / whiteboard, ticket or plan of the
 * given workspace, or null (other hosts, other workspaces, anything else).
 *
 * Issues: `…/boards/<board>#item-<id>` and `…/boards/<board>/items/<id>`; docs: `…/pages/<uuid>`;
 * tickets: `…/helpdesks/<helpdesk>/tickets/<id>`; plans: `…/plans/<slug>`.
 */
export function parseSmartLinkUrl(raw: string, workspaceSlug: string, origin = window.location.origin): string | null {
    const text = raw.trim();
    if (!text || /\s/.test(text)) return null;
    let url: URL;
    try {
        url = new URL(text, origin);
    } catch {
        return null;
    }
    // only absolute URLs of this app (a pasted "pages/…" without a host is just text)
    if (!/^https?:\/\//i.test(text) || url.origin !== origin) return null;

    const match = url.pathname.match(/^\/workspaces\/([^/]+)\/projects\/([^/]+)\/(.+?)\/?$/);
    if (!match) return null;
    const [, workspace, project, rest] = match;
    if (safeDecode(workspace) !== workspaceSlug) return null;

    let m: RegExpMatchArray | null;
    if ((m = rest.match(/^boards\/[^/]+\/items\/(\d+)$/))) return `item:${m[1]}`;
    if (/^boards\/[^/]+$/.test(rest) && (m = url.hash.match(/^#item-(\d+)$/))) return `item:${m[1]}`;
    if ((m = rest.match(/^pages\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i))) return `page:${m[1].toLowerCase()}`;
    if ((m = rest.match(/^helpdesks\/[^/]+\/tickets\/(\d+)$/))) return `ticket:${m[1]}`;
    if ((m = rest.match(/^plans\/([a-z0-9][a-z0-9-]*)$/)) && /^[a-z0-9][a-z0-9-]*$/.test(safeDecode(project))) {
        return `plan:${safeDecode(project)}/${m[1]}`;
    }

    return null;
}

function safeDecode(value: string): string {
    try {
        return decodeURIComponent(value);
    } catch {
        return value;
    }
}

/** The text a smart link keeps in the saved HTML (what share pages, exports and search see). */
export function smartLinkText(data: { key: string | null; title: string }): string {
    return data.key ? `${data.key} ${data.title}` : data.title;
}

/**
 * Only http(s) or app-relative hrefs (never `javascript:` …). Node fields can arrive from anywhere: HTML
 * import, and with live co-editing (BRAI-54) straight from another browser through Yjs.
 */
export function safeSmartLinkHref(url: string | null | undefined): string {
    const value = String(url ?? '').trim();
    return /^https?:\/\//i.test(value) || (value.startsWith('/') && !value.startsWith('//')) ? value : '#';
}
