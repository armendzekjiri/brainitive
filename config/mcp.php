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
        '*',
    ],

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
