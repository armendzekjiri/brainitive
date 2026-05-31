<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Paths the framework's HandleCors middleware applies to. The MCP endpoint,
    | the OAuth 2.1 endpoints, and the discovery documents are included so that
    | browser-based MCP clients (e.g. the MCP Inspector, web playgrounds) can
    | reach them. The `Mcp-Session-Id` header is exposed because the Streamable
    | HTTP transport relies on it for session continuity.
    |
    */

    'paths' => [
        'mcp',
        'mcp/*',
        'oauth/*',
        '.well-known/*',
        'api/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Mcp-Session-Id', 'WWW-Authenticate'],

    'max_age' => 86400,

    'supports_credentials' => false,

];
