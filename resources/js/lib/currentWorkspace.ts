/**
 * The workspace of the page the user is on (`/workspaces/{slug}/…`), or null elsewhere. Editor uploads send it as
 * `workspace`: the server requires one (EditorUploads::workspaceFor, Codex review) and counts the file against it.
 */
export function currentWorkspaceSlug(pathname: string = window.location.pathname): string | null {
    const match = /^\/workspaces\/([^/?#]+)/.exec(pathname);
    return match ? decodeURIComponent(match[1]) : null;
}
