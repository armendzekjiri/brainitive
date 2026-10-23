<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | Domains OAuth clients (ChatGPT, Claude, etc.) may use for their redirect
    | URIs during Dynamic Client Registration. "*" allows any http/https
    | redirect. Published explicitly so the value survives `config:cache`
    | (a package-merged default can drop out once config is cached).
    |
    */

    'redirect_domains' => [
        '*', // the package only does prefix matching; App\Http\Middleware\GuardOAuthFlows enforces the host list below
    ],

    /*
    | Hosts an https redirect URI may use when a client registers itself (plus loopback). Wildcards
    | cover the per-install browser-extension hosts. Add more with MCP_REDIRECT_HOSTS=a.com,*.b.com
    */
    'allowed_redirect_hosts' => array_values(array_filter(array_merge([
        'claude.ai', 'claude.com', 'chatgpt.com', 'chat.openai.com', 'platform.openai.com',
        'perplexity.ai', 'www.perplexity.ai', 'cursor.com', 'www.cursor.com', 'vscode.dev', 'insiders.vscode.dev',
        '*.chromiumapp.org', '*.extensions.allizom.org',
    ], array_map('trim', explode(',', (string) env('MCP_REDIRECT_HOSTS', '')))))),

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients use private-use URI schemes (RFC 8252) for
    | their redirect callbacks instead of https. Claude Desktop, Cursor and
    | VS Code are enabled here so their connectors can register.
    |
    */

    'custom_schemes' => [
        'claude',
        'cursor',
        'vscode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | OAuth authorization server issuer identifier (RFC 8414). Null defaults
    | to url('/'), which is what we want (it follows APP_URL).
    |
    */

    'authorization_server' => null,

];
