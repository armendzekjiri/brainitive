<?php

namespace App\Support\Templates;

/**
 * Project templates that ship with Brainitive (BRAI-72), written in the TemplateDocument
 * format: refs instead of ids, dates as days after the project's start date (day 0).
 * Default issue types / priorities are referenced by slug, so they work on every instance.
 */
class BuiltinTemplates
{
    /**
     * @return list<array{key: string, name: string, description: string, icon: string}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'software-development', 'name' => 'Software development', 'icon' => 'code', 'description' => 'Kanban from backlog to review and done, starter issues, a definition of done and a release checklist.'],
            ['key' => 'scrum', 'name' => 'Scrum project', 'icon' => 'repeat', 'description' => 'A Scrum board with a groomed backlog of stories, sprint ceremony notes and a working agreement.'],
            ['key' => 'client-website', 'name' => 'Client website delivery', 'icon' => 'globe', 'description' => 'Discovery to launch in phases, a timeline plan, a client brief and a launch checklist.'],
            ['key' => 'product-launch', 'name' => 'Product launch', 'icon' => 'rocket', 'description' => 'Launch workstreams, a plan with milestones, messaging doc and a go-to-market checklist.'],
        ];
    }

    public static function has(string $key): bool
    {
        return in_array($key, array_column(self::all(), 'key'), true);
    }

    /**
     * The (normalized) document of a built-in template.
     *
     * @throws TemplateException
     */
    public static function document(string $key): array
    {
        $doc = match ($key) {
            'software-development' => self::softwareDevelopment(),
            'scrum' => self::scrum(),
            'client-website' => self::clientWebsite(),
            'product-launch' => self::productLaunch(),
            default => throw new TemplateException('Unknown template.'),
        };

        return TemplateDocument::normalize(['format' => TemplateDocument::FORMAT] + $doc);
    }

    // ── building blocks ──────────────────────────────────────────────────────

    private static function doc(string $html): array
    {
        return ['type' => 'lexical', 'html' => $html];
    }

    private static function issue(string $ref, string $board, string $column, string $title, array $extra = []): array
    {
        return ['ref' => $ref, 'board' => $board, 'column' => $column, 'title' => $title] + $extra;
    }

    private static function bar(string $id, int $start, int $end, string $color, array $extra = []): array
    {
        return ['id' => $id, 'start' => $start, 'end' => $end, 'type' => 'bar', 'color' => $color] + $extra;
    }

    private static function milestone(string $id, int $day): array
    {
        return ['id' => $id, 'start' => $day, 'end' => $day, 'type' => 'milestone', 'color' => '#F59E0B'];
    }

    private static function tasks(array $titles, ?int $dueStep = null): array
    {
        return array_map(fn ($title, $i) => ['title' => $title, 'due' => $dueStep === null ? null : ($i + 1) * $dueStep], $titles, array_keys($titles));
    }

    // ── templates ────────────────────────────────────────────────────────────

    private static function softwareDevelopment(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-bug', 'name' => 'Bug', 'color' => '#ef4444'],
                ['ref' => 'l-feature', 'name' => 'Feature', 'color' => '#3b82f6'],
                ['ref' => 'l-tech-debt', 'name' => 'Tech debt', 'color' => '#a855f7'],
                ['ref' => 'l-docs', 'name' => 'Docs', 'color' => '#14b8a6'],
            ],
            'fields' => [
                ['ref' => 'f-env', 'name' => 'Environment', 'type' => 'select', 'options' => [
                    ['ref' => 'o-dev', 'label' => 'Development', 'color' => '#64748b'],
                    ['ref' => 'o-staging', 'label' => 'Staging', 'color' => '#f59e0b'],
                    ['ref' => 'o-prod', 'label' => 'Production', 'color' => '#ef4444'],
                ]],
            ],
            'boards' => [[
                'ref' => 'b-dev', 'name' => 'Development', 'type' => 'kanban',
                'description' => 'From idea to shipped. Keep "In progress" and "In review" small.',
                'columns' => [
                    ['ref' => 'c-backlog', 'name' => 'Backlog', 'color' => '#94a3b8'],
                    ['ref' => 'c-todo', 'name' => 'To Do', 'color' => '#6b7280'],
                    ['ref' => 'c-progress', 'name' => 'In Progress', 'color' => '#3b82f6', 'wip_limit' => 4],
                    ['ref' => 'c-review', 'name' => 'In Review', 'color' => '#a855f7', 'wip_limit' => 3],
                    ['ref' => 'c-done', 'name' => 'Done', 'color' => '#22c55e', 'is_done' => true],
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

    private static function scrum(): array
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
                    ['ref' => 'c-todo', 'name' => 'To Do', 'color' => '#6b7280'],
                    ['ref' => 'c-progress', 'name' => 'In Progress', 'color' => '#3b82f6'],
                    ['ref' => 'c-review', 'name' => 'In Review', 'color' => '#a855f7'],
                    ['ref' => 'c-done', 'name' => 'Done', 'color' => '#22c55e', 'is_done' => true],
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

    private static function clientWebsite(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-client', 'name' => 'Needs client input', 'color' => '#f97316'],
                ['ref' => 'l-design', 'name' => 'Design', 'color' => '#ec4899'],
                ['ref' => 'l-dev', 'name' => 'Development', 'color' => '#3b82f6'],
                ['ref' => 'l-content', 'name' => 'Content', 'color' => '#14b8a6'],
            ],
            'fields' => [
                ['ref' => 'f-approved', 'name' => 'Client approved', 'type' => 'checkbox'],
            ],
            'boards' => [[
                'ref' => 'b-site', 'name' => 'Website', 'type' => 'kanban',
                'columns' => [
                    ['ref' => 'c-todo', 'name' => 'To Do', 'color' => '#6b7280'],
                    ['ref' => 'c-progress', 'name' => 'In Progress', 'color' => '#3b82f6'],
                    ['ref' => 'c-client', 'name' => 'Client review', 'color' => '#f97316'],
                    ['ref' => 'c-done', 'name' => 'Done', 'color' => '#22c55e', 'is_done' => true],
                ],
            ]],
            'items' => [
                self::issue('i-kickoff', 'b-site', 'c-todo', 'Kick-off meeting with the client', ['type' => 'slug:task', 'priority' => 'high', 'due' => 2, 'labels' => ['l-client']]),
                self::issue('i-sitemap', 'b-site', 'c-todo', 'Sitemap and content inventory', ['type' => 'slug:task', 'start' => 3, 'due' => 7, 'labels' => ['l-content'], 'fields' => ['f-approved' => false]]),
                self::issue('i-wire', 'b-site', 'c-todo', 'Wireframes for key pages', ['type' => 'slug:task', 'start' => 7, 'due' => 14, 'labels' => ['l-design'], 'fields' => ['f-approved' => false]]),
                self::issue('i-visual', 'b-site', 'c-todo', 'Visual design', ['type' => 'slug:task', 'start' => 14, 'due' => 24, 'labels' => ['l-design'], 'fields' => ['f-approved' => false]]),
                self::issue('i-build', 'b-site', 'c-todo', 'Build templates and CMS', ['type' => 'slug:task', 'start' => 24, 'due' => 42, 'labels' => ['l-dev']]),
                self::issue('i-content', 'b-site', 'c-todo', 'Content entry', ['type' => 'slug:task', 'start' => 35, 'due' => 45, 'labels' => ['l-content', 'l-client']]),
                self::issue('i-qa', 'b-site', 'c-todo', 'QA on desktop and mobile', ['type' => 'slug:task', 'start' => 45, 'due' => 50, 'labels' => ['l-dev']]),
                self::issue('i-launch', 'b-site', 'c-todo', 'Go live', ['type' => 'slug:task', 'priority' => 'highest', 'due' => 52]),
            ],
            'links' => [
                ['from' => 'i-build', 'to' => 'i-visual', 'type' => 'is_blocked_by'],
                ['from' => 'i-launch', 'to' => 'i-qa', 'type' => 'is_blocked_by'],
            ],
            'pages' => [
                ['ref' => 'p-brief', 'name' => 'Client brief', 'icon' => '📝', 'content' => self::doc('<h1>Client brief</h1><h2>Goals</h2><p>…</p><h2>Audience</h2><p>…</p><h2>Must-have pages</h2><ul><li>Home</li><li>About</li><li>Contact</li></ul><h2>Contacts and approvals</h2><p>…</p>')],
            ],
            'task_lists' => [
                ['name' => 'Launch checklist', 'tasks' => self::tasks([
                    'Domain and DNS ready', 'SSL certificate active', 'Redirects from the old site', 'Analytics and cookie banner', 'Forms send to the right inbox', 'Favicon and social preview images', 'Final client sign-off',
                ])],
            ],
            'plans' => [[
                'name' => 'Website timeline',
                'rows' => [
                    ['id' => 'r1', 'name' => 'Discovery', 'item' => 'i-sitemap', 'bars' => [self::bar('b1', 0, 7, '#0EA5E9')]],
                    ['id' => 'r2', 'name' => 'Design', 'bars' => [self::bar('b2', 7, 24, '#EC4899')]],
                    ['id' => 'r3', 'name' => 'Development', 'item' => 'i-build', 'bars' => [self::bar('b3', 24, 42, '#3B82F6')]],
                    ['id' => 'r4', 'name' => 'Content & QA', 'bars' => [self::bar('b4', 35, 50, '#14B8A6')]],
                    ['id' => 'r5', 'name' => 'Launch', 'item' => 'i-launch', 'bars' => [self::milestone('m1', 52)]],
                ],
                'dependencies' => [['id' => 'd1', 'from' => 'b1', 'to' => 'b2'], ['id' => 'd2', 'from' => 'b2', 'to' => 'b3'], ['id' => 'd3', 'from' => 'b4', 'to' => 'm1']],
            ]],
        ];
    }

    private static function productLaunch(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-marketing', 'name' => 'Marketing', 'color' => '#ec4899'],
                ['ref' => 'l-product', 'name' => 'Product', 'color' => '#3b82f6'],
                ['ref' => 'l-sales', 'name' => 'Sales', 'color' => '#22c55e'],
                ['ref' => 'l-support', 'name' => 'Support', 'color' => '#f59e0b'],
            ],
            'fields' => [
                ['ref' => 'f-channel', 'name' => 'Channel', 'type' => 'select', 'options' => [
                    ['ref' => 'o-email', 'label' => 'Email', 'color' => '#3b82f6'],
                    ['ref' => 'o-social', 'label' => 'Social', 'color' => '#ec4899'],
                    ['ref' => 'o-press', 'label' => 'Press', 'color' => '#64748b'],
                    ['ref' => 'o-web', 'label' => 'Website', 'color' => '#14b8a6'],
                ]],
            ],
            'boards' => [[
                'ref' => 'b-launch', 'name' => 'Launch', 'type' => 'kanban',
                'columns' => [
                    ['ref' => 'c-ideas', 'name' => 'Ideas', 'color' => '#94a3b8'],
                    ['ref' => 'c-todo', 'name' => 'To Do', 'color' => '#6b7280'],
                    ['ref' => 'c-progress', 'name' => 'In Progress', 'color' => '#3b82f6'],
                    ['ref' => 'c-done', 'name' => 'Done', 'color' => '#22c55e', 'is_done' => true],
                ],
                'swimlanes' => ['mode' => 'custom', 'rest_name' => 'Other', 'lanes' => [
                    ['name' => 'Marketing', 'color' => '#ec4899', 'rules' => ['labels' => ['l-marketing']]],
                    ['name' => 'Product', 'color' => '#3b82f6', 'rules' => ['labels' => ['l-product']]],
                ]],
            ]],
            'items' => [
                self::issue('i-pos', 'b-launch', 'c-todo', 'Positioning and messaging', ['type' => 'slug:task', 'priority' => 'high', 'due' => 7, 'labels' => ['l-marketing']]),
                self::issue('i-page', 'b-launch', 'c-todo', 'Launch landing page', ['type' => 'slug:task', 'due' => 21, 'labels' => ['l-marketing'], 'fields' => ['f-channel' => 'o-web']]),
                self::issue('i-email', 'b-launch', 'c-todo', 'Announcement email', ['type' => 'slug:task', 'due' => 27, 'labels' => ['l-marketing'], 'fields' => ['f-channel' => 'o-email']]),
                self::issue('i-social', 'b-launch', 'c-todo', 'Social posts for launch week', ['type' => 'slug:task', 'due' => 27, 'labels' => ['l-marketing'], 'fields' => ['f-channel' => 'o-social']]),
                self::issue('i-beta', 'b-launch', 'c-todo', 'Beta feedback round', ['type' => 'slug:task', 'start' => 7, 'due' => 18, 'labels' => ['l-product']]),
                self::issue('i-docs', 'b-launch', 'c-todo', 'Help center articles', ['type' => 'slug:task', 'due' => 25, 'labels' => ['l-support']]),
                self::issue('i-sales', 'b-launch', 'c-todo', 'Sales one-pager and demo script', ['type' => 'slug:task', 'due' => 24, 'labels' => ['l-sales']]),
                self::issue('i-go', 'b-launch', 'c-todo', 'Launch day', ['type' => 'slug:task', 'priority' => 'highest', 'due' => 28]),
            ],
            'links' => [['from' => 'i-email', 'to' => 'i-pos', 'type' => 'is_blocked_by']],
            'pages' => [
                ['ref' => 'p-msg', 'name' => 'Messaging', 'icon' => '💬', 'content' => self::doc('<h1>Messaging</h1><h2>One-liner</h2><p>…</p><h2>Who it is for</h2><p>…</p><h2>Key benefits</h2><ol><li>…</li><li>…</li><li>…</li></ol><h2>Proof points</h2><p>…</p>')],
            ],
            'task_lists' => [
                ['name' => 'Go-to-market checklist', 'tasks' => self::tasks([
                    'Pricing confirmed', 'Landing page live', 'Help center ready', 'Support team briefed', 'Sales team briefed', 'Announcement scheduled', 'Metrics dashboard ready',
                ])],
            ],
            'plans' => [[
                'name' => 'Launch plan',
                'rows' => [
                    ['id' => 'r1', 'name' => 'Positioning', 'item' => 'i-pos', 'bars' => [self::bar('b1', 0, 7, '#EC4899')]],
                    ['id' => 'r2', 'name' => 'Beta', 'item' => 'i-beta', 'bars' => [self::bar('b2', 7, 18, '#3B82F6')]],
                    ['id' => 'r3', 'name' => 'Launch assets', 'bars' => [self::bar('b3', 14, 27, '#14B8A6')]],
                    ['id' => 'r4', 'name' => 'Launch day', 'item' => 'i-go', 'bars' => [self::milestone('m1', 28)]],
                ],
                'dependencies' => [['id' => 'd1', 'from' => 'b1', 'to' => 'b3'], ['id' => 'd2', 'from' => 'b3', 'to' => 'm1']],
            ]],
        ];
    }
}
