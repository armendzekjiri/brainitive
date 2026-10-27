<?php

namespace App\Support\Booking;

/**
 * Starting points for booking pages and meeting types (BRAI-158). A template only pre-fills: the page
 * and its types are ordinary records afterwards, every field editable, no link back to the template.
 */
final class BookingTemplates
{
    private const WEEKDAYS_9_17 = ['mon' => [['09:00', '17:00']], 'tue' => [['09:00', '17:00']], 'wed' => [['09:00', '17:00']], 'thu' => [['09:00', '17:00']], 'fri' => [['09:00', '17:00']]];

    /** @return array<string, array{name: string, description: string, title: string, intro: string, busy_sources?: array<string, mixed>, types: list<array<string, mixed>>}> */
    public static function all(): array
    {
        $weekdays = self::WEEKDAYS_9_17;

        return [
            'blank' => [
                'name' => 'Blank',
                'description' => 'One 30-minute meeting on weekdays, 9–17. Set up everything yourself.',
                'title' => 'Book a meeting',
                'intro' => '',
                'types' => [self::type('Meeting', 30, $weekdays)],
            ],
            'sales' => [
                'name' => 'Sales / intro call',
                'description' => 'A quick intro and a longer discovery call for new contacts.',
                'title' => 'Let’s talk',
                'intro' => 'Pick a time that suits you. We’ll send a calendar invite right away.',
                'types' => [
                    self::type('Intro call', 15, $weekdays, 'google_meet', ['buffer_after' => 5]),
                    self::type('Discovery call', 30, $weekdays, 'google_meet', ['buffer_after' => 10], [
                        self::question('company', 'Company', 'text'),
                        self::question('goal', 'What would you like to talk about?', 'textarea'),
                    ]),
                ],
            ],
            'consulting' => [
                'name' => 'Consulting',
                'description' => 'Paid sessions with breathing room between them.',
                'title' => 'Book a consulting session',
                'intro' => '',
                'types' => [
                    self::type('Consulting session', 60, $weekdays, 'google_meet', ['buffer_before' => 15, 'buffer_after' => 15, 'daily_max' => 4], [
                        self::question('topic', 'Topic', 'textarea', true),
                    ]),
                    self::type('Short consultation', 30, $weekdays, 'google_meet', ['buffer_after' => 10]),
                ],
            ],
            'support' => [
                'name' => 'Support / help session',
                'description' => 'Short slots for customers who need a hand.',
                'title' => 'Get help from our team',
                'intro' => 'Tell us briefly what you need, so we come prepared.',
                'types' => [
                    self::type('Quick help', 15, $weekdays, 'google_meet', ['min_notice_minutes' => 60], [self::question('issue', 'What do you need help with?', 'textarea', true)]),
                    self::type('Walkthrough', 30, $weekdays, 'google_meet', ['min_notice_minutes' => 120], [self::question('issue', 'What should we go through?', 'textarea', true)]),
                ],
            ],
            'interview' => [
                'name' => 'Job interview',
                'description' => 'Interview slots with the questions recruiters ask first.',
                'title' => 'Interview with us',
                'intro' => '',
                'types' => [
                    self::type('First interview', 30, $weekdays, 'google_meet', ['buffer_after' => 15, 'min_notice_minutes' => 1440], [
                        self::question('role', 'Role you applied for', 'text', true),
                        self::question('cv', 'Link to your CV or LinkedIn', 'text'),
                    ]),
                    self::type('Technical interview', 45, $weekdays, 'google_meet', ['buffer_after' => 15, 'min_notice_minutes' => 1440], [
                        self::question('role', 'Role you applied for', 'text', true),
                    ]),
                ],
            ],
            'emergency' => [
                'name' => 'Emergency',
                'description' => 'Short notice, evenings and weekends. Only real appointments block it.',
                'title' => 'Emergency appointment',
                'intro' => 'For urgent matters only.',
                'busy_sources' => ['issues' => false, 'tasks' => false],
                'types' => [
                    self::type('Emergency call', 15, [
                        'mon' => [['17:00', '22:00']], 'tue' => [['17:00', '22:00']], 'wed' => [['17:00', '22:00']], 'thu' => [['17:00', '22:00']],
                        'fri' => [['17:00', '22:00']], 'sat' => [['09:00', '20:00']], 'sun' => [['09:00', '20:00']],
                    ], 'phone', ['min_notice_minutes' => 30, 'horizon_days' => 7, 'step_minutes' => 15], [
                        self::question('phone', 'Phone number', 'phone', true),
                        self::question('issue', 'What happened?', 'textarea', true),
                    ]),
                ],
            ],
            'office_hours' => [
                'name' => 'Office hours',
                'description' => 'Short slots on one afternoon a week.',
                'title' => 'Office hours',
                'intro' => '',
                'types' => [self::type('Office hours', 15, ['wed' => [['14:00', '17:00']]], 'google_meet', ['step_minutes' => 15])],
            ],
            'coaching' => [
                'name' => 'Coaching / tutoring',
                'description' => 'Longer sessions with a daily limit.',
                'title' => 'Book a session',
                'intro' => '',
                'types' => [
                    self::type('Session', 60, $weekdays, 'google_meet', ['buffer_after' => 15, 'daily_max' => 3]),
                    self::type('Short session', 45, $weekdays, 'google_meet', ['buffer_after' => 15, 'daily_max' => 3]),
                ],
            ],
        ];
    }

    /** @return list<array{key: string, name: string, description: string, types: list<string>}> for the "new page" picker */
    public static function catalog(): array
    {
        return collect(self::all())->map(fn (array $t, string $key) => [
            'key' => $key,
            'name' => $t['name'],
            'description' => $t['description'],
            'types' => array_map(fn ($type) => $type['name'].' · '.$type['duration_minutes'].' min', $t['types']),
        ])->values()->all();
    }

    private static function type(string $name, int $minutes, array $weekly, string $location = 'none', array $rules = [], array $questions = []): array
    {
        return [
            'name' => $name,
            'duration_minutes' => $minutes,
            'location' => ['kind' => $location, 'value' => null],
            'availability' => ['weekly' => $weekly, 'overrides' => []],
            'rules' => $rules + BookingRules::DEFAULTS,
            'questions' => $questions,
        ];
    }

    private static function question(string $id, string $label, string $type, bool $required = false): array
    {
        return ['id' => $id, 'label' => $label, 'type' => $type, 'required' => $required, 'options' => []];
    }
}
