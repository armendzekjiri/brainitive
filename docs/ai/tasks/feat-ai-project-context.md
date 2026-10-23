# AI with project context (BRAI-30)

**Branch:** feat/ai-project-context
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-30 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-36

## Changes
- `AiController::generate` takes `project_id`; when the user can view that project, its name and plain-text description (max 4000 chars) are appended to the system prompt. The editor toolbar sends `projectId` (now also passed by the issue description editor, which turns on @mentions there).
- `projectAssist` (`ai.project-assist`, throttled): modes `summary` / `task` / `prompt`. A description shorter than 40 chars returns 422 with `needs_description`. `saveProjectAssist` stores a reviewed result as a sanitized draft doc. Replacing the description reuses `projects.update` after a confirm dialog.
- The HTTP call is moved into `chat()`. Error messages are capped (no raw response body).

## Tests
`tests/Feature/Ai/ProjectAiTest.php` (faked endpoint: context present / absent without access, modes, short description, sanitized draft). Browser with a mock AI server: summary shows "with project context", replace asks to confirm, task saves as a draft doc.
