<?php

namespace App\Support\LeadForms;

use Illuminate\Support\Str;

/**
 * Built-in lead form templates (BRAI-93): questions (with icon cards and conditional steps),
 * a colour theme, texts and the columns of the leads board. Option icons are lucide names
 * (see LEAD_ICONS in resources/js/lib/leadForms.ts) — any other text, e.g. an emoji, is shown as is.
 */
class LeadFormTemplates
{
    public const CATEGORIES = [
        'sales' => 'Sales',
        'marketing' => 'Marketing',
        'services' => 'Services',
        'events' => 'Events & education',
        'people' => 'People',
    ];

    /**
     * @return array<string, array{name: string, category: string, description: string, icon: string, theme: array, settings: array, columns: list<array>, steps: list<array>}>
     */
    public static function all(): array
    {
        return [
            'blank' => [
                'name' => 'Blank form',
                'category' => 'sales',
                'description' => 'One step with name and email — build the rest yourself.',
                'icon' => 'file-plus',
                'theme' => [],
                'settings' => [],
                'columns' => self::columns(['New', 'Contacted', 'Qualified', 'Won|done', 'Lost|done']),
                'steps' => [
                    self::step('Your details', [self::name(), self::email()]),
                ],
            ],

            'demo_request' => [
                'name' => 'B2B demo request',
                'category' => 'sales',
                'description' => 'Qualify demo requests by goal, team size and timing before the call.',
                'icon' => 'presentation',
                'theme' => ['primary' => '#4f46e5'],
                'settings' => [
                    'intro_title' => 'See it in action',
                    'intro_text' => 'Tell us a little about your team and we will tailor the demo to you.',
                    'submit_label' => 'Request my demo',
                    'thank_you_message' => 'Thanks! We will email you within one business day to pick a time.',
                ],
                'columns' => self::columns(['New', 'Contacted', 'Demo booked', 'Proposal', 'Won|done', 'Lost|done']),
                'steps' => [
                    self::step('What brings you here?', [
                        self::choice('goal', 'What do you want to achieve?', ['Grow sales' => 'trending-up', 'Save time' => 'clock', 'Replace a tool' => 'refresh-cw', 'Just exploring' => 'search'], true),
                        self::choice('team_size', 'How big is your team?', ['1–10' => 'user', '11–50' => 'users', '51–200' => 'building', '200+' => 'building-2'], true),
                    ]),
                    self::step('About you', [
                        self::name(), self::email('Work email'), self::company(true),
                        self::field('select', 'role', 'Your role', ['options' => self::options(['Founder / CEO', 'Sales', 'Marketing', 'Operations', 'IT', 'Other'])]),
                        self::phone(),
                    ]),
                    self::step('Timing', [
                        self::choice('timeline', 'When do you want to start?', ['As soon as possible' => 'zap', 'This quarter' => 'calendar', 'Later this year' => 'calendar-clock'], true),
                        self::field('long_text', 'notes', 'Anything we should know before the demo?'),
                        self::consent(),
                    ]),
                ],
            ],

            'contact_sales' => [
                'name' => 'Contact sales',
                'category' => 'sales',
                'description' => 'A short one-page form for your pricing or contact page.',
                'icon' => 'handshake',
                'theme' => ['primary' => '#0f766e', 'progress' => 'none'],
                'settings' => ['intro_title' => 'Talk to sales', 'submit_label' => 'Send'],
                'columns' => self::columns(['New', 'Qualified', 'Meeting', 'Proposal', 'Won|done', 'Lost|done']),
                'steps' => [
                    self::step('Contact sales', [
                        self::name(), self::email('Work email'), self::company(true), self::phone(),
                        self::field('select', 'company_size', 'Company size', ['options' => self::options(['1–10', '11–50', '51–200', '201–1000', '1000+'])]),
                        self::field('long_text', 'message', 'How can we help?', ['required' => true]),
                        self::consent(),
                    ]),
                ],
            ],

            'free_trial' => [
                'name' => 'Free trial sign-up',
                'category' => 'marketing',
                'description' => 'Capture trial sign-ups with their use case and how they found you.',
                'icon' => 'rocket',
                'theme' => ['primary' => '#7c3aed', 'radius' => 'full'],
                'settings' => [
                    'intro_title' => 'Start your free trial',
                    'intro_text' => '14 days, no credit card needed.',
                    'submit_label' => 'Start free trial',
                    'thank_you_message' => 'Check your inbox — your trial is on its way.',
                ],
                'columns' => self::columns(['New', 'Onboarding', 'Active', 'Converted|done', 'Churned|done']),
                'steps' => [
                    self::step('Create your account', [self::name(), self::email('Work email'), self::company()]),
                    self::step('What will you use it for?', [
                        self::choice('use_case', 'Pick all that apply', ['Projects' => 'layout-dashboard', 'Sales' => 'trending-up', 'Support' => 'life-buoy', 'Marketing' => 'megaphone', 'Personal' => 'user'], true, multi: true),
                        self::field('select', 'source', 'How did you hear about us?', ['options' => self::options(['Search engine', 'Social media', 'A friend or colleague', 'Podcast / blog', 'Event', 'Other'])]),
                        self::consent('I agree to the terms and the privacy policy.'),
                    ]),
                ],
            ],

            'newsletter' => [
                'name' => 'Newsletter with interests',
                'category' => 'marketing',
                'description' => 'Grow your list and learn what each subscriber cares about.',
                'icon' => 'mail',
                'theme' => ['primary' => '#db2777', 'radius' => 'full', 'progress' => 'none'],
                'settings' => [
                    'intro_title' => 'Join the newsletter',
                    'intro_text' => 'Fresh ideas in your inbox — no spam, unsubscribe any time.',
                    'submit_label' => 'Subscribe',
                    'thank_you_title' => "You're in!",
                    'thank_you_message' => 'Watch your inbox for the next issue.',
                ],
                'columns' => self::columns(['Subscribed', 'Engaged', 'Customer|done', 'Unsubscribed|done']),
                'steps' => [
                    self::step('Join the newsletter', [
                        self::email(), self::name(false),
                        self::choice('interests', 'What are you interested in?', ['Product news' => 'sparkles', 'Tips & guides' => 'lightbulb', 'Events' => 'calendar', 'Offers' => 'gift'], false, multi: true),
                        self::choice('frequency', 'How often?', ['Weekly' => 'calendar-days', 'Monthly' => 'calendar'], false),
                        self::consent('Yes, send me the newsletter.'),
                    ]),
                ],
            ],

            'agency_brief' => [
                'name' => 'Agency project brief',
                'category' => 'services',
                'description' => 'Services, budget and timeline up front — the website question only shows when needed.',
                'icon' => 'palette',
                'theme' => ['primary' => '#ea580c', 'font' => 'rounded', 'progress' => 'steps'],
                'settings' => [
                    'intro_title' => "Let's build something great",
                    'intro_text' => 'Answer a few questions and we will send you a first estimate.',
                    'submit_label' => 'Send brief',
                ],
                'columns' => self::columns(['New brief', 'Discovery call', 'Proposal sent', 'Won|done', 'Lost|done']),
                'steps' => [
                    self::step('What do you need?', [
                        self::choice('services', 'Pick the services', ['Website' => 'globe', 'Branding' => 'palette', 'SEO' => 'search', 'Ads' => 'megaphone', 'Social media' => 'share-2', 'App' => 'smartphone'], true, multi: true),
                    ]),
                    self::step('Your website', [
                        self::field('short_text', 'current_website', 'Current website (if any)', ['placeholder' => 'https://']),
                        self::field('number', 'pages', 'About how many pages?', ['min' => 1, 'max' => 500]),
                    ], ['visible_if' => self::when('services', 'includes', 'Website')]),
                    self::step('Budget & timeline', [
                        self::choice('budget', 'Budget', ['Under €5k' => 'piggy-bank', '€5k – €15k' => 'wallet', '€15k – €50k' => 'banknote', '€50k+' => 'gem'], true),
                        self::choice('deadline', 'When should it be ready?', ['In a month' => 'zap', 'In 2–3 months' => 'calendar', 'Flexible' => 'clock'], true),
                        self::field('long_text', 'description', 'Tell us about the project', ['required' => true]),
                    ]),
                    self::step('Your details', [self::name(), self::email(), self::company(), self::phone(), self::consent()]),
                ],
            ],

            'quote_calculator' => [
                'name' => 'Quote request',
                'category' => 'services',
                'description' => 'Pick a service, answer the questions for that service, get a quote.',
                'icon' => 'calculator',
                'theme' => ['primary' => '#16a34a'],
                'settings' => [
                    'intro_title' => 'Get a free quote',
                    'submit_label' => 'Get my quote',
                    'thank_you_message' => 'Thanks! Your personal quote will be in your inbox within 24 hours.',
                ],
                'columns' => self::columns(['New request', 'Quote sent', 'Accepted', 'Scheduled', 'Done|done', 'Declined|done']),
                'steps' => [
                    self::step('Which service?', [
                        self::choice('service', 'Choose a service', ['Cleaning' => 'sparkles', 'Moving' => 'truck', 'Renovation' => 'hammer', 'Gardening' => 'leaf'], true),
                    ]),
                    self::step('Cleaning details', [
                        self::field('number', 'area', 'Area in m²', ['required' => true, 'min' => 10, 'max' => 10000]),
                        self::choice('frequency', 'How often?', ['Once' => 'circle', 'Weekly' => 'calendar-days', 'Monthly' => 'calendar'], true),
                    ], ['visible_if' => self::when('service', 'equals', 'Cleaning')]),
                    self::step('Moving details', [
                        self::field('short_text', 'from_city', 'Moving from', ['required' => true]),
                        self::field('short_text', 'to_city', 'Moving to', ['required' => true]),
                        self::field('select', 'rooms', 'Number of rooms', ['options' => self::options(['1', '2', '3', '4', '5+'])]),
                    ], ['visible_if' => self::when('service', 'equals', 'Moving')]),
                    self::step('Project details', [
                        self::field('long_text', 'details', 'Describe the job', ['required' => true]),
                    ], ['visible_if' => self::when('service', 'not_equals', 'Cleaning')]),
                    self::step('When & who', [
                        self::field('date', 'preferred_date', 'Preferred date'),
                        self::name(), self::email(), self::phone(true),
                        self::field('short_text', 'postcode', 'Postcode', ['required' => true]),
                    ]),
                ],
            ],

            'real_estate' => [
                'name' => 'Real-estate inquiry',
                'category' => 'services',
                'description' => 'Buyers, renters and sellers each get their own questions.',
                'icon' => 'home',
                'theme' => ['primary' => '#0369a1', 'font' => 'serif', 'layout' => 'split'],
                'settings' => ['intro_title' => 'Find your next home', 'submit_label' => 'Send inquiry'],
                'columns' => self::columns(['New', 'Contacted', 'Viewing', 'Offer', 'Closed|done', 'Lost|done']),
                'steps' => [
                    self::step('What are you looking for?', [
                        self::choice('intent', 'I want to…', ['Buy' => 'key', 'Rent' => 'home', 'Sell' => 'tag'], true),
                        self::choice('property_type', 'Property type', ['Apartment' => 'building', 'House' => 'home', 'Land' => 'trees', 'Commercial' => 'store'], true),
                    ]),
                    self::step('Your search', [
                        self::field('short_text', 'area', 'Preferred area or city', ['required' => true]),
                        self::field('number', 'budget', 'Maximum budget (€)', ['min' => 0]),
                        self::field('select', 'bedrooms', 'Bedrooms', ['options' => self::options(['1', '2', '3', '4', '5+'])]),
                    ], ['visible_if' => self::when('intent', 'not_equals', 'Sell')]),
                    self::step('Your property', [
                        self::field('short_text', 'address', 'Address of the property', ['required' => true]),
                        self::field('number', 'living_area', 'Living area (m²)', ['min' => 1]),
                        self::field('number', 'asking_price', 'Price you have in mind (€)', ['min' => 0]),
                    ], ['visible_if' => self::when('intent', 'equals', 'Sell')]),
                    self::step('How can we reach you?', [self::name(), self::email(), self::phone(true), self::consent()]),
                ],
            ],

            'consultation' => [
                'name' => 'Consultation booking',
                'category' => 'services',
                'description' => 'Topic, format and preferred time for a first consultation.',
                'icon' => 'calendar-check',
                'theme' => ['primary' => '#0891b2', 'radius' => 'md'],
                'settings' => [
                    'intro_title' => 'Book a free consultation',
                    'submit_label' => 'Request appointment',
                    'thank_you_message' => 'Thanks! We will confirm your appointment by email.',
                ],
                'columns' => self::columns(['Requested', 'Confirmed', 'Done|done', 'No-show|done']),
                'steps' => [
                    self::step('What is it about?', [
                        self::choice('topic', 'Topic', ['Strategy' => 'target', 'Finance' => 'euro', 'Legal' => 'scale', 'Technology' => 'cpu'], true),
                        self::choice('format', 'How would you like to meet?', ['Video call' => 'video', 'Phone' => 'phone', 'In person' => 'map-pin'], true),
                    ]),
                    self::step('When suits you?', [
                        self::field('date', 'preferred_date', 'Preferred date', ['required' => true]),
                        self::choice('time_of_day', 'Time of day', ['Morning' => 'sunrise', 'Afternoon' => 'sun', 'Evening' => 'moon'], true),
                        self::field('long_text', 'notes', 'Anything we should prepare?'),
                    ]),
                    self::step('Your details', [self::name(), self::email(), self::phone(), self::consent()]),
                ],
            ],

            'webinar' => [
                'name' => 'Webinar registration',
                'category' => 'events',
                'description' => 'Register attendees and collect questions in advance.',
                'icon' => 'video',
                'theme' => ['primary' => '#2563eb', 'progress' => 'none'],
                'settings' => [
                    'intro_title' => 'Save your seat',
                    'submit_label' => 'Register',
                    'thank_you_title' => "You're registered!",
                    'thank_you_message' => 'We will send you the link before the webinar starts.',
                ],
                'columns' => self::columns(['Registered', 'Attended', 'Follow-up', 'Customer|done', 'No-show|done']),
                'steps' => [
                    self::step('Register for the webinar', [
                        self::name(), self::email(), self::company(),
                        self::field('select', 'role', 'Your role', ['options' => self::options(['Management', 'Marketing', 'Sales', 'Product', 'Engineering', 'Other'])]),
                        self::field('long_text', 'questions', 'Questions for the speakers?'),
                        self::consent(),
                    ]),
                ],
            ],

            'event_lead' => [
                'name' => 'Event lead capture',
                'category' => 'events',
                'description' => 'Quick capture at your booth or trade fair — rate how hot each lead is.',
                'icon' => 'ticket',
                'theme' => ['primary' => '#e11d48', 'layout' => 'full'],
                'settings' => ['intro_title' => 'Nice to meet you!', 'submit_label' => 'Save lead'],
                'columns' => self::columns(['Captured', 'Follow-up', 'Meeting', 'Opportunity|done', 'Not now|done']),
                'steps' => [
                    self::step('Who did you meet?', [self::name(), self::email(), self::company(true), self::phone()]),
                    self::step('Interest', [
                        self::choice('interest', 'Interested in', ['Product demo' => 'monitor', 'Pricing' => 'tag', 'Partnership' => 'handshake', 'Jobs' => 'briefcase'], true, multi: true),
                        self::field('rating', 'temperature', 'How hot is this lead?'),
                        self::field('long_text', 'notes', 'Notes from the conversation'),
                        self::field('hidden', 'event', 'Event', ['help' => 'Filled from the link, e.g. ?event=expo-2026']),
                    ]),
                ],
            ],

            'course_enrolment' => [
                'name' => 'Course / workshop enrolment',
                'category' => 'events',
                'description' => 'Enrol participants with their level and goals.',
                'icon' => 'graduation-cap',
                'theme' => ['primary' => '#9333ea', 'font' => 'rounded'],
                'settings' => [
                    'intro_title' => 'Enrol now',
                    'submit_label' => 'Enrol',
                    'thank_you_message' => 'Thanks! You will get the details and the payment link by email.',
                ],
                'columns' => self::columns(['Applied', 'Accepted', 'Paid', 'Enrolled|done', 'Waitlist']),
                'steps' => [
                    self::step('Choose your course', [
                        self::field('select', 'course', 'Course', ['required' => true, 'options' => self::options(['Beginner workshop', 'Advanced class', 'Weekend bootcamp', 'Private coaching'])]),
                        self::choice('level', 'Your experience', ['Beginner' => 'sprout', 'Intermediate' => 'leaf', 'Advanced' => 'trees'], true),
                        self::choice('goals', 'What do you want to get out of it?', ['New skills' => 'lightbulb', 'Career' => 'briefcase', 'Certificate' => 'award', 'Fun' => 'smile'], false, multi: true),
                    ]),
                    self::step('Your details', [self::name(), self::email(), self::phone(), self::consent('I accept the cancellation terms.')]),
                ],
            ],

            'job_interest' => [
                'name' => 'Job interest pool',
                'category' => 'people',
                'description' => 'Collect open applications — portfolio question only for designers.',
                'icon' => 'briefcase',
                'theme' => ['primary' => '#475569', 'progress' => 'steps'],
                'settings' => [
                    'intro_title' => 'Work with us',
                    'intro_text' => 'No open role that fits? Join our talent pool and we will reach out.',
                    'submit_label' => 'Join the talent pool',
                ],
                'columns' => self::columns(['New', 'Screening', 'Interview', 'Talent pool', 'Hired|done', 'Not a fit|done']),
                'steps' => [
                    self::step('Your field', [
                        self::choice('area', 'Area', ['Engineering' => 'code', 'Design' => 'pen-tool', 'Marketing' => 'megaphone', 'Sales' => 'trending-up', 'Operations' => 'settings'], true),
                        self::choice('seniority', 'Experience', ['Junior' => 'sprout', 'Mid-level' => 'leaf', 'Senior' => 'trees', 'Lead' => 'crown'], true),
                        self::choice('work_mode', 'Where do you want to work?', ['Remote' => 'globe', 'Hybrid' => 'shuffle', 'On-site' => 'building'], true),
                    ]),
                    self::step('Your profile', [
                        self::field('short_text', 'linkedin', 'LinkedIn profile', ['placeholder' => 'https://linkedin.com/in/…']),
                        self::field('short_text', 'portfolio', 'Portfolio link', ['required' => true, 'placeholder' => 'https://', 'visible_if' => self::when('area', 'equals', 'Design')]),
                        self::field('number', 'salary', 'Salary expectation per year (€)', ['min' => 0]),
                        self::field('long_text', 'about', 'Tell us about yourself'),
                    ]),
                    self::step('Contact', [self::name(), self::email(), self::phone(), self::consent('You may keep my data for future openings.')]),
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Everything needed to create a form from a template (definition normalised, theme and
     * settings filled with defaults).
     *
     * @return array{definition: array, theme: array, settings: array, columns: list<array>}
     */
    public static function build(string $key): array
    {
        $template = self::all()[$key] ?? self::all()['blank'];

        return [
            'definition' => LeadFormDefinition::normalize(['steps' => $template['steps']]),
            'theme' => LeadFormOptions::theme(null, $template['theme']),
            'settings' => LeadFormOptions::settings(null, $template['settings']),
            'columns' => $template['columns'],
        ];
    }

    /**
     * For the template picker: cards with the steps and columns as a preview.
     *
     * @return list<array>
     */
    public static function catalog(): array
    {
        $catalog = [];
        foreach (self::all() as $key => $template) {
            $catalog[] = [
                'key' => $key,
                'name' => $template['name'],
                'category' => $template['category'],
                'description' => $template['description'],
                'icon' => $template['icon'],
                'primary' => $template['theme']['primary'] ?? LeadFormOptions::THEME_DEFAULTS['primary'],
                'steps' => array_map(fn ($step) => [
                    'title' => $step['title'],
                    'conditional' => isset($step['visible_if']),
                    'fields' => array_map(fn ($field) => ['label' => $field['label'], 'type' => $field['type']], $step['fields']),
                ], $template['steps']),
                'columns' => array_column($template['columns'], 'name'),
            ];
        }

        return $catalog;
    }

    // ── builders ─────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $names  "Name" or "Name|done"
     * @return list<array{name: string, slug: string, color: string, is_done: bool}>
     */
    private static function columns(array $names): array
    {
        $colors = ['#64748b', '#3b82f6', '#8b5cf6', '#f59e0b'];
        $columns = [];
        $doneSeen = false;
        foreach ($names as $i => $name) {
            [$label, $flag] = array_pad(explode('|', $name), 2, null);
            $done = $flag === 'done';
            $columns[] = [
                'name' => $label,
                'slug' => Str::slug($label),
                // the first done column is the winning one (green), later ones lose (red)
                'color' => $done ? ($doneSeen ? '#ef4444' : '#10b981') : $colors[min($i, 3)],
                'is_done' => $done,
            ];
            $doneSeen = $doneSeen || $done;
        }

        return $columns;
    }

    private static function step(string $title, array $fields, array $extra = []): array
    {
        return ['id' => 's_'.Str::slug($title, '_'), 'title' => $title, 'fields' => $fields] + $extra;
    }

    private static function field(string $type, string $key, string $label, array $extra = []): array
    {
        return ['id' => 'f_'.$key, 'key' => $key, 'type' => $type, 'label' => $label] + $extra;
    }

    /**
     * @param  array<string, string>  $options  label => lucide icon
     */
    private static function choice(string $key, string $label, array $options, bool $required, bool $multi = false): array
    {
        $list = [];
        foreach ($options as $optionLabel => $icon) {
            $list[] = ['id' => self::optionId($optionLabel), 'label' => $optionLabel, 'icon' => $icon];
        }

        return self::field($multi ? 'multi_choice' : 'choice', $key, $label, ['required' => $required, 'options' => $list, 'layout' => 'cards']);
    }

    /**
     * @param  list<string>  $labels
     */
    private static function options(array $labels): array
    {
        return array_map(fn ($label) => ['id' => self::optionId($label), 'label' => $label], $labels);
    }

    private static function optionId(string $label): string
    {
        return 'o_'.(Str::slug($label, '_') ?: substr(md5($label), 0, 8));
    }

    /** A condition on a choice field, by option label. */
    private static function when(string $key, string $op, string $optionLabel): array
    {
        return ['field' => 'f_'.$key, 'op' => $op, 'value' => self::optionId($optionLabel)];
    }

    private static function name(bool $required = true): array
    {
        return self::field('short_text', 'name', 'Full name', ['required' => $required, 'maps_to' => 'name', 'placeholder' => 'Jane Doe']);
    }

    private static function email(string $label = 'Email'): array
    {
        return self::field('email', 'email', $label, ['required' => true, 'maps_to' => 'email', 'placeholder' => 'jane@company.com']);
    }

    private static function phone(bool $required = false): array
    {
        return self::field('phone', 'phone', 'Phone', ['required' => $required, 'maps_to' => 'phone']);
    }

    private static function company(bool $required = false): array
    {
        return self::field('short_text', 'company', 'Company', ['required' => $required, 'maps_to' => 'company']);
    }

    private static function consent(string $label = 'I agree that my details are stored to answer my request.'): array
    {
        return self::field('consent', 'consent', $label, ['required' => true]);
    }
}
