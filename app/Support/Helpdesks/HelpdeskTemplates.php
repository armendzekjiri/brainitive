<?php

namespace App\Support\Helpdesks;

/**
 * Helpdesk templates (BRAI-92): a helpdesk is a public form plus a ticket board, so templates
 * cover support desks as well as contact forms, registrations, applications and more.
 *
 * Each builder returns:
 *   columns:  [[name, color, is_done?]]                     — the first column receives new submissions
 *   fields:   [[label, type, options|null, required, on_card]]
 *   settings: helpdesk settings (form texts, default fields, widget button) — see HelpdeskForm
 */
class HelpdeskTemplates
{
    public const CATEGORIES = [
        'support' => 'Support',
        'forms' => 'Website forms',
        'events' => 'Events & bookings',
        'people' => 'People & internal',
        'product' => 'Product',
    ];

    /** key => [name, category, icon (Lucide), description, tags, builder method] */
    private const TEMPLATES = [
        'customer-support' => ['Customer support', 'support', 'life-buoy', 'A classic support desk: categories, order or account number, and a “waiting on customer” step.', ['Support', 'Portal'], 'customerSupport'],
        'it-helpdesk' => ['IT helpdesk', 'support', 'monitor', 'Internal IT requests with type, urgency, device and location.', ['Internal', 'Urgency'], 'itHelpdesk'],
        'bug-reports' => ['Bug reports & feedback', 'support', 'bug', 'Collect bugs, ideas and questions with page, browser and steps to reproduce.', ['Product', 'Screenshots'], 'bugReports'],
        'returns-refunds' => ['Returns & refunds', 'support', 'undo-2', 'Return requests with order number, reason and refund / exchange choice.', ['E-commerce', 'Orders'], 'returnsRefunds'],
        'contact-form' => ['Website contact form', 'forms', 'mail', 'A simple “Get in touch” form for your website with topic, phone and company.', ['Website', 'Thank-you screen'], 'contactForm'],
        'quote-request' => ['Quote request', 'forms', 'calculator', 'Leads ask for an offer: service, budget range, start date and project details.', ['Leads', 'Budget'], 'quoteRequest'],
        'newsletter-waitlist' => ['Newsletter & waitlist', 'forms', 'mail-plus', 'Sign-ups with interests and email consent — no subject, no message.', ['Sign-up', 'Consent'], 'newsletterWaitlist'],
        'partnership-requests' => ['Partnership requests', 'forms', 'handshake', 'Resellers, integrations and co-marketing requests with company details.', ['B2B', 'Leads'], 'partnershipRequests'],
        'press-media' => ['Press & media inquiries', 'forms', 'newspaper', 'Interview, comment and press-kit requests with outlet and deadline.', ['PR', 'Deadlines'], 'pressMedia'],
        'event-registration' => ['Event registration', 'events', 'ticket', 'Attendees register with ticket type, guests, dietary needs and T-shirt size; check them in on the board.', ['Registration', 'Check-in'], 'eventRegistration'],
        'appointment-booking' => ['Appointment requests', 'events', 'calendar-clock', 'Clients request a service, a preferred date and time and how to meet.', ['Bookings', 'Dates'], 'appointmentBooking'],
        'volunteer-signup' => ['Volunteer sign-up', 'events', 'heart-handshake', 'Volunteers pick areas of interest and availability.', ['Sign-up', 'Availability'], 'volunteerSignup'],
        'job-applications' => ['Job applications', 'people', 'briefcase', 'Candidates apply for a position with portfolio link, start date and CV upload.', ['Hiring', 'CV upload'], 'jobApplications'],
        'hr-requests' => ['HR & internal requests', 'people', 'users', 'Leave, equipment, payroll and document requests from the team.', ['Internal', 'Dates'], 'hrRequests'],
        'facilities-requests' => ['Office & facilities', 'people', 'building-2', 'Repairs, cleaning, supplies and access cards with room and urgency.', ['Internal', 'Office'], 'facilitiesRequests'],
        'feature-requests' => ['Feature requests', 'product', 'lightbulb', 'Customers suggest features with product area, importance and plan.', ['Product', 'Roadmap'], 'featureRequests'],
        'beta-signup' => ['Beta program sign-up', 'product', 'flask-conical', 'Testers apply for early access with team size and devices.', ['Sign-up', 'Product'], 'betaSignup'],
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

    public static function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /**
     * @return array{columns: list<array{0: string, 1: string, 2?: bool}>, fields: list<array{0: string, 1: string, 2: list<string>|null, 3: bool, 4: bool}>, settings: array<string, mixed>}
     */
    public static function build(string $key): array
    {
        $method = self::TEMPLATES[$key][5] ?? null;
        abort_if($method === null, 404);

        return self::$method();
    }

    /**
     * The gallery entries with what each template sets up (columns, form fields, texts).
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        return array_map(function ($template) {
            $built = self::build($template['key']);
            $s = $built['settings'];

            return $template + [
                'columns' => array_map(fn ($c) => ['name' => $c[0], 'color' => $c[1], 'is_done' => ! empty($c[2])], $built['columns']),
                'fields' => array_map(fn ($f) => ['label' => $f[0], 'type' => $f[1], 'options' => $f[2], 'is_required' => $f[3]], $built['fields']),
                'form' => [
                    'title' => $s['form_title'] ?? 'Contact support',
                    'submit_label' => $s['submit_label'] ?? 'Submit',
                    'welcome_message' => $s['welcome_message'] ?? 'How can we help you?',
                    'name' => empty($s['default_field_contact_name']['hidden']) ? ($s['default_field_contact_name']['label'] ?? 'Name') : null,
                    'name_required' => ! empty($s['default_field_contact_name']['is_required']),
                    'message_required' => ! empty($s['default_field_description']['is_required']),
                    'subject' => empty($s['default_field_subject']['hidden']) ? ($s['default_field_subject']['label'] ?? 'Subject') : null,
                    'message' => empty($s['default_field_description']['hidden']) ? ($s['default_field_description']['label'] ?? 'Message') : null,
                    'attachments' => empty($s['default_field_attachments']['hidden']),
                    'after_submit' => $s['after_submit'] ?? 'portal',
                ],
            ];
        }, self::all());
    }

    // ── Support ──────────────────────────────────────────────────────────────

    private static function customerSupport(): array
    {
        return [
            'columns' => [['New', '#94a3b8'], ['Open', '#3b82f6'], ['Waiting on customer', '#f59e0b'], ['Resolved', '#22c55e', true]],
            'fields' => [
                ['Category', 'select', ['Billing', 'Technical problem', 'Account', 'Other'], true, true],
                ['Order or account number', 'text', null, false, false],
            ],
            'settings' => [
                'button_text' => 'Support',
                'welcome_message' => 'Tell us what’s going on and we’ll get back to you by email.',
                'form_title' => 'Contact support',
                'submit_label' => 'Send request',
                'default_field_contact_name' => ['label' => 'Name', 'is_required' => false],
                'default_field_description' => ['label' => 'How can we help?', 'is_required' => true],
                'after_submit' => 'portal',
            ],
        ];
    }

    private static function itHelpdesk(): array
    {
        return [
            'columns' => [['New', '#94a3b8'], ['Assigned', '#6b7280'], ['In progress', '#3b82f6'], ['Waiting', '#f59e0b'], ['Resolved', '#22c55e', true]],
            'fields' => [
                ['Request type', 'select', ['Hardware', 'Software', 'Access & accounts', 'Network / Wi-Fi', 'Other'], true, true],
                ['Urgency', 'radio', ['I can’t work', 'It slows me down', 'Not urgent'], true, true],
                ['Device', 'text', null, false, false],
                ['Location', 'text', null, false, false],
            ],
            'settings' => [
                'button_text' => 'IT help',
                'welcome_message' => 'Something not working? Describe the problem and we’ll take care of it.',
                'form_title' => 'IT help request',
                'default_field_subject' => ['label' => 'Short summary'],
                'default_field_contact_name' => ['label' => 'Your name', 'is_required' => true],
                'default_field_description' => ['label' => 'What happened?', 'is_required' => true],
                'after_submit' => 'portal',
            ],
        ];
    }

    private static function bugReports(): array
    {
        return [
            'columns' => [['New', '#94a3b8'], ['Triaged', '#6b7280'], ['Planned', '#3b82f6'], ['Closed', '#22c55e', true]],
            'fields' => [
                ['Kind', 'radio', ['Bug', 'Idea', 'Question'], true, true],
                ['Page or screen', 'text', null, false, true],
                ['Browser / device', 'text', null, false, false],
                ['Steps to reproduce', 'rich_text', null, false, false],
            ],
            'settings' => [
                'button_text' => 'Feedback',
                'welcome_message' => 'Found a bug or have an idea? Screenshots help a lot.',
                'form_title' => 'Report a bug or share feedback',
                'submit_label' => 'Send feedback',
                'default_field_subject' => ['label' => 'Short summary'],
                'default_field_description' => ['label' => 'Details', 'is_required' => false],
                'after_submit' => 'portal',
            ],
        ];
    }

    private static function returnsRefunds(): array
    {
        return [
            'columns' => [['New', '#94a3b8'], ['Approved', '#3b82f6'], ['Item received', '#a855f7'], ['Refunded', '#22c55e', true], ['Declined', '#64748b', true]],
            'fields' => [
                ['Order number', 'text', null, true, true],
                ['Reason', 'select', ['Damaged', 'Wrong item', 'Doesn’t fit', 'Changed my mind', 'Other'], true, true],
                ['I would like', 'radio', ['A refund', 'An exchange', 'Store credit'], true, false],
                ['Purchase date', 'date', null, false, false],
            ],
            'settings' => [
                'button_text' => 'Returns',
                'welcome_message' => 'Return or exchange an order within 30 days.',
                'form_title' => 'Return or refund an order',
                'submit_label' => 'Start return',
                'default_field_subject' => ['hidden' => true],
                'auto_subject' => 'Return request',
                'default_field_contact_name' => ['label' => 'Name', 'is_required' => true],
                'default_field_description' => ['label' => 'Anything else we should know?', 'is_required' => false],
                'after_submit' => 'portal',
            ],
        ];
    }

    // ── Website forms ────────────────────────────────────────────────────────

    private static function contactForm(): array
    {
        return [
            'columns' => [['New', '#94a3b8'], ['Replied', '#3b82f6'], ['Done', '#22c55e', true]],
            'fields' => [
                ['Topic', 'select', ['General question', 'Sales', 'Support', 'Press', 'Other'], false, true],
                ['Company', 'text', null, false, false],
                ['Phone', 'text', null, false, false],
            ],
            'settings' => [
                'button_text' => 'Contact us',
                'welcome_message' => 'Questions, ideas or just hello — we’d love to hear from you.',
                'form_title' => 'Get in touch',
                'submit_label' => 'Send message',
                'success_message' => 'Thanks for reaching out! We’ll reply within one business day.',
                'default_field_subject' => ['hidden' => true],
                'auto_subject' => 'Website contact',
                'default_field_contact_name' => ['label' => 'Name', 'is_required' => true],
                'default_field_description' => ['label' => 'Message', 'is_required' => true],
                'default_field_attachments' => ['hidden' => true],
                'message_placeholder' => 'How can we help?',
                'after_submit' => 'message',
            ],
        ];
    }

    private static function quoteRequest(): array
    {
        return [
            'columns' => [['New request', '#94a3b8'], ['In discussion', '#3b82f6'], ['Quote sent', '#a855f7'], ['Accepted', '#22c55e', true], ['Declined', '#64748b', true]],
            'fields' => [
                ['Company', 'text', null, true, true],
                ['Service needed', 'select', ['Website', 'App', 'Branding', 'Marketing', 'Consulting', 'Other'], true, true],
                ['Budget range', 'select', ['Under 5,000', '5,000 – 15,000', '15,000 – 50,000', 'Over 50,000'], false, true],
                ['Desired start', 'date', null, false, false],
                ['Website', 'text', null, false, false],
            ],
            'settings' => [
                'button_text' => 'Get a quote',
                'welcome_message' => 'Tell us about your project and we’ll send you an offer within two business days.',
                'form_title' => 'Request a quote',
                'submit_label' => 'Request quote',
                'success_message' => 'Thanks! We’ll look at your project and send you a quote within two business days.',
                'default_field_subject' => ['hidden' => true],
                'auto_subject' => 'Quote request',
                'default_field_contact_name' => ['label' => 'Name', 'is_required' => true],
                'default_field_description' => ['label' => 'Tell us about your project', 'is_required' => true],
                'after_submit' => 'message',
            ],
        ];
    }

    private static function newsletterWaitlist(): array
    {
        return [
            'columns' => [['Signed up', '#3b82f6'], ['Welcomed', '#a855f7'], ['Unsubscribed', '#64748b', true]],
            'fields' => [
                ['Interested in', 'checkboxes', ['Product news', 'Tips & guides', 'Events', 'Offers'], false, true],
                ['I agree to receive emails and can unsubscribe at any time', 'checkbox', null, true, false],
            ],
            'settings' => [
                'button_text' => 'Join the list',
                'welcome_message' => 'Be the first to hear about news and early access.',
                'form_title' => 'Join the list',
                'submit_label' => 'Sign me up',
                'success_message' => 'You’re on the list! Watch your inbox for the next update.',
                'default_field_subject' => ['hidden' => true],
                'auto_subject' => 'Sign-up',
                'default_field_contact_name' => ['label' => 'First name', 'is_required' => false],
                'default_field_description' => ['hidden' => true],
                'default_field_attachments' => ['hidden' => true],
                'after_submit' => 'message',
            ],
        ];
    }

    private static function partnershipRequests(): array
    {
        return [
            'columns' => [['New', '#94a3b8'], ['In talks', '#3b82f6'], ['Partner', '#22c55e', true], ['Not now', '#64748b', true]],
            'fields' => [
                ['Company', 'text', null, true, true],
                ['Website', 'text', null, false, false],
                ['Partnership type', 'select', ['Reseller', 'Integration', 'Co-marketing', 'Affiliate', 'Other'], true, true],
                ['Company size', 'select', ['1–10', '11–50', '51–200', 'More than 200'], false, false],
            ],
            'settings' => [
                'button_text' => 'Partner with us',
                'welcome_message' => 'Let’s grow together. Tell us about your company and your idea.',
                'form_title' => 'Partnership request',
                'submit_label' => 'Send request',
                'success_message' => 'Thanks! Our partnerships team will get back to you within a week.',
                'default_field_subject' => ['hidden' => true],
                'auto_subject' => 'Partnership request',
                'default_field_contact_name' => ['label' => 'Name', 'is_required' => true],
                'default_field_description' => ['label' => 'What do you have in mind?', 'is_required' => true],
                'default_field_attachments' => ['hidden' => true],
                'after_submit' => 'message',
            ],
        ];
    }

    private static function pressMedia(): array
    {
        return [
            'columns' => [['New', '#94a3b8'], ['In progress', '#3b82f6'], ['Answered', '#22c55e', true]],
            'fields' => [
                ['Outlet / publication', 'text', null, true, true],
                ['Request type', 'select', ['Interview', 'Quote or comment', 'Press kit', 'Event invitation', 'Other'], true, true],
                ['Deadline', 'date', null, false, true],
                ['Phone', 'text', null, false, false],
            ],
            'settings' => [
                'button_text' => 'Press',
                'welcome_message' => 'Journalists and creators: we’re happy to help with your story.',
                'form_title' => 'Press & media inquiries',
                'submit_label' => 'Send inquiry',
                'success_message' => 'Thanks! Our press contact will reply before your deadline.',
                'default_field_subject' => ['label' => 'Topic'],
                'default_field_contact_name' => ['label' => 'Name', 'is_required' => true],
                'default_field_description' => ['label' => 'Your request', 'is_required' => true],
                'after_submit' => 'message',
            ],
        ];
    }

    // ── Events & bookings ────────────────────────────────────────────────────

    private static function eventRegistration(): array
    {
        return [
            'columns' => [['Registered', '#3b82f6'], ['Confirmed', '#a855f7'], ['Checked in', '#22c55e', true], ['Cancelled', '#64748b', true]],
            'fields' => [
                ['Ticket type', 'radio', ['Standard', 'VIP', 'Student'], true, true],
                ['Number of guests', 'number', null, false, true],
                ['Dietary needs', 'checkboxes', ['Vegetarian', 'Vegan', 'Gluten-free', 'Lactose-free', 'No restrictions'], false, false],
                ['T-shirt size', 'select', ['XS', 'S', 'M', 'L', 'XL', 'XXL'], false, false],
                ['Company', 'text', null, false, false],
                ['Photos of me may be used in event recaps', 'checkbox', null, false, false],
            ],
            'settings' => [
                'button_text' => 'Register',
                'welcome_message' => 'Save your spot — it takes less than a minute.',
                'form_title' => 'Register for the event',
                'submit_label' => 'Register',
                'success_message' => 'You’re registered! A confirmation is on its way to your inbox.',
                'default_field_subject' => ['hidden' => true],
                'auto_subject' => 'Registration',
                'default_field_contact_name' => ['label' => 'Full name', 'is_required' => true],
                'default_field_description' => ['label' => 'Anything we should know?', 'is_required' => false],
                'default_field_attachments' => ['hidden' => true],
                'after_submit' => 'message',
            ],
        ];
    }

    private static function appointmentBooking(): array
    {
        return [
            'columns' => [['Requested', '#94a3b8'], ['Confirmed', '#3b82f6'], ['Done', '#22c55e', true], ['Cancelled', '#64748b', true]],
            'fields' => [
                ['Service', 'select', ['Consultation', 'Demo', 'Follow-up', 'Other'], true, true],
                ['Preferred date', 'date', null, true, true],
                ['Preferred time', 'radio', ['Morning', 'Afternoon', 'Evening'], true, true],
                ['Meeting type', 'radio', ['In person', 'Video call', 'Phone call'], false, false],
                ['Phone', 'text', null, false, false],
            ],
            'settings' => [
                'button_text' => 'Book a call',
                'welcome_message' => 'Pick a service and a time that suits you — we’ll confirm by email.',
                'form_title' => 'Request an appointment',
                'submit_label' => 'Request appointment',
                'success_message' => 'Thanks! We’ll confirm your appointment by email shortly.',
                'default_field_subject' => ['hidden' => true],
                'auto_subject' => 'Appointment request',
                'default_field_contact_name' => ['label' => 'Name', 'is_required' => true],
                'default_field_description' => ['label' => 'Notes', 'is_required' => false],
                'default_field_attachments' => ['hidden' => true],
                'after_submit' => 'message',
            ],
        ];
    }

    private static function volunteerSignup(): array
    {
        return [
            'columns' => [['Signed up', '#3b82f6'], ['Contacted', '#a855f7'], ['Active', '#22c55e', true], ['Not available', '#64748b', true]],
            'fields' => [
                ['I’d like to help with', 'checkboxes', ['Event help', 'Social media', 'Mentoring', 'Fundraising', 'Logistics'], true, true],
                ['Availability', 'checkboxes', ['Weekdays', 'Evenings', 'Weekends'], false, true],
                ['Phone', 'text', null, false, false],
            ],
            'settings' => [
                'button_text' => 'Volunteer',
                'welcome_message' => 'Thank you for wanting to help! Tell us what you enjoy doing.',
                'form_title' => 'Become a volunteer',
                'submit_label' => 'Sign up',
                'success_message' => 'Thank you! We’ll be in touch about the next opportunity.',
                'default_field_subject' => ['hidden' => true],
                'auto_subject' => 'Volunteer',
                'default_field_contact_name' => ['label' => 'Full name', 'is_required' => true],
                'default_field_description' => ['label' => 'Experience or anything else', 'is_required' => false],
                'default_field_attachments' => ['hidden' => true],
                'after_submit' => 'message',
            ],
        ];
    }

    // ── People & internal ────────────────────────────────────────────────────

    private static function jobApplications(): array
    {
        return [
            'columns' => [['Applied', '#94a3b8'], ['Screening', '#6b7280'], ['Interview', '#3b82f6'], ['Offer', '#f59e0b'], ['Hired', '#22c55e', true], ['Not a fit', '#64748b', true]],
            'fields' => [
                ['Position', 'select', ['Frontend engineer', 'Backend engineer', 'Product designer', 'Sales', 'Open application'], true, true],
                ['LinkedIn or portfolio', 'text', null, false, false],
                ['Earliest start date', 'date', null, false, false],
                ['Work setup', 'radio', ['On-site', 'Hybrid', 'Remote'], false, true],
                ['Salary expectation', 'text', null, false, false],
            ],
            'settings' => [
                'button_text' => 'Apply',
                'welcome_message' => 'We’d love to meet you. Attach your CV below.',
                'form_title' => 'Apply for a job',
                'submit_label' => 'Send application',
                'success_message' => 'Thanks for applying! We read every application and reply within two weeks.',
                'default_field_subject' => ['hidden' => true],
                'auto_subject' => 'Application',
                'default_field_contact_name' => ['label' => 'Full name', 'is_required' => true],
                'default_field_description' => ['label' => 'Why do you want to join us?', 'is_required' => false],
                'after_submit' => 'message',
            ],
        ];
    }

    private static function hrRequests(): array
    {
        return [
            'columns' => [['Submitted', '#94a3b8'], ['In review', '#3b82f6'], ['Done', '#22c55e', true], ['Declined', '#64748b', true]],
            'fields' => [
                ['Request type', 'select', ['Leave / time off', 'Equipment', 'Payroll question', 'Document request', 'Other'], true, true],
                ['Department', 'select', ['Engineering', 'Sales', 'Marketing', 'Operations', 'Other'], false, true],
                ['From', 'date', null, false, false],
                ['To', 'date', null, false, false],
            ],
            'settings' => [
                'button_text' => 'HR request',
                'welcome_message' => 'Requests go to the people team and stay private.',
                'form_title' => 'HR request',
                'default_field_subject' => ['label' => 'Short summary'],
                'default_field_contact_name' => ['label' => 'Your name', 'is_required' => true],
                'default_field_description' => ['label' => 'Details', 'is_required' => false],
                'after_submit' => 'portal',
            ],
        ];
    }

    private static function facilitiesRequests(): array
    {
        return [
            'columns' => [['New', '#94a3b8'], ['Scheduled', '#a855f7'], ['In progress', '#3b82f6'], ['Done', '#22c55e', true]],
            'fields' => [
                ['Issue type', 'select', ['Repair', 'Cleaning', 'Furniture', 'Supplies', 'Access card', 'Other'], true, true],
                ['Location / room', 'text', null, true, true],
                ['Urgency', 'radio', ['Today', 'This week', 'Whenever'], false, true],
            ],
            'settings' => [
                'button_text' => 'Office request',
                'welcome_message' => 'Report a problem in the office or ask for supplies.',
                'form_title' => 'Office & facilities request',
                'default_field_subject' => ['label' => 'What do you need?'],
                'default_field_contact_name' => ['label' => 'Your name', 'is_required' => false],
                'default_field_description' => ['label' => 'Details', 'is_required' => false],
                'after_submit' => 'portal',
            ],
        ];
    }

    // ── Product ──────────────────────────────────────────────────────────────

    private static function featureRequests(): array
    {
        return [
            'columns' => [['Submitted', '#94a3b8'], ['Under review', '#6b7280'], ['Planned', '#3b82f6'], ['Shipped', '#22c55e', true], ['Not planned', '#64748b', true]],
            'fields' => [
                ['Product area', 'select', ['Dashboard', 'Reports', 'Integrations', 'Mobile app', 'Other'], true, true],
                ['How important is this to you?', 'radio', ['Nice to have', 'Important', 'Critical'], true, true],
                ['Your plan', 'select', ['Free', 'Pro', 'Business', 'Enterprise'], false, false],
                ['Company', 'text', null, false, false],
            ],
            'settings' => [
                'button_text' => 'Suggest a feature',
                'welcome_message' => 'What would make the product better for you?',
                'form_title' => 'Suggest a feature',
                'submit_label' => 'Send idea',
                'default_field_subject' => ['label' => 'Your idea in one line'],
                'default_field_description' => ['label' => 'What problem would it solve?', 'is_required' => true],
                'after_submit' => 'portal',
            ],
        ];
    }

    private static function betaSignup(): array
    {
        return [
            'columns' => [['Applied', '#94a3b8'], ['Accepted', '#3b82f6'], ['Onboarded', '#22c55e', true], ['Not now', '#64748b', true]],
            'fields' => [
                ['Company', 'text', null, false, true],
                ['Role', 'text', null, false, false],
                ['Team size', 'select', ['Just me', '2–10', '11–50', 'More than 50'], false, true],
                ['Devices you use', 'checkboxes', ['Web', 'iOS', 'Android', 'Desktop'], true, false],
                ['I can give feedback once a week', 'checkbox', null, false, false],
            ],
            'settings' => [
                'button_text' => 'Join the beta',
                'welcome_message' => 'Get early access and help shape what we build.',
                'form_title' => 'Join the beta',
                'submit_label' => 'Apply for access',
                'success_message' => 'Thanks! We’ll email you when your spot opens up.',
                'default_field_subject' => ['hidden' => true],
                'auto_subject' => 'Beta sign-up',
                'default_field_contact_name' => ['label' => 'Name', 'is_required' => true],
                'default_field_description' => ['label' => 'What would you use it for?', 'is_required' => false],
                'default_field_attachments' => ['hidden' => true],
                'after_submit' => 'message',
            ],
        ];
    }
}
