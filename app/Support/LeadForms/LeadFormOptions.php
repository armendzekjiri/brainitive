<?php

namespace App\Support\LeadForms;

use Illuminate\Validation\Rule;

/**
 * A lead form's look (`theme`) and behaviour (`settings`): whitelisted keys with defaults, so
 * the builder can save any subset and the public page always gets a complete set.
 */
class LeadFormOptions
{
    public const FONTS = ['inter', 'system', 'serif', 'rounded', 'mono'];

    public const THEME_DEFAULTS = [
        'primary' => '#4f46e5',
        'background' => '#f1f5f9',
        'surface' => '#ffffff',
        'text' => '#0f172a',
        'font' => 'inter',
        'radius' => 'lg', // none | md | lg | full
        'button' => 'solid', // solid | outline
        'layout' => 'card', // card (centered) | split (cover image beside the form) | full
        'progress' => 'bar', // bar | steps | none
        'logo_url' => null,
        'cover_url' => null,
    ];

    public const SETTING_DEFAULTS = [
        'intro_title' => null,
        'intro_text' => null,
        'next_label' => 'Next',
        'back_label' => 'Back',
        'submit_label' => 'Submit',
        'after_submit' => 'message', // message | redirect
        'thank_you_title' => 'Thank you!',
        'thank_you_message' => 'We received your details and will get back to you shortly.',
        'redirect_url' => null,
        'confirmation_email' => false, // email the lead (needs a field mapped to email)
        'confirmation_subject' => 'Thanks for reaching out',
        'confirmation_body' => "Hi {name},\n\nthanks for your message — we'll be in touch soon.",
        'notify_user_ids' => [], // project members emailed for every new lead
        'webhook_url' => null,
        'show_branding' => true,
        // Consent checkbox above the submit button (BRAI-156): off | optional | required.
        // The text the visitor saw is stored with the lead (leads.consent_text + consent_at).
        'consent' => 'off',
        'consent_text' => null,
    ];

    /** Shown when the form has a checkbox but no text of its own; {organization} = who runs the form. */
    public const DEFAULT_CONSENT_TEXT = 'I agree that {organization} stores and uses my details to respond to my request. I can withdraw this consent at any time.';

    private const COLOR = 'regex:/^#[0-9a-fA-F]{6}$/';

    /**
     * @return array<string, mixed>
     */
    public static function themeRules(): array
    {
        return [
            'theme' => ['sometimes', 'array:'.implode(',', array_keys(self::THEME_DEFAULTS))],
            'theme.primary' => ['sometimes', 'string', self::COLOR],
            'theme.background' => ['sometimes', 'string', self::COLOR],
            'theme.surface' => ['sometimes', 'string', self::COLOR],
            'theme.text' => ['sometimes', 'string', self::COLOR],
            'theme.font' => ['sometimes', Rule::in(self::FONTS)],
            'theme.radius' => ['sometimes', Rule::in(['none', 'md', 'lg', 'full'])],
            'theme.button' => ['sometimes', Rule::in(['solid', 'outline'])],
            'theme.layout' => ['sometimes', Rule::in(['card', 'split', 'full'])],
            'theme.progress' => ['sometimes', Rule::in(['bar', 'steps', 'none'])],
            'theme.logo_url' => ['sometimes', 'nullable', 'string', 'max:2048', self::imageUrlRule()],
            'theme.cover_url' => ['sometimes', 'nullable', 'string', 'max:2048', self::imageUrlRule()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function settingsRules(): array
    {
        return [
            'settings' => ['sometimes', 'array:'.implode(',', array_keys(self::SETTING_DEFAULTS))],
            'settings.intro_title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'settings.intro_text' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'settings.next_label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'settings.back_label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'settings.submit_label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'settings.after_submit' => ['sometimes', Rule::in(['message', 'redirect'])],
            'settings.thank_you_title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'settings.thank_you_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'settings.redirect_url' => ['sometimes', 'nullable', 'url:https,http', 'max:2048'], // required with after_submit=redirect: checked on the merged settings
            'settings.confirmation_email' => ['sometimes', 'boolean'],
            'settings.confirmation_subject' => ['sometimes', 'nullable', 'string', 'max:200'],
            'settings.confirmation_body' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'settings.notify_user_ids' => ['sometimes', 'array', 'max:50'],
            'settings.notify_user_ids.*' => ['integer'],
            'settings.webhook_url' => ['sometimes', 'nullable', 'url:https,http', 'max:2048'],
            'settings.show_branding' => ['sometimes', 'boolean'],
            'settings.consent' => ['sometimes', Rule::in(['off', 'optional', 'required'])],
            'settings.consent_text' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @param  array<string, mixed>  $changes  validated subset
     */
    public static function theme(?array $stored, array $changes = []): array
    {
        return array_intersect_key(array_merge(self::THEME_DEFAULTS, $stored ?? [], $changes), self::THEME_DEFAULTS);
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @param  array<string, mixed>  $changes  validated subset
     */
    public static function settings(?array $stored, array $changes = []): array
    {
        $settings = array_intersect_key(array_merge(self::SETTING_DEFAULTS, $stored ?? [], $changes), self::SETTING_DEFAULTS);
        $settings['notify_user_ids'] = array_values(array_unique(array_map('intval', (array) $settings['notify_user_ids'])));
        foreach (['next_label', 'back_label', 'submit_label'] as $label) {
            $settings[$label] = filled($settings[$label]) ? $settings[$label] : self::SETTING_DEFAULTS[$label]; // cleared → default
        }
        foreach (['confirmation_email', 'show_branding'] as $flag) {
            $settings[$flag] = (bool) $settings[$flag];
        }
        if (! in_array($settings['consent'], ['off', 'optional', 'required'], true)) {
            $settings['consent'] = 'off';
        }

        return $settings;
    }

    /**
     * What the public page needs — never the webhook URL or who gets notified.
     */
    public static function publicSettings(array $settings): array
    {
        return array_diff_key($settings, array_flip(['notify_user_ids', 'webhook_url', 'confirmation_email', 'confirmation_subject', 'confirmation_body']));
    }

    /**
     * The consent text a visitor sees (and that is stored with their lead), or null without a checkbox.
     *
     * @param  array<string, mixed>  $settings  merged settings (self::settings)
     */
    public static function consentText(array $settings, ?string $organization): ?string
    {
        if (($settings['consent'] ?? 'off') === 'off') {
            return null;
        }
        $text = filled($settings['consent_text'] ?? null) ? trim((string) $settings['consent_text']) : self::DEFAULT_CONSENT_TEXT;

        return str_replace('{organization}', filled($organization) ? (string) $organization : 'the organisation running this form', $text);
    }

    /** Logo / cover: an upload of this app (/storage/...) or an https image URL. */
    private static function imageUrlRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if ($value !== null && (str_contains((string) $value, '..') || ! preg_match('#^(/storage/[A-Za-z0-9/_.-]+|https://[^\s"\'<>]+)$#', (string) $value))) {
                $fail('Use an uploaded image or an https:// image URL.');
            }
        };
    }
}
