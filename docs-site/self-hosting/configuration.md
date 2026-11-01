---
title: Configuration
description: Every Brainitive environment variable with its default - app, database, mail, queue, cache, realtime, web push, social logins, legal pages and more.
order: 30
---

# Configuration

Brainitive is configured with environment variables in the `.env` file. This page lists every setting that matters for a self-hosted installation, grouped by topic, with its default.

**After changing `.env`:**

- Docker: `docker compose up -d` (the containers are recreated and the configuration is cached again).
- Manual install: `php artisan optimize` and `php artisan queue:restart`.

A "default" is the value Brainitive uses when the variable is not set. The Docker template `.env.docker.example` already sets sensible values for most of them.

::: tip Some settings live in the admin console
Sign-up (open or invite-only), email verification and an SMTP server can also be set in **Instance admin → Settings**. SMTP saved there overrides the `MAIL_*` variables. See the [Instance admin guide](/admin/).
:::

## App

| Variable | Default | What it does |
|---|---|---|
| `APP_NAME` | `Brainitive` | Name shown in emails and the browser title |
| `APP_ENV` | `production` | Keep `production` on a real server |
| `APP_DEBUG` | `false` | Shows detailed error pages. Never `true` on a public server |
| `APP_URL` | `http://localhost` | The address people open, for example `https://brainitive.example.com`. Used for links in emails, OAuth callbacks and secure cookies |
| `APP_KEY` | – | Encryption key. Docker generates it on first start if empty. Never change it on a running installation |
| `APP_PREVIOUS_KEYS` | – | Comma-separated old keys, so data encrypted with them can still be read after a key rotation |
| `APP_LOCALE` | `en` | Language of the app |
| `APP_FALLBACK_LOCALE` | `en` | Language used when a translation is missing |
| `TRUSTED_PROXIES` | – | IP addresses or networks (comma-separated) of your reverse proxy. Needed behind a proxy so Brainitive sees HTTPS and the real client IP. `*` trusts everyone (only if the app can't be reached any other way) |
| `LOG_CHANNEL` | `stack` | Where logs go. Docker uses `stderr` (shown by `docker compose logs`) |
| `LOG_STACK` | `single` | Channels of the `stack` log: `single` (one file) or `daily` |
| `LOG_LEVEL` | `debug` | Minimum level to log. `warning` is a good choice in production |
| `LOG_DAILY_DAYS` | `14` | Days of log files kept with the `daily` channel |

## Docker only

These are read by `compose.yaml`, not by the app.

| Variable | Default | What it does |
|---|---|---|
| `BRAINITIVE_IMAGE` | `ghcr.io/armendzekjiri/brainitive:latest` | Image to run. Pin a version with a tag like `:1.45` |
| `SERVER_NAME` | `:80` | `:80` serves plain HTTP (behind your own proxy). A domain name makes Caddy fetch a TLS certificate |
| `HTTP_PORT` | `8080` | Host port for HTTP |
| `HTTPS_PORT` | `8443` | Host port for HTTPS |

In Docker, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `COLLAB_URL` and `LIVE_PUBLISH_URL` are set by `compose.yaml` itself.

## Database

Brainitive runs on PostgreSQL. Other databases are not supported.

| Variable | Default | What it does |
|---|---|---|
| `DB_CONNECTION` | `sqlite` | Set to `pgsql` |
| `DB_HOST` | `127.0.0.1` | Database server (Docker: `db`) |
| `DB_PORT` | `5432` | Database port |
| `DB_DATABASE` | `laravel` | Database name (Docker template: `brainitive`) |
| `DB_USERNAME` | `root` | Database user (Docker template: `brainitive`) |
| `DB_PASSWORD` | – | Database password. Required in Docker |
| `DB_URL` | – | Alternative: the whole connection as one URL |
| `DB_SSLMODE` | `prefer` | PostgreSQL SSL mode, for example `require` for a remote database |

## Cache, sessions and queue

| Variable | Default | What it does |
|---|---|---|
| `CACHE_STORE` | `database` | `database` or `redis` (Docker template: `redis`) |
| `CACHE_PREFIX` | from `APP_NAME` | Key prefix, useful when several apps share one Redis |
| `SESSION_DRIVER` | `database` | `database` or `redis` (Docker template: `redis`) |
| `SESSION_LIFETIME` | `120` | Minutes of inactivity before a session expires |
| `SESSION_ENCRYPT` | `false` | Encrypt session data |
| `SESSION_DOMAIN` | – | Cookie domain. Leave empty unless you know you need it |
| `SESSION_SECURE_COOKIE` | `true` when `APP_URL` starts with `https://` | Send the session cookie only over HTTPS |
| `SESSION_SAME_SITE` | `lax` | SameSite setting of the session cookie |
| `SESSION_COOKIE` | from `APP_NAME` | Name of the session cookie |
| `QUEUE_CONNECTION` | `database` | `database` or `redis` (Docker template: `redis`) |
| `DB_QUEUE_RETRY_AFTER` | `90` | Seconds before a stuck job on the database queue is retried |
| `REDIS_QUEUE_RETRY_AFTER` | `90` | Same for the Redis queue |

### Redis

Only needed when cache, sessions or queue use Redis.

| Variable | Default | What it does |
|---|---|---|
| `REDIS_CLIENT` | `phpredis` | PHP Redis client (needs the `redis` PHP extension) |
| `REDIS_HOST` | `127.0.0.1` | Redis server (Docker: `redis`) |
| `REDIS_PORT` | `6379` | Redis port |
| `REDIS_PASSWORD` | – | Redis password |
| `REDIS_USERNAME` | – | Redis user (ACL) |
| `REDIS_URL` | – | Alternative: the whole connection as one URL |
| `REDIS_DB` | `0` | Database number for sessions and queue |
| `REDIS_CACHE_DB` | `1` | Database number for the cache |
| `REDIS_PREFIX` | from `APP_NAME` | Key prefix |

## Mail

See [Email](./email) for setup and testing.

| Variable | Default | What it does |
|---|---|---|
| `MAIL_MAILER` | `log` | `smtp` to send mail. `log` only writes mails to the log |
| `MAIL_HOST` | `127.0.0.1` | SMTP server |
| `MAIL_PORT` | `2525` | SMTP port, usually `587` (STARTTLS) or `465` (SSL/TLS) |
| `MAIL_SCHEME` | – | `smtps` for port 465 (SSL/TLS); leave empty for 587 |
| `MAIL_USERNAME` | – | SMTP user |
| `MAIL_PASSWORD` | – | SMTP password |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | Sender address of every email |
| `MAIL_FROM_NAME` | `Example` | Sender name, usually `"${APP_NAME}"` |
| `MAIL_EHLO_DOMAIN` | host of `APP_URL` | Name the server uses to greet the SMTP server |
| `MAIL_URL` | – | Alternative: the whole SMTP connection as one URL |
| `MAIL_SENDMAIL_PATH` | `/usr/sbin/sendmail -bs -i` | Used with `MAIL_MAILER=sendmail` |
| `NOTIFICATION_EMAIL_DIGEST_SECONDS` | `60` | Activity emails (assignments, mentions, comments, status changes, page updates, sprints) wait this many seconds from the first one and go out as one email per person and workspace. `0` sends every email on its own, at once. The bell and push are never delayed. Needs the queue worker; with `QUEUE_CONNECTION=sync` emails are always sent at once |

### Email to helpdesk

| Variable | Default | What it does |
|---|---|---|
| `INBOUND_MAIL_DOMAIN` | – | Domain for helpdesk forwarding addresses (for example `inbound.example.com`). Off while empty |
| `INBOUND_MAIL_PROVIDER` | `postmark` | `postmark` or `mailgun`: which setup the helpdesk settings describe |
| `INBOUND_MAIL_POSTMARK_TOKEN` | – | Long random string, the password in Postmark's webhook URL |
| `INBOUND_MAIL_MAILGUN_SIGNING_KEY` | – | Mailgun's HTTP webhook signing key |
| `INBOUND_MAIL_REPLAY_WINDOW` | `900` | Seconds a Mailgun signature stays valid |
| `INBOUND_MAIL_MAX_ATTACHMENT_KB` | `10240` | Larger attachments are dropped (the mail still arrives) |
| `INBOUND_MAIL_MAX_ATTACHMENTS` | `10` | Maximum attachments per mail |
| `INBOUND_MAIL_MAX_TOTAL_KB` | `30720` | Maximum size of all attachments together |
| `HELPDESK_MAIL_MAX_MESSAGE_MB` | `25` | Connected IMAP mailboxes: larger mails are not downloaded |
| `HELPDESK_MAIL_ALLOW_PRIVATE_HOSTS` | `false` | Only with `APP_ENV=local`: allow mail servers on private networks and unencrypted connections |

## Realtime: live co-editing

Read by the app. See [Realtime server](./realtime).

| Variable | Default | What it does |
|---|---|---|
| `COLLAB_URL` | – (Docker: `/collab`) | Where the browser connects, for example `wss://brainitive.example.com/collab` or the path `/collab`. Live co-editing is off while empty |
| `COLLAB_SECRET` | derived from `APP_KEY` | Secret shared with the realtime server |
| `COLLAB_TOKEN_TTL` | `120` | Seconds a connection token is valid |
| `COLLAB_MAX_STATE_BYTES` | `16777216` | Largest stored state of one document (16 MB) |

## Realtime: live channels

Instant notification bell, presence and typing indicators. They use the same realtime server.

| Variable | Default | What it does |
|---|---|---|
| `LIVE_ENABLED` | `true` | `false` turns live channels off but keeps live co-editing |
| `LIVE_URL` | `COLLAB_URL` + `?live=1` | Where the browser connects for live channels. Normally leave empty |
| `LIVE_PUBLISH_URL` | – (Docker: `http://realtime:1234`) | The realtime server's own internal address, for example `http://127.0.0.1:1234`. Never the public URL. Without it, the bell checks every 30 seconds instead |
| `LIVE_TOKEN_TTL` | `120` | Seconds a channel token is valid for joining |

The realtime server process has its own variables (`COLLAB_HOST`, `COLLAB_PORT`, `COLLAB_APP_URL` …), listed on the [Realtime server](./realtime#environment-variables) page.

## Web push

Browser notifications. Generate keys with `php artisan webpush:vapid --show`.

| Variable | Default | What it does |
|---|---|---|
| `VAPID_SUBJECT` | – | Contact for push services, for example `mailto:admin@example.com` |
| `VAPID_PUBLIC_KEY` | – | Public key. Without it no browser can subscribe |
| `VAPID_PRIVATE_KEY` | – | Private key |
| `VAPID_PEM_FILE` | – | Alternative: path to a PEM file with the key pair |

## Search

Search runs on PostgreSQL and has no settings. See [Search](./search).

## Social logins and calendars

Each login button appears only when its client ID and secret are set. Setup guides: [Google](/integrations/google), [Microsoft](/integrations/microsoft), [GitHub](/integrations/github).

| Variable | Default | What it does |
|---|---|---|
| `GOOGLE_CLIENT_ID` | – | Google OAuth client (login, Google Calendar and Tasks) |
| `GOOGLE_CLIENT_SECRET` | – | |
| `GOOGLE_REDIRECT_URI` | `APP_URL/login/google/callback` | Login callback URL |
| `MICROSOFT_CLIENT_ID` | – | Microsoft Entra app (login, Outlook calendars, Teams meetings) |
| `MICROSOFT_CLIENT_SECRET` | – | |
| `MICROSOFT_TENANT` | `common` | `common` allows personal and work/school accounts |
| `MICROSOFT_REDIRECT_URI` | `APP_URL/calendar/connections/outlook/callback` | Calendar callback URL |
| `MICROSOFT_LOGIN_REDIRECT_URI` | `APP_URL/login/microsoft/callback` | Login callback URL |
| `GITHUB_CLIENT_ID` | – | GitHub OAuth app for "Continue with GitHub" |
| `GITHUB_CLIENT_SECRET` | – | |
| `GITHUB_REDIRECT_URI` | `APP_URL/login/github/callback` | Login callback URL |

### GitHub App (repository connections)

Normally created in **Instance admin → GitHub App**. These variables override the stored app when `GITHUB_APP_ID` is set.

| Variable | Default | What it does |
|---|---|---|
| `GITHUB_APP_ID` | – | App ID |
| `GITHUB_APP_SLUG` | – | App slug (from its URL) |
| `GITHUB_APP_CLIENT_ID` | – | Client ID |
| `GITHUB_APP_CLIENT_SECRET` | – | Client secret |
| `GITHUB_APP_PRIVATE_KEY` | – | The PEM key itself (`\n` escapes allowed) or a path to the `.pem` file |
| `GITHUB_APP_WEBHOOK_SECRET` | – | Webhook secret |

### GitHub stars

| Variable | Default | What it does |
|---|---|---|
| `GITHUB_STARS` | `true` | Show the star count on "Star on GitHub" buttons. The scheduler fetches it hourly from GitHub's API. `false` turns that off |
| `GITHUB_REPOSITORY` | `armendzekjiri/brainitive` | Repository the buttons link to |

## AI, MCP and outgoing requests

| Variable | Default | What it does |
|---|---|---|
| `AI_ALLOW_PRIVATE_ENDPOINTS` | `false` | Let workspace AI settings call servers on localhost or your private network (Ollama, LM Studio). Off so users can't make the server reach internal services |
| `MCP_REDIRECT_HOSTS` | – | Extra callback hosts for AI apps that register over OAuth, comma-separated (`app.example.com,*.example.org`). Claude, ChatGPT, Perplexity, Cursor, VS Code and the browser extension are allowed already |
| `CALENDAR_ALLOWED_PORTS` | `8443,5001,5232` | Ports a CalDAV server or calendar link may use besides 80 and 443 |
| `CALENDAR_MAX_RESPONSE_BYTES` | `5000000` | Largest calendar file or CalDAV response read |
| `CALENDAR_ICS_REFRESH_MINUTES` | `15` | Minutes a subscribed calendar link is cached |

## Legal pages

A self-hosted installation never shows Brainitive Cloud's operator data. `/privacy`, `/terms` and `/cookies` show a short notice about your installation, and `/imprint` appears once you set your details. Empty values are hidden.

| Variable | Default | What it does |
|---|---|---|
| `LEGAL_OPERATOR_NAME` | – | Your company or name |
| `LEGAL_OPERATOR_DESCRIPTION` | – | For example "GmbH, represented by …" |
| `LEGAL_ADDRESS` | – | Postal address, line breaks as `\n` |
| `LEGAL_EMAIL` | – | Contact email |
| `LEGAL_PHONE` | – | Phone |
| `LEGAL_REPRESENTATIVE` | – | Managing director or authorised representative |
| `LEGAL_REGISTER` | – | Commercial register entry |
| `LEGAL_VAT_ID` | – | VAT ID |
| `LEGAL_CONTENT_RESPONSIBLE` | – | Person responsible for content |
| `LEGAL_DPO` | – | Data protection officer |
| `LEGAL_EU_REPRESENTATIVE` | – | EU representative (outside the EU only) |
| `LEGAL_SUPERVISORY_AUTHORITY` | – | Data protection authority |
| `LEGAL_GOVERNING_LAW` | – | For example "German law" |
| `LEGAL_JURISDICTION` | – | Place of jurisdiction |
| `LEGAL_PRIVACY_URL` | – | Link your own privacy policy instead |
| `LEGAL_TERMS_URL` | – | Link your own terms instead |
| `LEGAL_IMPRINT_URL` | – | Link your own imprint instead |
| `LEGAL_HOSTING_PROVIDER`, `LEGAL_HOSTING_LOCATION` | – | Hosting provider and location |
| `LEGAL_EMAIL_PROVIDER`, `LEGAL_EMAIL_PROVIDER_LOCATION` | – | Email provider and location |
| `LEGAL_BACKUP_PROVIDER`, `LEGAL_BACKUP_LOCATION` | – | Backup provider and location |
| `LEGAL_SERVER_LOG_RETENTION`, `LEGAL_BACKUP_RETENTION`, `LEGAL_ANALYTICS_RETENTION` | – | Storage periods as free text, for example "14 days" |
| `LEGAL_CLOUD` | detected from `APP_URL` | Leave empty. Only Brainitive Cloud uses its own legal texts |

Each workspace can also add its own legal name, privacy policy and imprint in its workspace settings.

## Analytics

Cookieless Umami analytics, loaded only after the visitor agrees, and only on the hosts in `ANALYTICS_HOSTS`. The default lists only Brainitive Cloud's hosts, so a self-hosted installation loads no analytics.

| Variable | Default | What it does |
|---|---|---|
| `ANALYTICS_HOSTS` | `brainitive.app,www.brainitive.app` | Hosts where analytics may load |
| `ANALYTICS_UMAMI_SCRIPT` | Brainitive Cloud's | Umami script URL |
| `ANALYTICS_UMAMI_WEBSITE_ID` | Brainitive Cloud's | Umami website ID |
