<?php

namespace App\Support\Helpdesks;

use App\Models\Helpdesk;
use App\Models\HelpdeskField;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The public form of a helpdesk (widget, embed, support page): its texts and default fields
 * from `helpdesks.settings`, and the server-side rules for public submissions (BRAI-92).
 */
class HelpdeskForm
{
    public const AFTER_SUBMIT = ['portal', 'message'];

    /** Every settings key a helpdesk may store; anything else sent to update() is dropped. */
    public const SETTING_KEYS = [
        'brand_color', 'button_text', 'button_position', 'welcome_message', 'logo_url',
        'default_field_contact_name', 'default_field_description', 'default_field_attachments', 'default_field_subject',
        'form_title', 'submit_label', 'message_placeholder', 'success_message', 'after_submit', 'auto_subject',
    ];

    public const TEXT_LIMITS = [
        'form_title' => 80,
        'submit_label' => 40,
        'message_placeholder' => 120,
        'success_message' => 500,
        'auto_subject' => 80,
    ];

    /**
     * Texts shown on the public form, with today's defaults.
     *
     * @return array{form_title: string, submit_label: string, message_placeholder: string, success_message: string, after_submit: string}
     */
    public static function texts(Helpdesk $helpdesk): array
    {
        $s = $helpdesk->settings ?? [];

        return [
            'form_title' => self::text($s['form_title'] ?? null) ?? 'Contact support',
            'submit_label' => self::text($s['submit_label'] ?? null) ?? 'Submit',
            'message_placeholder' => self::text($s['message_placeholder'] ?? null) ?? 'Type your message…',
            'success_message' => self::text($s['success_message'] ?? null) ?? 'Thanks! We received your message and will get back to you by email.',
            'after_submit' => in_array($s['after_submit'] ?? null, self::AFTER_SUBMIT, true) ? $s['after_submit'] : 'portal',
        ];
    }

    /**
     * The subject field: shown with a label, or hidden (then it's filled automatically).
     *
     * @return array{label: string, hidden: bool}
     */
    public static function subject(Helpdesk $helpdesk): array
    {
        $config = $helpdesk->settings['default_field_subject'] ?? [];

        return [
            'label' => self::text($config['label'] ?? null) ?? 'Subject',
            'hidden' => ! empty($config['hidden']),
        ];
    }

    /**
     * Subject for a submission without one: "<auto_subject> — <name or email>".
     */
    public static function autoSubject(Helpdesk $helpdesk, ?string $name, string $email): string
    {
        $prefix = self::text($helpdesk->settings['auto_subject'] ?? null) ?? $helpdesk->name;

        return mb_substr($prefix.' — '.(trim((string) $name) !== '' ? trim((string) $name) : $email), 0, 255);
    }

    /**
     * Validation for a public submission: the default fields as configured and every custom
     * field by type (required, numbers, dates, choices from the field's options).
     *
     * @return array<string, mixed>
     */
    public static function publicRules(Helpdesk $helpdesk): array
    {
        $s = $helpdesk->settings ?? [];
        $nameRequired = empty($s['default_field_contact_name']['hidden']) && ! empty($s['default_field_contact_name']['is_required']);
        $messageRequired = empty($s['default_field_description']['hidden']) && ! empty($s['default_field_description']['is_required']);

        $rules = [
            'subject' => [self::subject($helpdesk)['hidden'] ? 'nullable' : 'required', 'string', 'max:255'],
            'description' => [$messageRequired ? 'required' : 'nullable', 'string', 'max:200000'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_name' => [$nameRequired ? 'required' : 'nullable', 'string', 'max:255'],
        ];

        foreach ($helpdesk->fields as $field) {
            $key = 'field_'.$field->name;
            $required = $field->is_required ? 'required' : 'nullable';
            $options = array_values(array_filter((array) $field->options, 'is_string'));
            $rules[$key] = match (self::type($field)) {
                'number' => [$required, 'numeric'],
                'date' => [$required, 'date_format:Y-m-d'],
                'select', 'radio' => [$required, 'string', Rule::in($options)],
                'checkboxes' => [$required, 'array', 'max:'.max(1, count($options))],
                'checkbox' => [$field->is_required ? 'accepted' : 'nullable', 'boolean'],
                'file' => ['nullable'],
                'rich_text' => [$required, 'string', 'max:200000'],
                default => [$required, 'string', 'max:10000'],
            };
            if (self::type($field) === 'checkboxes') {
                $rules[$key.'.*'] = ['string', Rule::in($options)];
            }
        }

        return $rules;
    }

    /**
     * The widget and the embed send multi-choice answers as a JSON string; turn them into
     * arrays before validation so they can be checked against the options.
     */
    public static function prepare(Request $request, Helpdesk $helpdesk): void
    {
        foreach ($helpdesk->fields as $field) {
            $key = 'field_'.$field->name;
            $value = $request->input($key);
            if (self::type($field) === 'checkboxes' && is_string($value)) {
                $decoded = json_decode($value, true);
                $request->merge([$key => is_array($decoded) ? array_values($decoded) : ($value === '' ? null : [$value])]);
            }
        }
    }

    public static function type(HelpdeskField $field): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim((string) $field->type))) ?: 'text';
    }

    /**
     * Validation for the text settings when admins edit them.
     *
     * @return array<string, mixed>
     */
    public static function settingsRules(): array
    {
        $rules = [
            // Known keys only, small values: the public form config loads these on every page view
            'settings' => ['nullable', 'array:'.implode(',', self::SETTING_KEYS)],
            'settings.brand_color' => ['nullable', 'string', 'max:20'],
            'settings.button_text' => ['nullable', 'string', 'max:40'],
            'settings.button_position' => ['nullable', 'in:bottom-right,bottom-left'],
            'settings.welcome_message' => ['nullable', 'string', 'max:500'],
            'settings.logo_url' => ['nullable', 'string', 'max:2048'],
            'settings.default_field_contact_name' => ['nullable', 'array:label,is_required,hidden'],
            'settings.default_field_description' => ['nullable', 'array:label,is_required,hidden'],
            'settings.default_field_attachments' => ['nullable', 'array:hidden'],
            'settings.*.label' => ['nullable', 'string', 'max:60'],
        ];
        foreach (self::TEXT_LIMITS as $key => $max) {
            $rules["settings.{$key}"] = ['nullable', 'string', "max:{$max}"];
        }
        $rules['settings.after_submit'] = ['nullable', Rule::in(self::AFTER_SUBMIT)];
        $rules['settings.default_field_subject'] = ['nullable', 'array:label,hidden'];
        $rules['settings.default_field_subject.label'] = ['nullable', 'string', 'max:60'];
        $rules['settings.default_field_subject.hidden'] = ['nullable', 'boolean'];

        return $rules;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
