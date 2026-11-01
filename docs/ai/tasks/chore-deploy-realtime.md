# chore/deploy-realtime — deploys install and restart the realtime server (BRAI-434)

Release v1.48.0. Brainitive card: BRAI-434.

## Problem
The Deploy (prod) and Pre-release (dev) workflows updated the app but not the realtime server
(`realtime/`, systemd `brainitive-realtime` / `brainitive-realtime-dev`). After v1.47.3 the server
kept running old code until someone ran `npm --prefix realtime ci --omit=dev` and
`sudo systemctl restart …` by hand.

## Change
- `.github/workflows/deploy.yml` and `pre-release.yml`: at the end of the server script, if the
  unit exists (`systemctl cat`), install the realtime dependencies and `sudo -n systemctl restart`
  the unit, then check `is-active`. A failed restart prints a `::warning::` instead of failing the
  deploy: editors fall back to editing alone and recover by themselves once it runs (BRAI-345).
- `npm ci` failing still fails the deploy (`set -e`): a broken dependency install is a real error.
- Docs: `docs-site/self-hosting/realtime.md` → "After an update" explains how to automate it.

## Needs on the server
The deploy user needs passwordless sudo for `systemctl restart brainitive-realtime(-dev)`.
Without it the deploy shows the warning and the restart stays manual.

## Tests
Workflow-only change: no PHPUnit / e2e. Verified by the next dev deploy log
(">>> brainitive-realtime-dev restarted").
