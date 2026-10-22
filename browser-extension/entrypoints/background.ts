import { createApi } from '@/lib/api';
import { getAccessToken, NotSignedInError, signIn, signOut } from '@/lib/auth';
import type { Message, Reply } from '@/lib/messages';
import { cropScreenshot, isRestrictedUrl, selectArea } from '@/lib/page';
import { getSession, setPendingCapture } from '@/lib/storage';
import type { PendingCapture, SearchHit } from '@/lib/types';
import { browser, type Browser } from 'wxt/browser';

const MENUS = [
    { id: 'save-link', title: 'Save link to Brainitive', contexts: ['link'] },
    { id: 'issue-selection', title: 'Create issue from selection', contexts: ['selection'] },
    { id: 'snippet-selection', title: 'Add selection to snippets', contexts: ['selection'] },
    { id: 'doc-selection', title: 'Clip selection to a doc', contexts: ['selection'] },
    { id: 'issue-page', title: 'Create issue from this page', contexts: ['page'] },
    { id: 'save-page', title: 'Save page as bookmark', contexts: ['page'] },
    { id: 'clip-page', title: 'Clip page to a doc', contexts: ['page'] },
    { id: 'screenshot-area', title: 'Screenshot an area…', contexts: ['page', 'selection', 'link', 'image'] },
] as const;

export default defineBackground(() => {
    const api = createApi(getAccessToken);

    browser.runtime.onInstalled.addListener(async () => {
        await browser.contextMenus.removeAll();
        for (const menu of MENUS) {
            browser.contextMenus.create({ id: menu.id, title: menu.title, contexts: [...menu.contexts] as never });
        }
    });

    browser.contextMenus.onClicked.addListener((info, tab) => {
        if (!tab?.id) return;
        const base: PendingCapture = { tabId: tab.id, pageUrl: tab.url, pageTitle: tab.title };

        switch (info.menuItemId) {
            case 'save-link':
                return handOff({ ...base, mode: 'bookmark', linkUrl: info.linkUrl, selectionText: info.selectionText }, tab.windowId);
            case 'issue-selection':
                return handOff({ ...base, mode: 'issue', selectionText: info.selectionText }, tab.windowId);
            case 'snippet-selection':
                return handOff({ ...base, mode: 'snippet', selectionText: info.selectionText }, tab.windowId);
            case 'doc-selection':
                return handOff({ ...base, mode: 'doc', selectionText: info.selectionText }, tab.windowId);
            case 'issue-page':
                return handOff({ ...base, mode: 'issue' }, tab.windowId);
            case 'save-page':
                return handOff({ ...base, mode: 'bookmark' }, tab.windowId);
            case 'clip-page':
                return handOff({ ...base, mode: 'doc', clipWholePage: true }, tab.windowId);
            case 'screenshot-area':
                return captureArea(tab);
        }
    });

    browser.commands.onCommand.addListener(async (command, tab) => {
        if (command !== 'screenshot-area') return;
        const target = tab ?? (await browser.tabs.query({ active: true, currentWindow: true }))[0];
        if (target) await captureArea(target);
    });

    browser.runtime.onMessage.addListener((message: Message, _sender, sendResponse) => {
        handle(message).then(sendResponse);
        return true; // async reply
    });

    async function handle(message: Message): Promise<Reply> {
        try {
            switch (message.type) {
                case 'sign-in': {
                    const session = await signIn(message.server);
                    await openPopup();
                    return { ok: true, data: { server: session.server } };
                }
                case 'sign-out':
                    await signOut();
                    return { ok: true, data: null };
                case 'get-token':
                    return { ok: true, data: await getAccessToken(message.force) };
                case 'capture-area':
                    await captureArea(await browser.tabs.get(message.tabId));
                    return { ok: true, data: null };
            }
        } catch (error) {
            return { ok: false, error: (error as Error).message, signedOut: error instanceof NotSignedInError };
        }
    }

    /** Drag a rectangle on the page → cropped PNG → new issue in the popup. */
    async function captureArea(tab: Browser.tabs.Tab): Promise<void> {
        const base: PendingCapture = { mode: 'issue', tabId: tab.id, pageUrl: tab.url, pageTitle: tab.title };
        if (!tab.id || isRestrictedUrl(tab.url)) {
            return handOff({ ...base, error: 'The browser doesn’t allow screenshots of this page.' }, tab.windowId);
        }

        try {
            const rect = await selectArea(tab.id);
            if (!rect) return; // cancelled
            const shot = await browser.tabs.captureVisibleTab(tab.windowId, { format: 'png' });
            await handOff({ ...base, screenshot: await cropScreenshot(shot, rect) }, tab.windowId);
        } catch (error) {
            await handOff({ ...base, error: `Couldn’t take the screenshot: ${(error as Error).message}` }, tab.windowId);
        }
    }

    async function handOff(capture: PendingCapture, windowId?: number): Promise<void> {
        await setPendingCapture(capture);
        await openPopup(windowId);
    }

    /**
     * The toolbar popup where the browser allows opening it from code (Chrome 127+),
     * otherwise the same page in a small window.
     */
    async function openPopup(windowId?: number): Promise<void> {
        try {
            await browser.action.openPopup(windowId ? { windowId } : undefined);
        } catch {
            await browser.windows.create({ url: browser.runtime.getURL('/popup.html?window=1'), type: 'popup', width: 420, height: 680 });
        }
    }

    // Address bar: "bt <words>" searches Brainitive.
    let searchTimer: ReturnType<typeof setTimeout> | undefined;
    let lastHits: SearchHit[] = [];

    browser.omnibox.setDefaultSuggestion({ description: 'Search Brainitive' });

    browser.omnibox.onInputChanged.addListener((text, suggest) => {
        clearTimeout(searchTimer);
        if (text.trim().length < 2) return;
        searchTimer = setTimeout(async () => {
            try {
                const results = await api.search(text.trim());
                lastHits = [...results.items, ...results.pages, ...results.projects, ...results.bookmarks, ...results.snippets, ...results.workspaces];
                suggest(
                    lastHits.slice(0, 6).map((hit) => ({
                        content: hit.url,
                        description: omniboxText(hit.title, [hit.subtitle, hit.workspace].filter(Boolean).join(' · ')),
                    })),
                );
            } catch (error) {
                lastHits = [];
                browser.omnibox.setDefaultSuggestion({
                    description: error instanceof NotSignedInError ? 'Sign in with the Brainitive toolbar button first' : 'Search Brainitive',
                });
            }
        }, 200);
    });

    browser.omnibox.onInputEntered.addListener(async (text, disposition) => {
        const session = await getSession();
        if (!session) return;

        // Only ever open pages on the signed-in server.
        const url = text.startsWith(`${session.server}/`) ? text : (lastHits[0]?.url ?? session.server);
        if (url !== session.server && !url.startsWith(`${session.server}/`)) return;

        if (disposition === 'currentTab') {
            await browser.tabs.update({ url });
        } else {
            await browser.tabs.create({ url, active: disposition !== 'newBackgroundTab' });
        }
    });
});

/** Chrome renders suggestion markup (so escape it); Firefox shows plain text. */
function omniboxText(title: string, detail: string): string {
    if (import.meta.env.FIREFOX) {
        return detail ? `${title} — ${detail}` : title;
    }
    const escape = (value: string) => value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    return detail ? `${escape(title)} <dim>${escape(detail)}</dim>` : escape(title);
}
