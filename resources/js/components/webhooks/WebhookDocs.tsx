import { BookOpen } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { CopyButton } from '@/components/leadForms/SharePanel';

const ENVELOPE = `POST <your URL>
Content-Type: application/json
User-Agent: Brainitive-Webhook/1.0
X-Brainitive-Event: item.created
X-Brainitive-Delivery: 5b0f…   (unique per delivery)
X-Brainitive-Timestamp: 1761300000
X-Brainitive-Signature: sha256=9c1e…

{
  "id": "1d6f…",                 // event id: the same on a redelivery
  "event": "item.created",
  "occurred_at": "2026-10-24T09:30:00+00:00",
  "workspace": { "id": 1, "slug": "acme", "name": "Acme" },
  "actor": { "id": 7, "name": "Erin Owner" },   // null for portal / system events
  "data": {
    "item": {
      "id": 42, "key": "WEB-12", "title": "Homepage hero",
      "url": "https://…/boards/main-board/items/42",
      "status": "To Do", "assignee": { "id": 9, "name": "Max" }, …
    }
  }
}`;

const NODE = `import crypto from 'node:crypto';
import express from 'express';

const app = express();
const SECRET = process.env.BRAINITIVE_WEBHOOK_SECRET;

// keep the raw body: the signature covers the exact bytes
app.post('/brainitive-webhook', express.raw({ type: 'application/json' }), (req, res) => {
    const timestamp = req.get('X-Brainitive-Timestamp') ?? '';
    const signature = req.get('X-Brainitive-Signature') ?? '';
    const expected = 'sha256=' + crypto
        .createHmac('sha256', SECRET)
        .update(\`\${timestamp}.\${req.body}\`)
        .digest('hex');

    const valid = signature.length === expected.length
        && crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected));
    const fresh = Math.abs(Date.now() / 1000 - Number(timestamp)) < 300;
    if (!valid || !fresh) return res.sendStatus(401);

    const event = JSON.parse(req.body.toString('utf8'));
    console.log(event.event, event.data);
    res.sendStatus(204); // answer 2xx quickly; do slow work afterwards
});`;

const PHP = `<?php
$secret = getenv('BRAINITIVE_WEBHOOK_SECRET');
$body = file_get_contents('php://input');           // the raw body
$timestamp = $_SERVER['HTTP_X_BRAINITIVE_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_BRAINITIVE_SIGNATURE'] ?? '';

$expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

if (! hash_equals($expected, $signature) || abs(time() - (int) $timestamp) > 300) {
    http_response_code(401);
    exit;
}

$event = json_decode($body, true);
// $event['event'], $event['data'] …
http_response_code(204);`;

function Code({ code }: { code: string }) {
    return (
        <div className="relative">
            <div className="absolute right-2 top-2"><CopyButton value={code} /></div>
            <pre className="max-h-[28rem] overflow-auto rounded-xl bg-muted p-4 pr-24 text-xs leading-relaxed"><code>{code}</code></pre>
        </div>
    );
}

/** How a receiver reads and verifies deliveries (BRAI-104). */
export default function WebhookDocs() {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-lg"><BookOpen className="size-5" />Payload and signature</CardTitle>
                <CardDescription>
                    Every delivery is a JSON POST with the same envelope. Verify it with your webhook’s secret: the signature is
                    an HMAC-SHA256 of <code>{'{timestamp}.{raw body}'}</code>. Reject old timestamps to stop replays, and answer with a
                    2xx within 10 seconds. Server errors (5xx, 429) and timeouts are retried after 30 s, 2 min and 10 min when a
                    queue worker runs; other responses are not. After 10 failed deliveries in a row the webhook is disabled and
                    the workspace admins are notified.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Tabs defaultValue="envelope">
                    <TabsList>
                        <TabsTrigger value="envelope">Envelope</TabsTrigger>
                        <TabsTrigger value="node">Verify in Node</TabsTrigger>
                        <TabsTrigger value="php">Verify in PHP</TabsTrigger>
                    </TabsList>
                    <TabsContent value="envelope"><Code code={ENVELOPE} /></TabsContent>
                    <TabsContent value="node"><Code code={NODE} /></TabsContent>
                    <TabsContent value="php"><Code code={PHP} /></TabsContent>
                </Tabs>
            </CardContent>
        </Card>
    );
}
