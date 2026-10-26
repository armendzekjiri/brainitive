<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\InstanceTestMail;
use App\Models\AdminAuditLog;
use App\Models\InstanceSettings;
use App\Support\Admin\InstanceMailConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Instance-wide settings: sign-up, email verification and the outgoing mail server.
 */
class SettingsController extends Controller
{
    public function edit(): Response
    {
        $settings = InstanceSettings::instance()->settings ?? [];
        $mail = $settings['mail'] ?? [];

        return Inertia::render('Admin/Settings', [
            'settings' => [
                'allow_registration' => InstanceSettings::allowsRegistration(),
                'require_email_verification' => InstanceSettings::requiresEmailVerification(),
                'mail' => [
                    'enabled' => (bool) ($mail['enabled'] ?? false),
                    'host' => $mail['host'] ?? '',
                    'port' => $mail['port'] ?? 587,
                    'encryption' => array_key_exists('encryption', $mail) ? ($mail['encryption'] ?? 'none') : 'tls',
                    'username' => $mail['username'] ?? '',
                    'has_password' => ! empty($mail['password']),
                    'from_address' => $mail['from_address'] ?? config('mail.from.address'),
                    'from_name' => $mail['from_name'] ?? config('mail.from.name'),
                ],
            ],
            // What applies when the admin SMTP is off — no secrets.
            'envMail' => [
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'from_address' => config('mail.from.address'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'allow_registration' => ['required', 'boolean'],
            'require_email_verification' => ['required', 'boolean'],
            'mail.enabled' => ['required', 'boolean'],
            'mail.host' => ['nullable', 'required_if:mail.enabled,true', 'string', 'max:255'],
            'mail.port' => ['nullable', 'required_if:mail.enabled,true', 'integer', 'between:1,65535'],
            'mail.encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'mail.username' => ['nullable', 'string', 'max:255'],
            'mail.password' => ['nullable', 'string', 'max:1024'],
            'mail.clear_password' => ['boolean'],
            'mail.from_address' => ['nullable', 'required_if:mail.enabled,true', 'email', 'max:255'],
            'mail.from_name' => ['nullable', 'string', 'max:255'],
        ]);

        $instance = InstanceSettings::instance();
        $current = $instance->settings ?? [];
        $mail = $validated['mail'];

        // An empty password field keeps the stored one; "clear" removes it.
        $password = match (true) {
            $request->boolean('mail.clear_password') => null,
            filled($mail['password'] ?? null) => InstanceSettings::encryptSecret($mail['password']),
            default => $current['mail']['password'] ?? null,
        };

        $instance->settings = array_merge($current, [
            'allow_registration' => $validated['allow_registration'],
            'require_email_verification' => $validated['require_email_verification'],
            'mail' => [
                'enabled' => $mail['enabled'],
                'host' => $mail['host'] ?? null,
                'port' => $mail['port'] ?? null,
                'encryption' => ($mail['encryption'] ?? 'tls') === 'none' ? null : ($mail['encryption'] ?? 'tls'),
                'username' => $mail['username'] ?? null,
                'password' => $password,
                'from_address' => $mail['from_address'] ?? null,
                'from_name' => $mail['from_name'] ?? null,
            ],
        ]);
        $instance->save();

        InstanceMailConfig::apply();

        AdminAuditLog::record('settings.updated', null, [
            'allow_registration' => $validated['allow_registration'],
            'require_email_verification' => $validated['require_email_verification'],
            'smtp' => $mail['enabled'] ? ($mail['host'] ?? null) : 'off',
            'smtp_password_changed' => $password !== ($current['mail']['password'] ?? null),
        ]);

        return back()->with('success', 'Settings saved.');
    }

    /**
     * Sends right away (not queued) so the admin sees SMTP errors immediately.
     */
    public function testEmail(Request $request): RedirectResponse
    {
        $validated = $request->validate(['to' => ['required', 'email']]);

        InstanceMailConfig::apply();

        try {
            Mail::to($validated['to'])->send(new InstanceTestMail((string) config('mail.default')));
        } catch (\Throwable $e) {
            return back()->with('error', 'Sending failed: '.$e->getMessage());
        }

        AdminAuditLog::record('settings.test_email_sent', null, ['to' => $validated['to']]);

        return back()->with('success', "Test email sent to {$validated['to']} via ".config('mail.default').'.');
    }
}
