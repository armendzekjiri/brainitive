# Self-hosting Brainitive

Brainitive is open source (AGPL-3.0) and runs on any machine with Docker. One image runs the
web app, the queue worker, the scheduler and the realtime server for live co-editing; PostgreSQL
and Redis run next to it.
Don't want to run servers? [Brainitive Cloud](https://www.brainitive.app/#pricing) is the same
software, hosted and updated for you.

## Requirements

- Docker 24+ with the Compose plugin
- 2 GB RAM (4 GB for teams above ~50 people), 10 GB disk plus your uploads
- A domain name if you want HTTPS without your own reverse proxy
- An SMTP account for invitations, password resets and notifications (set it in `.env` or
  later in the admin console)

## Quick start

No clone and no build: `compose.yaml` runs the published image, it only needs a `.env`.

```bash
mkdir brainitive && cd brainitive
curl -fsSLO https://raw.githubusercontent.com/armendzekjiri/brainitive/main/compose.yaml
curl -fsSL https://raw.githubusercontent.com/armendzekjiri/brainitive/main/.env.docker.example -o .env

# 1. Edit .env: DB_PASSWORD, APP_URL, MAIL_* (MAIL_MAILER=log until you have SMTP)

# 2. Start (downloads the image the first time)
docker compose up -d
```

Open `http://<server>:8080`, register your account, then make it the instance admin (this
also marks its email as verified, so you get in before mail works):

```bash
docker compose exec app php artisan make:instance-admin you@example.com
```

Then open **your avatar → Instance admin**. The console has:

- **Overview** — users, workspaces, storage, failed jobs, mail status.
- **Users** — accounts with workspace / project counts, password reset links (emailed, or
  copied to share yourself), verify email, suspend, instance-admin role, delete.
- **Workspaces** — owner, members, projects; transfer ownership, delete.
- **Settings** — open or invite-only sign-up, email verification, SMTP server + test email.
- **Audit log** — every admin action.

Instance admins manage the instance but **can't read workspace content**: they only see
workspaces they've been invited to, like everyone else.

`compose.yaml` runs `ghcr.io/armendzekjiri/brainitive:latest`, the newest release. Image
tags:

| Tag | What |
|---|---|
| `latest` | Newest release |
| `1.15.0`, `1.15` | A specific release / newest patch of a minor version — pin one with `BRAINITIVE_IMAGE` in `.env` |
| `edge` | Latest commit on `main` (may be unreleased) |

Release notes: GitHub → Releases. To build from source instead, `git clone` the repository,
set `BRAINITIVE_IMAGE=brainitive:local` in `.env` and run `docker compose up -d --build`.

## HTTPS

**Option A — Brainitive gets the certificate itself (simplest).** Point DNS to the server,
then in `.env`:

```dotenv
APP_URL=https://brainitive.example.com
SERVER_NAME=brainitive.example.com
HTTP_PORT=80
HTTPS_PORT=443
```

`docker compose up -d` again — Caddy (inside the image) fetches and renews a Let's Encrypt
certificate. Certificates live in the `caddy_data` volume.

**Option B — behind your own reverse proxy** (nginx, Traefik, Cloudflare Tunnel): keep
`SERVER_NAME=:80`, proxy to port `HTTP_PORT`, set `APP_URL=https://…` and
`TRUSTED_PROXIES` to your proxy's IP or network (e.g. `172.16.0.0/12` for a proxy on the Docker
network) so links and cookies use HTTPS. Avoid `*` unless the app port is reachable only through
the proxy — with `*`, any client can fake its IP (and so dodge login rate limits).

## Updating

```bash
docker compose pull           # the newest image of your tag (latest, or the version you pinned)
docker compose up -d
```

Building from source instead: `git pull && docker compose up -d --build`.

Migrations run automatically when the `app` container starts. Check **Help → Changelog**
for what changed.

## Search

Search ("Find anything": the search page, ⌘K and the MCP `search` tool) is built in: it runs on
the PostgreSQL you already have — full text plus `pg_trgm` for typos and `unaccent` for accents.
There is no extra service to run.

- **Nothing to do after an update.** The first time a version with a new search index starts,
  the `app` container (and the scheduler, every few minutes) notices that the index was never
  built and queues the build; the `queue` container fills it in the background. Search shows
  what is already indexed meanwhile — a few minutes for large instances. New and changed content
  is indexed as it is saved.
- **Extensions.** The migration creates `pg_trgm` and `unaccent` itself (the bundled `db`
  container allows that). On your own PostgreSQL the database user needs to be allowed to create
  them (owner of the database, PostgreSQL 13+); otherwise search still works, just without typo
  tolerance / accent folding. After installing them later run
  `docker compose exec app php artisan search:reindex --setup`.
- **Rebuild by hand** (never required): `docker compose exec app php artisan search:reindex`
  (`--queue` to run it on the queue, `--type=item` for one kind of content).

## Backups

Back up the database and the `storage` volume (uploads, OAuth keys):

```bash
docker compose exec db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' > brainitive-$(date +%F).sql
docker run --rm -v brainitive_storage:/storage -v "$PWD":/backup alpine tar czf /backup/storage-$(date +%F).tgz -C /storage .
```

(The volume name is `<folder>_storage` — check with `docker volume ls`.) Restore the database
into a fresh `pgdata` volume with `docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB"' < brainitive-….sql`.

## Optional features

| Feature | How |
|---|---|
| Google / GitHub login | Create OAuth apps, set `GOOGLE_*` / `GITHUB_*` in `.env` (callback URLs in `.env.example`) |
| Google Calendar & Tasks | Same Google OAuth app with the Calendar API enabled |
| Web push notifications | `docker compose exec app php artisan webpush:vapid`, copy the keys into `.env` |
| AI writing helpers | Workspace settings → AI (bring your own provider key) |
| MCP (Claude, ChatGPT) | Add `https://<your-domain>/mcp` as a connector; users sign in with OAuth |
| Browser extension | Nothing to set up: in the extension choose **Self-hosted** and enter your domain. It registers itself over OAuth and needs HTTPS (plain http only for `localhost`) |
| Email to helpdesk (forwarding address) | A domain for incoming mail + Postmark or Mailgun, see [Email to helpdesk](#email-to-helpdesk-forwarding-addresses) |

After changing `.env`: `docker compose up -d` (the app container rebuilds its config cache
on start).

## Live co-editing of documents

Several people can edit the same document at once — everyone sees the others' text appear and
their named, coloured carets — and documents keep working offline (changes stay in the browser and
merge when it reconnects). This is the `realtime` service in `compose.yaml`: the same image, running
a small Node server (Hocuspocus / Yjs). It's **included and on by default**, with nothing to set up:

- the browser connects to `/collab` on your Brainitive address; Caddy in the `app` container passes
  it on to the `realtime` service, so there's no extra port and it works behind your own reverse
  proxy too (proxy WebSockets — nginx needs the `Upgrade` / `Connection` headers for `/collab`);
- the `realtime` service reaches the app on the internal port `8081` (not published). It gets neither
  your `.env` nor the storage volume: the `app` container hands it the shared secret (derived from
  `APP_KEY`, or `COLLAB_SECRET` from `.env`) through the small `collab` volume.

The draft → publish workflow doesn't change: people edit the draft together, and **Publish /
Update** makes it live as before. Readers of a published page keep seeing the published version.

**Turn it off:** set `COLLAB_URL=` (empty) in `.env`, delete the `realtime` service from
`compose.yaml` (or `docker compose stop realtime`) and run `docker compose up -d`. Documents are
then edited one person at a time, as before. If the service is down while `COLLAB_URL` is set,
editors see "Live editing unavailable — editing alone" and keep working without it.

### Live updates (bell, presence, typing)

The same `realtime` service also carries **live channels**: the notification bell rings the moment
something arrives (no reload, no 30-second wait), and later features show who else is on an issue,
who is typing and changes to boards and plans as they happen. Nothing to set up:

- the browser uses the same `/collab` address with `?live=1` (no extra proxy rule — if you put
  your own nginx in front, its `/collab` WebSocket location covers it);
- the app tells the service about changes on the internal Docker network
  (`LIVE_PUBLISH_URL=http://realtime:1234`, set by `compose.yaml`; the service accepts that only from
  private networks, without proxy headers and with a key derived from the shared secret — it is never
  reachable through Caddy);
- every message only says *what* changed (ids); browsers fetch the content through the normal,
  permission-checked pages, and access to each channel is re-checked every minute.

**Turn it off** (keeping live co-editing): `LIVE_ENABLED=false` in `.env`. Without the `realtime`
service everything still works — the bell checks every 30 seconds as before.

## Email to helpdesk (forwarding addresses)

Every helpdesk can get an address like `support-k3x9q2m7ab@inbound.example.com` (Helpdesk
settings → **Email** → *Forwarding address*). Teams forward their support mailbox there (Gmail,
Outlook, any alias), and every mail becomes a ticket. Brainitive doesn't run a mail server: an
inbound-mail provider receives the mail and posts it to Brainitive. Until this is set up, the
settings explain it and the webhook endpoints answer 404.

1. **Pick a domain (or subdomain) only for this**, e.g. `inbound.example.com`, and point its MX
   records at the provider:
   - **Postmark:** `MX 10 inbound.postmarkapp.com`. In the server's *Inbound* stream →
     Settings, set *Inbound domain forwarding* to your domain.
   - **Mailgun:** add the domain under *Sending → Domains* (DNS records as shown there; MX
     `mxa.mailgun.org` / `mxb.mailgun.org`, or `mxa.eu.mailgun.org` / `mxb.eu.mailgun.org` in the EU).
2. **Send the mail to Brainitive:**
   - **Postmark:** Inbound stream → Settings → *Webhook URL*:
     `https://postmark:<token>@<your-domain>/api/inbound-mail/postmark` (basic auth) or
     `https://<your-domain>/api/inbound-mail/postmark?token=<token>` (if a proxy in front of
     Brainitive already uses basic auth). `<token>` = a long random string
     (`openssl rand -hex 32`).
   - **Mailgun:** *Receiving → Create route*: expression type *Match recipient*,
     `.*@inbound.example.com`, action *Forward* to `https://<your-domain>/api/inbound-mail/mailgun`,
     and *Stop*. Requests are verified with the *HTTP webhook signing key* (Sending → Webhooks).
3. **`.env`:**

   ```dotenv
   INBOUND_MAIL_DOMAIN=inbound.example.com
   INBOUND_MAIL_PROVIDER=postmark            # or mailgun: which setup the settings page shows
   INBOUND_MAIL_POSTMARK_TOKEN=<token>        # Postmark
   INBOUND_MAIL_MAILGUN_SIGNING_KEY=<key>     # Mailgun
   ```

   Optional limits: `INBOUND_MAIL_MAX_ATTACHMENT_KB` (10240), `INBOUND_MAIL_MAX_ATTACHMENTS` (10),
   `INBOUND_MAIL_MAX_TOTAL_KB` (30720) — larger attachments are dropped, the mail still arrives;
   `INBOUND_MAIL_REPLAY_WINDOW` (900 s) for Mailgun signatures. Your web server / PHP must accept
   request bodies as large as the provider sends (Postmark: up to 35 MB per message, base64
   inflates attachments by a third).

Received mail is processed by the queue worker (the `queue` container), and the webhook answers
at once. A regenerated address replaces the old one immediately. Refused mail (unknown address,
stale or replayed signature) is answered so that the provider does not retry (Postmark 403,
Mailgun 406); a wrong token or signature gets 401.

Prefer your own mailbox over IMAP instead? Helpdesk settings → Email → *Connected mailbox* reads
it directly, no provider needed.

## Email to helpdesk (connected mailbox, IMAP)

Helpdesk settings → **Email** → *Connected mailbox*: Brainitive logs in to a mailbox over IMAP every
minute, turns new mail into tickets (or replies, when someone answers a ticket mail) and sends agent
replies back by email in the same thread. Nothing to set up on the server besides the scheduler:

- **The scheduler must run** (`php artisan schedule:run` every minute; the Docker setup's
  `scheduler` container does it). It runs `app:poll-helpdesk-mailboxes`, which you can also run by
  hand (`--helpdesk=<id>`, `--force` to skip the pause after errors).
- **Use a mailbox only for support.** Only *unread* mail is imported; afterwards it is marked as
  read, or moved to the folder you set. Mail somebody reads in a mail client first is skipped.
- **Credentials:** many providers need an *app password* for IMAP (Gmail / Google Workspace and
  Microsoft 365 with two-step sign-in; Microsoft 365 may need IMAP / SMTP AUTH enabled for the
  mailbox). Passwords are stored encrypted with `APP_KEY` and never shown again.
- **Replies** go out either through the app's mailer (`MAIL_*`, from your app address with the
  helpdesk's name and `Reply-To` the support address — this needs no DNS changes) or through the
  mailbox's own SMTP server (from the support address itself; its SPF / DKIM then apply).
- **Servers must be on the public internet** and use the standard ports (IMAP 143 / 993, SMTP 25 /
  465 / 587 / 2525) with SSL/TLS or STARTTLS. Local / private mail servers are refused (the server
  connects to what users type in); for local development only, `APP_ENV=local` plus
  `HELPDESK_MAIL_ALLOW_PRIVATE_HOSTS=true` allows them and unencrypted connections.
- **Limits:** messages above `HELPDESK_MAIL_MAX_MESSAGE_MB` (25) are not downloaded (listed as
  "too large" under *Recently received*); attachments follow the public upload rules (10 MB each,
  10 per message, no programs or scripts).
- **Loops:** auto-replies, bounces, mailing lists and Brainitive's own mail are ignored, and one
  sender can open at most 10 tickets per hour and helpdesk. *Recently received* in the settings
  shows what came in and why something was ignored or rejected.

## Legal pages (imprint, privacy, terms)

A self-hosted install never shows Brainitive Cloud's operator data. `/privacy`, `/terms` and `/cookies`
show a short notice about *your* installation; `/imprint` appears once you set your details. Set them
in `.env` (all optional, see `.env.example` → "Legal pages"):

```bash
LEGAL_OPERATOR_NAME="Example GmbH"
LEGAL_ADDRESS="Example Street 1\n12345 Town, Germany"
LEGAL_EMAIL=privacy@example.com
LEGAL_PRIVACY_URL=https://example.com/privacy   # link your own documents instead
LEGAL_TERMS_URL=https://example.com/terms
```

The DPA, sub-processor list and refund policy are Brainitive Cloud documents and return 404 on your
install. Each workspace can add its own legal name, privacy policy and imprint (Workspace settings →
Legal); they're shown on its support pages, forms and portals. Details: `docs/legal/README.md`.

## Security notes

- **Uploads are served from `/storage` and must never run as code.** The Docker image ships
  `docker/Caddyfile`, which answers 404 for `*.php` under `/storage` and serves every upload
  that isn't passive media / a document in a sandbox. Upload names are generated by the server
  from v1.16.1 on. If you run Brainitive **without** Docker, do the same in your web server:

  ```nginx
  # nginx — before your `location ~ \.php$` block
  location ^~ /storage/ {
      location ~* \.(php\d?|phtml|phar|pht|inc)$ { return 404; }
      # passive media / documents: served normally
      location ~* \.(pdf|png|jpe?g|gif|webp|avif|bmp|ico|heic|heif|tiff?|mp3|wav|ogg|oga|m4a|aac|flac|mp4|m4v|mov|webm|avi|mkv|zip|gz|tgz|7z|rar|bz2|tar|bin|docx?|xlsx?|pptx?|odt|ods|odp|csv|tsv|txt|md|json|rtf)$ {
          add_header X-Content-Type-Options nosniff always;
          try_files $uri =404;
      }
      # everything else (HTML, SVG, XML … from older versions): sandboxed, never runs script
      add_header X-Content-Type-Options nosniff always;
      add_header Content-Security-Policy "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox" always;
      try_files $uri =404;
  }
  ```

  ```apache
  # Apache — storage/app/public/.htaccess
  php_flag engine off
  <FilesMatch "\.(php\d?|phtml|phar|pht|inc)$">
      Require all denied
  </FilesMatch>
  ```
- **Check old uploads** after updating: `php artisan uploads:audit` lists files with
  executable or HTML/SVG extensions saved by older versions (`--delete` removes them), and
  separately files whose type isn't on the upload allowlist (review only, never deleted).
  A `.php` file there means someone tried to run code: review your logs.
- **AI endpoints on your own network** (Ollama, LM Studio…) need
  `AI_ALLOW_PRIVATE_ENDPOINTS=true`. By default, server-side requests (AI, link previews)
  only go to public addresses.

## Troubleshooting

- **App key** — generated on the first start and kept in the `storage` volume
  (`storage/app.key`), so back that volume up. To manage it yourself, set `APP_KEY=` in `.env`
  (`docker compose run --rm --no-deps app php artisan key:generate --show`); a key in `.env` wins.
  Never change it on a running instance: sessions, encrypted settings and tokens depend on it.
- **`Database not reachable`** — `DB_PASSWORD` in `.env` must match what PostgreSQL was first
  started with. Changing it later needs `ALTER USER` inside PostgreSQL, or a fresh `pgdata` volume.
- **Upgrading from the old compose file with MariaDB** — those installs never got past the
  first migrations, so there is no data to keep. PostgreSQL uses a new `pgdata` volume, so
  `git pull && docker compose up -d` just works; the old one can go: `docker volume rm <folder>_db`.
- **Sign-up or "forgot password" fails with a mail error** — `MAIL_*` points to a server that
  doesn't exist. Keep `MAIL_MAILER=log` until SMTP works (mails then appear in
  `docker compose logs app`).
- **Logs** — `docker compose logs -f app queue scheduler`.
- **Mails not arriving** — Instance admin → Settings → *Send a test email* shows the mail
  server's error. SMTP saved there overrides `MAIL_*` in `.env`.

## Licence

Self-hosting is free under the [GNU AGPL-3.0](../LICENSE). If you modify Brainitive and offer
it to others over a network, you must publish your changes under the same licence. For a
commercial licence without that obligation, contact hello@brainitive.app.

## Connecting AI apps (MCP)

Apps register themselves when you connect them. Only redirect hosts of known clients (Claude,
ChatGPT, Perplexity, Cursor, VS Code, the browser extension) and `localhost` are accepted. To allow
another client, add its callback host: `MCP_REDIRECT_HOSTS=app.example.com,*.example.org`.

