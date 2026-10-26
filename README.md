<p align="center">
  <img src="public/images/logo.png" alt="Brainitive" height="64">
</p>

<p align="center">
  <strong>Boards, docs, whiteboards and Gantt plans — one open-source workspace for the whole project.</strong>
</p>

<p align="center">
  <a href="https://www.brainitive.app">Website</a> ·
  <a href="https://www.brainitive.app/#pricing">Cloud</a> ·
  <a href="docs/self-hosting.md">Self-hosting</a> ·
  <a href="CONTRIBUTING.md">Contributing</a> ·
  <a href="LICENSE">AGPL-3.0</a>
</p>

<p align="center">
  <a href="https://github.com/armendzekjiri/brainitive/actions/workflows/deploy.yml"><img src="https://github.com/armendzekjiri/brainitive/actions/workflows/deploy.yml/badge.svg?branch=main" alt="Production deploy"></a>
  <a href="https://github.com/armendzekjiri/brainitive/actions/workflows/pre-release.yml"><img src="https://github.com/armendzekjiri/brainitive/actions/workflows/pre-release.yml/badge.svg?branch=pre-release" alt="Tests + e2e on dev"></a>
  <a href="https://github.com/armendzekjiri/brainitive/actions/workflows/e2e-nightly.yml"><img src="https://github.com/armendzekjiri/brainitive/actions/workflows/e2e-nightly.yml/badge.svg" alt="Nightly e2e (Firefox, WebKit)"></a>
  <a href="https://github.com/armendzekjiri/brainitive/releases/latest"><img src="https://img.shields.io/github/v/release/armendzekjiri/brainitive?label=release" alt="Latest release"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/armendzekjiri/brainitive" alt="License"></a>
  <a href="https://github.com/armendzekjiri/brainitive/stargazers"><img src="https://img.shields.io/github/stars/armendzekjiri/brainitive?style=flat" alt="GitHub stars"></a>
</p>

## Get Brainitive

**Brainitive Cloud** — hosted for you, updates and backups included. Free during early
access; paid plans come later and are announced in advance. →
[www.brainitive.app](https://www.brainitive.app/#pricing)

**Self-hosted** — free and complete, on your own server:

```bash
git clone https://github.com/armendzekjiri/brainitive.git && cd brainitive
cp .env.docker.example .env   # then set DB_PASSWORD
docker compose up -d --build  # open http://localhost:8080
```

HTTPS, backups, updates and optional features: [docs/self-hosting.md](docs/self-hosting.md).

![Gantt plans in Brainitive](public/images/screenshots/plans.jpg)

## What's inside

- **Boards** — Kanban or Scrum: sprints, backlog, burndown and velocity, issue history, WIP
  limits, time tracking, shareable filters, list / timeline / calendar views.
- **Plans** — Gantt timelines with milestones, dependencies, templates, Excel import /
  export, share links; rows can be linked to board issues and stay in sync.
- **Docs & wiki** — distraction-free editor with slash commands, drafts, publishing and
  version history.
- **Whiteboards** — Excalidraw with templates, autosave and history; turn sticky notes into
  issues and see them as live cards.
- **Lists, My Work, Calendar** — light to-dos, everything assigned to you, Google Calendar &
  Tasks sync.
- **Helpdesk** — embeddable support widget and ticket portal.
- **AI** — an MCP server so Claude or ChatGPT can read and update your workspace, plus
  bring-your-own-key writing helpers.
- **Snippets & bookmarks**, roles and permissions, notifications and web push.

| Boards | Docs | Whiteboards |
|---|---|---|
| ![Board](public/images/screenshots/board.jpg) | ![Docs](public/images/screenshots/docs.jpg) | ![Whiteboard](public/images/screenshots/whiteboard.jpg) |

## Development

Laravel 12 · Inertia 2 · React 18 + TypeScript · Tailwind · PHPUnit.

```bash
cp .env.example .env
composer install && npm ci
php artisan key:generate && php artisan migrate --seed
composer run dev
```

Architecture, conventions and pitfalls are documented in [`docs/ai/`](docs/ai/README.md).
Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## Licence

Brainitive is free software under the [GNU Affero General Public License v3.0](LICENSE). If
you run a modified version for others over a network, you must make your changes available
under the same licence. Commercial licences without that obligation are available —
hello@brainitive.app.

"Brainitive" and the logo are trademarks of the project owners; the licence covers the code,
not the name.
