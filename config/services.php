<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/login/google/callback'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI', env('APP_URL').'/login/github/callback'),
        // the open-source repository behind the "Star on GitHub" buttons (App\Support\GithubStars)
        'repository' => env('GITHUB_REPOSITORY', 'armendzekjiri/brainitive'),
        'show_stars' => (bool) env('GITHUB_STARS', true),
    ],

    // The instance's GitHub App (BRAI-61) for repository connections — separate from the social
    // login above. Normally created in Admin → GitHub App (manifest flow, stored encrypted); these
    // env vars override the stored app when GITHUB_APP_ID is set. The private key may be the PEM
    // itself (\n escapes allowed) or a path to the .pem file.
    'github_app' => [
        'app_id' => env('GITHUB_APP_ID'),
        'slug' => env('GITHUB_APP_SLUG'),
        'client_id' => env('GITHUB_APP_CLIENT_ID'),
        'client_secret' => env('GITHUB_APP_CLIENT_SECRET'),
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
        'webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
        // e2e only (APP_ENV=e2e): a fake app + faked GitHub API, no network
        'fake' => (bool) env('GITHUB_APP_FAKE', false),
    ],

    // Workspace AI endpoints on localhost / private networks (e.g. Ollama next to a
    // self-hosted instance). Off by default: a workspace admin could otherwise make the
    // server call internal services.
    'ai' => [
        'allow_private_endpoints' => env('AI_ALLOW_PRIVATE_ENDPOINTS', false),
    ],

    // Email → helpdesk through a forwarding address (BRAI-124, v1.40.0). Mail to
    // <helpdesk>-<random>@INBOUND_MAIL_DOMAIN reaches a provider (Postmark or Mailgun), which posts
    // it to /api/inbound-mail/{provider}. Off (404) until the domain and that provider's secret are
    // set. Setup: docs/self-hosting.md → "Email to helpdesk".
    'inbound_mail' => [
        'domain' => env('INBOUND_MAIL_DOMAIN'),
        // which provider the setup steps describe: postmark | mailgun
        'provider' => env('INBOUND_MAIL_PROVIDER', 'postmark'),
        // Postmark: the password of the webhook URL's basic auth, or ?token=… in the URL
        'postmark_token' => env('INBOUND_MAIL_POSTMARK_TOKEN'),
        // Mailgun: the HTTP webhook signing key (Sending → Webhooks)
        'mailgun_signing_key' => env('INBOUND_MAIL_MAILGUN_SIGNING_KEY'),
        // a Mailgun signature older (or newer) than this many seconds is refused
        'replay_window' => (int) env('INBOUND_MAIL_REPLAY_WINDOW', 900),
        'max_attachment_kb' => (int) env('INBOUND_MAIL_MAX_ATTACHMENT_KB', 10240),
        'max_attachments' => (int) env('INBOUND_MAIL_MAX_ATTACHMENTS', 10),
        'max_total_kb' => (int) env('INBOUND_MAIL_MAX_TOTAL_KB', 30720),
    ],

];
