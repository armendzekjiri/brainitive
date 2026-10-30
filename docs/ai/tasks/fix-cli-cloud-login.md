# CLI Cloud login and short command

**Branch:** fix/cli-cloud-login
**Status:** in-progress
**Started:** 2026-10-30

## Problem / goal
The CLI defaults to the non-www Cloud URL while OAuth metadata uses the www origin. Provide a shorter CLI name without breaking existing installations or overwriting another command.

## Approach
Use the canonical Cloud origin and migrate only unsigned-in old defaults, retaining strict OAuth same-origin validation and self-hosted behavior. Install a safe `bi` shortcut when available while preserving `brainitive`, and add `-s` search and `-c` capture shortcuts.

## Test plan
Go tests for URL, existing config handling and shortcut dispatch, with Go tests in CI; installer checks including conflicting names and existing links; type/build as applicable.

## Docs
Update CLI usage and shared knowledge.

## Pre-release website split follow-up
Account deletion must navigate to the external homepage as a full page, and live homepage checks must validate the Astro site while self-hosted checks continue covering Laravel's homepage.
