package config

import (
	"fmt"
	"net/http"
	"time"
)

// HTTPClient is the client for every request to a Brainitive server: it never follows a
// redirect. Go would replay a 307/308 POST body (refresh token, authorization code + PKCE
// verifier) and keeps Authorization for a same-host, different-port target, so a redirect could
// carry credentials past the origin checks (Codex CLI/extension review 5). Discovery,
// registration, token, MCP and extension-API requests all use it; a redirect is an error that
// names the target origin. (`brainitive update` downloads from GitHub with its own client:
// those redirects carry no credentials.)
func HTTPClient(timeout time.Duration) *http.Client {
	return &http.Client{Timeout: timeout, CheckRedirect: refuseRedirect}
}

func refuseRedirect(req *http.Request, _ []*http.Request) error {
	return fmt.Errorf("the server redirected to %s://%s; the CLI doesn't follow redirects with credentials. If that is the right address, use it with --server", req.URL.Scheme, req.URL.Host)
}
