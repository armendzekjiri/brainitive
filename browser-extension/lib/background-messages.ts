import { browser, type Browser } from 'wxt/browser';
import { ApiError, type Api } from './api';
import { NotSignedInError } from './auth';
import type { Message, Reply } from './messages';

export interface Handlers {
    /** The API client with the background's token provider. */
    api: Api;
    signIn: (server: string) => Promise<{ server: string }>;
    signOut: () => Promise<{ revoked: boolean }>;
    captureArea: (tabId: number) => Promise<void>;
}

/**
 * Only the extension's own pages (popup, side panel, options — also the popup opened as a
 * window, which has a tab) may send privileged messages. A content script of this extension
 * runs on a web page (its URL is the page's), another extension has another id.
 */
export function isExtensionPage(sender: Browser.runtime.MessageSender): boolean {
    if (sender.id !== browser.runtime.id || !sender.url) return false;
    try {
        return new URL(sender.url).origin === new URL(browser.runtime.getURL('/')).origin;
    } catch {
        return false;
    }
}

/**
 * The background's `runtime.onMessage` handler. Tokens never leave the background (Codex
 * CLI/extension review 8): pages send `api` messages and get the result, not a token.
 */
export function createMessageHandler(handlers: Handlers) {
    return async function handle(message: Message, sender: Browser.runtime.MessageSender): Promise<Reply> {
        if (!isExtensionPage(sender)) {
            return { ok: false, error: 'Not allowed.' };
        }
        try {
            switch (message.type) {
                case 'sign-in':
                    return { ok: true, data: await handlers.signIn(message.server) };
                case 'sign-out':
                    return { ok: true, data: await handlers.signOut() };
                case 'api': {
                    if (!Object.hasOwn(handlers.api, message.method) || !Array.isArray(message.args)) {
                        return { ok: false, error: 'Unknown API call.' };
                    }
                    const call = handlers.api[message.method] as (...args: unknown[]) => Promise<unknown>;
                    return { ok: true, data: await call(...message.args) };
                }
                case 'capture-area':
                    await handlers.captureArea(message.tabId);
                    return { ok: true, data: null };
            }
        } catch (error) {
            if (error instanceof ApiError) {
                return { ok: false, error: error.message, status: error.status, errors: error.errors };
            }
            return { ok: false, error: (error as Error).message, signedOut: error instanceof NotSignedInError };
        }
        return { ok: false, error: 'Unknown message.' };
    };
}
