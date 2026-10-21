<?php

namespace App\Support\Plans;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Starting points for a new plan, laid out from the current week so a plan starts "now".
 * Bars are given in weeks from the start (Mon–Fri), milestones on a week's Friday.
 */
class PlanTemplates
{
    public const COLORS = [
        'blue' => '#0E83DC',
        'teal' => '#14B8A6',
        'green' => '#22C55E',
        'lime' => '#84CC16',
        'amber' => '#F59E0B',
        'orange' => '#F97316',
        'red' => '#EF4444',
        'pink' => '#EC4899',
        'violet' => '#8B5CF6',
        'slate' => '#64748B',
    ];

    private CarbonImmutable $start;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @var list<array{id: string, from: string, to: string}> */
    private array $dependencies = [];

    /**
     * @return list<array{key: string, name: string, description: string}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'blank', 'name' => 'Blank plan', 'description' => 'Start from an empty sheet'],
            ['key' => 'project', 'name' => 'Project plan', 'description' => 'Initiation · Planning · Execution · Closing'],
            ['key' => 'product-launch', 'name' => 'Product launch', 'description' => 'Research → build → marketing → launch'],
            ['key' => 'software-release', 'name' => 'Software release', 'description' => 'Sprints, QA and a release milestone'],
            ['key' => 'marketing-campaign', 'name' => 'Marketing campaign', 'description' => 'Strategy, content, channels, measuring'],
            ['key' => 'event', 'name' => 'Event planning', 'description' => 'Concept, venue, promotion, event day'],
            ['key' => 'workshop', 'name' => 'Workshop series', 'description' => 'Prepare, run and follow up workshops'],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, dependencies: list<array{id: string, from: string, to: string}>}
     */
    public static function build(string $key, ?CarbonImmutable $today = null): array
    {
        $builder = new self(($today ?? CarbonImmutable::today())->startOfWeek(CarbonImmutable::MONDAY));

        match ($key) {
            'project' => $builder->project(),
            'product-launch' => $builder->productLaunch(),
            'software-release' => $builder->softwareRelease(),
            'marketing-campaign' => $builder->marketingCampaign(),
            'event' => $builder->event(),
            'workshop' => $builder->workshop(),
            default => $builder->blank(),
        };

        return ['rows' => $builder->rows, 'dependencies' => $builder->dependencies];
    }

    private function __construct(CarbonImmutable $start)
    {
        $this->start = $start;
    }

    private function blank(): void
    {
        $this->group('Phase 1');
        $this->task('First task', 1, [$this->bar(0, 1, 'blue')]);
        $this->task('Second task', 1, []);
        $this->task('', 0, []);
    }

    private function project(): void
    {
        $this->group('Initiation');
        $charter = $this->bar(0, 1, 'blue');
        $this->task('Project charter', 1, [$charter], 'Project lead');
        $this->task('Stakeholder kick-off', 1, [$this->milestone(0, 'red')]);
        $this->group('Planning');
        $scope = $this->bar(1, 2, 'teal');
        $this->task('Scope & requirements', 1, [$scope]);
        $this->task('Schedule & budget', 1, [$this->bar(2, 1, 'teal')]);
        $this->link($charter, $scope);
        $this->group('Execution');
        $build = $this->bar(3, 6, 'green');
        $this->task('Build', 1, [$build]);
        $this->task('Status reviews', 1, [$this->bar(4, 1, 'slate'), $this->bar(6, 1, 'slate'), $this->bar(8, 1, 'slate')]);
        $this->link($scope, $build);
        $this->group('Closing');
        $handover = $this->bar(9, 1, 'amber');
        $this->task('Handover & lessons learned', 1, [$handover]);
        $this->task('Project closed', 1, [$this->milestone(9, 'red')]);
        $this->link($build, $handover);
    }

    private function productLaunch(): void
    {
        $this->group('Research');
        $research = $this->bar(0, 2, 'violet');
        $this->task('Customer interviews', 1, [$research]);
        $this->task('Positioning', 1, [$this->bar(1, 1, 'violet')]);
        $this->group('Build');
        $mvp = $this->bar(2, 5, 'blue');
        $this->task('MVP', 1, [$mvp]);
        $this->task('Beta', 1, [$this->bar(6, 2, 'blue')]);
        $this->link($research, $mvp);
        $this->group('Marketing');
        $this->task('Landing page', 1, [$this->bar(5, 2, 'pink')]);
        $this->task('Launch content', 1, [$this->bar(6, 2, 'pink')]);
        $this->task('Press & partners', 1, [$this->bar(7, 1, 'pink')]);
        $this->group('Launch');
        $launch = $this->milestone(8, 'red');
        $this->task('Launch day', 1, [$launch]);
        $this->task('Post-launch review', 1, [$this->bar(9, 1, 'amber')]);
    }

    private function softwareRelease(): void
    {
        $this->group('Development');
        $previous = null;
        foreach (range(1, 4) as $sprint) {
            $bar = $this->bar(($sprint - 1) * 2, 2, 'blue', "Sprint {$sprint}");
            $this->task("Sprint {$sprint}", 1, [$bar]);
            if ($previous) {
                $this->link($previous, $bar);
            }
            $previous = $bar;
        }
        $this->group('Quality');
        $qa = $this->bar(8, 1, 'amber');
        $this->task('QA & bug fixing', 1, [$qa]);
        $this->link($previous, $qa);
        $this->task('Release candidate', 1, [$this->milestone(8, 'orange')]);
        $this->group('Release');
        $this->task('Release', 1, [$this->milestone(9, 'red')]);
        $this->task('Monitoring & hotfixes', 1, [$this->bar(9, 2, 'slate')]);
    }

    private function marketingCampaign(): void
    {
        $this->group('Strategy');
        $this->task('Goals & audience', 1, [$this->bar(0, 1, 'violet')]);
        $this->task('Budget approved', 1, [$this->milestone(1, 'red')]);
        $this->group('Content');
        $this->task('Copy & visuals', 1, [$this->bar(1, 2, 'pink')]);
        $this->task('Video', 1, [$this->bar(2, 2, 'pink')]);
        $this->group('Channels');
        $this->task('Social', 1, [$this->bar(4, 4, 'blue')]);
        $this->task('Newsletter', 1, [$this->bar(4, 1, 'teal'), $this->bar(6, 1, 'teal')]);
        $this->task('Ads', 1, [$this->bar(5, 3, 'orange')]);
        $this->group('Measure');
        $this->task('Reporting', 1, [$this->bar(8, 1, 'slate')]);
    }

    private function event(): void
    {
        $this->group('Concept');
        $this->task('Format & budget', 1, [$this->bar(0, 1, 'violet')]);
        $this->group('Venue & suppliers');
        $this->task('Book venue', 1, [$this->bar(1, 2, 'teal')]);
        $this->task('Catering & tech', 1, [$this->bar(2, 2, 'teal')]);
        $this->group('Promotion');
        $this->task('Invitations', 1, [$this->bar(3, 1, 'pink')]);
        $this->task('Registration open', 1, [$this->bar(3, 4, 'pink')]);
        $this->group('Event');
        $this->task('Event day', 1, [$this->milestone(7, 'red')]);
        $this->task('Follow-up & feedback', 1, [$this->bar(8, 1, 'amber')]);
    }

    private function workshop(): void
    {
        $this->group('Preparation');
        $this->task('Goals & participants', 1, [$this->bar(0, 1, 'violet')], 'Facilitator');
        $this->task('Agenda & material', 1, [$this->bar(1, 1, 'violet')], 'Facilitator');
        $this->group('Workshops');
        foreach ([2, 3, 4] as $index => $week) {
            $this->task('Workshop '.($index + 1), 1, [$this->milestone($week, 'blue')]);
        }
        $this->group('Follow-up');
        $this->task('Summary & decisions', 1, [$this->bar(5, 1, 'green')]);
        $this->task('Action items', 1, [$this->bar(5, 3, 'amber')]);
    }

    private function group(string $name): void
    {
        $this->task($name, 0, []);
    }

    /**
     * @param  list<array<string, mixed>>  $bars
     */
    private function task(string $name, int $level, array $bars, ?string $who = null): void
    {
        $this->rows[] = array_filter([
            'id' => $this->id('r'),
            'name' => $name,
            'level' => $level,
            'who' => $who,
            'bars' => $bars,
        ], fn ($value) => $value !== null);
    }

    /**
     * Mon of week $week to Fri of week $week + $weeks - 1.
     *
     * @return array<string, mixed>
     */
    private function bar(int $week, int $weeks, string $color, ?string $label = null): array
    {
        $start = $this->start->addWeeks($week);

        return array_filter([
            'id' => $this->id('b'),
            'start' => $start->toDateString(),
            'end' => $start->addWeeks($weeks - 1)->addDays(4)->toDateString(),
            'type' => 'bar',
            'color' => self::COLORS[$color],
            'label' => $label,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function milestone(int $week, string $color): array
    {
        $day = $this->start->addWeeks($week)->addDays(4)->toDateString();

        return ['id' => $this->id('m'), 'start' => $day, 'end' => $day, 'type' => 'milestone', 'color' => self::COLORS[$color]];
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private function link(array $from, array $to): void
    {
        $this->dependencies[] = ['id' => $this->id('d'), 'from' => $from['id'], 'to' => $to['id']];
    }

    private function id(string $prefix): string
    {
        return $prefix.'-'.Str::lower(Str::random(10));
    }
}
