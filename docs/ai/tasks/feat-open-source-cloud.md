# Open source + Brainitive Cloud pricing

**Branch:** feat/open-source-cloud
**Status:** done
**Started:** 2026-10-22
**Board card:** —

## Problem / goal
Make Brainitive open source and earn money with the hosted cloud instead of feature limits:
licence, contribution rules, a self-hosting path that works, and pricing that matches.

## Decisions
- **AGPL-3.0-only** for the whole app (all current features open). Commercial licence and
  Enterprise for companies that can't use AGPL. CLA (modelled on the Apache ICLA) so
  contributions can be dual-licensed. CLA and licence choice to be reviewed by a lawyer.
- **Pricing (EUR, per user / month):** Cloud Free €0 (≤ 5 members, 1 GB) · Team €6 (€5
  yearly, 100 GB, email support) · Business €12 (€10 yearly, priority support, DPA, 99.9% SLA,
  30-day restore) · Self-hosted €0 (AGPL) · Enterprise custom (commercial licence,
  on-premise / dedicated, SAML/SCIM on request). No feature gating — only seats, storage
  and service.
- **Self-hosting:** one FrankenPHP image (Caddy + PHP 8.4, automatic HTTPS) for web, queue and
  scheduler; MariaDB + Redis via compose; image published to GHCR by a workflow.

## Changes
- `LICENSE`, `CLA.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md` (Contributor Covenant 2.1),
  `SECURITY.md`, `README.md`, issue / PR templates, `.github/workflows/cla.yml`.
- `Dockerfile`, `docker/entrypoint.sh`, `docker/php.ini`, `.dockerignore`, `compose.yaml`,
  `.env.docker.example`, `.github/workflows/docker-publish.yml`, `docs/self-hosting.md`.
- `bootstrap/app.php` (`TRUSTED_PROXIES`), `config/app.php` (default name), licence fields in
  `composer.json` / `package.json`.
- Repo cleanup: untracked the copies under `public/js/` (index.php, .htaccess, favicons, hot,
  sw.js …) created by the old widget build; `.gitignore` keeps only `excalidraw-dist/` and
  `helpdesk-widget.js` there. gitleaks over the full history: only Excalidraw's public
  Firebase web key (inside its npm bundle).
- Homepage pricing rewritten (`Welcome.tsx`: cloud tiers with monthly / yearly toggle,
  self-hosted + enterprise cards, GitHub links, "Open source" badge); Terms and Refund pages
  updated; changelog v1.14.0.

## Test plan
- Gate (pint, tsc, PHPUnit, build).
- Docker, in this sandbox: compose validated; the app ran in the FrankenPHP base image with
  the real entrypoint against SQLite (migrations, seeds, Passport keys, optimize, Caddy):
  `/`, `/login`, `/register`, `/up` 200, unknown routes 404, queue + scheduler commands work.
  **Not verified here:** the full image build (the sandbox blocks deb.debian.org, pecl.php.net
  and GitHub downloads) — the first run of the publish workflow is the real test.
- Homepage pricing rendered in Chromium (light, dark, phone).

## Open questions
- Lawyer review: AGPL choice, CLA text, Terms / Refund wording.
- Register the "Brainitive" trademark; create security@ and billing@ mailboxes.
- Billing (Stripe / Cashier) and seat limits for the cloud — not built yet.
- Make the repository public only after the review.
