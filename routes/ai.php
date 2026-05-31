<?php

use App\Mcp\Servers\BrainitiveServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP (Model Context Protocol) Routes
|--------------------------------------------------------------------------
|
| The Brainitive MCP server lets remote AI clients (ChatGPT custom connectors,
| Claude remote connectors on web/desktop/mobile) read and write the user's
| content over HTTPS. Authentication is OAuth 2.1 via Laravel Passport.
|
| - Mcp::oauthRoutes() publishes the OAuth discovery documents
|   (/.well-known/oauth-protected-resource, /.well-known/oauth-authorization-server),
|   the Dynamic Client Registration endpoint (/oauth/register) and advertises
|   the single `mcp:use` scope. The /oauth/authorize + /oauth/token endpoints
|   come from Passport itself.
| - Mcp::web('/mcp', ...) is the Streamable HTTP transport endpoint, protected
|   by Passport's `api` guard so every request carries a validated bearer token.
|
*/

Mcp::oauthRoutes();

Mcp::web('/mcp', BrainitiveServer::class)
    ->middleware(['auth:api']);
