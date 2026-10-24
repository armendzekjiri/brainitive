# Self-hosting Brainitive

Brainitive is open source (AGPL-3.0) and runs on any machine with Docker. One image runs the
web app, the queue worker and the scheduler; MariaDB and Redis run next to it.
Don't want to run servers? [Brainitive Cloud](https://www.brainitive.app/#pricing) is the same
software, hosted and updated for you.

## Requirements

- Docker 24+ with the Compose plugin
- 2 GB RAM (4 GB for teams above ~50 people), 10 GB disk plus your uploads
- A domain name if you want HTTPS without your own reverse proxy
- An SMTP account for invitations, password resets and notifications (set it in `.env` or
  later in the admin console)

## Quick start

```bash
git clone https://github.com/armendzekjiri/brainitive.git
cd brainitive
cp .env.docker.example .env

# 1. App key — paste the output into .env as APP_KEY=…
docker compose run --rm --no-deps app php artisan key:generate --show

# 2. Edit .env: APP_URL, DB_PASSWORD, DB_ROOT_PASSWORD, MAIL_*

# 3. Start
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

Release notes: GitHub → Releases. To build from source instead, set
`BRAINITIVE_IMAGE=brainitive:local` in `.env` and run `docker compose up -d --build`.

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
git pull                      # or change the image tag
docker compose pull           # skip when you build yourself
docker compose up -d --build
```

Migrations run automatically when the `app` container starts. Check **Help → Changelog**
for what changed.

## Backups

Back up the database and the `storage` volume (uploads, OAuth keys):

```bash
docker compose exec db sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" --single-transaction "$MARIADB_DATABASE"' > brainitive-$(date +%F).sql
docker run --rm -v brainitive_storage:/storage -v "$PWD":/backup alpine tar czf /backup/storage-$(date +%F).tgz -C /storage .
```

(The volume name is `<folder>_storage` — check with `docker volume ls`.)

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

- **`APP_KEY is missing`** — step 1 of the quick start.
- **`Database not reachable`** — `DB_PASSWORD` in `.env` must match what MariaDB was first
  started with. Changing it later needs `ALTER USER` inside MariaDB, or a fresh `db` volume.
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

