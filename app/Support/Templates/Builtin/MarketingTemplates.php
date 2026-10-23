<?php

namespace App\Support\Templates\Builtin;

/**
 * Built-in templates — Marketing (BRAI-72 / BRAI-91).
 */
class MarketingTemplates
{
    use Blocks;

    public static function productLaunch(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-marketing', 'name' => 'Marketing', 'color' => '#ec4899'],
                ['ref' => 'l-product', 'name' => 'Product', 'color' => '#3b82f6'],
                ['ref' => 'l-sales', 'name' => 'Sales', 'color' => '#22c55e'],
                ['ref' => 'l-support', 'name' => 'Support', 'color' => '#f59e0b'],
            ],
            'fields' => [
                ['ref' => 'f-channel', 'name' => 'Channel', 'type' => 'select', 'options' => self::options([
                    'o-email' => ['Email', '#3b82f6'],
                    'o-social' => ['Social', '#ec4899'],
                    'o-press' => ['Press', '#64748b'],
                    'o-web' => ['Website', '#14b8a6'],
                ])],
            ],
            'boards' => [[
                'ref' => 'b-launch', 'name' => 'Launch', 'type' => 'kanban',
                'columns' => [
                    self::column('c-ideas', 'Ideas', '#94a3b8'),
                    self::column('c-todo', 'To Do', '#6b7280'),
                    self::column('c-progress', 'In Progress', '#3b82f6'),
                    self::column('c-done', 'Done', '#22c55e', ['is_done' => true]),
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

    public static function marketingCampaign(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-content', 'name' => 'Content', 'color' => '#14b8a6'],
                ['ref' => 'l-design', 'name' => 'Design', 'color' => '#ec4899'],
                ['ref' => 'l-paid', 'name' => 'Paid', 'color' => '#f59e0b'],
                ['ref' => 'l-analytics', 'name' => 'Analytics', 'color' => '#6366f1'],
            ],
            'fields' => [
                ['ref' => 'f-channel', 'name' => 'Channel', 'type' => 'select', 'options' => self::options([
                    'o-email' => ['Email', '#3b82f6'],
                    'o-social' => ['Social', '#ec4899'],
                    'o-search' => ['Search ads', '#f59e0b'],
                    'o-web' => ['Website', '#14b8a6'],
                    'o-events' => ['Events', '#a855f7'],
                ])],
                ['ref' => 'f-budget', 'name' => 'Budget', 'type' => 'number'],
            ],
            'boards' => [[
                'ref' => 'b-campaign', 'name' => 'Campaign', 'type' => 'kanban',
                'columns' => [
                    self::column('c-ideas', 'Ideas', '#94a3b8'),
                    self::column('c-planned', 'Planned', '#6b7280'),
                    self::column('c-progress', 'In Progress', '#3b82f6'),
                    self::column('c-review', 'Review', '#a855f7'),
                    self::column('c-live', 'Live', '#22c55e', ['is_done' => true]),
                ],
            ]],
            'items' => [
                self::issue('i-brief', 'b-campaign', 'c-planned', 'Campaign brief and goals', ['type' => 'slug:task', 'priority' => 'high', 'due' => 3]),
                self::issue('i-audience', 'b-campaign', 'c-planned', 'Audience and targeting', ['type' => 'slug:task', 'due' => 5, 'labels' => ['l-analytics']]),
                self::issue('i-creative', 'b-campaign', 'c-planned', 'Key visual and creative assets', ['type' => 'slug:task', 'start' => 5, 'due' => 12, 'labels' => ['l-design']]),
                self::issue('i-landing', 'b-campaign', 'c-planned', 'Campaign landing page', ['type' => 'slug:task', 'start' => 8, 'due' => 15, 'labels' => ['l-content'], 'fields' => ['f-channel' => 'o-web']]),
                self::issue('i-emails', 'b-campaign', 'c-planned', 'Email sequence (3 emails)', ['type' => 'slug:task', 'due' => 15, 'labels' => ['l-content'], 'fields' => ['f-channel' => 'o-email']]),
                self::issue('i-ads', 'b-campaign', 'c-planned', 'Set up paid ads', ['type' => 'slug:task', 'due' => 16, 'labels' => ['l-paid'], 'fields' => ['f-channel' => 'o-search', 'f-budget' => 2000]]),
                self::issue('i-launch', 'b-campaign', 'c-planned', 'Campaign goes live', ['type' => 'slug:task', 'priority' => 'highest', 'due' => 17]),
                self::issue('i-report', 'b-campaign', 'c-ideas', 'Results report', ['type' => 'slug:task', 'due' => 45, 'labels' => ['l-analytics']]),
            ],
            'links' => [['from' => 'i-launch', 'to' => 'i-landing', 'type' => 'is_blocked_by']],
            'pages' => [
                ['ref' => 'p-brief', 'name' => 'Campaign brief', 'icon' => '📣', 'content' => self::doc('<h1>Campaign brief</h1><h2>Goal</h2><p>What should change, by how much, by when?</p><h2>Audience</h2><p>…</p><h2>Key message</h2><p>…</p><h2>Channels and budget</h2><ul><li>…</li></ul><h2>How we measure it</h2><ul><li>…</li></ul>')],
            ],
            'task_lists' => [
                ['name' => 'Launch checklist', 'tasks' => self::tasks([
                    'Tracking links (UTM) set', 'Landing page tested on mobile', 'Emails sent to a test list', 'Ads approved', 'Team knows the launch time',
                ])],
            ],
            'plans' => [[
                'name' => 'Campaign timeline',
                'rows' => [
                    ['id' => 'r1', 'name' => 'Plan', 'item' => 'i-brief', 'bars' => [self::bar('b1', 0, 5, '#6366F1')]],
                    ['id' => 'r2', 'name' => 'Create', 'bars' => [self::bar('b2', 5, 16, '#EC4899')]],
                    ['id' => 'r3', 'name' => 'Live', 'item' => 'i-launch', 'bars' => [self::milestone('m1', 17)]],
                    ['id' => 'r4', 'name' => 'Run and optimise', 'bars' => [self::bar('b3', 17, 44, '#22C55E')]],
                    ['id' => 'r5', 'name' => 'Report', 'item' => 'i-report', 'bars' => [self::milestone('m2', 45)]],
                ],
                'dependencies' => [['id' => 'd1', 'from' => 'b1', 'to' => 'b2'], ['id' => 'd2', 'from' => 'b2', 'to' => 'm1']],
            ]],
        ];
    }

    public static function contentCalendar(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-blog', 'name' => 'Blog', 'color' => '#3b82f6'],
                ['ref' => 'l-newsletter', 'name' => 'Newsletter', 'color' => '#14b8a6'],
                ['ref' => 'l-video', 'name' => 'Video', 'color' => '#ef4444'],
                ['ref' => 'l-case', 'name' => 'Case study', 'color' => '#a855f7'],
            ],
            'fields' => [
                ['ref' => 'f-publish', 'name' => 'Publish date', 'type' => 'date'],
                ['ref' => 'f-keyword', 'name' => 'Target keyword', 'type' => 'text'],
            ],
            'boards' => [[
                'ref' => 'b-content', 'name' => 'Content', 'type' => 'kanban',
                'columns' => [
                    self::column('c-ideas', 'Ideas', '#94a3b8'),
                    self::column('c-writing', 'Writing', '#3b82f6', ['wip_limit' => 4]),
                    self::column('c-editing', 'Editing', '#a855f7'),
                    self::column('c-scheduled', 'Scheduled', '#f59e0b'),
                    self::column('c-published', 'Published', '#22c55e', ['is_done' => true]),
                ],
            ]],
            'items' => [
                self::issue('i-1', 'b-content', 'c-writing', 'How-to guide for your main use case', ['type' => 'slug:task', 'labels' => ['l-blog'], 'due' => 5, 'fields' => ['f-publish' => 7, 'f-keyword' => 'how to …']]),
                self::issue('i-2', 'b-content', 'c-ideas', 'Monthly newsletter', ['type' => 'slug:task', 'labels' => ['l-newsletter'], 'due' => 12, 'fields' => ['f-publish' => 14]]),
                self::issue('i-3', 'b-content', 'c-ideas', 'Customer story', ['type' => 'slug:task', 'labels' => ['l-case'], 'due' => 18, 'fields' => ['f-publish' => 21]]),
                self::issue('i-4', 'b-content', 'c-ideas', 'Short product demo video', ['type' => 'slug:task', 'labels' => ['l-video'], 'due' => 25, 'fields' => ['f-publish' => 28]]),
                self::issue('i-5', 'b-content', 'c-ideas', 'Round-up: top questions from customers', ['type' => 'slug:task', 'labels' => ['l-blog']]),
            ],
            'pages' => [
                ['ref' => 'p-guide', 'name' => 'Editorial guidelines', 'icon' => '✍️', 'content' => self::doc('<h1>Editorial guidelines</h1><h2>Voice</h2><p>Friendly, direct, no jargon. Short sentences.</p><h2>Structure</h2><ul><li>Headline promises one clear benefit</li><li>Answer the question in the first paragraph</li><li>Subheadings every 300 words</li><li>End with one next step</li></ul><h2>Before publishing</h2><p>See the “Before publishing” list.</p>')],
            ],
            'task_lists' => [
                ['name' => 'Before publishing', 'tasks' => self::tasks([
                    'Spelling and links checked', 'Meta title and description', 'Images compressed with alt text', 'Internal links added', 'Shared on social and in the newsletter',
                ])],
            ],
        ];
    }

    public static function socialMedia(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-promo', 'name' => 'Promotion', 'color' => '#f97316'],
                ['ref' => 'l-edu', 'name' => 'Educational', 'color' => '#3b82f6'],
                ['ref' => 'l-bts', 'name' => 'Behind the scenes', 'color' => '#a855f7'],
                ['ref' => 'l-ugc', 'name' => 'Community', 'color' => '#22c55e'],
            ],
            'fields' => [
                ['ref' => 'f-platform', 'name' => 'Platform', 'type' => 'select', 'options' => self::options([
                    'o-linkedin' => ['LinkedIn', '#0a66c2'],
                    'o-instagram' => ['Instagram', '#e1306c'],
                    'o-tiktok' => ['TikTok', '#111827'],
                    'o-x' => ['X', '#64748b'],
                    'o-facebook' => ['Facebook', '#1877f2'],
                ])],
                ['ref' => 'f-post', 'name' => 'Post date', 'type' => 'date'],
            ],
            'boards' => [[
                'ref' => 'b-social', 'name' => 'Social posts', 'type' => 'kanban',
                'columns' => [
                    self::column('c-ideas', 'Ideas', '#94a3b8'),
                    self::column('c-drafting', 'Drafting', '#3b82f6'),
                    self::column('c-approval', 'Approval', '#f59e0b'),
                    self::column('c-scheduled', 'Scheduled', '#a855f7'),
                    self::column('c-posted', 'Posted', '#22c55e', ['is_done' => true]),
                ],
            ]],
            'items' => [
                self::issue('i-1', 'b-social', 'c-drafting', 'Tip of the week', ['type' => 'slug:task', 'labels' => ['l-edu'], 'fields' => ['f-platform' => 'o-linkedin', 'f-post' => 1]]),
                self::issue('i-2', 'b-social', 'c-ideas', 'Meet the team: photo series', ['type' => 'slug:task', 'labels' => ['l-bts'], 'fields' => ['f-platform' => 'o-instagram', 'f-post' => 3]]),
                self::issue('i-3', 'b-social', 'c-ideas', '30-second feature demo', ['type' => 'slug:task', 'labels' => ['l-edu'], 'fields' => ['f-platform' => 'o-tiktok', 'f-post' => 5]]),
                self::issue('i-4', 'b-social', 'c-ideas', 'Share a customer post', ['type' => 'slug:task', 'labels' => ['l-ugc'], 'fields' => ['f-platform' => 'o-linkedin', 'f-post' => 8]]),
                self::issue('i-5', 'b-social', 'c-ideas', 'Monthly offer announcement', ['type' => 'slug:task', 'labels' => ['l-promo'], 'fields' => ['f-platform' => 'o-facebook', 'f-post' => 10]]),
            ],
            'pages' => [
                ['ref' => 'p-voice', 'name' => 'Brand voice for social', 'icon' => '📱', 'content' => self::doc('<h1>Brand voice for social</h1><ul><li>Talk like a helpful colleague, not an ad</li><li>One idea per post</li><li>Mix: 40% educational, 30% community, 20% behind the scenes, 10% promotion</li><li>Reply to comments within one working day</li></ul><h2>Hashtags</h2><p>…</p>')],
            ],
            'task_lists' => [
                ['name' => 'Weekly routine', 'tasks' => self::tasks([
                    'Plan next week’s posts', 'Reply to comments and messages', 'Check what performed best', 'Collect ideas from the team',
                ], null, ['recurrence' => 'weekly'])],
            ],
        ];
    }

    public static function eventPlanning(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-venue', 'name' => 'Venue', 'color' => '#6366f1'],
                ['ref' => 'l-catering', 'name' => 'Catering', 'color' => '#f97316'],
                ['ref' => 'l-speakers', 'name' => 'Speakers', 'color' => '#a855f7'],
                ['ref' => 'l-promo', 'name' => 'Promotion', 'color' => '#ec4899'],
                ['ref' => 'l-logistics', 'name' => 'Logistics', 'color' => '#14b8a6'],
            ],
            'fields' => [
                ['ref' => 'f-cost', 'name' => 'Cost', 'type' => 'number'],
                ['ref' => 'f-vendor', 'name' => 'Vendor', 'type' => 'text'],
                ['ref' => 'f-confirmed', 'name' => 'Confirmed', 'type' => 'checkbox'],
            ],
            'boards' => [[
                'ref' => 'b-event', 'name' => 'Event', 'type' => 'kanban',
                'columns' => [
                    self::column('c-todo', 'To Do', '#6b7280'),
                    self::column('c-progress', 'In Progress', '#3b82f6'),
                    self::column('c-waiting', 'Waiting on vendor', '#f59e0b'),
                    self::column('c-done', 'Done', '#22c55e', ['is_done' => true]),
                ],
            ]],
            'items' => [
                self::issue('i-venue', 'b-event', 'c-todo', 'Book the venue', ['type' => 'slug:task', 'priority' => 'highest', 'labels' => ['l-venue'], 'due' => 7, 'fields' => ['f-confirmed' => false]]),
                self::issue('i-speakers', 'b-event', 'c-todo', 'Confirm speakers', ['type' => 'slug:task', 'priority' => 'high', 'labels' => ['l-speakers'], 'due' => 14]),
                self::issue('i-registration', 'b-event', 'c-todo', 'Registration page and tickets', ['type' => 'slug:task', 'labels' => ['l-promo'], 'due' => 14]),
                self::issue('i-promo', 'b-event', 'c-todo', 'Promotion: email, social, partners', ['type' => 'slug:task', 'labels' => ['l-promo'], 'start' => 14, 'due' => 40]),
                self::issue('i-catering', 'b-event', 'c-todo', 'Catering', ['type' => 'slug:task', 'labels' => ['l-catering'], 'due' => 30, 'fields' => ['f-confirmed' => false]]),
                self::issue('i-av', 'b-event', 'c-todo', 'Audio, video and streaming', ['type' => 'slug:task', 'labels' => ['l-logistics'], 'due' => 35]),
                self::issue('i-badges', 'b-event', 'c-todo', 'Badges, signage and printed agenda', ['type' => 'slug:task', 'labels' => ['l-logistics'], 'due' => 42]),
                self::issue('i-day', 'b-event', 'c-todo', 'Event day', ['type' => 'slug:task', 'priority' => 'highest', 'due' => 45]),
                self::issue('i-followup', 'b-event', 'c-todo', 'Thank-you email and survey', ['type' => 'slug:task', 'labels' => ['l-promo'], 'due' => 47]),
            ],
            'links' => [['from' => 'i-registration', 'to' => 'i-venue', 'type' => 'is_blocked_by']],
            'pages' => [
                ['ref' => 'p-brief', 'name' => 'Event brief', 'icon' => '🎉', 'content' => self::doc('<h1>Event brief</h1><h2>What and why</h2><p>…</p><h2>Date, place, expected guests</h2><p>…</p><h2>Budget</h2><p>…</p><h2>Agenda</h2><ul><li>…</li></ul><h2>Contacts</h2><p>Venue, catering, AV — names and numbers.</p>')],
            ],
            'task_lists' => [
                ['name' => 'Day-of checklist', 'tasks' => self::tasks([
                    'Arrive two hours early', 'Test sound, slides and Wi-Fi', 'Registration desk ready', 'Signage up', 'Speakers briefed', 'Photos taken', 'Venue handed back',
                ])],
            ],
            'plans' => [[
                'name' => 'Event timeline',
                'rows' => [
                    ['id' => 'r1', 'name' => 'Venue and speakers', 'bars' => [self::bar('b1', 0, 14, '#6366F1')]],
                    ['id' => 'r2', 'name' => 'Promotion', 'item' => 'i-promo', 'bars' => [self::bar('b2', 14, 40, '#EC4899')]],
                    ['id' => 'r3', 'name' => 'Logistics', 'bars' => [self::bar('b3', 25, 43, '#14B8A6')]],
                    ['id' => 'r4', 'name' => 'Event day', 'item' => 'i-day', 'bars' => [self::milestone('m1', 45)]],
                ],
                'dependencies' => [['id' => 'd1', 'from' => 'b1', 'to' => 'b2'], ['id' => 'd2', 'from' => 'b3', 'to' => 'm1']],
            ]],
        ];
    }
}
