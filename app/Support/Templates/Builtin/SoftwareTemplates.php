<?php

namespace App\Support\Templates\Builtin;

/**
 * Built-in templates — Software (BRAI-72 / BRAI-91).
 */
class SoftwareTemplates
{
    use Blocks;

    public static function softwareDevelopment(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-bug', 'name' => 'Bug', 'color' => '#ef4444'],
                ['ref' => 'l-feature', 'name' => 'Feature', 'color' => '#3b82f6'],
                ['ref' => 'l-tech-debt', 'name' => 'Tech debt', 'color' => '#a855f7'],
                ['ref' => 'l-docs', 'name' => 'Docs', 'color' => '#14b8a6'],
            ],
            'fields' => [
                ['ref' => 'f-env', 'name' => 'Environment', 'type' => 'select', 'options' => self::options([
                    'o-dev' => ['Development', '#64748b'],
                    'o-staging' => ['Staging', '#f59e0b'],
                    'o-prod' => ['Production', '#ef4444'],
                ])],
            ],
            'boards' => [[
                'ref' => 'b-dev', 'name' => 'Development', 'type' => 'kanban',
                'description' => 'From idea to shipped. Keep "In progress" and "In review" small.',
                'columns' => [
                    self::column('c-backlog', 'Backlog', '#94a3b8'),
                    self::column('c-todo', 'To Do', '#6b7280'),
                    self::column('c-progress', 'In Progress', '#3b82f6', ['wip_limit' => 4]),
                    self::column('c-review', 'In Review', '#a855f7', ['wip_limit' => 3]),
                    self::column('c-done', 'Done', '#22c55e', ['is_done' => true]),
                ],
                'swimlanes' => ['mode' => 'custom', 'rest_name' => 'Everything else', 'lanes' => [
                    ['name' => 'Bugs', 'color' => '#ef4444', 'rules' => ['labels' => ['l-bug']]],
                ]],
            ]],
            'items' => [
                self::issue('i-epic', 'b-dev', 'c-todo', 'Project setup', ['type' => 'slug:epic', 'priority' => 'high', 'description' => '<p>Everything the team needs before the first feature lands.</p>']),
                self::issue('i-repo', 'b-dev', 'c-todo', 'Set up the repository and branching rules', ['parent' => 'i-epic', 'type' => 'slug:task', 'priority' => 'high', 'labels' => ['l-tech-debt'], 'due' => 2, 'story_points' => 2]),
                self::issue('i-ci', 'b-dev', 'c-todo', 'Add CI: lint, tests and build on every push', ['parent' => 'i-epic', 'type' => 'slug:task', 'priority' => 'high', 'due' => 4, 'story_points' => 3]),
                self::issue('i-envs', 'b-dev', 'c-backlog', 'Prepare staging and production environments', ['parent' => 'i-epic', 'type' => 'slug:task', 'due' => 7, 'story_points' => 3, 'fields' => ['f-env' => 'o-staging']]),
                self::issue('i-feature', 'b-dev', 'c-backlog', 'First feature: describe the user story', ['type' => 'slug:story', 'priority' => 'medium', 'labels' => ['l-feature'], 'story_points' => 5, 'description' => '<p><strong>As a</strong> … <strong>I want</strong> … <strong>so that</strong> …</p><p>Acceptance criteria:</p><ul><li>…</li></ul>']),
                self::issue('i-bug', 'b-dev', 'c-backlog', 'Example bug: steps to reproduce', ['type' => 'slug:bug', 'priority' => 'medium', 'labels' => ['l-bug'], 'fields' => ['f-env' => 'o-dev'], 'description' => '<p><strong>Steps:</strong></p><ol><li>…</li></ol><p><strong>Expected:</strong> …</p><p><strong>Actual:</strong> …</p>']),
                self::issue('i-docs', 'b-dev', 'c-backlog', 'Write the README and contribution guide', ['type' => 'slug:task', 'priority' => 'low', 'labels' => ['l-docs']]),
            ],
            'links' => [['from' => 'i-ci', 'to' => 'i-repo', 'type' => 'is_blocked_by']],
            'pages' => [
                ['ref' => 'p-eng', 'type' => 'folder', 'name' => 'Engineering'],
                ['ref' => 'p-dod', 'parent' => 'p-eng', 'name' => 'Definition of done', 'icon' => '✅', 'content' => self::doc('<h1>Definition of done</h1><p>An issue moves to <strong>Done</strong> when:</p><ul><li>The code is reviewed and merged</li><li>Tests cover the change and pass in CI</li><li>It is deployed to staging and checked</li><li>Docs and the changelog are updated</li></ul>')],
                ['ref' => 'p-workflow', 'parent' => 'p-eng', 'name' => 'How we work', 'icon' => '🧭', 'content' => self::doc('<h1>How we work</h1><h2>Board</h2><p><strong>Backlog</strong> → ideas and requests. <strong>To Do</strong> → ready to start. <strong>In Progress</strong> → someone is on it (max. 4). <strong>In Review</strong> → waiting for a review (max. 3). <strong>Done</strong> → meets the definition of done.</p><h2>Branches</h2><p>One branch per issue, named after the issue key.</p>')],
            ],
            'task_lists' => [
                ['name' => 'Release checklist', 'description' => 'Run through this before every release.', 'tasks' => self::tasks([
                    'Changelog updated', 'Version bumped', 'All CI checks green', 'Staging smoke test passed', 'Database migrations reviewed', 'Release notes shared with the team',
                ])],
            ],
        ];
    }

    public static function scrum(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-frontend', 'name' => 'Frontend', 'color' => '#3b82f6'],
                ['ref' => 'l-backend', 'name' => 'Backend', 'color' => '#22c55e'],
                ['ref' => 'l-ux', 'name' => 'UX', 'color' => '#ec4899'],
            ],
            'boards' => [[
                'ref' => 'b-scrum', 'name' => 'Sprint board', 'type' => 'scrum',
                'description' => 'Plan sprints from the backlog; the board shows the running sprint.',
                'columns' => [
                    self::column('c-todo', 'To Do', '#6b7280'),
                    self::column('c-progress', 'In Progress', '#3b82f6'),
                    self::column('c-review', 'In Review', '#a855f7'),
                    self::column('c-done', 'Done', '#22c55e', ['is_done' => true]),
                ],
            ]],
            'items' => [
                self::issue('i-e1', 'b-scrum', 'c-todo', 'Onboarding', ['type' => 'slug:epic', 'priority' => 'high']),
                self::issue('i-s1', 'b-scrum', 'c-todo', 'As a new user I can sign up with email', ['parent' => 'i-e1', 'type' => 'slug:story', 'priority' => 'high', 'story_points' => 5, 'labels' => ['l-backend']]),
                self::issue('i-s1a', 'b-scrum', 'c-todo', 'Sign-up form', ['parent' => 'i-s1', 'type' => 'slug:subtask', 'labels' => ['l-frontend']]),
                self::issue('i-s1b', 'b-scrum', 'c-todo', 'Verification email', ['parent' => 'i-s1', 'type' => 'slug:subtask', 'labels' => ['l-backend']]),
                self::issue('i-s2', 'b-scrum', 'c-todo', 'As a new user I see a short product tour', ['parent' => 'i-e1', 'type' => 'slug:story', 'priority' => 'medium', 'story_points' => 3, 'labels' => ['l-ux', 'l-frontend']]),
                self::issue('i-s3', 'b-scrum', 'c-todo', 'As an admin I can invite teammates', ['type' => 'slug:story', 'priority' => 'medium', 'story_points' => 8, 'labels' => ['l-backend']]),
                self::issue('i-spike', 'b-scrum', 'c-todo', 'Spike: choose the analytics tool', ['type' => 'slug:task', 'priority' => 'low', 'story_points' => 2]),
            ],
            'pages' => [
                ['ref' => 'p-scrum', 'type' => 'folder', 'name' => 'Scrum'],
                ['ref' => 'p-agreement', 'parent' => 'p-scrum', 'name' => 'Working agreement', 'icon' => '🤝', 'content' => self::doc('<h1>Working agreement</h1><ul><li>Sprints are two weeks, starting on Monday</li><li>Daily stand-up at 9:30, 15 minutes</li><li>Stories are estimated in points before planning</li><li>"Done" means merged, tested and deployed to staging</li></ul>')],
                ['ref' => 'p-planning', 'parent' => 'p-scrum', 'name' => 'Sprint planning notes', 'icon' => '🗓️', 'content' => self::doc('<h1>Sprint planning</h1><h2>Sprint goal</h2><p>…</p><h2>Capacity</h2><p>…</p><h2>Committed stories</h2><ul><li>…</li></ul>')],
                ['ref' => 'p-retro', 'parent' => 'p-scrum', 'name' => 'Retrospective', 'icon' => '🔁', 'content' => self::doc('<h1>Retrospective</h1><h2>What went well</h2><ul><li>…</li></ul><h2>What to improve</h2><ul><li>…</li></ul><h2>Action items</h2><ul><li>…</li></ul>')],
            ],
            'task_lists' => [
                ['name' => 'Sprint ceremonies', 'tasks' => self::tasks(['Sprint planning', 'Daily stand-ups', 'Backlog refinement', 'Sprint review', 'Retrospective'])],
            ],
        ];
    }

    public static function bugTracking(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-regression', 'name' => 'Regression', 'color' => '#ef4444'],
                ['ref' => 'l-crash', 'name' => 'Crash', 'color' => '#b91c1c'],
                ['ref' => 'l-ui', 'name' => 'UI', 'color' => '#ec4899'],
                ['ref' => 'l-perf', 'name' => 'Performance', 'color' => '#f59e0b'],
                ['ref' => 'l-data', 'name' => 'Data', 'color' => '#14b8a6'],
            ],
            'fields' => [
                ['ref' => 'f-severity', 'name' => 'Severity', 'type' => 'select', 'options' => self::options([
                    'o-blocker' => ['Blocker', '#b91c1c'],
                    'o-critical' => ['Critical', '#ef4444'],
                    'o-major' => ['Major', '#f97316'],
                    'o-minor' => ['Minor', '#64748b'],
                ])],
                ['ref' => 'f-env', 'name' => 'Environment', 'type' => 'select', 'options' => self::options([
                    'o-prod' => ['Production', '#ef4444'],
                    'o-staging' => ['Staging', '#f59e0b'],
                    'o-local' => ['Local', '#64748b'],
                ])],
                ['ref' => 'f-version', 'name' => 'Affected version', 'type' => 'text'],
            ],
            'boards' => [[
                'ref' => 'b-bugs', 'name' => 'Bugs', 'type' => 'kanban',
                'description' => 'Every bug gets triaged within a day: severity, priority and an owner.',
                'columns' => [
                    self::column('c-new', 'New', '#94a3b8'),
                    self::column('c-triaged', 'Triaged', '#6b7280'),
                    self::column('c-progress', 'In Progress', '#3b82f6', ['wip_limit' => 5]),
                    self::column('c-fixed', 'Fixed', '#a855f7'),
                    self::column('c-verified', 'Verified', '#22c55e', ['is_done' => true]),
                    self::column('c-wontfix', "Won't fix", '#64748b', ['is_done' => true]),
                ],
                'swimlanes' => ['mode' => 'priority', 'rest_name' => null, 'lanes' => []],
            ]],
            'items' => [
                self::issue('i-1', 'b-bugs', 'c-new', 'Checkout button does nothing on Safari', ['type' => 'slug:bug', 'priority' => 'highest', 'labels' => ['l-regression', 'l-ui'], 'fields' => ['f-severity' => 'o-critical', 'f-env' => 'o-prod', 'f-version' => '2.4.0'], 'description' => '<p><strong>Steps:</strong></p><ol><li>Open the cart in Safari 17</li><li>Click “Checkout”</li></ol><p><strong>Expected:</strong> payment page opens. <strong>Actual:</strong> nothing happens, console shows an error.</p>']),
                self::issue('i-2', 'b-bugs', 'c-new', 'App crashes when uploading a 0-byte file', ['type' => 'slug:bug', 'priority' => 'high', 'labels' => ['l-crash'], 'fields' => ['f-severity' => 'o-major', 'f-env' => 'o-prod']]),
                self::issue('i-3', 'b-bugs', 'c-triaged', 'Search results load slowly for large accounts', ['type' => 'slug:bug', 'priority' => 'medium', 'labels' => ['l-perf'], 'fields' => ['f-severity' => 'o-major', 'f-env' => 'o-prod']]),
                self::issue('i-4', 'b-bugs', 'c-triaged', 'Dates show in the wrong time zone in exports', ['type' => 'slug:bug', 'priority' => 'medium', 'labels' => ['l-data'], 'fields' => ['f-severity' => 'o-minor', 'f-env' => 'o-staging']]),
                self::issue('i-5', 'b-bugs', 'c-new', 'Typo on the pricing page', ['type' => 'slug:bug', 'priority' => 'lowest', 'labels' => ['l-ui'], 'fields' => ['f-severity' => 'o-minor', 'f-env' => 'o-prod']]),
            ],
            'pages' => [
                ['ref' => 'p-guide', 'name' => 'How to report a bug', 'icon' => '🐞', 'content' => self::doc('<h1>How to report a bug</h1><p>A good report gets fixed faster. Please include:</p><ul><li><strong>Steps to reproduce</strong> — numbered, from a fresh page</li><li><strong>Expected vs. actual</strong> result</li><li><strong>Environment</strong> — browser / device, production or staging, version</li><li>Screenshots or a screen recording</li></ul><h2>Severity</h2><ul><li><strong>Blocker</strong> — nobody can work, no workaround</li><li><strong>Critical</strong> — a main feature is broken</li><li><strong>Major</strong> — broken, but there is a workaround</li><li><strong>Minor</strong> — cosmetic or rare</li></ul>')],
            ],
            'task_lists' => [
                ['name' => 'Triage checklist', 'description' => 'For every new bug.', 'tasks' => self::tasks([
                    'Reproduced on the latest version', 'Severity and priority set', 'Duplicates checked and linked', 'Owner assigned', 'Reporter told what happens next',
                ])],
            ],
        ];
    }

    public static function releaseDevops(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-infra', 'name' => 'Infrastructure', 'color' => '#6366f1'],
                ['ref' => 'l-security', 'name' => 'Security', 'color' => '#ef4444'],
                ['ref' => 'l-hotfix', 'name' => 'Hotfix', 'color' => '#f97316'],
                ['ref' => 'l-monitoring', 'name' => 'Monitoring', 'color' => '#14b8a6'],
            ],
            'fields' => [
                ['ref' => 'f-env', 'name' => 'Environment', 'type' => 'select', 'options' => self::options([
                    'o-staging' => ['Staging', '#f59e0b'],
                    'o-prod' => ['Production', '#ef4444'],
                ])],
                ['ref' => 'f-rollback', 'name' => 'Rollback plan ready', 'type' => 'checkbox'],
            ],
            'boards' => [[
                'ref' => 'b-ops', 'name' => 'Releases & ops', 'type' => 'kanban',
                'columns' => [
                    self::column('c-planned', 'Planned', '#94a3b8'),
                    self::column('c-progress', 'In Progress', '#3b82f6'),
                    self::column('c-review', 'Code review', '#a855f7'),
                    self::column('c-ready', 'Ready to deploy', '#f59e0b'),
                    self::column('c-deployed', 'Deployed', '#22c55e', ['is_done' => true]),
                ],
                'swimlanes' => ['mode' => 'custom', 'rest_name' => 'Planned work', 'lanes' => [
                    ['name' => 'Hotfixes', 'color' => '#f97316', 'rules' => ['labels' => ['l-hotfix']]],
                ]],
            ]],
            'items' => [
                self::issue('i-release', 'b-ops', 'c-planned', 'Release 1.0', ['type' => 'slug:epic', 'priority' => 'high', 'due' => 21]),
                self::issue('i-freeze', 'b-ops', 'c-planned', 'Code freeze and release candidate', ['parent' => 'i-release', 'type' => 'slug:task', 'due' => 14]),
                self::issue('i-notes', 'b-ops', 'c-planned', 'Write release notes', ['parent' => 'i-release', 'type' => 'slug:task', 'due' => 19]),
                self::issue('i-deploy', 'b-ops', 'c-planned', 'Deploy 1.0 to production', ['parent' => 'i-release', 'type' => 'slug:task', 'priority' => 'highest', 'due' => 21, 'fields' => ['f-env' => 'o-prod', 'f-rollback' => false]]),
                self::issue('i-pipeline', 'b-ops', 'c-progress', 'Automate deploys to staging', ['type' => 'slug:task', 'labels' => ['l-infra'], 'due' => 5, 'fields' => ['f-env' => 'o-staging']]),
                self::issue('i-backups', 'b-ops', 'c-planned', 'Nightly database backups with a restore test', ['type' => 'slug:task', 'priority' => 'high', 'labels' => ['l-infra'], 'due' => 7]),
                self::issue('i-alerts', 'b-ops', 'c-planned', 'Uptime and error-rate alerts', ['type' => 'slug:task', 'labels' => ['l-monitoring'], 'due' => 10]),
                self::issue('i-deps', 'b-ops', 'c-planned', 'Update dependencies with known vulnerabilities', ['type' => 'slug:task', 'labels' => ['l-security']]),
            ],
            'links' => [['from' => 'i-deploy', 'to' => 'i-freeze', 'type' => 'is_blocked_by']],
            'pages' => [
                ['ref' => 'p-ops', 'type' => 'folder', 'name' => 'Operations'],
                ['ref' => 'p-runbook', 'parent' => 'p-ops', 'name' => 'Deployment runbook', 'icon' => '📘', 'content' => self::doc('<h1>Deployment runbook</h1><h2>Before</h2><ul><li>Release candidate passed on staging</li><li>Migrations reviewed, backup taken</li><li>Rollback plan written down</li></ul><h2>Deploy</h2><ol><li>Announce the deploy in the team channel</li><li>Run the deploy pipeline</li><li>Watch errors and response times for 15 minutes</li></ol><h2>Roll back</h2><p>Redeploy the previous version and restore the backup if a migration ran.</p>')],
                ['ref' => 'p-incident', 'parent' => 'p-ops', 'name' => 'Incident response', 'icon' => '🚨', 'content' => self::doc('<h1>Incident response</h1><ol><li><strong>Acknowledge</strong> — one person leads, one communicates</li><li><strong>Mitigate</strong> — roll back first, debug later</li><li><strong>Communicate</strong> — status update every 30 minutes</li><li><strong>Review</strong> — blameless write-up within 3 days</li></ol>')],
            ],
            'task_lists' => [
                ['name' => 'Deployment checklist', 'tasks' => self::tasks([
                    'CI green on the release branch', 'Changelog and version updated', 'Database backup taken', 'Deploy announced', 'Smoke test after deploy', 'Error rates checked after 15 minutes',
                ])],
            ],
            'plans' => [[
                'name' => 'Release 1.0 plan',
                'rows' => [
                    ['id' => 'r1', 'name' => 'Build', 'bars' => [self::bar('b1', 0, 13, '#3B82F6')]],
                    ['id' => 'r2', 'name' => 'Code freeze', 'item' => 'i-freeze', 'bars' => [self::milestone('m1', 14)]],
                    ['id' => 'r3', 'name' => 'Stabilize on staging', 'bars' => [self::bar('b2', 14, 20, '#A855F7')]],
                    ['id' => 'r4', 'name' => 'Release 1.0', 'item' => 'i-deploy', 'bars' => [self::milestone('m2', 21)]],
                ],
                'dependencies' => [['id' => 'd1', 'from' => 'b1', 'to' => 'm1'], ['id' => 'd2', 'from' => 'b2', 'to' => 'm2']],
            ]],
        ];
    }
}
