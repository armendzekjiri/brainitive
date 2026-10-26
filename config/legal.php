<?php

/*
|--------------------------------------------------------------------------
| Legal pages: operator data, documents and analytics (BRAI-156)
|--------------------------------------------------------------------------
|
| One place for everything the imprint, privacy policy, terms, sub-processor list and DPA show
| about the operator. Read through App\Support\Legal\LegalInfo, never directly from the pages.
|
| Brainitive Cloud (brainitive.app) fills in its own defaults in LegalInfo (contact email, OVHcloud,
| MXroute). A SELF-HOSTED install shows only what its operator sets here — never Brainitive's data:
| set LEGAL_OPERATOR_NAME, LEGAL_ADDRESS, LEGAL_EMAIL… and/or link your own documents with
| LEGAL_PRIVACY_URL / LEGAL_TERMS_URL / LEGAL_IMPRINT_URL. Empty values are not rendered.
| See docs/legal/README.md.
|
*/

return [

    // true = Brainitive Cloud texts (privacy policy, terms, DPA, sub-processors).
    // Unset = detected from APP_URL (brainitive.app, www., dev.); self-hosted installs are false.
    'cloud' => env('LEGAL_CLOUD') === null ? null : (bool) filter_var(env('LEGAL_CLOUD'), FILTER_VALIDATE_BOOL),

    'operator' => [
        'name' => env('LEGAL_OPERATOR_NAME'),
        // e.g. "Sole proprietor", "GmbH, represented by …" — free text, shown under the name
        'description' => env('LEGAL_OPERATOR_DESCRIPTION'),
        'address' => env('LEGAL_ADDRESS'),                 // postal address, line breaks as "\n" or ", "
        'email' => env('LEGAL_EMAIL'),
        'phone' => env('LEGAL_PHONE'),
        'representative' => env('LEGAL_REPRESENTATIVE'),   // managing director / authorised representative
        'register' => env('LEGAL_REGISTER'),               // e.g. "Amtsgericht München, HRB 123456"
        'vat_id' => env('LEGAL_VAT_ID'),
        'content_responsible' => env('LEGAL_CONTENT_RESPONSIBLE'), // § 18 (2) MStV, if editorial content
        'dpo' => env('LEGAL_DPO'),                         // data protection officer (name + contact), if appointed
        'eu_representative' => env('LEGAL_EU_REPRESENTATIVE'), // Art. 27 GDPR, only outside the EU
        'supervisory_authority' => env('LEGAL_SUPERVISORY_AUTHORITY'),
        'governing_law' => env('LEGAL_GOVERNING_LAW'),     // e.g. "German law"
        'jurisdiction' => env('LEGAL_JURISDICTION'),       // place of jurisdiction for merchants
    ],

    // Self-hosted: the operator's own documents (shown / linked instead of Brainitive Cloud's)
    'documents' => [
        'privacy_url' => env('LEGAL_PRIVACY_URL'),
        'terms_url' => env('LEGAL_TERMS_URL'),
        'imprint_url' => env('LEGAL_IMPRINT_URL'),
    ],

    // Processors (Brainitive Cloud defaults live in LegalInfo; set these to override)
    'providers' => [
        'hosting' => env('LEGAL_HOSTING_PROVIDER'),
        'hosting_location' => env('LEGAL_HOSTING_LOCATION'),
        'email' => env('LEGAL_EMAIL_PROVIDER'),
        'email_location' => env('LEGAL_EMAIL_PROVIDER_LOCATION'),
        'backup' => env('LEGAL_BACKUP_PROVIDER'),
        'backup_location' => env('LEGAL_BACKUP_LOCATION'),
    ],

    // Storage periods the code can't know (free text, e.g. "14 days"); empty = shown as "to be confirmed"
    'retention' => [
        'server_logs' => env('LEGAL_SERVER_LOG_RETENTION'),
        'backups' => env('LEGAL_BACKUP_RETENTION'),
        'analytics' => env('LEGAL_ANALYTICS_RETENTION'),
    ],

    // The operator's self-hosted, cookieless Umami (brainitive.app only; see LegalInfo / Analytics)
    'analytics' => [
        'hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('ANALYTICS_HOSTS', 'brainitive.app,www.brainitive.app'))))),
        'script' => env('ANALYTICS_UMAMI_SCRIPT', 'https://stats.novabrainhub.de/script.js'),
        'website_id' => env('ANALYTICS_UMAMI_WEBSITE_ID', 'e6d93341-8b10-4d6a-8629-b9599635909e'),
    ],

];
