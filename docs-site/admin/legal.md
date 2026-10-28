---
title: Legal pages
description: "Imprint, privacy policy, terms and cookies on a self-hosted Brainitive install: the LEGAL_* settings, workspace legal details and analytics with consent."
order: 40
---

# Legal pages

Every Brainitive installation has a privacy page, terms, a cookie page and, once you add your details, an imprint. This page explains what they show on a self-hosted install, what you as the operator need to set, and how workspaces name themselves on their public pages.

::: warning Not legal advice
The legal texts in Brainitive were written carefully but are not legal advice. As the operator of an installation you're responsible for it. Have your own documents checked by a lawyer.
:::

## The legal pages of an installation

| Page | Address | Self-hosted install | Brainitive Cloud |
|---|---|---|---|
| Privacy | `/privacy` | Short notice about **your** installation, linking your own policy if you set one | Full privacy policy |
| Terms | `/terms` | Short notice, linking your own terms if you set them | Full terms of use |
| Cookies | `/cookies` | List of the cookies and browser storage the app uses, plus the statistics setting | Same |
| Imprint | `/imprint` | Only once you set your details (otherwise "not found") | Yes |
| Sub-processors | `/legal/subprocessors` | Not available | Yes |
| Data processing agreement | `/legal/dpa` | Not available | Yes |
| Refund policy | `/refund` | Not available | Yes |

Links to these pages appear in the footer of the homepage, the sign-in and sign-up pages, the account menu and every legal page. Pages that don't exist on your install are left out.

A self-hosted install **never shows Brainitive Cloud's operator details**. Until you configure anything, `/privacy` and `/terms` say that the installation is run by its operator, that the operator is responsible for the personal data in it, and that the developers of the open-source software receive no data from it.

Brainitive Cloud is detected from `APP_URL`. You can force it either way with `LEGAL_CLOUD=true` or `LEGAL_CLOUD=false`; leave it unset on your own install.

## What you should configure

Set your details in `.env` (all optional; empty values are simply not shown), then restart with `docker compose up -d`. See [Configuration](/self-hosting/configuration) for how `.env` works.

### Link your own documents

The simplest setup: point the pages to documents you already have.

```dotenv
LEGAL_PRIVACY_URL=https://example.com/privacy
LEGAL_TERMS_URL=https://example.com/terms
LEGAL_IMPRINT_URL=https://example.com/imprint
```

`/privacy` and `/terms` then show a **Read the operator's privacy policy** (or terms) link. If you set only `LEGAL_IMPRINT_URL` and no name or address, the **Imprint** footer link goes straight to your URL.

### Operator details (imprint)

To have Brainitive render an imprint page from your details:

| Setting | Shown as |
|---|---|
| `LEGAL_OPERATOR_NAME` | Name of the person or company running the install |
| `LEGAL_OPERATOR_DESCRIPTION` | Free text under the name, e.g. "GmbH, represented by …" |
| `LEGAL_ADDRESS` | Postal address; use `\n` for line breaks |
| `LEGAL_EMAIL`, `LEGAL_PHONE` | Contact |
| `LEGAL_REPRESENTATIVE` | Managing director or authorised representative |
| `LEGAL_REGISTER` | Commercial register entry, e.g. "Amtsgericht München, HRB 123456" |
| `LEGAL_VAT_ID` | VAT ID |
| `LEGAL_CONTENT_RESPONSIBLE` | Person responsible for editorial content (Germany, § 18 (2) MStV) |

Example:

```dotenv
LEGAL_OPERATOR_NAME="Example GmbH"
LEGAL_ADDRESS="Example Street 1\n12345 Town, Germany"
LEGAL_EMAIL=privacy@example.com
```

The imprint appears as soon as a name, an address, an email or an imprint URL is set. Only rows with a value are shown.

### More settings

These fill in details used by the Cloud documents (privacy, terms, sub-processors). On a self-hosted install you'll mostly need them for your own documents, but you can keep them in one place:

| Settings | Purpose |
|---|---|
| `LEGAL_DPO`, `LEGAL_EU_REPRESENTATIVE`, `LEGAL_SUPERVISORY_AUTHORITY` | Privacy contacts |
| `LEGAL_GOVERNING_LAW`, `LEGAL_JURISDICTION` | Terms |
| `LEGAL_HOSTING_PROVIDER`, `LEGAL_HOSTING_LOCATION`, `LEGAL_EMAIL_PROVIDER`, `LEGAL_EMAIL_PROVIDER_LOCATION`, `LEGAL_BACKUP_PROVIDER`, `LEGAL_BACKUP_LOCATION` | Service providers |
| `LEGAL_SERVER_LOG_RETENTION`, `LEGAL_BACKUP_RETENTION`, `LEGAL_ANALYTICS_RETENTION` | Storage periods, free text such as `14 days` |

The full list with comments is in `.env.example` under "Legal pages".

## Accounts and personal data

A few things work the same on every install:

- **Terms at sign-up.** When someone creates an account (with a password or with Google, GitHub or Microsoft), Brainitive records when they accepted the terms and which version.
- **Download my data.** Every user can download a ZIP of their personal data from **Profile → Your data → Download my data**. The link works for 24 hours.
- **Account deletion.** Users can delete their own account in their profile. This is refused while they own a workspace that has other members. Work they created in shared workspaces is handed to the workspace owner instead of being deleted. Admins can also delete accounts, see [Users](./users#delete-an-account).
- **No third-party requests.** Fonts and drawing assets are served by your own installation. YouTube, Figma and X embeds load only after a click.

## Your workspace's public pages

Workspaces can publish pages for people outside Brainitive: support pages and the helpdesk widget, lead forms, feedback and client portals, share links, public plans and changelogs. For the data visitors enter there, the **workspace's organisation** is responsible (the "controller" under the GDPR), not the person running the installation.

So each workspace can say who runs its pages:

1. Open **Workspace settings → Legal** (workspace admins).
2. Fill in:
   - **Legal name of your organisation**, shown as "This page is operated by …". Empty = the workspace name.
   - **Privacy policy URL** (https).
   - **Imprint URL** (https). Required for businesses in Germany and Austria.
3. Save.

Public pages then show a short "operated by … using Brainitive" note with these links, and forms show a privacy line under the submit button. Lead forms can also have an optional or required consent checkbox with your own text.

This works the same on Brainitive Cloud and on self-hosted installs.

## Analytics with consent

Brainitive can count page views with a self-hosted, cookieless [Umami](https://umami.is) instance, but **only after the visitor agrees**:

- A small notice asks with equal **Allow** and **No thanks** buttons. Nothing loads before "Allow".
- Browsers that send "Global Privacy Control" or "Do Not Track" count as "no".
- Visitors can change their answer any time on `/cookies`.
- Statistics never run on customer-facing pages (support pages, forms, portals, share links, booking pages, changelogs) or on pages with a secret in the address (password reset, email verification, invitations, calendar feeds).

On a self-hosted install analytics is **off** by default: it only runs on the hosts listed in `ANALYTICS_HOSTS`, which default to Brainitive Cloud's addresses. To use your own Umami, set all three:

```dotenv
ANALYTICS_HOSTS=brainitive.example.com
ANALYTICS_UMAMI_SCRIPT=https://umami.example.com/script.js
ANALYTICS_UMAMI_WEBSITE_ID=your-website-id
```

::: warning
If you turn analytics on, mention it in your own privacy policy. The built-in self-hosted privacy notice doesn't describe it.
:::
