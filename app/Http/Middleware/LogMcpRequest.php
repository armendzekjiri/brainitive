<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * TEMPORARY diagnostic middleware. Logs what remote MCP clients (Perplexity,
 * ChatGPT, Claude) actually send to the /mcp endpoint, so we can see why a
 * client connects but shows "no tools". Remove once the connector issue is
 * resolved. Runs BEFORE auth:api so it also captures unauthenticated (401)
 * requests.
 */
class LogMcpRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $auth = $request->header('Authorization');

        $rpcMethod = null;
        $protocolVersion = null;
        try {
            $body = json_decode((string) $request->getContent(), true);
            if (is_array($body)) {
                $rpcMethod = $body['method'] ?? null;
                $protocolVersion = $body['params']['protocolVersion'] ?? null;
            }
        } catch (\Throwable $e) {
            // ignore non-JSON bodies
        }

        Log::info('[MCP-DEBUG] incoming', [
            'http_method' => $request->method(),
            'rpc_method' => $rpcMethod,
            'protocol_version' => $protocolVersion,
            'user_agent' => $request->userAgent(),
            'accept' => $request->header('Accept'),
            'content_type' => $request->header('Content-Type'),
            'has_authorization' => $auth !== null,
            'auth_scheme' => $auth ? strtok($auth, ' ') : null,
            'mcp_session_id' => $request->header('MCP-Session-Id'),
            'ip' => $request->ip(),
        ]);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        Log::info('[MCP-DEBUG] response', [
            'http_method' => $request->method(),
            'status' => $response->getStatusCode(),
            'content_type' => $response->headers->get('Content-Type'),
        ]);
    }
}
