package cli

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/spf13/cobra"

	"github.com/armendzekjiri/brainitive/cli/internal/auth"
	"github.com/armendzekjiri/brainitive/cli/internal/config"
	"github.com/armendzekjiri/brainitive/cli/internal/output"
)

// recorder is a fake server that remembers every request (method, path, Authorization).
type recorder struct {
	mu       sync.Mutex
	requests []string
	srv      *httptest.Server
}

func (r *recorder) seen() []string {
	r.mu.Lock()
	defer r.mu.Unlock()
	return append([]string(nil), r.requests...)
}

func newRecorder(t *testing.T, handler http.HandlerFunc) *recorder {
	t.Helper()
	rec := &recorder{}
	rec.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		rec.mu.Lock()
		rec.requests = append(rec.requests, r.Method+" "+r.URL.Path+" "+r.Header.Get("Authorization"))
		rec.mu.Unlock()
		if handler != nil {
			handler(w, r)
		}
	}))
	t.Cleanup(rec.srv.Close)
	return rec
}

// mcpReply answers list-workspaces like the real server (one workspace).
func mcpReply(w http.ResponseWriter, r *http.Request) {
	switch r.URL.Path {
	case "/mcp":
		var msg map[string]any
		json.NewDecoder(r.Body).Decode(&msg)
		json.NewEncoder(w).Encode(map[string]any{"jsonrpc": "2.0", "id": msg["id"], "result": map[string]any{
			"content": []any{}, "structuredContent": map[string]any{"workspaces": []any{map[string]any{"slug": "acme", "name": "Acme", "role": "admin"}}},
		}})
	case "/api/cli/logout":
		json.NewEncoder(w).Encode(map[string]string{"message": "Logged out."})
	default:
		http.NotFound(w, r)
	}
}

// runWith runs the CLI against a saved config; the caller sets BRAINITIVE_* env first.
func runWith(t *testing.T, cfg *config.Config, args ...string) (string, *config.Config, error) {
	t.Helper()
	if err := cfg.Save(); err != nil {
		t.Fatal(err)
	}
	app := &App{Version: "test"}
	root := app.rootCommand()
	var buf bytes.Buffer
	root.SetArgs(args)
	root.SetOut(&buf)
	root.SetErr(&buf)
	root.SetContext(context.Background())
	root.PersistentPreRunE = func(cmd *cobra.Command, _ []string) error {
		loaded, err := config.Load()
		if err != nil {
			return err
		}
		app.cfg = loaded
		app.out = &output.Printer{JSON: app.jsonMode(), Out: &buf, Err: &buf}
		return nil
	}
	err := root.Execute()
	after, loadErr := config.Load()
	if loadErr != nil {
		t.Fatal(loadErr)
	}
	return buf.String(), after, err
}

func signedInTo(server string) *config.Config {
	return &config.Config{Server: server, Session: &config.Session{
		Server: server, ClientID: "c1", TokenEndpoint: server + "/oauth/token",
		AccessToken: "token-A", RefreshToken: "refresh-A", ExpiresAt: time.Now().Add(time.Hour).Unix(),
	}}
}

// Codex CLI/extension review 1: a stored session's token only ever goes to the server it was
// issued by — never to a server picked by --server or BRAINITIVE_SERVER.
func TestStoredSessionNeverGoesToAnotherServer(t *testing.T) {
	a := newRecorder(t, mcpReply)
	b := newRecorder(t, mcpReply)
	t.Setenv("BRAINITIVE_CONFIG", filepath.Join(t.TempDir(), "config.json"))
	t.Setenv("BRAINITIVE_TOKEN", "")
	t.Setenv("BRAINITIVE_WORKSPACE", "")

	t.Setenv("BRAINITIVE_SERVER", b.srv.URL)
	if out, _, err := runWith(t, signedInTo(a.srv.URL), "workspaces"); err == nil || !strings.Contains(err.Error(), "brainitive login") {
		t.Fatalf("BRAINITIVE_SERVER=B with A's session: err = %v (%s)", err, out)
	}
	t.Setenv("BRAINITIVE_SERVER", "")
	if out, _, err := runWith(t, signedInTo(a.srv.URL), "--server", b.srv.URL, "workspaces"); err == nil || !strings.Contains(err.Error(), "brainitive login") {
		t.Fatalf("--server B with A's session: err = %v (%s)", err, out)
	}
	if got := b.seen(); len(got) != 0 {
		t.Fatalf("server B received requests: %v", got)
	}

	// The session still works on its own server.
	if out, _, err := runWith(t, signedInTo(a.srv.URL), "workspaces"); err != nil {
		t.Fatalf("own server: %v (%s)", err, out)
	}

	// Logout revokes at the session's server, whatever --server / BRAINITIVE_SERVER say.
	t.Setenv("BRAINITIVE_SERVER", b.srv.URL)
	out, after, err := runWith(t, signedInTo(a.srv.URL), "logout")
	if err != nil {
		t.Fatal(err, out)
	}
	if got := b.seen(); len(got) != 0 {
		t.Fatalf("logout sent requests to server B: %v", got)
	}
	if !contains(a.seen(), "DELETE /api/cli/logout Bearer token-A") || !strings.Contains(out, "token revoked") || !strings.Contains(out, a.srv.URL) {
		t.Fatalf("logout must revoke on A: requests %v, output %q", a.seen(), out)
	}
	if after.Session != nil {
		t.Fatal("logout must forget the session")
	}
}

// Codex CLI/extension review 11: a rotated refresh token that can't be saved is an error, not a
// silently used in-memory token (the next run would hold a refresh token that is used up).
func TestRefreshThatCannotBeSavedFails(t *testing.T) {
	srv := newRecorder(t, func(w http.ResponseWriter, r *http.Request) {
		json.NewEncoder(w).Encode(map[string]any{"access_token": "access-2", "refresh_token": "refresh-2", "expires_in": 3600})
	})
	blocker := filepath.Join(t.TempDir(), "not-a-dir")
	if err := os.WriteFile(blocker, nil, 0o600); err != nil {
		t.Fatal(err)
	}
	t.Setenv("BRAINITIVE_CONFIG", filepath.Join(blocker, "config.json")) // its "directory" is a file
	t.Setenv("BRAINITIVE_TOKEN", "")

	for _, renew := range []bool{false, true} {
		cfg := signedInTo(srv.srv.URL)
		cfg.Session.ExpiresAt = 0 // expiring: Token refreshes
		app := &App{cfg: cfg}
		tokens := &sessionTokens{app: app, server: srv.srv.URL}
		var token string
		var err error
		if renew {
			token, err = tokens.Renew(context.Background())
		} else {
			token, err = tokens.Token(context.Background())
		}
		if err == nil || token != "" || !strings.Contains(err.Error(), "couldn't save") {
			t.Fatalf("renew=%v: token %q, err %v; want a save error and no token", renew, token, err)
		}
	}
}

// Codex CLI/extension review 1: `login --server A` while BRAINITIVE_SERVER=B must call A
// (and only A) with the new token right after signing in.
func TestLoginUsesTheServerItSignedInTo(t *testing.T) {
	var a *recorder
	a = newRecorder(t, func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/.well-known/oauth-authorization-server":
			json.NewEncoder(w).Encode(map[string]string{
				"authorization_endpoint": a.srv.URL + "/oauth/authorize",
				"token_endpoint":         a.srv.URL + "/oauth/token",
				"registration_endpoint":  a.srv.URL + "/oauth/register",
			})
		case "/oauth/register":
			w.WriteHeader(201)
			json.NewEncoder(w).Encode(map[string]string{"client_id": "client-1"})
		case "/oauth/token":
			json.NewEncoder(w).Encode(map[string]any{"access_token": "new-A", "refresh_token": "refresh-A", "expires_in": 3600})
		default:
			mcpReply(w, r)
		}
	})
	b := newRecorder(t, mcpReply)
	oldBrowser := auth.OpenBrowser
	defer func() { auth.OpenBrowser = oldBrowser }()
	auth.OpenBrowser = func(u string) error {
		parsed, _ := url.Parse(u)
		q := parsed.Query()
		go http.Get(q.Get("redirect_uri") + "?state=" + q.Get("state") + "&code=code-1")
		return nil
	}

	t.Setenv("BRAINITIVE_CONFIG", filepath.Join(t.TempDir(), "config.json"))
	t.Setenv("BRAINITIVE_TOKEN", "")
	t.Setenv("BRAINITIVE_SERVER", b.srv.URL)
	out, after, err := runWith(t, &config.Config{Server: config.DefaultServer}, "login", "--server", a.srv.URL)
	if err != nil {
		t.Fatal(err, out)
	}
	if got := b.seen(); len(got) != 0 {
		t.Fatalf("server B received requests during login: %v", got)
	}
	if !contains(a.seen(), "POST /mcp Bearer new-A") {
		t.Fatalf("the post-login call must go to A: %v", a.seen())
	}
	if after.Server != a.srv.URL || after.Session == nil || after.Session.Server != a.srv.URL {
		t.Fatalf("saved config = %+v / %+v", after, after.Session)
	}
}
