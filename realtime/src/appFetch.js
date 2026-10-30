// How the realtime server reaches Brainitive's internal API (BRAI-345).
//
// COLLAB_APP_URL defaults to the public APP_URL, so every access check / document load / store goes
// out to the server's own public IP and back in through nginx. A firewall or missing hairpin NAT
// breaks exactly that (the browser's WebSocket still works) → every connection is refused.
// COLLAB_APP_HOST lets the server call the app on loopback instead, e.g.
//   COLLAB_APP_URL=https://127.0.0.1   COLLAB_APP_HOST=www.brainitive.app
// — the request then carries `Host: www.brainitive.app` (nginx picks the right server block) and,
// over https, the TLS name www.brainitive.app (the certificate still verifies). Node's fetch ignores a
// Host header, hence node:http(s) here.
import http from 'node:http';
import https from 'node:https';

/**
 * A fetch-compatible function for the internal API: with `host`, requests go to the URL's address but
 * say `Host: <host>` (and use it as TLS server name); without, the global fetch. Never follows
 * redirects: a 3xx (e.g. http → https) is an answer the caller reports, not something to chase.
 */
export function createAppFetch({ host = '', fetchImpl = globalThis.fetch } = {}) {
    if (!host) {
        return (url, options = {}) => fetchImpl(url, { ...options, redirect: 'manual' });
    }
    return (url, { method = 'GET', headers = {}, body, signal } = {}) => new Promise((resolve, reject) => {
        const target = new URL(url);
        const client = target.protocol === 'https:' ? https : http;
        const request = client.request(target, {
            method,
            headers: { ...headers, Host: host, ...(body !== undefined ? { 'Content-Length': Buffer.byteLength(body) } : {}) },
            ...(target.protocol === 'https:' ? { servername: host.replace(/:\d+$/, '') } : {}),
            signal,
        }, (response) => {
            const chunks = [];
            response.on('data', (chunk) => chunks.push(chunk));
            response.on('error', reject);
            response.on('end', () => {
                const text = Buffer.concat(chunks).toString('utf8');
                const status = response.statusCode ?? 0;
                resolve({
                    status,
                    ok: status >= 200 && status < 300,
                    headers: { get: (name) => response.headers[String(name).toLowerCase()] ?? null },
                    json: async () => JSON.parse(text),
                    text: async () => text,
                });
            });
        });
        request.on('error', reject);
        if (body !== undefined) request.write(body);
        request.end();
    });
}

/** Where a failed call went, for log lines: `POST https://127.0.0.1/api/… (Host www.brainitive.app)`. */
export const describeTarget = (method, url, host) => `${method} ${url}${host ? ` (Host ${host})` : ''}`;
