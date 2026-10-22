# Security policy

## Reporting a vulnerability

Please **do not** open a public issue for security problems.

Send the details to **security@brainitive.com** (or use GitHub's
"Report a vulnerability" button under the Security tab). Include steps to reproduce and the
version or commit you tested. We aim to confirm within 3 working days and to ship a fix for
confirmed issues as quickly as possible. We credit reporters in the release notes unless
you prefer to stay anonymous.

## Supported versions

Security fixes go into the latest release on `main` and are deployed to Brainitive Cloud.
Self-hosters should update regularly (`git pull` / new Docker image, then
`php artisan migrate --force`).

## Scope

In scope: this repository (web app, API, MCP server, helpdesk widget, Docker setup).
Out of scope: denial of service through volume, social engineering, and issues in
third-party services or self-hosted infrastructure we don't control.
