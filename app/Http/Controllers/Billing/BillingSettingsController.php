<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingSetting;
use App\Models\Workspace;
use App\Support\Billing\BillingLogo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Billing → Settings (v1.38.0, BRAI-101), workspace admins only: currency, VAT, net / gross prices,
 * rounding, invoice mode + numbering, payment terms, issuer, bank details, footer and the logo
 * printed on billing documents. One BillingSetting row per workspace.
 */
class BillingSettingsController extends Controller
{
    /** Offered in the currency picker; any other ISO 4217-shaped code can be typed in. */
    public const CURRENCIES = ['EUR', 'USD', 'CHF', 'GBP', 'MKD', 'ALL', 'RSD', 'BAM', 'PLN', 'CZK', 'HUF', 'RON', 'BGN', 'SEK', 'NOK', 'DKK', 'TRY', 'CAD', 'AUD', 'JPY'];

    public function edit(Workspace $workspace): Response
    {
        Gate::authorize('manageBilling', $workspace);
        $settings = BillingSetting::forWorkspace($workspace);
        $hasLogo = BillingLogo::owns($settings->logo_path, $workspace->id) && Storage::disk(BillingLogo::DISK)->exists($settings->logo_path);

        return Inertia::render('Billing/Settings', [
            'settings' => [
                'currency' => $settings->currency,
                'default_tax_rate' => number_format($settings->default_tax_rate_bp / 100, 2, '.', ''),
                'prices_include_tax' => $settings->prices_include_tax,
                'rounding_minutes' => $settings->rounding_minutes,
                'invoice_mode' => $settings->invoice_mode,
                'number_prefix' => $settings->number_prefix ?? '',
                'next_number' => $settings->next_number,
                'payment_terms_days' => $settings->payment_terms_days,
                'company_name' => $settings->company_name ?? '',
                'company_address' => $settings->company_address ?? '',
                'company_vat_id' => $settings->company_vat_id ?? '',
                'company_email' => $settings->company_email ?? '',
                'company_phone' => $settings->company_phone ?? '',
                'bank_name' => $settings->bank_name ?? '',
                'iban' => $settings->iban ?? '',
                'bic' => $settings->bic ?? '',
                'footer' => $settings->footer ?? '',
            ],
            // cache-busted by the file name (a new upload is a new uuid)
            'logoUrl' => $hasLogo ? route('billing.settings.logo', ['workspace' => $workspace, 'v' => pathinfo($settings->logo_path, PATHINFO_FILENAME)]) : null,
            'currencies' => self::CURRENCIES,
            'roundingOptions' => BillingSetting::ROUNDING_OPTIONS,
        ]);
    }

    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageBilling', $workspace);

        // normalise before validating: "de89 3704 …" → "DE893704…", currency in upper case
        // (strings only: anything else is left for the `string` rule to refuse)
        $compact = fn ($value) => is_string($value) ? strtoupper((string) preg_replace('/\s+/', '', $value)) : $value;
        $request->merge([
            'currency' => $compact($request->input('currency')),
            'iban' => $compact($request->input('iban')),
            'bic' => $compact($request->input('bic')),
        ]);

        $data = $request->validate([
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'default_tax_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'prices_include_tax' => ['required', 'boolean'],
            'rounding_minutes' => ['required', 'integer', Rule::in(BillingSetting::ROUNDING_OPTIONS)],
            'invoice_mode' => ['required', 'boolean'],
            'number_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9 ._\/#-]*$/'],
            'next_number' => ['required', 'integer', 'min:1', 'max:99999999'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'company_address' => ['nullable', 'string', 'max:1000'],
            'company_vat_id' => ['nullable', 'string', 'max:40'],
            'company_email' => ['nullable', 'string', 'email', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:40'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            // loose: country code, check digits, 11–30 letters / digits (the mod-97 check is the bank's job)
            'iban' => ['nullable', 'string', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/'],
            'bic' => ['nullable', 'string', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/'],
            'footer' => ['nullable', 'string', 'max:2000'],
        ], [
            'currency.regex' => 'Use a three-letter currency code, e.g. EUR.',
            'number_prefix.regex' => 'Use letters, digits, spaces and . _ / # - only.',
            'iban.regex' => 'This doesn’t look like an IBAN (two letters, two digits, then 11–30 letters or digits).',
            'bic.regex' => 'A BIC has 8 or 11 letters and digits.',
        ]);

        $data['default_tax_rate_bp'] = (int) round(((float) $data['default_tax_rate']) * 100);
        unset($data['default_tax_rate']);
        foreach (['number_prefix', 'company_name', 'company_address', 'company_vat_id', 'company_email', 'company_phone', 'bank_name', 'iban', 'bic', 'footer'] as $field) {
            $data[$field] = filled($data[$field] ?? null) ? trim((string) $data[$field]) : null;
        }

        $settings = BillingSetting::forWorkspace($workspace);
        $settings->fill($data);
        $settings->workspace_id = $workspace->id;
        $settings->save();

        return back()->with('success', 'Billing settings saved.');
    }

    /** The logo for the settings page preview — private disk, admins only, never a public URL. */
    public function logo(Workspace $workspace): StreamedResponse
    {
        Gate::authorize('manageBilling', $workspace);
        $path = BillingSetting::where('workspace_id', $workspace->id)->value('logo_path');
        abort_unless(BillingLogo::owns($path, $workspace->id) && Storage::disk(BillingLogo::DISK)->exists($path), 404);

        return Storage::disk(BillingLogo::DISK)->response($path, 'logo.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storeLogo(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageBilling', $workspace);
        $max = BillingLogo::MAX_SOURCE_SIDE;
        $request->validate([
            'logo' => ['required', 'file', 'max:1024', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', "dimensions:max_width={$max},max_height={$max}"],
        ], [
            'logo.max' => 'The logo may be at most 1 MB.',
            'logo.mimes' => 'The logo must be a PNG, JPG or WebP image.',
            'logo.mimetypes' => 'The logo must be a PNG, JPG or WebP image.',
            'logo.dimensions' => "The logo may be at most {$max} pixels wide and high.",
        ]);

        $settings = BillingSetting::forWorkspace($workspace);
        $settings->workspace_id = $workspace->id;
        BillingLogo::store($settings, $request->file('logo'));

        return back()->with('success', 'Logo uploaded.');
    }

    public function destroyLogo(Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageBilling', $workspace);
        BillingLogo::remove(BillingSetting::forWorkspace($workspace));

        return back()->with('success', 'Logo removed.');
    }
}
