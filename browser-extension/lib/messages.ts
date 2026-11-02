import { browser } from 'wxt/browser';
import type { Api } from './api';

/** The API calls an extension page can ask the background to make (`lib/client.ts`). */
export type ApiMethod = keyof Api;

/**
 * Messages from the popup / side panel / options page to the background worker. There is no
 * message that returns a token: the background makes the API calls (Codex CLI/extension
 * review 8), and it only answers the extension's own pages (`isExtensionPage`).
 */
export type Message =
    | { type: 'sign-in'; server: string }
    | { type: 'sign-out' }
    | { type: 'api'; method: ApiMethod; args: unknown[] }
    | { type: 'capture-area'; tabId: number };

export type Reply<T = unknown> =
    | { ok: true; data: T }
    | { ok: false; error: string; signedOut?: boolean; status?: number; errors?: Record<string, string[]> };

/** An error the background reported; `status` / `errors` are set for API errors. */
export class BackgroundError extends Error {
    constructor(
        message: string,
        public status?: number,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

export async function send<T>(message: Message): Promise<T> {
    const reply = (await browser.runtime.sendMessage(message)) as Reply<T> | undefined;
    if (!reply) {
        throw new Error('The extension’s background worker didn’t answer. Try again.');
    }
    if (!reply.ok) {
        const error = new BackgroundError(reply.error, reply.status, reply.errors);
        if (reply.signedOut) {
            error.name = 'NotSignedInError';
        }
        throw error;
    }
    return reply.data;
}
