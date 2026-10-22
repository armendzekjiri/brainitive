import { ApiError } from '@/lib/api';
import { api } from '@/lib/client';
import { extractArticle, htmlToMarkdown, quote, sourceLine } from '@/lib/markdown';
import { send } from '@/lib/messages';
import { dataUrlToBlob, readDocument } from '@/lib/page';
import { getLastDestination, setLastDestination } from '@/lib/storage';
import type { Created, Destinations } from '@/lib/types';
import { Camera, Crop, Loader2, X } from 'lucide-react';
import { type FormEvent, type KeyboardEvent, type ReactNode, useEffect, useMemo, useState } from 'react';
import { browser } from 'wxt/browser';

/** What the forms know about the page being captured. */
export interface PageContext {
    tabId?: number;
    windowId?: number;
    url: string;
    title: string;
    selectionText: string;
    selectionHtml: string;
    linkUrl?: string;
    /** Browser pages that can't be scripted or captured. */
    restricted: boolean;
    screenshot?: string;
    clipWholePage?: boolean;
}

interface FormProps {
    page: PageContext;
    destinations: Destinations;
    onCreated: (created: Created, label: string) => void;
}

interface Option {
    value: number;
    label: string;
    group: string;
}

// ---------------------------------------------------------------------------------------
// Shared pieces

function DestinationSelect({ label, options, value, onChange, empty }: { label: string; options: Option[]; value: number | null; onChange: (value: number) => void; empty: string }) {
    const groups = useMemo(() => {
        const map = new Map<string, Option[]>();
        options.forEach((option) => map.set(option.group, [...(map.get(option.group) ?? []), option]));
        return [...map.entries()];
    }, [options]);

    if (options.length === 0) {
        return <div className="banner">{empty}</div>;
    }

    return (
        <label className="field">
            <span>{label}</span>
            <select value={value ?? ''} onChange={(e) => onChange(Number(e.target.value))} required>
                {groups.map(([group, items]) => (
                    <optgroup key={group} label={group}>
                        {items.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </optgroup>
                ))}
            </select>
        </label>
    );
}

/** Keep the remembered destination if it still exists, else the first option. */
function useRemembered<T extends { id: number }>(mode: Parameters<typeof getLastDestination>[0], options: Option[]) {
    const [remembered, setRemembered] = useState<T | null | undefined>(undefined);
    useEffect(() => {
        getLastDestination<T>(mode).then(setRemembered);
    }, [mode]);

    const [id, setId] = useState<number | null>(null);
    useEffect(() => {
        if (remembered === undefined) return;
        setId((current) => {
            if (current && options.some((o) => o.value === current)) return current;
            if (remembered && options.some((o) => o.value === remembered.id)) return remembered.id;
            return options[0]?.value ?? null;
        });
    }, [remembered, options]);

    return { id, setId, remembered: remembered ?? null };
}

function Submit({ busy, label, disabled }: { busy: boolean; label: string; disabled?: boolean }) {
    return (
        <div className="actions">
            <span className="hint" style={{ marginRight: 'auto', alignSelf: 'center' }}>
                <kbd>{navigator.platform.includes('Mac') ? '⌘' : 'Ctrl'}</kbd> + <kbd>Enter</kbd>
            </span>
            <button type="submit" className="button primary" disabled={busy || disabled}>
                {busy && <Loader2 size={15} className="spin" />}
                {label}
            </button>
        </div>
    );
}

function Form({ onSubmit, error, children }: { onSubmit: () => Promise<void>; error: string | null; children: ReactNode }) {
    const submit = (event: FormEvent) => {
        event.preventDefault();
        void onSubmit();
    };
    const onKeyDown = (event: KeyboardEvent<HTMLFormElement>) => {
        if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
            event.preventDefault();
            event.currentTarget.requestSubmit();
        }
    };
    return (
        <form className="body" onSubmit={submit} onKeyDown={onKeyDown}>
            {error && <div className="banner">{error}</div>}
            {children}
        </form>
    );
}

function useSubmit() {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const run = async (task: () => Promise<void>) => {
        setBusy(true);
        setError(null);
        try {
            await task();
        } catch (e) {
            setError(e instanceof ApiError || e instanceof Error ? e.message : 'Something went wrong.');
        } finally {
            setBusy(false);
        }
    };
    return { busy, error, setError, run };
}

function selectionMarkdown(page: PageContext): string {
    if (page.selectionHtml) return htmlToMarkdown(page.selectionHtml, page.url);
    return page.selectionText;
}

function initialNotes(page: PageContext): string {
    const parts = page.url ? [sourceLine(page.title, page.url)] : [];
    const selected = selectionMarkdown(page);
    if (selected.trim()) parts.push(quote(selected));
    return parts.join('\n\n');
}

// ---------------------------------------------------------------------------------------
// Issue (+ screenshot)

const DRAFT_KEY = 'issueDraft';

export function IssueForm({ page, destinations, onCreated }: FormProps) {
    const boards = useMemo(
        () =>
            destinations.workspaces.flatMap((ws) =>
                ws.projects.flatMap((project) => project.boards.map((board) => ({ value: board.id, label: board.name, group: `${ws.name} › ${project.name}`, board }))),
            ),
        [destinations],
    );
    const { id: boardId, setId: setBoardId, remembered } = useRemembered<{ id: number; columnId: number | null; assignToMe: boolean }>('issue', boards);
    const board = boards.find((b) => b.value === boardId)?.board;

    const [columnId, setColumnId] = useState<number | null>(null);
    const [title, setTitle] = useState(page.title);
    const [description, setDescription] = useState(() => initialNotes(page));
    const [assignToMe, setAssignToMe] = useState(false);
    const [screenshot, setScreenshot] = useState<string | null>(page.screenshot ?? null);
    const { busy, error, setError, run } = useSubmit();

    useEffect(() => {
        if (remembered) setAssignToMe(remembered.assignToMe);
    }, [remembered]);

    useEffect(() => {
        if (!board) return;
        setColumnId((current) => {
            if (current && board.columns.some((c) => c.id === current)) return current;
            if (remembered?.id === board.id && board.columns.some((c) => c.id === remembered.columnId)) return remembered.columnId;
            return board.columns[0]?.id ?? null;
        });
    }, [board, remembered]);

    // Restore what was typed before "Select area" closed the popup.
    useEffect(() => {
        if (!page.screenshot) return;
        browser.storage.session.get(DRAFT_KEY).then(({ issueDraft }) => {
            const draft = issueDraft as { url: string; title: string; description: string } | undefined;
            if (draft?.url === page.url) {
                setTitle(draft.title);
                setDescription(draft.description);
            }
            browser.storage.session.remove(DRAFT_KEY);
        });
    }, [page.screenshot, page.url]);

    const captureVisible = () =>
        run(async () => {
            if (page.restricted) throw new Error('The browser doesn’t allow screenshots of this page.');
            setScreenshot(await browser.tabs.captureVisibleTab(page.windowId!, { format: 'png' }));
        });

    const captureArea = async () => {
        if (page.restricted || !page.tabId) {
            setError('The browser doesn’t allow screenshots of this page.');
            return;
        }
        await browser.storage.session.set({ [DRAFT_KEY]: { url: page.url, title, description } });
        await send({ type: 'capture-area', tabId: page.tabId }).catch(() => undefined);
        window.close();
    };

    const submit = () =>
        run(async () => {
            if (!board) return;
            const data = new FormData();
            data.set('title', title.trim());
            data.set('description', description);
            if (columnId) data.set('column_id', String(columnId));
            data.set('assign_to_me', assignToMe ? '1' : '0');
            if (screenshot) data.set('screenshot', await dataUrlToBlob(screenshot), 'screenshot.png');

            const created = await api.createIssue(board.id, data);
            await setLastDestination('issue', { id: board.id, columnId, assignToMe });
            onCreated(created, `${created.item_key} created`);
        });

    return (
        <Form onSubmit={submit} error={error}>
            <DestinationSelect label="Board" options={boards} value={boardId} onChange={setBoardId} empty="You can’t add issues to any board yet. Ask a project admin for editor access." />
            {board && board.columns.length > 1 && (
                <label className="field">
                    <span>Status</span>
                    <select value={columnId ?? ''} onChange={(e) => setColumnId(Number(e.target.value))}>
                        {board.columns.map((column) => (
                            <option key={column.id} value={column.id}>
                                {column.name}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            <label className="field">
                <span>Summary</span>
                <input type="text" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={255} required autoFocus />
            </label>
            <label className="field">
                <span>Description (Markdown)</span>
                <textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={5} />
            </label>

            {screenshot ? (
                <div className="shot">
                    <img src={screenshot} alt="Screenshot to attach" />
                    <button type="button" className="icon-button" onClick={() => setScreenshot(null)} aria-label="Remove screenshot" title="Remove screenshot">
                        <X size={15} />
                    </button>
                </div>
            ) : (
                <div className="row">
                    <button type="button" className="button small" onClick={captureVisible} disabled={busy || page.restricted}>
                        <Camera size={14} /> Visible area
                    </button>
                    <button type="button" className="button small" onClick={captureArea} disabled={busy || page.restricted}>
                        <Crop size={14} /> Select area
                    </button>
                </div>
            )}

            <label className="check">
                <input type="checkbox" checked={assignToMe} onChange={(e) => setAssignToMe(e.target.checked)} /> Assign to me
            </label>
            <Submit busy={busy} label="Create issue" disabled={!board || !title.trim()} />
        </Form>
    );
}

// ---------------------------------------------------------------------------------------
// Bookmark

export function BookmarkForm({ page, destinations, onCreated }: FormProps) {
    const groups = useMemo(
        () => destinations.workspaces.flatMap((ws) => ws.bookmarks.map((bookmark) => ({ value: bookmark.id, label: bookmark.name, group: ws.name }))),
        [destinations],
    );
    const { id, setId } = useRemembered<{ id: number }>('bookmark', groups);
    const [url, setUrl] = useState(page.linkUrl ?? page.url);
    const [title, setTitle] = useState(page.linkUrl ? '' : page.title);
    const [note, setNote] = useState(page.selectionText);
    const { busy, error, run } = useSubmit();

    const submit = () =>
        run(async () => {
            if (!id) return;
            const created = await api.saveBookmark(id, url.trim()
                ? { type: 'link', url: url.trim(), title: title.trim() || undefined, content: note.trim() || undefined }
                : { type: 'note', title: title.trim() || undefined, content: note.trim() });
            await setLastDestination('bookmark', { id });
            onCreated(created, 'Bookmark saved');
        });

    return (
        <Form onSubmit={submit} error={error}>
            <DestinationSelect label="Bookmark group" options={groups} value={id} onChange={setId} empty="You can’t add to any bookmark group yet. Create one in Brainitive under Bookmarks." />
            <label className="field">
                <span>Link</span>
                <input type="url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://" />
            </label>
            <label className="field">
                <span>Title</span>
                <input type="text" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Fetched from the page when empty" maxLength={500} />
            </label>
            <label className="field">
                <span>Note</span>
                <textarea value={note} onChange={(e) => setNote(e.target.value)} rows={3} placeholder="Optional" />
            </label>
            <Submit busy={busy} label="Save bookmark" disabled={!id || (!url.trim() && !note.trim())} />
        </Form>
    );
}

// ---------------------------------------------------------------------------------------
// Doc (clip)

type ClipSource = 'article' | 'selection' | 'link';

export function DocForm({ page, destinations, onCreated }: FormProps) {
    const projects = useMemo(
        () => destinations.workspaces.flatMap((ws) => ws.projects.filter((p) => p.can_create_pages).map((project) => ({ value: project.id, label: project.name, group: ws.name }))),
        [destinations],
    );
    const { id, setId, remembered } = useRemembered<{ id: number; publish: boolean }>('doc', projects);
    const hasSelection = page.selectionText.trim().length > 0;
    const [source, setSource] = useState<ClipSource>(page.restricted ? 'link' : hasSelection && !page.clipWholePage ? 'selection' : 'article');
    const [title, setTitle] = useState(page.title);
    const [markdown, setMarkdown] = useState('');
    const [loading, setLoading] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const [publish, setPublish] = useState(false);
    const { busy, error, run } = useSubmit();

    useEffect(() => {
        if (remembered) setPublish(remembered.publish);
    }, [remembered]);

    useEffect(() => {
        const source_ = sourceLine(page.title, page.url);
        setNotice(null);
        if (source === 'link') {
            setMarkdown(source_);
            return;
        }
        if (source === 'selection') {
            setMarkdown(`${source_}\n\n${selectionMarkdown(page)}`);
            return;
        }
        if (!page.tabId) return;
        setLoading(true);
        readDocument(page.tabId)
            .then((doc) => {
                const article = doc ? extractArticle(doc.html, doc.url) : null;
                if (!article) {
                    setNotice('No article found on this page — saved the link instead.');
                    setMarkdown(source_);
                    return;
                }
                setTitle(article.title || page.title);
                const byline = [article.byline, article.siteName].filter(Boolean).join(' · ');
                setMarkdown([source_, byline && `*${byline}*`, article.markdown].filter(Boolean).join('\n\n'));
            })
            .finally(() => setLoading(false));
    }, [source, page]);

    const submit = () =>
        run(async () => {
            if (!id) return;
            const created = await api.createPage(id, { title: title.trim(), markdown, publish });
            await setLastDestination('doc', { id, publish });
            onCreated(created, publish ? 'Doc published' : 'Draft saved');
        });

    return (
        <Form onSubmit={submit} error={error}>
            <DestinationSelect label="Project" options={projects} value={id} onChange={setId} empty="You can’t create docs in any project yet. Ask a project admin for editor access." />
            <div className="segmented" role="group" aria-label="What to clip">
                <button type="button" aria-pressed={source === 'article'} onClick={() => setSource('article')} disabled={page.restricted}>
                    Article
                </button>
                <button type="button" aria-pressed={source === 'selection'} onClick={() => setSource('selection')} disabled={!hasSelection}>
                    Selection
                </button>
                <button type="button" aria-pressed={source === 'link'} onClick={() => setSource('link')}>
                    Link only
                </button>
            </div>
            {notice && <p className="hint" style={{ margin: 0 }}>{notice}</p>}
            <label className="field">
                <span>Title</span>
                <input type="text" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={255} required />
            </label>
            <label className="field">
                <span>Content (Markdown){loading && ' — reading the page…'}</span>
                <textarea value={markdown} onChange={(e) => setMarkdown(e.target.value)} rows={8} />
            </label>
            <label className="check">
                <input type="checkbox" checked={publish} onChange={(e) => setPublish(e.target.checked)} /> Publish now <small>(otherwise a draft only you can see)</small>
            </label>
            <Submit busy={busy} label={publish ? 'Publish doc' : 'Save draft'} disabled={!id || !title.trim() || loading} />
        </Form>
    );
}

// ---------------------------------------------------------------------------------------
// Task

export function TaskForm({ page, destinations, onCreated }: FormProps) {
    const lists = useMemo(
        () =>
            destinations.workspaces.flatMap((ws) =>
                ws.projects.flatMap((project) => project.task_lists.map((list) => ({ value: list.id, label: list.name, group: `${ws.name} › ${project.name}` }))),
            ),
        [destinations],
    );
    const { id, setId, remembered } = useRemembered<{ id: number; assignToMe: boolean }>('task', lists);
    const [title, setTitle] = useState(page.selectionText.trim().split('\n')[0]?.slice(0, 200) || page.title);
    const [notes, setNotes] = useState(() => (page.url ? sourceLine(page.title, page.url) : ''));
    const [dueDate, setDueDate] = useState('');
    const [assignToMe, setAssignToMe] = useState(true);
    const { busy, error, run } = useSubmit();

    useEffect(() => {
        if (remembered) setAssignToMe(remembered.assignToMe);
    }, [remembered]);

    const submit = () =>
        run(async () => {
            if (!id) return;
            const created = await api.createTask(id, { title: title.trim(), notes, due_date: dueDate || undefined, assign_to_me: assignToMe });
            await setLastDestination('task', { id, assignToMe });
            onCreated(created, 'Task added');
        });

    return (
        <Form onSubmit={submit} error={error}>
            <DestinationSelect label="Task list" options={lists} value={id} onChange={setId} empty="You can’t add to any task list yet. Create one in a project first." />
            <label className="field">
                <span>Task</span>
                <input type="text" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={500} required autoFocus />
            </label>
            <label className="field">
                <span>Notes (Markdown)</span>
                <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} />
            </label>
            <div className="row">
                <label className="field">
                    <span>Due</span>
                    <input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
                </label>
                <label className="check" style={{ alignSelf: 'flex-end', paddingBottom: 8 }}>
                    <input type="checkbox" checked={assignToMe} onChange={(e) => setAssignToMe(e.target.checked)} /> Assign to me
                </label>
            </div>
            <Submit busy={busy} label="Add task" disabled={!id || !title.trim()} />
        </Form>
    );
}
