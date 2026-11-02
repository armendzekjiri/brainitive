import { browser } from 'wxt/browser';
import { bumpGeneration, forgetClient, getClient, getGeneration, getSession, setClient, setLastServer, setSession } from './storage';
import type { Session } from './types';

/**
 * OAuth 2.1 + PKCE against the Brainitive server — the same Passport setup the MCP
 * connectors use. The extension registers itself on each server (Dynamic Client
 * Registration), so self-hosted instances need no setup. Runs in the background only:
 * the popup closes as soon as the sign-in window opens.
 */

export const SCOPE = 'capture';

export class NotSignedInError extends Error {
    constructor(message = 'Sign in to Brainitive first.') {
        super(message);
        this.name = 'NotSignedInError';
    }
}

interface ServerMetadata {
    authorization_endpoint: string;
    token_endpoint: string;
    registration_endpoint: string;
}

interface TokenResponse {
    access_token: string;
    refresh_token?: string;
    expires_in: number;
}

/**
 * `brainitive.example.com` → `https://brainitive.example.com` (origin only, no path).
 * Local addresses without a scheme (`localhost:8000`) default to http.
 */
export function normalizeServer(input: string): string {
    const value = input.trim();
    const local = /^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?(\/|$)/i.test(value);
    const url = new URL(/^https?:\/\//i.test(value) ? value : `${local ? 'http' : 'https'}://${value}`);
    return url.origin;
}

const LOOPBACK = new Set(['localhost', '127.0.0.1', '[::1]']);

/**
 * The background's own check of a server origin (the sign-in form checks too, but any
 * extension page can send `sign-in`): https, or plain http only on an exact loopback
 * address; no user name / password, path, query or fragment.
 */
export function checkServer(server: string): void {
    let url: URL;
    try {
        url = new URL(server);
    } catch {
        throw new Error(`${server} is not a valid server address.`);
    }
    if (url.username || url.password || url.origin !== server) {
        throw new Error(`${server} is not a plain server address (https://host[:port]).`);
    }
    if (url.protocol === 'https:') return;
    if (url.protocol === 'http:' && LOOPBACK.has(url.hostname)) return;
    throw new Error(`Use https for ${url.host}: plain http only works for localhost, 127.0.0.1 and [::1].`);
}

/**
 * True when `endpoint` is on exactly the server's origin (scheme, host and port) without
 * credentials in the URL. OAuth metadata, the stored token endpoint and every token request
 * must pass this (Codex CLI/extension review 4): a code + PKCE verifier or a refresh token
 * never goes to another origin.
 */
export function sameOrigin(server: string, endpoint: string): boolean {
    try {
        const url = new URL(endpoint);
        return !url.username && !url.password && url.origin === new URL(server).origin;
    } catch {
        return false;
    }
}

export async function signIn(server: string): Promise<Session> {
    checkServer(server);
    const metadata = await discover(server);
    const redirectUri = browser.identity.getRedirectURL();

    let client = await getClient(server);
    if (!client || client.redirectUri !== redirectUri) {
        client = { clientId: await register(metadata, redirectUri), redirectUri };
        await setClient(server, client);
    }

    const verifier = randomString(64);
    const state = randomString(32);
    const authorizeUrl = new URL(metadata.authorization_endpoint);
    authorizeUrl.search = new URLSearchParams({
        response_type: 'code',
        client_id: client.clientId,
        redirect_uri: redirectUri,
        scope: SCOPE,
        state,
        code_challenge: await challenge(verifier),
        code_challenge_method: 'S256',
    }).toString();

    const responseUrl = await browser.identity.launchWebAuthFlow({ url: authorizeUrl.toString(), interactive: true });
    if (!responseUrl) {
        throw new Error('Sign-in was cancelled.');
    }

    const params = new URL(responseUrl).searchParams;
    if (params.get('error')) {
        throw new Error(params.get('error') === 'access_denied' ? 'Sign-in was cancelled.' : params.get('error_description') || 'Sign-in failed.');
    }
    if (params.get('state') !== state || !params.get('code')) {
        throw new Error('Sign-in failed: the response didn’t match the request.');
    }

    const tokens = await tokenRequest(metadata.token_endpoint, {
        grant_type: 'authorization_code',
        client_id: client.clientId,
        redirect_uri: redirectUri,
        code_verifier: verifier,
        code: params.get('code')!,
    }).catch(async (error: Error) => {
        // The server forgot this client (e.g. a reset database): register again next time.
        if (error.message.includes('invalid_client')) {
            await forgetClient(server);
        }
        throw error;
    });

    const session: Session = {
        server,
        tokenEndpoint: metadata.token_endpoint,
        clientId: client.clientId,
        redirectUri,
        ...sessionTokens(tokens, null),
    };
    await exclusive(async () => {
        await bumpGeneration(); // a refresh still running for the previous session must not land
        await setSession(session);
    });
    await setLastServer(server);

    return session;
}

/** Forget the tokens and everything cached from the account. */
export async function signOut(): Promise<void> {
    await exclusive(async () => {
        await bumpGeneration();
        await setSession(null);
        await browser.storage.local.remove(['destinations', 'lastDestination']);
    });
}

/*
 * Session writes are serialized, and each sign-in / sign-out starts a new session
 * "generation" (stored, so it survives the worker being restarted). A refresh remembers the
 * generation it started in and only stores its result — or signs out after a failure — if
 * that generation is still current. Without this, a refresh answered after a sign-out signed
 * the user back in, and one answered after a new sign-in overwrote (or deleted) the new
 * session (Codex CLI/extension review 3). All auth runs in the background, so an in-memory
 * queue is enough to make check-and-write atomic.
 */
let queue: Promise<unknown> = Promise.resolve();

function exclusive<T>(task: () => Promise<T>): Promise<T> {
    const run = queue.then(task, task);
    queue = run.catch(() => undefined);
    return run;
}

let refreshing: Promise<Session> | null = null;

/**
 * A valid access token, refreshed when it is about to expire. Refresh tokens are single
 * use, so all contexts get their token from here (one refresh at a time).
 */
export async function getAccessToken(force = false): Promise<{ token: string; server: string }> {
    let { session, generation } = await exclusive(async () => ({ session: await getSession(), generation: await getGeneration() }));
    if (!session) {
        throw new NotSignedInError();
    }

    if (force || session.expiresAt - 60_000 < Date.now()) {
        refreshing ??= refresh(session, generation).finally(() => (refreshing = null));
        session = await refreshing;
    }

    return { token: session.accessToken, server: session.server };
}

/** Clears the session — but only the one this refresh belonged to. */
async function expire(generation: number): Promise<never> {
    await exclusive(async () => {
        if ((await getGeneration()) === generation) await setSession(null);
    });
    throw new NotSignedInError('Your Brainitive session expired. Sign in again.');
}

async function refresh(session: Session, generation: number): Promise<Session> {
    if (!session.refreshToken || !sameOrigin(session.server, session.tokenEndpoint)) {
        return expire(generation);
    }

    let tokens: TokenResponse;
    try {
        tokens = await tokenRequest(session.tokenEndpoint, {
            grant_type: 'refresh_token',
            client_id: session.clientId,
            refresh_token: session.refreshToken,
            scope: SCOPE,
        });
    } catch (error) {
        if (error instanceof TypeError) {
            throw error; // offline — keep the session
        }
        return expire(generation);
    }

    return exclusive(async () => {
        if ((await getGeneration()) !== generation) {
            // Signed out or signed in again meanwhile: drop these tokens and hand out the
            // current session (if any), never the old one's fresh token.
            const current = await getSession();
            if (!current) throw new NotSignedInError();
            return current;
        }
        const next = { ...session, ...sessionTokens(tokens, session.refreshToken) };
        await setSession(next);
        return next;
    });
}

async function discover(server: string): Promise<ServerMetadata> {
    let response: Response;
    try {
        response = await fetch(`${server}/.well-known/oauth-authorization-server`, { headers: { Accept: 'application/json' }, redirect: 'error' });
    } catch {
        throw new Error(`Couldn’t reach ${server}. Check the address and your connection.`);
    }
    const metadata = response.ok ? ((await response.json().catch(() => null)) as ServerMetadata | null) : null;
    if (!metadata?.authorization_endpoint || !metadata.token_endpoint || !metadata.registration_endpoint) {
        throw new Error(`${server} doesn’t look like a Brainitive server.`);
    }
    for (const endpoint of [metadata.authorization_endpoint, metadata.token_endpoint, metadata.registration_endpoint]) {
        if (!sameOrigin(server, endpoint)) {
            throw new Error(`${server}’s sign-in settings point at another address (${endpoint}). Not signing in.`);
        }
    }
    return metadata;
}

async function register(metadata: ServerMetadata, redirectUri: string): Promise<string> {
    const response = await fetch(metadata.registration_endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ client_name: `Brainitive for ${browserName()}`, redirect_uris: [redirectUri] }),
        redirect: 'error',
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok || !body.client_id) {
        throw new Error(body.error_description || 'The server refused to register the extension.');
    }
    return String(body.client_id);
}

async function tokenRequest(endpoint: string, params: Record<string, string>): Promise<TokenResponse> {
    const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
        body: new URLSearchParams(params),
        // A 307/308 would replay the code + verifier or the refresh token elsewhere (Codex CLI/extension review 5).
        redirect: 'error',
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok || !body.access_token) {
        throw new Error(`${body.error ?? 'token_error'}: ${body.error_description ?? body.message ?? 'Sign-in failed.'}`);
    }
    return body as TokenResponse;
}

function sessionTokens(tokens: TokenResponse, previousRefresh: string | null) {
    return {
        accessToken: tokens.access_token,
        refreshToken: tokens.refresh_token ?? previousRefresh,
        expiresAt: Date.now() + tokens.expires_in * 1000,
    };
}

function randomString(length: number): string {
    const bytes = crypto.getRandomValues(new Uint8Array(length));
    return base64Url(bytes).slice(0, length);
}

async function challenge(verifier: string): Promise<string> {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
    return base64Url(new Uint8Array(digest));
}

function base64Url(bytes: Uint8Array): string {
    let binary = '';
    bytes.forEach((byte) => (binary += String.fromCharCode(byte)));
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function browserName(): string {
    const agent = navigator.userAgent;
    if (agent.includes('Firefox/')) return 'Firefox';
    if (agent.includes('Edg/')) return 'Edge';
    return 'Chrome';
}
