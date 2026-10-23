<?php

namespace App\Support\Templates\Builtin;

/**
 * Built-in templates — Agency & clients, HR & people, Sales, Operations & finance
 * (BRAI-72 / BRAI-91).
 */
class BusinessTemplates
{
    use Blocks;

    // ── Agency & clients ─────────────────────────────────────────────────────

    public static function clientWebsite(): array
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
                    self::column('c-todo', 'To Do', '#6b7280'),
                    self::column('c-progress', 'In Progress', '#3b82f6'),
                    self::column('c-client', 'Client review', '#f97316'),
                    self::column('c-done', 'Done', '#22c55e', ['is_done' => true]),
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

    public static function clientOnboarding(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-contract', 'name' => 'Contract', 'color' => '#6366f1'],
                ['ref' => 'l-access', 'name' => 'Access', 'color' => '#f59e0b'],
                ['ref' => 'l-kickoff', 'name' => 'Kick-off', 'color' => '#22c55e'],
            ],
            'fields' => [
                ['ref' => 'f-done-client', 'name' => 'Client confirmed', 'type' => 'checkbox'],
            ],
            'boards' => [[
                'ref' => 'b-onboard', 'name' => 'Onboarding', 'type' => 'kanban',
                'columns' => [
                    self::column('c-todo', 'Not started', '#6b7280'),
                    self::column('c-progress', 'In progress', '#3b82f6'),
                    self::column('c-client', 'Waiting on client', '#f97316'),
                    self::column('c-done', 'Done', '#22c55e', ['is_done' => true]),
                ],
            ]],
            'items' => [
                self::issue('i-contract', 'b-onboard', 'c-todo', 'Send contract and get it signed', ['type' => 'slug:task', 'priority' => 'highest', 'labels' => ['l-contract'], 'due' => 2]),
                self::issue('i-invoice', 'b-onboard', 'c-todo', 'Send the first invoice', ['type' => 'slug:task', 'labels' => ['l-contract'], 'due' => 3]),
                self::issue('i-assets', 'b-onboard', 'c-todo', 'Collect brand assets and account access', ['type' => 'slug:task', 'priority' => 'high', 'labels' => ['l-access'], 'due' => 5, 'fields' => ['f-done-client' => false]]),
                self::issue('i-folder', 'b-onboard', 'c-todo', 'Set up the shared folder and channel', ['type' => 'slug:task', 'labels' => ['l-access'], 'due' => 3]),
                self::issue('i-kickoff', 'b-onboard', 'c-todo', 'Kick-off meeting', ['type' => 'slug:task', 'priority' => 'high', 'labels' => ['l-kickoff'], 'due' => 7]),
                self::issue('i-goals', 'b-onboard', 'c-todo', 'Agree on goals and success metrics', ['type' => 'slug:task', 'labels' => ['l-kickoff'], 'due' => 10, 'fields' => ['f-done-client' => false]]),
                self::issue('i-checkin', 'b-onboard', 'c-todo', 'Two-week check-in call', ['type' => 'slug:task', 'due' => 21]),
            ],
            'links' => [['from' => 'i-kickoff', 'to' => 'i-contract', 'type' => 'is_blocked_by']],
            'pages' => [
                ['ref' => 'p-welcome', 'name' => 'Welcome pack', 'icon' => '👋', 'content' => self::doc('<h1>Welcome!</h1><p>We’re glad to work with you. Here is how we’ll work together.</p><h2>Your team</h2><p>…</p><h2>How to reach us</h2><p>…</p><h2>What we need from you</h2><ul><li>Brand assets (logo, fonts, colors)</li><li>Access to the accounts we’ll work on</li><li>One contact person for approvals</li></ul><h2>What happens next</h2><p>Kick-off, then weekly updates every Friday.</p>')],
            ],
            'task_lists' => [
                ['name' => 'Onboarding checklist', 'tasks' => self::tasks([
                    'Client added to the CRM', 'Contract signed', 'First invoice sent', 'Welcome pack sent', 'Kick-off scheduled', 'Weekly update slot agreed',
                ])],
            ],
        ];
    }

    public static function designRequests(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-social', 'name' => 'Social graphic', 'color' => '#ec4899'],
                ['ref' => 'l-web', 'name' => 'Web', 'color' => '#3b82f6'],
                ['ref' => 'l-print', 'name' => 'Print', 'color' => '#a855f7'],
                ['ref' => 'l-brand', 'name' => 'Brand', 'color' => '#f59e0b'],
            ],
            'fields' => [
                ['ref' => 'f-requester', 'name' => 'Requested by', 'type' => 'text'],
                ['ref' => 'f-format', 'name' => 'Format / size', 'type' => 'text'],
            ],
            'boards' => [[
                'ref' => 'b-design', 'name' => 'Design requests', 'type' => 'kanban',
                'columns' => [
                    self::column('c-requests', 'Requests', '#94a3b8'),
                    self::column('c-design', 'In design', '#3b82f6', ['wip_limit' => 3]),
                    self::column('c-feedback', 'Feedback', '#f59e0b'),
                    self::column('c-approved', 'Approved', '#22c55e', ['is_done' => true]),
                ],
                'swimlanes' => ['mode' => 'priority', 'rest_name' => null, 'lanes' => []],
            ]],
            'items' => [
                self::issue('i-1', 'b-design', 'c-requests', 'Banner for the spring newsletter', ['type' => 'slug:task', 'priority' => 'medium', 'labels' => ['l-web'], 'due' => 5, 'fields' => ['f-requester' => 'Marketing', 'f-format' => '1200 × 400 px']]),
                self::issue('i-2', 'b-design', 'c-requests', 'Three LinkedIn post graphics', ['type' => 'slug:task', 'priority' => 'medium', 'labels' => ['l-social'], 'due' => 7, 'fields' => ['f-requester' => 'Marketing', 'f-format' => '1200 × 1200 px']]),
                self::issue('i-3', 'b-design', 'c-requests', 'Event roll-up banner', ['type' => 'slug:task', 'priority' => 'high', 'labels' => ['l-print'], 'due' => 14, 'fields' => ['f-format' => '85 × 200 cm, print-ready PDF']]),
                self::issue('i-4', 'b-design', 'c-requests', 'Refresh the slide template', ['type' => 'slug:task', 'priority' => 'low', 'labels' => ['l-brand']]),
            ],
            'pages' => [
                ['ref' => 'p-how', 'name' => 'How to request design work', 'icon' => '🎨', 'content' => self::doc('<h1>How to request design work</h1><p>Create an issue in <strong>Requests</strong> with:</p><ul><li>What it is for and who will see it</li><li>Format and size (set the “Format / size” field)</li><li>Text that goes on it — final, proofread</li><li>Deadline (due date) — at least 5 working days ahead</li></ul><p>We pick requests by priority every Monday.</p>')],
                ['ref' => 'p-brand', 'name' => 'Brand basics', 'icon' => '🎯', 'content' => self::doc('<h1>Brand basics</h1><h2>Logo</h2><p>…</p><h2>Colors</h2><p>…</p><h2>Fonts</h2><p>…</p><h2>Do and don’t</h2><ul><li>…</li></ul>')],
            ],
        ];
    }

    // ── HR & people ──────────────────────────────────────────────────────────

    public static function recruiting(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-eng', 'name' => 'Engineering', 'color' => '#3b82f6'],
                ['ref' => 'l-design', 'name' => 'Design', 'color' => '#ec4899'],
                ['ref' => 'l-sales', 'name' => 'Sales', 'color' => '#22c55e'],
            ],
            'fields' => [
                ['ref' => 'f-source', 'name' => 'Source', 'type' => 'select', 'options' => self::options([
                    'o-referral' => ['Referral', '#22c55e'],
                    'o-linkedin' => ['LinkedIn', '#0a66c2'],
                    'o-jobboard' => ['Job board', '#f59e0b'],
                    'o-agency' => ['Agency', '#a855f7'],
                ])],
                ['ref' => 'f-score', 'name' => 'Interview score', 'type' => 'number'],
                ['ref' => 'f-start', 'name' => 'Possible start', 'type' => 'date'],
            ],
            'boards' => [[
                'ref' => 'b-hiring', 'name' => 'Candidates', 'type' => 'kanban',
                'description' => 'One card per candidate. Never put personal data you don’t need here.',
                'columns' => [
                    self::column('c-applied', 'Applied', '#94a3b8'),
                    self::column('c-screen', 'Screening', '#6b7280'),
                    self::column('c-interview', 'Interview', '#3b82f6'),
                    self::column('c-offer', 'Offer', '#f59e0b'),
                    self::column('c-hired', 'Hired', '#22c55e', ['is_done' => true]),
                    self::column('c-rejected', 'Not a fit', '#64748b', ['is_done' => true]),
                ],
                'swimlanes' => ['mode' => 'custom', 'rest_name' => 'Other roles', 'lanes' => [
                    ['name' => 'Engineering', 'color' => '#3b82f6', 'rules' => ['labels' => ['l-eng']]],
                    ['name' => 'Sales', 'color' => '#22c55e', 'rules' => ['labels' => ['l-sales']]],
                ]],
            ]],
            'items' => [
                self::issue('i-1', 'b-hiring', 'c-applied', 'Sample candidate — Frontend engineer', ['type' => 'slug:task', 'labels' => ['l-eng'], 'fields' => ['f-source' => 'o-linkedin']]),
                self::issue('i-2', 'b-hiring', 'c-screen', 'Sample candidate — Backend engineer', ['type' => 'slug:task', 'labels' => ['l-eng'], 'fields' => ['f-source' => 'o-referral']]),
                self::issue('i-3', 'b-hiring', 'c-interview', 'Sample candidate — Account executive', ['type' => 'slug:task', 'labels' => ['l-sales'], 'fields' => ['f-source' => 'o-jobboard', 'f-score' => 4]]),
                self::issue('i-4', 'b-hiring', 'c-applied', 'Sample candidate — Product designer', ['type' => 'slug:task', 'labels' => ['l-design'], 'fields' => ['f-source' => 'o-agency']]),
            ],
            'pages' => [
                ['ref' => 'p-hiring', 'type' => 'folder', 'name' => 'Hiring'],
                ['ref' => 'p-scorecard', 'parent' => 'p-hiring', 'name' => 'Interview scorecard', 'icon' => '📋', 'content' => self::doc('<h1>Interview scorecard</h1><p>Score each area 1–5 and write one sentence of evidence.</p><ul><li><strong>Skills for the role</strong> — …</li><li><strong>Problem solving</strong> — …</li><li><strong>Communication</strong> — …</li><li><strong>Team fit and values</strong> — …</li></ul><p><strong>Recommendation:</strong> strong yes / yes / no / strong no</p>')],
                ['ref' => 'p-jd', 'parent' => 'p-hiring', 'name' => 'Job description template', 'icon' => '📄', 'content' => self::doc('<h1>[Role title]</h1><h2>About us</h2><p>…</p><h2>What you’ll do</h2><ul><li>…</li></ul><h2>What you bring</h2><ul><li>…</li></ul><h2>What we offer</h2><ul><li>…</li></ul><h2>How to apply</h2><p>…</p>')],
            ],
            'task_lists' => [
                ['name' => 'Hiring process', 'tasks' => self::tasks([
                    'Job description approved', 'Job posted', 'Screening calls (30 min)', 'Interviews with the team', 'Reference check', 'Offer sent', 'Every candidate gets an answer',
                ])],
            ],
        ];
    }

    public static function employeeOnboarding(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-before', 'name' => 'Before day 1', 'color' => '#6366f1'],
                ['ref' => 'l-week1', 'name' => 'Week 1', 'color' => '#3b82f6'],
                ['ref' => 'l-month1', 'name' => 'Month 1', 'color' => '#14b8a6'],
                ['ref' => 'l-90', 'name' => 'First 90 days', 'color' => '#22c55e'],
            ],
            'boards' => [[
                'ref' => 'b-onboarding', 'name' => 'Onboarding', 'type' => 'kanban',
                'columns' => [
                    self::column('c-todo', 'To Do', '#6b7280'),
                    self::column('c-progress', 'In Progress', '#3b82f6'),
                    self::column('c-done', 'Done', '#22c55e', ['is_done' => true]),
                ],
                'swimlanes' => ['mode' => 'custom', 'rest_name' => 'Later', 'lanes' => [
                    ['name' => 'Before day 1', 'color' => '#6366f1', 'rules' => ['labels' => ['l-before']]],
                    ['name' => 'Week 1', 'color' => '#3b82f6', 'rules' => ['labels' => ['l-week1']]],
                ]],
            ]],
            'items' => [
                self::issue('i-laptop', 'b-onboarding', 'c-todo', 'Order and set up the laptop', ['type' => 'slug:task', 'priority' => 'high', 'labels' => ['l-before'], 'due' => 0]),
                self::issue('i-accounts', 'b-onboarding', 'c-todo', 'Create accounts (email, chat, Brainitive)', ['type' => 'slug:task', 'priority' => 'high', 'labels' => ['l-before'], 'due' => 0]),
                self::issue('i-buddy', 'b-onboarding', 'c-todo', 'Pick an onboarding buddy', ['type' => 'slug:task', 'labels' => ['l-before'], 'due' => 0]),
                self::issue('i-welcome', 'b-onboarding', 'c-todo', 'Welcome meeting and team lunch', ['type' => 'slug:task', 'labels' => ['l-week1'], 'due' => 0]),
                self::issue('i-tools', 'b-onboarding', 'c-todo', 'Walk through tools and how we work', ['type' => 'slug:task', 'labels' => ['l-week1'], 'due' => 2]),
                self::issue('i-first', 'b-onboarding', 'c-todo', 'First small task delivered', ['type' => 'slug:task', 'labels' => ['l-week1'], 'due' => 4]),
                self::issue('i-30', 'b-onboarding', 'c-todo', '30-day check-in', ['type' => 'slug:task', 'labels' => ['l-month1'], 'due' => 30]),
                self::issue('i-60', 'b-onboarding', 'c-todo', '60-day check-in', ['type' => 'slug:task', 'labels' => ['l-90'], 'due' => 60]),
                self::issue('i-90', 'b-onboarding', 'c-todo', '90-day review', ['type' => 'slug:task', 'priority' => 'high', 'labels' => ['l-90'], 'due' => 90]),
            ],
            'pages' => [
                ['ref' => 'p-handbook', 'name' => 'Welcome handbook', 'icon' => '📖', 'content' => self::doc('<h1>Welcome to the team!</h1><h2>Your first week</h2><ul><li>Day 1: welcome meeting, accounts, lunch with the team</li><li>Day 2–3: tools and how we work, meet your buddy</li><li>Day 5: first small task delivered</li></ul><h2>Who to ask</h2><p>Your buddy for everyday questions, your manager for priorities.</p><h2>Useful links</h2><ul><li>…</li></ul>')],
            ],
            'task_lists' => [
                ['name' => 'First day', 'tasks' => self::tasks([
                    'Laptop works, Wi-Fi connected', 'Signed in to all tools', 'Met the team', 'Read the welcome handbook', 'Coffee with your buddy',
                ])],
            ],
            'plans' => [[
                'name' => '90-day plan',
                'rows' => [
                    ['id' => 'r1', 'name' => 'Learn: people, product, tools', 'bars' => [self::bar('b1', 0, 30, '#3B82F6')]],
                    ['id' => 'r2', 'name' => '30-day check-in', 'item' => 'i-30', 'bars' => [self::milestone('m1', 30)]],
                    ['id' => 'r3', 'name' => 'Contribute: own small projects', 'bars' => [self::bar('b2', 30, 60, '#14B8A6')]],
                    ['id' => 'r4', 'name' => 'Lead: take full ownership', 'bars' => [self::bar('b3', 60, 90, '#22C55E')]],
                    ['id' => 'r5', 'name' => '90-day review', 'item' => 'i-90', 'bars' => [self::milestone('m2', 90)]],
                ],
                'dependencies' => [['id' => 'd1', 'from' => 'b1', 'to' => 'b2'], ['id' => 'd2', 'from' => 'b2', 'to' => 'b3']],
            ]],
        ];
    }

    // ── Sales ────────────────────────────────────────────────────────────────

    public static function salesPipeline(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-inbound', 'name' => 'Inbound', 'color' => '#22c55e'],
                ['ref' => 'l-outbound', 'name' => 'Outbound', 'color' => '#3b82f6'],
                ['ref' => 'l-partner', 'name' => 'Partner', 'color' => '#a855f7'],
            ],
            'fields' => [
                ['ref' => 'f-value', 'name' => 'Deal value', 'type' => 'number'],
                ['ref' => 'f-close', 'name' => 'Expected close', 'type' => 'date'],
                ['ref' => 'f-size', 'name' => 'Company size', 'type' => 'select', 'options' => self::options([
                    'o-small' => ['1–50', '#94a3b8'],
                    'o-mid' => ['51–500', '#3b82f6'],
                    'o-large' => ['500+', '#6366f1'],
                ])],
            ],
            'boards' => [[
                'ref' => 'b-pipeline', 'name' => 'Pipeline', 'type' => 'kanban',
                'description' => 'One card per deal. Move it as it progresses; set value and expected close.',
                'columns' => [
                    self::column('c-lead', 'Lead', '#94a3b8'),
                    self::column('c-qualified', 'Qualified', '#6b7280'),
                    self::column('c-meeting', 'Meeting', '#3b82f6'),
                    self::column('c-proposal', 'Proposal', '#a855f7'),
                    self::column('c-negotiation', 'Negotiation', '#f59e0b'),
                    self::column('c-won', 'Won', '#22c55e', ['is_done' => true]),
                    self::column('c-lost', 'Lost', '#64748b', ['is_done' => true]),
                ],
            ]],
            'items' => [
                self::issue('i-1', 'b-pipeline', 'c-lead', 'Example Co — 20 seats', ['type' => 'slug:task', 'labels' => ['l-inbound'], 'fields' => ['f-value' => 4800, 'f-close' => 30, 'f-size' => 'o-small']]),
                self::issue('i-2', 'b-pipeline', 'c-qualified', 'Sample Industries — pilot', ['type' => 'slug:task', 'labels' => ['l-outbound'], 'fields' => ['f-value' => 12000, 'f-close' => 45, 'f-size' => 'o-mid']]),
                self::issue('i-3', 'b-pipeline', 'c-proposal', 'Demo Group — company-wide rollout', ['type' => 'slug:task', 'priority' => 'high', 'labels' => ['l-partner'], 'fields' => ['f-value' => 60000, 'f-close' => 60, 'f-size' => 'o-large']]),
            ],
            'pages' => [
                ['ref' => 'p-playbook', 'name' => 'Sales playbook', 'icon' => '🤝', 'content' => self::doc('<h1>Sales playbook</h1><h2>Stages</h2><ul><li><strong>Lead</strong> — we know the company and a contact</li><li><strong>Qualified</strong> — budget, need, decision-maker and timing are clear</li><li><strong>Meeting</strong> — first call or demo held</li><li><strong>Proposal</strong> — offer sent</li><li><strong>Negotiation</strong> — terms being agreed</li></ul><h2>Rules</h2><ul><li>Every deal has a value and an expected close date</li><li>No deal sits in a stage for more than 30 days without a next step</li></ul>')],
            ],
            'task_lists' => [
                ['name' => 'Qualification questions', 'tasks' => self::tasks([
                    'Budget: is money set aside?', 'Authority: who decides?', 'Need: what problem are they solving?', 'Timing: when do they want to start?',
                ])],
                ['name' => 'Weekly pipeline review', 'tasks' => self::tasks([
                    'Update deal values and close dates', 'Move or close stale deals', 'Plan next steps for the top 5 deals',
                ], null, ['recurrence' => 'weekly'])],
            ],
        ];
    }

    // ── Operations & finance ─────────────────────────────────────────────────

    public static function budgetApprovals(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-software', 'name' => 'Software', 'color' => '#3b82f6'],
                ['ref' => 'l-hardware', 'name' => 'Hardware', 'color' => '#6366f1'],
                ['ref' => 'l-travel', 'name' => 'Travel', 'color' => '#14b8a6'],
                ['ref' => 'l-services', 'name' => 'Services', 'color' => '#f59e0b'],
            ],
            'fields' => [
                ['ref' => 'f-amount', 'name' => 'Amount', 'type' => 'number'],
                ['ref' => 'f-center', 'name' => 'Cost center', 'type' => 'select', 'options' => self::options([
                    'o-ops' => ['Operations', '#64748b'],
                    'o-marketing' => ['Marketing', '#ec4899'],
                    'o-product' => ['Product', '#3b82f6'],
                    'o-sales' => ['Sales', '#22c55e'],
                ])],
                ['ref' => 'f-vendor', 'name' => 'Vendor', 'type' => 'text'],
                ['ref' => 'f-recurring', 'name' => 'Recurring cost', 'type' => 'checkbox'],
            ],
            'boards' => [[
                'ref' => 'b-requests', 'name' => 'Purchase requests', 'type' => 'kanban',
                'columns' => [
                    self::column('c-submitted', 'Submitted', '#94a3b8'),
                    self::column('c-review', 'Under review', '#f59e0b'),
                    self::column('c-approved', 'Approved', '#3b82f6'),
                    self::column('c-ordered', 'Ordered', '#a855f7'),
                    self::column('c-received', 'Received', '#22c55e', ['is_done' => true]),
                    self::column('c-rejected', 'Rejected', '#64748b', ['is_done' => true]),
                ],
            ]],
            'items' => [
                self::issue('i-1', 'b-requests', 'c-submitted', 'Design tool licences for 3 people', ['type' => 'slug:task', 'labels' => ['l-software'], 'fields' => ['f-amount' => 540, 'f-center' => 'o-marketing', 'f-recurring' => true]]),
                self::issue('i-2', 'b-requests', 'c-review', 'Two monitors for the new hires', ['type' => 'slug:task', 'labels' => ['l-hardware'], 'fields' => ['f-amount' => 600, 'f-center' => 'o-ops', 'f-recurring' => false]]),
                self::issue('i-3', 'b-requests', 'c-submitted', 'Trip to the customer conference', ['type' => 'slug:task', 'labels' => ['l-travel'], 'due' => 20, 'fields' => ['f-amount' => 1400, 'f-center' => 'o-sales', 'f-recurring' => false]]),
            ],
            'pages' => [
                ['ref' => 'p-policy', 'name' => 'Spending policy', 'icon' => '💶', 'content' => self::doc('<h1>Spending policy</h1><ul><li>Up to 250 — your team lead approves</li><li>250 to 2,500 — department head approves</li><li>Over 2,500 — management approves, two quotes needed</li><li>Recurring costs (subscriptions) always need approval</li></ul><h2>How to request</h2><p>Create an issue in <strong>Submitted</strong> with amount, cost center, vendor and why it’s needed.</p>')],
            ],
            'task_lists' => [
                ['name' => 'Month-end', 'tasks' => self::tasks([
                    'All received items have an invoice', 'Invoices forwarded to accounting', 'Subscriptions reviewed', 'Spend per cost center shared',
                ], null, ['recurrence' => 'monthly'])],
            ],
        ];
    }

    public static function officeRequests(): array
    {
        return [
            'labels' => [
                ['ref' => 'l-hardware', 'name' => 'Hardware', 'color' => '#6366f1'],
                ['ref' => 'l-software', 'name' => 'Software', 'color' => '#3b82f6'],
                ['ref' => 'l-access', 'name' => 'Access', 'color' => '#f59e0b'],
                ['ref' => 'l-facilities', 'name' => 'Facilities', 'color' => '#14b8a6'],
            ],
            'fields' => [
                ['ref' => 'f-location', 'name' => 'Location', 'type' => 'text'],
                ['ref' => 'f-requester', 'name' => 'Requested by', 'type' => 'text'],
            ],
            'boards' => [[
                'ref' => 'b-requests', 'name' => 'Requests', 'type' => 'kanban',
                'columns' => [
                    self::column('c-new', 'New', '#94a3b8'),
                    self::column('c-assigned', 'Assigned', '#6b7280'),
                    self::column('c-progress', 'In Progress', '#3b82f6'),
                    self::column('c-waiting', 'Waiting', '#f59e0b'),
                    self::column('c-resolved', 'Resolved', '#22c55e', ['is_done' => true]),
                ],
                'swimlanes' => ['mode' => 'priority', 'rest_name' => null, 'lanes' => []],
            ]],
            'items' => [
                self::issue('i-1', 'b-requests', 'c-new', 'Printer on the 2nd floor jams', ['type' => 'slug:task', 'priority' => 'medium', 'labels' => ['l-hardware', 'l-facilities'], 'fields' => ['f-location' => '2nd floor']]),
                self::issue('i-2', 'b-requests', 'c-new', 'Access to the shared finance folder', ['type' => 'slug:task', 'priority' => 'high', 'labels' => ['l-access']]),
                self::issue('i-3', 'b-requests', 'c-new', 'Install the video editing app', ['type' => 'slug:task', 'priority' => 'low', 'labels' => ['l-software']]),
                self::issue('i-4', 'b-requests', 'c-new', 'Meeting room screen does not connect', ['type' => 'slug:task', 'priority' => 'high', 'labels' => ['l-hardware'], 'fields' => ['f-location' => 'Meeting room A']]),
            ],
            'pages' => [
                ['ref' => 'p-faq', 'name' => 'IT & office FAQ', 'icon' => '💻', 'content' => self::doc('<h1>IT &amp; office FAQ</h1><h2>How do I request something?</h2><p>Create an issue in <strong>New</strong>. Urgent (you can’t work)? Set priority to Highest.</p><h2>Wi-Fi</h2><p>…</p><h2>Printers</h2><p>…</p><h2>Guests and deliveries</h2><p>…</p>')],
            ],
            'task_lists' => [
                ['name' => 'New hire IT setup', 'tasks' => self::tasks([
                    'Laptop ordered', 'Accounts created', 'Two-factor login set up', 'Added to team channels and calendars', 'Building access card ready',
                ])],
                ['name' => 'Monthly office check', 'tasks' => self::tasks([
                    'Supplies restocked', 'First-aid kit checked', 'Software updates installed', 'Unused accounts removed',
                ], null, ['recurrence' => 'monthly'])],
            ],
        ];
    }
}
