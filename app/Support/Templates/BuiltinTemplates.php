<?php

namespace App\Support\Templates;

use App\Support\Templates\Builtin\BusinessTemplates;
use App\Support\Templates\Builtin\MarketingTemplates;
use App\Support\Templates\Builtin\PersonalTemplates;
use App\Support\Templates\Builtin\SoftwareTemplates;

/**
 * The project templates that ship with Brainitive (BRAI-72 / BRAI-91): the catalog entry of
 * each (name, category, tags) and where its document is built (Builtin\*Templates, written
 * in the TemplateDocument format: refs instead of ids, dates as days after day 0).
 */
class BuiltinTemplates
{
    /** Gallery categories in display order (the gallery adds "Your workspace" for saved ones). */
    public const CATEGORIES = [
        'software' => 'Software',
        'marketing' => 'Marketing',
        'agency' => 'Agency & clients',
        'hr' => 'HR & people',
        'sales' => 'Sales',
        'operations' => 'Operations & finance',
        'personal' => 'Personal & team',
    ];

    /**
     * key => [name, category, icon (Lucide), description, tags, builder]
     */
    private const TEMPLATES = [
        'software-development' => ['Software development', 'software', 'code', 'Kanban from backlog to review and done, starter issues, a definition of done and a release checklist.', ['Kanban', 'Bugs lane'], [SoftwareTemplates::class, 'softwareDevelopment']],
        'scrum' => ['Scrum project', 'software', 'repeat', 'A Scrum board with a groomed backlog of stories, sprint ceremony notes and a working agreement.', ['Scrum', 'Story points'], [SoftwareTemplates::class, 'scrum']],
        'bug-tracking' => ['Bug tracking', 'software', 'bug', 'Triage bugs from New to Verified with severity, environment and affected version, lanes by priority.', ['Kanban', 'Severity field'], [SoftwareTemplates::class, 'bugTracking']],
        'release-devops' => ['Release & DevOps', 'software', 'server', 'Plan releases and ops work: hotfix lane, deployment runbook, incident response and a release plan.', ['Kanban', 'Timeline'], [SoftwareTemplates::class, 'releaseDevops']],
        'marketing-campaign' => ['Marketing campaign', 'marketing', 'megaphone', 'Brief, creative, landing page, emails and ads — with channel and budget fields and a campaign timeline.', ['Kanban', 'Timeline', 'Budget'], [MarketingTemplates::class, 'marketingCampaign']],
        'content-calendar' => ['Content calendar', 'marketing', 'calendar-days', 'Ideas to published: blog posts, newsletters and videos with publish dates and editorial guidelines.', ['Kanban', 'Publish dates'], [MarketingTemplates::class, 'contentCalendar']],
        'social-media' => ['Social media', 'marketing', 'share-2', 'Plan, approve and schedule posts per platform, with a brand-voice guide and a weekly routine.', ['Kanban', 'Platforms'], [MarketingTemplates::class, 'socialMedia']],
        'event-planning' => ['Event planning', 'marketing', 'party-popper', 'Venue, speakers, catering and promotion with vendor costs, a timeline to event day and a day-of checklist.', ['Kanban', 'Timeline', 'Costs'], [MarketingTemplates::class, 'eventPlanning']],
        'product-launch' => ['Product launch', 'marketing', 'rocket', 'Launch workstreams, a plan with milestones, messaging doc and a go-to-market checklist.', ['Kanban', 'Timeline'], [MarketingTemplates::class, 'productLaunch']],
        'client-website' => ['Client website delivery', 'agency', 'globe', 'Discovery to launch in phases, a timeline plan, a client brief and a launch checklist.', ['Kanban', 'Timeline', 'Approvals'], [BusinessTemplates::class, 'clientWebsite']],
        'client-onboarding' => ['Client onboarding', 'agency', 'handshake', 'Contract, access, kick-off and first check-in for every new client, with a welcome pack.', ['Kanban', 'Checklist'], [BusinessTemplates::class, 'clientOnboarding']],
        'design-requests' => ['Design requests', 'agency', 'palette', 'A request queue for the design team: formats, requesters, feedback rounds and brand basics.', ['Kanban', 'Intake'], [BusinessTemplates::class, 'designRequests']],
        'recruiting' => ['Recruiting pipeline', 'hr', 'user-plus', 'Candidates from applied to hired, lanes per team, interview scorecard and job description template.', ['Kanban', 'Pipeline'], [BusinessTemplates::class, 'recruiting']],
        'employee-onboarding' => ['Employee onboarding', 'hr', 'users', 'Everything before day 1, week 1 and the first 90 days — with a welcome handbook and a 90-day plan.', ['Kanban', 'Timeline'], [BusinessTemplates::class, 'employeeOnboarding']],
        'sales-pipeline' => ['Sales pipeline', 'sales', 'trending-up', 'Deals from lead to won with value, expected close and company size, plus a sales playbook.', ['Kanban', 'Deal value'], [BusinessTemplates::class, 'salesPipeline']],
        'budget-approvals' => ['Budget & purchase approvals', 'operations', 'circle-dollar-sign', 'Purchase requests from submitted to received, with amount, cost center and a spending policy.', ['Kanban', 'Approvals'], [BusinessTemplates::class, 'budgetApprovals']],
        'office-requests' => ['Office & IT requests', 'operations', 'monitor', 'A simple request desk for hardware, software, access and facilities, with an FAQ.', ['Kanban', 'Intake'], [BusinessTemplates::class, 'officeRequests']],
        'personal-tasks' => ['Personal tasks', 'personal', 'list-todo', 'Inbox, Next, Waiting for, Someday — a calm personal system with a weekly review.', ['Kanban', 'Weekly review'], [PersonalTemplates::class, 'personalTasks']],
        'team-okrs' => ['Team goals (OKRs)', 'personal', 'target', 'Objectives with measurable key results, progress and confidence fields and a quarter plan.', ['Kanban', 'Timeline'], [PersonalTemplates::class, 'teamOkrs']],
        'meeting-notes' => ['Meeting notes & 1:1s', 'personal', 'notebook-pen', 'Meeting and 1:1 note templates, a decision log and a board for action items.', ['Docs', 'Action items'], [PersonalTemplates::class, 'meetingNotes']],
    ];

    /**
     * @return list<array{key: string, name: string, category: string, icon: string, description: string, tags: list<string>}>
     */
    public static function all(): array
    {
        $list = [];
        foreach (self::TEMPLATES as $key => [$name, $category, $icon, $description, $tags]) {
            $list[] = ['key' => $key, 'name' => $name, 'category' => $category, 'icon' => $icon, 'description' => $description, 'tags' => $tags];
        }

        return $list;
    }

    public static function has(string $key): bool
    {
        return isset(self::TEMPLATES[$key]);
    }

    /**
     * The hand-written document before normalization (tests compare it with normalize()'s
     * output, so a typo in a ref can't vanish silently).
     */
    public static function raw(string $key): array
    {
        if (! self::has($key)) {
            throw new TemplateException('Unknown template.');
        }

        return ['format' => TemplateDocument::FORMAT] + call_user_func(self::TEMPLATES[$key][5]);
    }

    /**
     * The (normalized) document of a built-in template.
     *
     * @throws TemplateException
     */
    public static function document(string $key): array
    {
        if (! self::has($key)) {
            throw new TemplateException('Unknown template.');
        }

        return TemplateDocument::normalize(self::raw($key));
    }
}
