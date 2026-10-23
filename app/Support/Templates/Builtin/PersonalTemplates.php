<?php

namespace App\Support\Templates\Builtin;

/**
 * Built-in templates — Personal & team (BRAI-91).
 */
class PersonalTemplates
{
    use Blocks;

    public static function personalTasks(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-work', 'name' => 'Work', 'color' => '#3b82f6'],
                ['ref' => 'l-home', 'name' => 'Home', 'color' => '#22c55e'],
                ['ref' => 'l-errands', 'name' => 'Errands', 'color' => '#f59e0b'],
                ['ref' => 'l-health', 'name' => 'Health', 'color' => '#ec4899'],
            ],
            'boards' => [[
                'ref' => 'b-tasks', 'name' => 'My tasks', 'type' => 'kanban',
                'description' => 'Capture everything in Inbox, decide what’s next once a day.',
                'columns' => [
                    self::column('c-inbox', 'Inbox', '#94a3b8'),
                    self::column('c-next', 'Next', '#3b82f6', ['wip_limit' => 5]),
                    self::column('c-waiting', 'Waiting for', '#f59e0b'),
                    self::column('c-someday', 'Someday', '#a855f7'),
                    self::column('c-done', 'Done', '#22c55e', ['is_done' => true]),
                ],
            ]],
            'items' => [
                self::issue('i-1', 'b-tasks', 'c-inbox', 'Drop every new to-do here first', ['type' => 'slug:task', 'description' => '<p>Don’t sort while capturing — do it once a day.</p>']),
                self::issue('i-2', 'b-tasks', 'c-next', 'Plan the week', ['type' => 'slug:task', 'priority' => 'high', 'labels' => ['l-work'], 'due' => 0]),
                self::issue('i-3', 'b-tasks', 'c-next', 'Book a dentist appointment', ['type' => 'slug:task', 'labels' => ['l-health'], 'due' => 3]),
                self::issue('i-4', 'b-tasks', 'c-waiting', 'Reply from the landlord', ['type' => 'slug:task', 'labels' => ['l-home']]),
                self::issue('i-5', 'b-tasks', 'c-someday', 'Learn a new language', ['type' => 'slug:task', 'priority' => 'low']),
            ],
            'pages' => [
                ['ref' => 'p-system', 'name' => 'How this board works', 'icon' => '🧠', 'content' => self::doc('<h1>How this board works</h1><ul><li><strong>Inbox</strong> — everything new, unsorted</li><li><strong>Next</strong> — what you’ll actually do soon (max. 5)</li><li><strong>Waiting for</strong> — someone else has to act</li><li><strong>Someday</strong> — ideas, not commitments</li></ul><p>Every Friday: run the weekly review list.</p>')],
            ],
            'task_lists' => [
                ['name' => 'Weekly review', 'tasks' => self::tasks([
                    'Empty the Inbox', 'Check Waiting for — chase what’s late', 'Pick next week’s top 3', 'Look at Someday once', 'Clear the desk and the downloads folder',
                ], null, ['recurrence' => 'weekly'])],
            ],
        ];
    }

    public static function teamOkrs(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-growth', 'name' => 'Growth', 'color' => '#22c55e'],
                ['ref' => 'l-quality', 'name' => 'Quality', 'color' => '#3b82f6'],
                ['ref' => 'l-team', 'name' => 'Team', 'color' => '#a855f7'],
            ],
            'fields' => [
                ['ref' => 'f-progress', 'name' => 'Progress %', 'type' => 'number'],
                ['ref' => 'f-target', 'name' => 'Target', 'type' => 'text'],
                ['ref' => 'f-confidence', 'name' => 'Confidence', 'type' => 'select', 'options' => self::options([
                    'o-high' => ['High', '#22c55e'],
                    'o-medium' => ['Medium', '#f59e0b'],
                    'o-low' => ['Low', '#ef4444'],
                ])],
            ],
            'boards' => [[
                'ref' => 'b-okrs', 'name' => 'Goals', 'type' => 'kanban',
                'description' => 'Objectives are epics, key results their children. Update progress every two weeks.',
                'columns' => [
                    self::column('c-todo', 'Not started', '#94a3b8'),
                    self::column('c-track', 'On track', '#22c55e'),
                    self::column('c-risk', 'At risk', '#ef4444'),
                    self::column('c-done', 'Achieved', '#3b82f6', ['is_done' => true]),
                ],
            ]],
            'items' => [
                self::issue('o-1', 'b-okrs', 'c-todo', 'Objective: Customers love the product', ['type' => 'slug:epic', 'priority' => 'high', 'labels' => ['l-quality'], 'due' => 90]),
                self::issue('kr-1', 'b-okrs', 'c-todo', 'KR: Raise customer satisfaction from 7.5 to 8.5', ['parent' => 'o-1', 'type' => 'slug:task', 'labels' => ['l-quality'], 'fields' => ['f-progress' => 0, 'f-target' => '8.5 / 10', 'f-confidence' => 'o-medium']]),
                self::issue('kr-2', 'b-okrs', 'c-todo', 'KR: Answer support tickets within 4 hours', ['parent' => 'o-1', 'type' => 'slug:task', 'labels' => ['l-quality'], 'fields' => ['f-progress' => 0, 'f-target' => '< 4 h', 'f-confidence' => 'o-high']]),
                self::issue('o-2', 'b-okrs', 'c-todo', 'Objective: Grow faster', ['type' => 'slug:epic', 'priority' => 'high', 'labels' => ['l-growth'], 'due' => 90]),
                self::issue('kr-3', 'b-okrs', 'c-todo', 'KR: 100 new paying customers', ['parent' => 'o-2', 'type' => 'slug:task', 'labels' => ['l-growth'], 'fields' => ['f-progress' => 0, 'f-target' => '100', 'f-confidence' => 'o-low']]),
                self::issue('kr-4', 'b-okrs', 'c-todo', 'KR: Website conversion from 2% to 3%', ['parent' => 'o-2', 'type' => 'slug:task', 'labels' => ['l-growth'], 'fields' => ['f-progress' => 0, 'f-target' => '3%', 'f-confidence' => 'o-medium']]),
            ],
            'pages' => [
                ['ref' => 'p-okrs', 'name' => 'How we set goals', 'icon' => '🎯', 'content' => self::doc('<h1>How we set goals</h1><ul><li>2–3 objectives per quarter — what we want to achieve, in words</li><li>2–4 key results each — numbers that prove it</li><li>Aim high: 70% achieved is a good result</li><li>Every two weeks: update progress and confidence</li></ul>')],
            ],
            'task_lists' => [
                ['name' => 'Goal rhythm', 'tasks' => self::tasks([
                    'Update progress and confidence on every key result', 'Move at-risk results into the team meeting',
                ], null, ['recurrence' => 'weekly'])],
            ],
            'plans' => [[
                'name' => 'Quarter',
                'rows' => [
                    ['id' => 'r1', 'name' => 'Set goals', 'bars' => [self::bar('b1', 0, 6, '#6366F1')]],
                    ['id' => 'r2', 'name' => 'Work on key results', 'bars' => [self::bar('b2', 7, 84, '#22C55E')]],
                    ['id' => 'r3', 'name' => 'Mid-quarter check-in', 'bars' => [self::milestone('m1', 45)]],
                    ['id' => 'r4', 'name' => 'Score and review', 'bars' => [self::milestone('m2', 90)]],
                ],
                'dependencies' => [['id' => 'd1', 'from' => 'b1', 'to' => 'b2']],
            ]],
        ];
    }

    public static function meetingNotes(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-team', 'name' => 'Team meeting', 'color' => '#3b82f6'],
                ['ref' => 'l-one', 'name' => '1:1', 'color' => '#a855f7'],
                ['ref' => 'l-decision', 'name' => 'Decision needed', 'color' => '#f97316'],
            ],
            'boards' => [[
                'ref' => 'b-actions', 'name' => 'Action items', 'type' => 'kanban',
                'description' => 'Every action from a meeting becomes a card with an owner and a due date.',
                'columns' => [
                    self::column('c-todo', 'To Do', '#6b7280'),
                    self::column('c-progress', 'In Progress', '#3b82f6'),
                    self::column('c-done', 'Done', '#22c55e', ['is_done' => true]),
                ],
            ]],
            'items' => [
                self::issue('i-1', 'b-actions', 'c-todo', 'Example: share the Q3 numbers with the team', ['type' => 'slug:task', 'labels' => ['l-team'], 'due' => 2]),
                self::issue('i-2', 'b-actions', 'c-todo', 'Example: decide on the new meeting time', ['type' => 'slug:task', 'labels' => ['l-decision'], 'due' => 7]),
            ],
            'pages' => [
                ['ref' => 'p-meetings', 'type' => 'folder', 'name' => 'Meetings'],
                ['ref' => 'p-weekly', 'parent' => 'p-meetings', 'name' => 'Weekly team meeting', 'icon' => '🗓️', 'content' => self::doc('<h1>Weekly team meeting</h1><p><em>Copy this page for each meeting (… → Duplicate).</em></p><h2>Wins since last week</h2><ul><li>…</li></ul><h2>Updates</h2><ul><li>…</li></ul><h2>Blockers</h2><ul><li>…</li></ul><h2>Decisions</h2><ul><li>…</li></ul><h2>Action items</h2><p>Add them as cards on the Action items board.</p>')],
                ['ref' => 'p-one', 'parent' => 'p-meetings', 'name' => '1:1 notes', 'icon' => '💬', 'content' => self::doc('<h1>1:1 notes</h1><h2>How are you doing?</h2><p>…</p><h2>What’s on your mind</h2><ul><li>…</li></ul><h2>Feedback (both ways)</h2><p>…</p><h2>Growth and goals</h2><p>…</p><h2>Next steps</h2><ul><li>…</li></ul>')],
                ['ref' => 'p-decisions', 'parent' => 'p-meetings', 'name' => 'Decision log', 'icon' => '📌', 'content' => self::doc('<h1>Decision log</h1><p>One line per decision: date — decision — who decided — why.</p><ul><li>…</li></ul>')],
            ],
            'task_lists' => [
                ['name' => 'Recurring meetings', 'tasks' => [
                    ['title' => 'Prepare the weekly team meeting agenda', 'due' => 0, 'recurrence' => 'weekly'],
                    ['title' => 'Send meeting notes and action items', 'due' => 0, 'recurrence' => 'weekly'],
                    ['title' => 'Monthly retro: what should we change?', 'due' => 28, 'recurrence' => 'monthly'],
                ]],
            ],
        ];
    }
}
