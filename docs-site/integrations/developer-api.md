---
title: Developer API
description: "Use Brainitive from your own scripts and apps: create a personal API key, limit it to workspaces, projects or boards and to the areas it needs, and call the JSON API with curl, JavaScript or Python."
order: 84
---

# Developer API

Brainitive has a JSON API under `/api/v1` — the same one the Brainitive desktop, mobile and TV apps use. With a **personal API key** your own scripts and apps can use it too: create issues from a CI job, move cards when a deploy finishes, pull time logs into a spreadsheet, or sync tasks with another tool.

API keys are available to everyone, on every plan of Brainitive Cloud and on self-hosted servers. Rate limits protect the server (see [Rate limits](#rate-limits)).

- **API reference:** [docs.brainitive.app/api](/api) — every endpoint, its parameters and answers, and what a key needs for it.
- **Base URL:** `https://www.brainitive.app/api/v1` on Brainitive Cloud, `https://<your server>/api/v1` when self-hosted.

## Create a key

1. Open **Profile → API keys** (`/profile/api-keys`) and click **Create API key**.
2. Give it a name you will recognise later, for example "Deploy script".
3. Choose **where** it works and **what** it may do (below), whether it may **delete**, and when it **expires** (30 days, 90 days, 1 year or never).
4. Click **Create key** and copy the key right away. It starts with `brai_` and is shown **only once**: Brainitive stores only a fingerprint of it. If you lose it, regenerate the key (the old secret stops working) or create a new one.

You get an email whenever a key is created or regenerated on your account. Keep keys like passwords: in a secret store or environment variable, never in code or a public repository.

## Where: the scope

| Scope | The key reaches |
|---|---|
| **All my workspaces** | Every workspace you belong to, including ones you join later |
| **Chosen workspaces** | Only those workspaces |
| **Chosen projects** | Only those projects. Workspace-wide content (snippets, bookmarks, workspace members and insights) is not included; the workspace itself can be read so its projects can be listed |
| **Chosen boards** | **Issues & boards** only sees those boards (board lists, issues, sprints, backlog, dated issues). Other areas you grant see the whole projects those boards belong to — for example their docs or task lists. Project-wide issue summaries (health, workload) and search are not available to such a key |

A key never reaches more than you can: it acts as you, and every permission check of the web app still applies (a viewer's key can't edit issues). If you lose access to a project, your keys lose it too.

Your personal data — your capture inbox, calendar, bookings and timer — belongs to no workspace: for those only the area counts.

## What: areas and levels

Each area is **None**, **Read** or **Read + write**. Write means create, update and move — not delete.

| Area | Covers |
|---|---|
| **Issues & boards** | Boards, issues, comments, watchers, attachments, issue links and subtasks, sprints and backlog, project config, dated issues, project health and workload |
| **Docs & whiteboards** | Docs, folders, versions, reviews, page links, whiteboards, polls in docs, resolving Brainitive links |
| **Plans & releases** | Gantt plans and releases |
| **My Work, tasks & captures** | My Work, My Day, Today, task lists and to-dos, your capture inbox |
| **Time tracking** | Your timer, work logs and timesheet |
| **Calendar & bookings** | Calendar events, online meetings, bookings |
| **Helpdesk, leads & feedback** | Helpdesk tickets, lead forms and leads, feedback and client portals |
| **Snippets & bookmarks** | Snippet and bookmark collections (keys over whole workspaces only) |
| **Members** | Workspace and project members (read only) |

**Delete** is a separate permission, off by default. Without it a key gets `403 key_ability_denied` on every `DELETE` that removes something (issues, docs, comments, files, tasks, work logs …). Taking an issue off My Day, unwatching or unlinking issues is a write, not a delete.

Answers that combine areas only contain the parts the key can read: **Today** (`GET /me/today`) and the calendar agenda include your connected calendars and bookings only with **Calendar & bookings**, issues only with **Issues & boards** and to-dos only with **My Work**; resolving Brainitive links and a doc's related content leave out anything outside the key's scope or areas, as if it didn't exist. Workspace insights need read on every area, and project health needs **Issues & boards** and **Plans & releases**.

Some endpoints need no area: `GET /me` (who the key acts as), `GET /workspaces`, `GET /workspaces/{slug}`, `GET /workspaces/{slug}/projects` and `GET /projects/{id}` — all limited to the key's scope, and a project only lists the sections of areas the key can read. Uploading a file for an issue or doc (`POST /uploads`) needs write on Issues & boards or Docs & whiteboards. Search (`GET /search`) needs read on every content area and a key not limited to boards.

### Never available with an API key

These stay with the Brainitive apps and the web, whatever a key is allowed: signing in or out, changing your profile, password or avatar, devices, invitations, creating workspaces or projects, inviting members, the sidebar, notifications and their settings, the private calendar feed link, connected apps, "Download my data", API key management, live updates (realtime), live co-editing of docs and whiteboards (edit a doc with `PATCH /pages/{uuid}` instead) and offline sync. They answer `403 key_endpoint_not_allowed`. The [API reference](/api) marks each of them "API keys: not available".

## Your first request

Send the key as a bearer token:

```bash
curl https://www.brainitive.app/api/v1/me \
  -H "Authorization: Bearer brai_your_key_here"
```

The answer tells you who the key acts as and which workspaces it reaches:

```json
{ "data": { "id": 12, "name": "Alex Doe", "email": "alex@example.com", "workspaces": [ { "slug": "acme", "name": "Acme" } ] } }
```

Requests and answers are JSON (send `Content-Type: application/json` with a body). Dates are ISO-8601 in UTC. Single records come wrapped in `data`.

### List, create and move an issue

```bash
KEY=brai_your_key_here
API=https://www.brainitive.app/api/v1

# the boards of project 42, then the issues of board 7
curl "$API/projects/42/boards" -H "Authorization: Bearer $KEY"
curl "$API/boards/7/items?limit=50" -H "Authorization: Bearer $KEY"

# create an issue on board 7
curl -X POST "$API/boards/7/items" -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"title": "Deploy 2.4.0 failed", "description": "See the CI log."}'

# move issue 1234 to column 31 (GET /boards/7 lists the columns)
curl -X POST "$API/items/1234/move" -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" -d '{"column_id": 31}'
```

### JavaScript (fetch)

```js
const API = 'https://www.brainitive.app/api/v1';
const headers = { Authorization: `Bearer ${process.env.BRAINITIVE_API_KEY}`, 'Content-Type': 'application/json' };

const res = await fetch(`${API}/boards/7/items`, {
    method: 'POST',
    headers,
    body: JSON.stringify({ title: 'Deploy 2.4.0 failed' }),
});
if (!res.ok) {
    const error = await res.json(); // { message, code }
    throw new Error(`${res.status} ${error.code}: ${error.message}`);
}
const { data: issue } = await res.json();
console.log(issue.key, issue.url);
```

The API is meant for servers and scripts (Node.js, Deno, Bun, CI jobs). It sends no CORS headers, so a web page in a browser can't call it directly — and it shouldn't, because the page would expose your key.

### Python (requests)

```python
import os
import requests

API = "https://www.brainitive.app/api/v1"
session = requests.Session()
session.headers["Authorization"] = f"Bearer {os.environ['BRAINITIVE_API_KEY']}"

issues, cursor = [], None
while True:
    params = {"limit": 100, **({"cursor": cursor} if cursor else {})}
    page = session.get(f"{API}/boards/7/items", params=params, timeout=30)
    page.raise_for_status()
    body = page.json()
    issues += body["data"]
    cursor = body["next_cursor"]
    if not cursor:
        break

print(len(issues), "issues")
```

## Pagination

Lists use cursor pagination: `?limit=` (default 50, at most 100) and `?cursor=`. The answer is `{ "data": [...], "next_cursor": "..." }`; pass `next_cursor` back as `cursor` until it is `null`. Many lists also take `updated_since` (ISO-8601) to fetch only what changed.

## Errors

Every error has the same shape: `{ "message": "…", "code": "…" }` (validation errors add `errors: { field: [ … ] }`).

| Status | `code` | Meaning |
|---|---|---|
| 401 | `unauthenticated` | No key, or a key that doesn't exist |
| 401 | `api_key_revoked` / `api_key_expired` | The key was revoked (also when you change your password) or has expired |
| 401 | `api_keys_disabled` | The server's administrator turned API keys off |
| 403 | `key_ability_denied` | The key's area level doesn't cover this ("This key can't write issues & boards."), or it lacks Delete |
| 403 | `key_scope_denied` | Outside the key's workspaces / projects / boards ("This key has no access to project Website.") |
| 403 | `workspace_api_keys_disabled` | The workspace's admins turned API keys off for it |
| 403 | `key_endpoint_not_allowed` | This endpoint is never available with an API key |
| 403 | `forbidden` | You (the key's user) aren't allowed to do this, e.g. a viewer editing |
| 404 | `not_found` | It doesn't exist — or you can't open it (a key never confirms that something you can't see exists) |
| 409 | `conflict` | Someone changed it since you read it |
| 422 | `validation_failed` | Invalid input, see `errors` |
| 429 | `rate_limited` | Too many requests, wait `Retry-After` seconds |

## Rate limits

Per key: **120 reads** (GET) and **60 writes** (everything else) a minute, plus 600 requests a minute per IP address. Every answer carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`; a `429` also `Retry-After` (seconds). Self-hosted servers can change the limits (see below).

Creates accept an optional `request_id` (a UUID): retrying with the same one never creates a duplicate, so a timeout is safe to retry.

## Webhooks

To react to changes instead of polling, use [outgoing webhooks](./webhooks): Brainitive calls your URL when issues, docs, sprints, tickets or leads change.

## Managing keys

- **Profile → API keys** lists your keys with where and what they may do, when they were created, last used and expire. Revoke one and it stops working at once. Regenerate gives a key a new secret with the same settings.
- Keys are also listed under **Profile → Connected apps**.
- Changing or resetting your password, and a suspended account, revoke all your keys.
- **Workspace admins** can turn API keys off for their workspace: **Workspace settings → Legal → Developer API → Allow API keys**. Keys then get `403 workspace_api_keys_disabled` for that workspace's content and no longer list it, but keep working in other workspaces.

## Self-hosting

API keys work out of the box. Settings (see [Configuration](../self-hosting/configuration#developer-api)):

| Variable | Default | What it does |
|---|---|---|
| `API_KEYS_ENABLED` | `true` | `false` turns API keys off for the whole server: every key answers `401 api_keys_disabled` and nobody can create one. Existing keys work again when it's back on |
| `API_KEYS_READ_PER_MINUTE` | `120` | Reads per key and minute |
| `API_KEYS_WRITE_PER_MINUTE` | `60` | Writes per key and minute |

Make sure your reverse proxy forwards `/api/v1`.
