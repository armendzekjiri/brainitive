# Live channels — the foundation of "Everything live" (BRAI-151, part 1)

**Branch:** feat/live-foundation (from release/v1.44.0)
**Status:** done (ready for the lead to merge into release/v1.44.0)
**Board card:** BRAI-151 (board item 327) · release v1.44.0 "Everything live"

## Problem / goal
Brainitive refreshes most screens only on reload or by polling (the bell every 30 s). v1.44.0 makes
the app live: instant bell, live comments, "who's here" + typing, live issue descriptions, live boards
and plans, live whiteboards. This branch builds the shared **live channel** layer the other v1.44.0
branches build on — pub/sub channels, presence and short client signals over one WebSocket per tab —
and proves it end to end with the notification bell.

Follow-ups on top of this contract: (a) live basics (bell polish, comments, who's here + typing,
issue descriptions), (b) live boards + plans. Live whiteboards run on the Yjs / Hocuspocus document
path (v1.43.0) and don't need this layer.

## Approach

### One process, one port: the realtime server gains live channels
- Same Node process as live co-editing (`realtime/`, Hocuspocus 3.4.4), same port, no new service.
  Everything lives in `realtime/src/live/*`; `server.js` is untouched. `attachLive(server)` adds a
  Hocuspocus extension (`onUpgrade` / `onRequest`) that takes over two kinds of requests and lets
  everything else through to Hocuspocus:
  - **WebSocket upgrades with `?live=1`** (any path, e.g. `/collab?live=1`) or on the path `/live`
    → the live hub (its own `ws` server, 8 KB frames).
  - **`POST /internal/live/publish`** → the publish endpoint (below). Any other `/internal/…` → 404.
- **Multiplexed on the existing `/collab` location** — nginx `location = /collab` and Docker's Caddy
  `handle /collab*` match the path only, so `wss://host/collab?live=1` reaches the same process with
  **no proxy change**. The browser URL = `LIVE_URL` or, by default, `COLLAB_URL` + `?live=1`.
- Off when live editing is off (no `COLLAB_URL` / secret) or with `LIVE_ENABLED=false`.

### Auth: short-lived token listing channels
- `POST /live/token` (`live.token`, auth + verified, `ReadOnlySession`, `throttle:60,1,live.token`)
  body `{channels: string[]}` (1–50) → `{url, token, expires_at, channels: allowed[], denied: [],
  user: {id, name, avatar}}`; 404 when live is off for this request.
- Channels are authorized in **one place**, `App\Support\Live\LiveChannels` (a type → resolver map,
  each resolver runs the existing policy):

  | channel | who may join |
  |---|---|
  | `user.{id}` | that user only |
  | `item.{id}` | `ItemPolicy::view` (item, its board and project not trashed) |
  | `board.{id}` | `BoardPolicy::view` (board + project not trashed) |
  | `plan.{id}` | `PlanPolicy::view` (plan + project not trashed) |
  | `page.{uuid}` | `PagePolicy::view` (drafts: author / project admins only) |

  Every parent up to the workspace must exist (not trashed); a check that throws denies that one
  channel (reported), never the whole batch. Suspended users get nothing. Unknown / malformed names
  (ids > 18 digits included) are denied.
- Token = `base64url(json).base64url(HMAC-SHA256)` like `CollabToken`, key label **`live-token`**
  (derived from `COLLAB_SECRET`, never equal to the collab keys). Payload `{v: 1, typ: 'live', sub,
  name, avatar, ch: [...], iat, exp}`, TTL `LIVE_TOKEN_TTL` (120 s). A channel revoked at time T is
  not granted again by a token with `iat ≤ T` (only by a newer one — Brainitive asked again). The token is sent as the first
  message (`{type: 'auth', token}`), never in the URL. A socket may send more tokens later (same
  `sub`) to add channels without reconnecting.
- **Re-validation:** every 60 s the server asks `POST /api/internal/collab/live/access`
  (`internal.collab.live.access`, existing `VerifyCollabSecret` bearer, reuses nginx's
  `/api/internal/collab/` location and Docker's `:8081` site) `{users: [{id, channels}]}` →
  `{users: {"<id>": [allowed]}}` and drops every channel a user lost (`{type: 'revoked', channel}`).
  Only subscribed channels are checked; grants nobody subscribed to are forgotten at each round (the
  browser asks for a new token when it needs one). A failed request is retried user by user, so one
  bad entry can't shield the others; a user whose check fails 3 rounds in a row is disconnected
  (4403, fail closed). Rounds never overlap.

### Publishing from Laravel
- `App\Support\Live\Live::publish(string $channel, string $event, array $payload = []): void` —
  validated at once (unit tests: throws; elsewhere: reported + dropped), queued in `DB::afterCommit`
  (a rolled-back change publishes nothing) and sent **once per request / queued job / command after
  the response** (`defer(..., 'live.publish')->always()`: one POST for all messages, ≤ 100 per POST)
  to `LIVE_PUBLISH_URL/internal/live/publish` with a 0.3 s connect / 1 s total timeout, **never
  throws, never fails the request**. After a failure publishing pauses for 15 s (cache flag). Tests
  that publish call `$this->withoutDefer()` (or `defer()->invoke()`); phpunit.xml forces
  `LIVE_ENABLED=false` so a developer's `.env` COLLAB_URL never publishes from other tests.
- `LIVE_PUBLISH_URL` = the realtime server's own address, never the public one: prod
  `http://127.0.0.1:6201`, dev `http://127.0.0.1:6202`, Docker `http://realtime:1234`. Empty →
  derived from the live URL when that is `ws://127.0.0.1|localhost:<port>` (local dev, zero config);
  otherwise publishing is off (presence + signals still work, the bell keeps polling).
- The publish endpoint (realtime) requires: `POST`, **no** `X-Forwarded-*` / `X-Real-IP` /
  `Forwarded` header (so it never answers through a proxy), a loopback peer (`LIVE_PUBLISH_FROM=private`
  also allows private networks — Docker), `Authorization: Bearer <hmac(secret, 'live-publish')>`,
  body ≤ 64 KB, `{messages: [{channel, event, payload}]}` (≤ 100) → `202 {delivered}`.

### The payload rule (normative)
Live messages reach every socket subscribed to a channel — including one whose access was revoked
less than a minute ago. So **payloads carry no content**, only what tells a client *what to refetch*:
- ids / uuids, the event type, version / revision numbers, `updated_at`, counts, and short
  non-sensitive hints (a status key, a column id).
- Flat object, ≤ 16 keys from an **allow-list** (`Live::KEY`): `id, ids, uuid, uuids, version,
  revision, count, position, kind, type, action, status, field, fields` and `*_id, *_ids, *_uuid,
  *_uuids, *_at, *_count, *_version, *_revision, *_position, *_kind, *_type, *_status`; values scalar
  (strings ≤ 64 chars) or a list of ≤ 100 scalars; JSON ≤ 2 KB. Anything else (`title`, `name`,
  `body`, `label`, …) is refused — a new key is a conscious change of `Live::KEY` (+ a test). The
  realtime server also refuses nested payloads.
- Clients **refetch through the normal permission-checked endpoints**, so someone who lost access
  between re-checks gets a 403 instead of the data.
- Presence carries the member's id, name and avatar URL (the people already visible to everyone who
  can open that content). Signals carry a small client payload (≤ 1 KB) relayed only to the channel's
  current members; never put content in a signal either (typing = `{}`, a field name at most).

### Presence + signals
- `{type: 'subscribe', channel, presence: true}` announces this socket in the channel; members =
  `{id, name, avatar}` per user (several tabs = one member), sent as a full list
  (`{type: 'presence', channel, members}`) on every change (coalesced 50 ms) to all subscribers.
- `{type: 'signal', channel, name, data}` (name `^[a-z][a-z0-9_.:-]{0,39}$`, data JSON ≤ 1 KB, only
  on a subscribed channel) → `{type: 'signal', channel, name, data, from: {id, name}}` to the
  channel's **other** sockets. The sender is set by the server from the token — never from the client.
- Limits per socket: 40 messages burst / 8 per s (beyond → closed 4429), signals 10 burst / 4 per s
  per person and channel over all their sockets (beyond → dropped, `{type: 'error', code:
  'rate_limited'}`), 50 subscriptions per socket and 100 distinct channels per person, 50
  granted channels (when full, the oldest grant the socket isn't subscribed to is forgotten), 20
  sockets per user, ≤ 500 unauthenticated sockets in total, auth within 10 s, ping every 30 s. The
  re-check sends ≤ 100 users per request (Laravel also merges repeated entries of one user).

### Frontend: one socket per tab — `resources/js/lib/live/`
- `client.ts` — `LiveClient` singleton: ref-counted subscriptions, token fetch (batched, 30 ms),
  reconnect with exponential backoff + jitter (1 s → 30 s), fresh token on every connect, re-auth to
  add channels, `online` → reconnect now, hidden tab > 2 min → disconnect, visible → reconnect.
  Close 4401/4403 → new token; token endpoint 404 / 401 / 419 → stop (live off / signed out).
- `hooks.ts`:
  - `useLiveChannel(channel | null, {onEvent, onResync})` → `{status}`. `onResync` runs whenever
    the subscription comes back after a gap (reconnect): events published meanwhile are lost, refetch.
  - `useLivePresence(channel | null)` → `LiveMember[]` (others and me; announces me).
  - `useLiveSignal(channel | null, name, onSignal?)` → `send(data)`.
- Complete no-op when the shared prop `live` is null (no socket, no token request).
- A channel Brainitive allowed but the server didn't keep (socket full) is marked forbidden instead
  of asking for tokens in a loop; refused / revoked channels are asked for again at most once a
  minute (by a new subscription).
- e2e only (`APP_ENV=e2e`): `window.__brainitiveLive` exposes the client so specs can check presence
  and signals without UI (`e2e/helpers/live.ts` → `liveTab(page)`).

### Reference: the bell
- `LiveServiceProvider` listens to `NotificationSent` (channel `database`) → `Live::publish("user.{id}",
  'notification.created', ['id' => <uuid>])`.
- `useUnreadCount` (bell) subscribes `user.{me}` and refreshes the count; the open bell panel and the
  inbox page reload their feed (first page). The 30 s poll stays as the fallback; `onResync` refreshes
  after a reconnect.

## How to add a channel type
1. `LiveChannels::TYPES`: `'<type>' => [<id pattern>, <resolver>]` — the resolver loads the model
   (not trashed, parents not trashed) and runs the policy.
2. Nothing in `realtime/` (channel names are opaque there: `^[a-z][a-z0-9_]{0,31}\.[A-Za-z0-9_-]{1,64}$`).
3. Tests in `tests/Feature/Live/LiveChannelsTest.php` (allowed / other workspace / trashed / outsider).
4. Publish from where the change happens (`Live::publish("board.{$id}", 'item.moved', ['id' => …])`);
   app-wide hooks go in `LiveServiceProvider`. Subscribe with `useLiveChannel` and refetch.

## Changes
- `config/live.php`; `app/Support/Live/{LiveConfig,LiveToken,LiveChannels,Live}.php`;
  `LiveTokenController` (`live.token`), `LiveInternalController` (`internal.collab.live.access`);
  `LiveServiceProvider`; shared prop `live`.
- `realtime/src/live/{token,hub,publish,access,index}.js`, `realtime/src/index.js` wires it.
- `resources/js/lib/live/{client,hooks,index}.ts`; `useNotifications.ts` subscribes.
- Docker: compose `LIVE_PUBLISH_URL` (app/queue/scheduler) + `LIVE_PUBLISH_FROM=private` (realtime);
  Caddy unchanged (`/collab*` already matches). `docs/self-hosting.md`, `.env.example`.
- e2e: `e2e/helpers/realtime.ts` + global setup starts one live server on `E2E_PORT + 101` for the
  whole run (`live-docs.spec.ts` keeps its own on `+ 100`); specs opt in with `X-E2E-Live: 1`.

## Test plan
- PHPUnit `tests/Feature/Live/`: token endpoint (matrix: own user only, other workspace / project,
  trashed item / board / plan / project / workspace, draft pages, suspended, malformed, off / e2e
  opt-in), shared prop, token signature + key separation, internal access endpoint (auth,
  revocation, suspension, trashed workspace), `Live::publish` (no throw when down, pause after
  failure, afterCommit, one POST per request, request shape), payload rule, bell publish.
- Node `realtime/test/live.test.js`: token verify, join refusal, presence join / leave, signal relay
  only within the channel, sender not spoofable, rate limits (per person), publish needs key +
  loopback + no proxy headers + flat payload, re-check drops channels, old tokens can't re-grant,
  failing entries isolated / fail closed.
- e2e `e2e/tests/live-channel.spec.ts`: notification for B appears in B's open bell without reload;
  two users' presence on the same issue + a typing signal.

## Known limits
- Events published while a tab is disconnected are lost (no replay): subscribers refetch on `onResync`.
- Re-check cost: about two queries per (user, subscribed channel) per minute (policies run as-is,
  no per-project memo yet) — fine for today's sizes; memoize `hasProjectAccess` if it shows up.
- The live bell reloads only the feed's first page (rows loaded with "Load more" drop until scrolled
  again) — agent (a) polishes the bell.
- No per-IP cap on sockets in Node; nginx `limit_conn` (commented in `nginx-collab.conf`) is the knob.

## Production setup (lead)
- No nginx change: live channels ride on `location = /collab` (`?live=1`).
- App `.env`: `LIVE_PUBLISH_URL=http://127.0.0.1:6201` (prod) / `http://127.0.0.1:6202` (dev), then
  `php artisan config:cache`. Without it everything works except server-pushed events (the bell polls).
- Realtime service: restart after deploy (new code); no new env (`LIVE_PUBLISH_FROM` defaults to loopback).
