package cli

import (
	"context"
	"net/http"
	"path/filepath"
	"strings"
	"testing"

	"github.com/armendzekjiri/brainitive/cli/internal/api"
	"github.com/armendzekjiri/brainitive/cli/internal/auth"
	"github.com/armendzekjiri/brainitive/cli/internal/config"
	"github.com/armendzekjiri/brainitive/cli/internal/mcp"
)

// Codex CLI/extension review 5: a 307/308 from the server must not carry the bearer token or a
// request body (refresh token, code + verifier) to another origin. Both test servers listen on
// 127.0.0.1 with different ports — the case where Go's default client keeps Authorization.
func TestRedirectsAreNotFollowedWithCredentials(t *testing.T) {
	b := newRecorder(t, mcpReply)
	code := http.StatusTemporaryRedirect
	a := newRecorder(t, func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, b.srv.URL+r.URL.Path, code)
	})
	t.Setenv("BRAINITIVE_CONFIG", filepath.Join(t.TempDir(), "config.json"))
	t.Setenv("BRAINITIVE_TOKEN", "")
	ctx := context.Background()

	for _, status := range []int{http.StatusTemporaryRedirect, http.StatusPermanentRedirect} {
		code = status
		// MCP call with the session's bearer token.
		app := &App{cfg: signedInTo(a.srv.URL)}
		tokens := &sessionTokens{app: app, server: a.srv.URL}
		if _, err := mcp.New(a.srv.URL, tokens, "test").Call(ctx, "list-workspaces", nil); err == nil {
			t.Error("MCP call through a redirect must fail")
		}
		// Extension API (logout) with the bearer token.
		if err := api.New(a.srv.URL, tokens).Logout(ctx); err == nil {
			t.Error("extension API call through a redirect must fail")
		}
		// Token refresh: the POST body holds the refresh token.
		sess := &config.Session{Server: a.srv.URL, TokenEndpoint: a.srv.URL + "/oauth/token", ClientID: "c", RefreshToken: "refresh-A"}
		if err := auth.Refresh(ctx, sess); err == nil || !strings.Contains(err.Error(), "redirect") {
			t.Errorf("refresh through a redirect = %v, want a redirect error", err)
		}
	}
	if got := b.seen(); len(got) != 0 {
		t.Fatalf("the redirect target received requests: %v", got)
	}
}
