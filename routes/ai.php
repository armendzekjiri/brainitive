<?php

use App\Http\Middleware\EnsureUserIsNotSuspended;
use App\Http\Middleware\RejectCaptureTokens;
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

// 120 requests a minute per user (as the browser extension's API), counted after auth:api so connectors sharing
// an egress IP (ChatGPT, Claude) don't share one budget. No request logging before auth (Codex review 9 / 12: the
// temporary [MCP-DEBUG] logger wrote caller-controlled JSON to the log for unauthenticated requests).
Mcp::web('/mcp', BrainitiveServer::class)
    ->middleware(['auth:api', 'throttle:120,1,mcp', EnsureUserIsNotSuspended::class, RejectCaptureTokens::class]);
