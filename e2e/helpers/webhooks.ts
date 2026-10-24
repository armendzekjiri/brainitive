import crypto from 'node:crypto';
import http from 'node:http';
import type { AddressInfo } from 'node:net';
import { SEED } from '../env';

/** Helpers for the outgoing webhooks journey (tests/webhooks.spec.ts, BRAI-104). */

export const webhooksUrl = (workspace: string = SEED.workspace) => `/workspaces/${workspace}/webhooks`;

export interface ReceivedWebhook {
    headers: http.IncomingHttpHeaders;
    body: string;
    json: { id: string; event: string; occurred_at: string; workspace: { slug: string }; actor: { name: string } | null; data: Record<string, any> };
}

/**
 * A receiver on 127.0.0.1 (random port) that records every POST and answers 200. The local e2e
 * app may deliver there because env.ts sets WEBHOOKS_ALLOW_PRIVATE_TARGETS (honoured only with
 * APP_ENV=e2e).
 */
export async function startReceiver(): Promise<{ url: string; received: ReceivedWebhook[]; close: () => Promise<void> }> {
    const received: ReceivedWebhook[] = [];
    const server = http.createServer((req, res) => {
        const chunks: Buffer[] = [];
        req.on('data', (chunk: Buffer) => chunks.push(chunk));
        req.on('end', () => {
            const body = Buffer.concat(chunks).toString('utf8');
            try {
                received.push({ headers: req.headers, body, json: JSON.parse(body) });
            } catch {
                // not JSON: not ours
            }
            res.writeHead(200, { 'Content-Type': 'text/plain' }).end('received');
        });
    });
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
    const { port } = server.address() as AddressInfo;

    return {
        url: `http://127.0.0.1:${port}/hook`,
        received,
        close: () => new Promise<void>((resolve) => server.close(() => resolve())),
    };
}

/** X-Brainitive-Signature as the app computes it: sha256=hmac(secret, "{timestamp}.{body}"). */
export function expectedSignature(secret: string, delivery: ReceivedWebhook): string {
    const timestamp = String(delivery.headers['x-brainitive-timestamp'] ?? '');
    return 'sha256=' + crypto.createHmac('sha256', secret).update(`${timestamp}.${delivery.body}`).digest('hex');
}
