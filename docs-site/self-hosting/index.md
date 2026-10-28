---
title: Self-hosting
description: Run Brainitive on your own server. What you need, Docker or manual install, and which services make up a complete Brainitive installation.
---

# Self-hosting

Brainitive is open source (AGPL-3.0) and runs on your own server. This section explains how to install it, configure it, keep it up to date and keep your data safe.

::: tip Don't want to run a server?
[Brainitive Cloud](https://www.brainitive.app/#pricing) is the same software, hosted and updated for you.
:::

## What you need

| | |
|---|---|
| **Server** | Linux with 2 GB RAM (4 GB for teams above about 50 people) and 10 GB of disk plus room for uploads |
| **PHP** | 8.4 (the version the Docker image ships; 8.2 is the minimum) |
| **Database** | PostgreSQL. Brainitive is built and tested on PostgreSQL only. The Docker setup uses PostgreSQL 16 |
| **Node.js** | 22, to build the frontend and to run the realtime server for live features |
| **Mail** | An SMTP account for invitations, password resets and notifications |
| **Domain + HTTPS** | A domain name pointing to your server. HTTPS is needed for the browser extension, web push and secure cookies |

With Docker you don't install PHP, Node.js or PostgreSQL yourself. The image contains everything.

## Two ways to install

- **[Docker](./docker)** (recommended). Download two files, set a password, run `docker compose up -d`. No build, no PHP setup. Updates are one `docker compose pull`.
- **[Manual install](./manual-install)**. Ubuntu, nginx, PHP-FPM and PostgreSQL set up by hand. Choose this if you already run PHP apps this way or can't use Docker.

## What runs

A complete Brainitive installation is four processes plus the database:

| Process | What it does | Required? |
|---|---|---|
| **Web app** | Serves pages and the API (PHP) | Yes |
| **Queue worker** | Sends emails and notifications, builds the search index, runs imports and exports in the background | Yes |
| **Scheduler** | Runs timed tasks every minute: reminders, recurring issues, helpdesk mailboxes, SLA checks, clean-ups | Yes |
| **Realtime server** | A small Node.js server for live co-editing and live updates (the bell, presence, typing) | Optional. Without it, Brainitive still works but without live features |

The Docker setup starts all four for you, plus PostgreSQL and Redis. In a manual install you set up each one. See [Queue worker and scheduler](./queue-and-scheduler) and [Realtime server](./realtime).

## Pages in this section

1. [Docker](./docker): the quick start with the published image, first admin account, HTTPS, updates.
2. [Manual install](./manual-install): Ubuntu, nginx, PHP-FPM, PostgreSQL step by step.
3. [Configuration](./configuration): every environment variable, grouped, with defaults.
4. [Realtime server](./realtime): live co-editing and live channels, systemd and nginx setup.
5. [Email](./email): SMTP settings, which emails Brainitive sends, testing, SPF and DKIM.
6. [Queue worker and scheduler](./queue-and-scheduler): why both are required and what they run.
7. [Search](./search): full-text search on PostgreSQL and how to rebuild the index.
8. [Upgrading](./upgrading): update a Docker or manual installation safely.
9. [Backups](./backups): what to back up, how to restore, and project export.
10. [Troubleshooting](./troubleshooting): common problems and their fixes.

After installing, open **avatar menu → Instance admin** to manage users and settings. See the [Instance admin guide](/admin/). Social logins and calendars are covered under [Integrations](/integrations/).
