# BRAI-345 — "Live editing unavailable — editing alone" and it doesn't recover

**Branch:** fix/live-editing-unavailable
**Status:** done (focused tests green; full gate by the lead)
**Started:** 2026-10-30

## Problem
Users see "Live editing unavailable — editing alone. Try again" in issue descriptions
(`components/item/ItemDetail.tsx`) and documents (`Pages/Pages/Show.tsx`) and it never recovers.

## Findings (code)
1. `useLiveDocument` gives up after **2 socket disconnects or 5 s** during the first connect and then
   **destroys the provider** — no automatic retry ever, until the user clicks "Try again". A realtime
   restart (every deploy restarts the service), a slow access check / document load in Laravel, a
   phone or laptop waking up is enough.
2. Issue descriptions: when the `items.collab.session` question fails (network, 5xx, 419, 6 s
   timeout) the banner's **"Try again" calls `retryProbe`, which resets state but never re-runs the
   probe effect** (its deps don't change) → stuck on the read-only "connecting" view for good.
3. Documents: "Try again" runs `flushSave()` (never rejects) and switches to live even when that save
   failed → the edits made alone stay only in `pendingChangesRef` and the live editor opens without
   them. Issue descriptions: "Try again" switches without saving the text typed alone at all.
4. Nothing says *why*: the server refuses with a bare "Not authorized"/4401 for every cause (bad or
   expired token, no access, stale epoch, Laravel unreachable from the realtime server, internal API
   401/404/429/5xx) and logs nothing; the client shows one sentence.
5. A refused connection retries every ~1 s without backoff (the socket had opened, so the provider's
   backoff restarts) and re-fetches a session each time.
6. The page-load token is reused for a later first attempt even when it expired meanwhile.

## Approach
- **Server** (`realtime/src/server.js`, `token.js`): classify each refusal (`invalid-token`,
  `expired-token`, `wrong-document`, `document-replaced`, `access-denied`, `app-unreachable`,
  `app-error-<status>`), send it as the permission-denied reason and the 4401 close reason, log one
  line (`[realtime] refused …`) so `journalctl` shows the cause.
- **Client** (`useLiveDocument`): first connect tolerates transient failures (backoff, up to 3 failed
  attempts / 10 s; two refusals in a row fail fast); every failure is classified (`unreachable`,
  `refused`, `timeout`, `session`) → `failure` + `console.warn`. In fallback a background probe
  (throw-away provider, no awareness, no IndexedDB) retries with backoff 5 s → 60 s, at once on
  `online` / tab visible; success → `recovered`. Refusals while live back off in the token function.
  A cached first token is only used while it is still valid.
- **Hosts**: banner shows the reason and "Retrying automatically…" / "Live editing is available
  again". Switching to live (button or automatic) never drops text: documents switch only after the
  single-user save really stored everything (automatic switch only after 5 s without typing);
  descriptions save the text typed alone first (automatic switch only when nothing was typed).
  `useLiveItemDescription`: the probe re-runs on "Try again" (generation counter) and retries in the
  background too.

## Test plan
- `realtime/test/server.test.js`: refusal reasons reach the client (token, document, access, app down).
- e2e `e2e/tests/live-recovery.spec.ts` (own realtime server on `COLLAB.port + 5`): document — server
  down → banner with reason, autosave works, server back → live again by itself, text kept; issue
  description — server down → reason, text typed alone, server back → "available again" → switch keeps
  the text; session request failing → "Try again" really reconnects; wrong secret → "refused".
- PHPUnit: no Laravel change planned (if one comes: filter on the collab tests).
- `npx tsc --noEmit`, `pint --test` on changed PHP files.

## Docs
ARCHITECTURE realtime section (fallback + recovery), KNOWLEDGE (root causes + server checks).

## Infra (not fixable in code — for the user)
Strong suspect (from the user): the server can't reach its own public IP (firewall / no hairpin NAT),
so the realtime server's calls to `COLLAB_APP_URL` (= public APP_URL) fail → every connection refused.
Added `COLLAB_APP_HOST` (`realtime/src/appFetch.js`): `COLLAB_APP_URL=https://127.0.0.1` +
`COLLAB_APP_HOST=www.brainitive.app` calls nginx on loopback with the public Host header and TLS name.
Checks / commands: KNOWLEDGE → "Live editing unavailable … (BRAI-345)".
