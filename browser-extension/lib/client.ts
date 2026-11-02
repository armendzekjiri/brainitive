import type { Api } from './api';
import { send, type ApiMethod } from './messages';

/**
 * API client for extension pages. The background makes every call with its token and returns
 * the result; pages never see a token (Codex CLI/extension review 8). Same methods as `Api`;
 * arguments and results cross as JSON, so they are plain objects (no FormData / Blob).
 */
export const api = new Proxy({} as Api, {
    get: (_target, method) =>
        typeof method === 'string' ? (...args: unknown[]) => send({ type: 'api', method: method as ApiMethod, args }) : undefined,
});
