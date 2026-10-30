# Relicense: AGPL-3.0 → Business Source License 1.1

**Branch:** chore/relicense-bsl (release/v1.46.0)
**Status:** done
**Started:** 2026-10-30
**Board card:** [BRAI-159](https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-432)
**Supersedes the licence decision in:** [feat-open-source-cloud.md](./feat-open-source-cloud.md)

## Problem / goal
Under the AGPL anyone may run Brainitive as a paid hosted service, and big companies self-host
for free. We want: nobody competes with Brainitive Cloud using our code, organizations with
€2M or more annual revenue pay a commercial licence (also when they self-host), everyone else
self-hosts for free. We keep calling Brainitive "open source" (as Outline does under BSL 1.1),
and say exactly what the licence is where it matters (README, docs, Terms).

## Decisions (user, 2026-10-30)
- **BSL 1.1** (MariaDB template, unmodified) with our own Additional Use Grant:
  - production use allowed, also self-hosted, if the organization (with its affiliates) has
    **less than €2M total annual revenue**;
  - no **Hosted Service**: offering Brainitive (or a service substantially based on it) to third
    parties as a hosted / managed / subscription service;
  - **service providers (agencies)** may install and run it for a client; the client's revenue counts;
  - personal, non-profit and educational use always allowed.
- **Change Date:** four years after each version is published. **Change Licence:** AGPL-3.0
  (BSL covenant: must be GPL-2.0-or-later compatible — AGPL-3.0 combines with GPL-3.0; lawyer to confirm).
- **Licensor:** Armend Zekjiri (Brainitive), sole proprietor — lawyer to confirm.
- Versions already released under AGPL-3.0 (v1.14.0–v1.45.3) stay AGPL; the new licence applies
  from v1.46.0.
- Commercial licence price: "contact us" for now (billing tickets come later).
- Dependencies: the AGPL-compatibility rule becomes "must allow use in a source-available /
  proprietary product": MIT, BSD, Apache-2.0, ISC, MPL-2.0, LGPL (as a library) — no GPL / AGPL /
  SSPL. Checked 2026-10-30: none in composer.lock or the npm lockfiles (nette is BSD-or-GPL,
  dompdf LGPL).

## Changes
- `LICENSE` (BSL 1.1 + grant), `CLA.md` (licence names), `CONTRIBUTING.md`, `README.md`.
- Licence fields `BUSL-1.1`: `composer.json`, `package.json`, `package-lock.json` (root),
  `realtime/package.json`, `browser-extension/package.json`.
- App: `Pages/Welcome.tsx` (pricing headline, self-hosted card, enterprise card),
  `Pages/Terms.tsx` (software licence paragraphs).
- Docs: `docs/self-hosting.md` (licence section), `docs-site/` (footer, self-hosting intro, new
  `self-hosting/licence.md`), `docs/ai/CONVENTIONS.md` (licence & dependencies),
  `docs/ai/skills/review-checklist.md`, `docs/ai/KNOWLEDGE.md` (decision).
- e2e: homepage shows the self-hosting licence terms and links the licence page.

## Test plan
- `e2e/tests/homepage.spec.ts`: pricing shows "free under €2M revenue" and the commercial licence.
- Gate (pint, tsc, PHPUnit, build), docs-site build.

## Open questions
- Lawyer review: grant wording, "revenue" definition, Licensor name, Change Licence, CLA.
- Commercial licence prices + licence keys (billing tickets).
