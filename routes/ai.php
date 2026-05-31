<?php

use App\Http\Middleware\LogMcpRequest;
use App\Mcp\Servers\BrainitiveServer;
use Illuminate\Support\Facades\Route;
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
    ->middleware([LogMcpRequest::class, 'auth:api']);

// TEMPORARY: log GET probes (some clients open a standalone GET SSE stream before
// POSTing). laravel/mcp normally answers GET with 405; we keep that behaviour but
// log the attempt first. Registered AFTER Mcp::web() so this GET route overrides
// the package's silent 405 handler (last route registered for a method+URI wins).
// Remove together with LogMcpRequest once the Perplexity connector is sorted.
Route::get('/mcp', function () {
    \Illuminate\Support\Facades\Log::info('[MCP-DEBUG] GET probe', [
        'accept' => request()->header('Accept'),
        'user_agent' => request()->userAgent(),
        'has_authorization' => request()->header('Authorization') !== null,
    ]);

    return response('', 405)->header('Allow', 'POST');
})->middleware([LogMcpRequest::class]);
