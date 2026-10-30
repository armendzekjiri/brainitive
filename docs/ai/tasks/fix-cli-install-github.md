# CLI installers hosted on GitHub (v1.47.1)

**Branch:** fix/cli-install-github (release/v1.47.1)
**Status:** done
**Started:** 2026-10-30
**Builds on:** [release-v1.47.0.md](./release-v1.47.0.md)

## Problem
`curl -fsSL brainitive.app/install.sh | sh` answered 404: the route only exists once v1.47 is
deployed, and production still ran v1.45.3. The installer depended on the website's version, while
the binaries it downloads already live on the GitHub release.

## Decision (user, 2026-10-30)
The installers ship on the GitHub release next to the binaries; the website, README and docs show
the GitHub address. `brainitive.app/install.sh` / `install.ps1` stay as short aliases that forward
(302) to `releases/latest/download/…`.

## Changes
- `.github/workflows/release.yml` (job `cli`): uploads `install.sh` + `install.ps1` with the binaries.
- `routes/web.php`: `/install.sh`, `/install.ps1` → 302 to the latest release (repo from
  `services.github.repository`), still session-free and throttled.
- `resources/js/lib/cli.ts` (`CLI_INSTALL_SH`, `CLI_INSTALL_PS1`): homepage card + CLI section,
  HelpModal integration card, changelog v1.47.1.
- README, `cli/README.md`, `cli/install.sh|ps1` usage comments, docs-site `integrations/cli.md` + index,
  ARCHITECTURE.

## Test plan
- `tests/Feature/CliInstallScriptTest.php`: both addresses redirect; installers + release upload.
- e2e `homepage.spec.ts`: the CLI section shows the GitHub install command.
- Works for real only after the next release to `main` (the release job uploads the files).
