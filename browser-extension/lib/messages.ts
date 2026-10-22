import { browser } from 'wxt/browser';

/** Messages from the popup / side panel to the background worker. */
export type Message =
    | { type: 'sign-in'; server: string }
    | { type: 'sign-out' }
    | { type: 'get-token'; force?: boolean }
    | { type: 'capture-area'; tabId: number };

export type Reply<T = unknown> = { ok: true; data: T } | { ok: false; error: string; signedOut?: boolean };

export async function send<T>(message: Message): Promise<T> {
    const reply = (await browser.runtime.sendMessage(message)) as Reply<T> | undefined;
    if (!reply) {
        throw new Error('The extension’s background worker didn’t answer. Try again.');
    }
    if (!reply.ok) {
        const error = new Error(reply.error);
        if (reply.signedOut) {
            error.name = 'NotSignedInError';
        }
        throw error;
    }
    return reply.data;
}
