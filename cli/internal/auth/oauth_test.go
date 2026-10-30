package auth

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"

	"github.com/armendzekjiri/brainitive/cli/internal/config"
)

func TestChallengeIsRFC7636(t *testing.T) {
	// Example from RFC 7636 appendix B.
	got := Challenge("dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk")
	if got != "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM" {
		t.Fatalf("challenge = %s", got)
	}
	p, err := NewPKCE()
	if err != nil || len(p.Verifier) != 64 || p.Challenge != Challenge(p.Verifier) {
		t.Fatalf("NewPKCE() = %+v, %v", p, err)
	}
}

// A fake Brainitive: metadata, registration, an authorize page that redirects straight back
// with a code, and a token endpoint that checks PKCE.
func TestLoginAndRefresh(t *testing.T) {
	var srv *httptest.Server
	var registered struct{ RedirectURI, Challenge string }
	mux := http.NewServeMux()
	mux.HandleFunc("/.well-known/oauth-authorization-server", func(w http.ResponseWriter, r *http.Request) {
		json.NewEncoder(w).Encode(map[string]string{
			"authorization_endpoint": srv.URL + "/oauth/authorize",
			"token_endpoint":         srv.URL + "/oauth/token",
			"registration_endpoint":  srv.URL + "/oauth/register",
		})
	})
	mux.HandleFunc("/oauth/register", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			ClientName   string   `json:"client_name"`
			RedirectURIs []string `json:"redirect_uris"`
		}
		json.NewDecoder(r.Body).Decode(&body)
		if body.ClientName != "Brainitive CLI" || len(body.RedirectURIs) != 1 || !strings.HasPrefix(body.RedirectURIs[0], "http://127.0.0.1:") {
			t.Errorf("unexpected registration %+v", body)
		}
		registered.RedirectURI = body.RedirectURIs[0]
		w.WriteHeader(201)
		json.NewEncoder(w).Encode(map[string]string{"client_id": "client-1"})
	})
	mux.HandleFunc("/oauth/token", func(w http.ResponseWriter, r *http.Request) {
		r.ParseForm()
		switch r.Form.Get("grant_type") {
		case "authorization_code":
			if r.Form.Get("code") != "code-1" || Challenge(r.Form.Get("code_verifier")) != registered.Challenge || r.Form.Get("client_id") != "client-1" {
				w.WriteHeader(400)
				json.NewEncoder(w).Encode(map[string]string{"error": "invalid_grant", "error_description": "bad code"})
				return
			}
			json.NewEncoder(w).Encode(map[string]any{"access_token": "access-1", "refresh_token": "refresh-1", "expires_in": 3600})
		case "refresh_token":
			if r.Form.Get("refresh_token") != "refresh-1" || r.Form.Get("scope") != Scope {
				w.WriteHeader(400)
				json.NewEncoder(w).Encode(map[string]string{"error": "invalid_grant"})
				return
			}
			json.NewEncoder(w).Encode(map[string]any{"access_token": "access-2", "refresh_token": "refresh-2", "expires_in": 3600})
		}
	})
	srv = httptest.NewServer(mux)
	defer srv.Close()

	// "The browser": parse the authorize URL and hit the CLI's callback like the server would.
	OpenBrowser = func(u string) error {
		parsed, _ := url.Parse(u)
		q := parsed.Query()
		if q.Get("scope") != Scope || q.Get("code_challenge_method") != "S256" || q.Get("redirect_uri") != registered.RedirectURI {
			t.Errorf("unexpected authorize url %s", u)
		}
		registered.Challenge = q.Get("code_challenge")
		go func() {
			time.Sleep(20 * time.Millisecond)
			http.Get(q.Get("redirect_uri") + "?state=" + q.Get("state") + "&code=code-1")
		}()
		return nil
	}

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	session, err := Login(ctx, srv.URL, func(string) {})
	if err != nil {
		t.Fatal(err)
	}
	if session.AccessToken != "access-1" || session.RefreshToken != "refresh-1" || session.ClientID != "client-1" || session.TokenEndpoint != srv.URL+"/oauth/token" {
		t.Fatalf("session = %+v", session)
	}
	if Expiring(session) {
		t.Fatal("a fresh token must not be expiring")
	}

	if err := Refresh(ctx, session); err != nil {
		t.Fatal(err)
	}
	if session.AccessToken != "access-2" || session.RefreshToken != "refresh-2" {
		t.Fatalf("refresh didn't rotate: %+v", session)
	}

	expired := &config.Session{TokenEndpoint: srv.URL + "/oauth/token", ClientID: "client-1", RefreshToken: "stale"}
	if err := Refresh(ctx, expired); err == nil || !strings.Contains(err.Error(), "brainitive login") {
		t.Fatalf("stale refresh should tell the user to log in, got %v", err)
	}
}

func TestLoginReportsCancel(t *testing.T) {
	var srv *httptest.Server
	mux := http.NewServeMux()
	mux.HandleFunc("/.well-known/oauth-authorization-server", func(w http.ResponseWriter, r *http.Request) {
		json.NewEncoder(w).Encode(map[string]string{"authorization_endpoint": srv.URL + "/a", "token_endpoint": srv.URL + "/t", "registration_endpoint": srv.URL + "/r"})
	})
	mux.HandleFunc("/r", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(201)
		json.NewEncoder(w).Encode(map[string]string{"client_id": "c"})
	})
	srv = httptest.NewServer(mux)
	defer srv.Close()
	OpenBrowser = func(u string) error {
		q, _ := url.Parse(u)
		go func() {
			cb := q.Query().Get("redirect_uri")
			// A drive-by request without our state is answered 400, escaped, and must not end the flow.
			res, err := http.Get(cb + "?error=x&error_description=%3Cscript%3Ealert(1)%3C/script%3E")
			if err == nil {
				body := make([]byte, 4096)
				n, _ := res.Body.Read(body)
				if res.StatusCode != 400 || strings.Contains(string(body[:n]), "<script>") {
					t.Errorf("stray callback: status %d body %q", res.StatusCode, body[:n])
				}
			}
			http.Get(cb + "?state=" + q.Query().Get("state") + "&error=access_denied")
		}()
		return nil
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if _, err := Login(ctx, srv.URL, func(string) {}); err == nil || !strings.Contains(err.Error(), "cancelled") {
		t.Fatalf("expected a cancel error, got %v", err)
	}
}

func TestDiscoverRejectsForeignEndpoints(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		json.NewEncoder(w).Encode(map[string]string{"authorization_endpoint": "https://evil.example/authorize", "token_endpoint": "https://evil.example/token", "registration_endpoint": "https://evil.example/register"})
	}))
	defer srv.Close()
	if _, err := Discover(context.Background(), srv.URL); err == nil || !strings.Contains(err.Error(), "another host") {
		t.Fatalf("expected a mix-up error, got %v", err)
	}
	if !sameOrigin("https://brainitive.app", "https://brainitive.app/oauth/token") || sameOrigin("https://brainitive.app", "http://brainitive.app/oauth/token") || !sameOrigin("http://localhost:8000", "http://localhost:8000/oauth/token") || sameOrigin("https://a.test", "custom://a.test/x") {
		t.Fatal("sameOrigin")
	}
}

func TestDiscoverRejectsNonBrainitive(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte("<html>")) }))
	defer srv.Close()
	if _, err := Discover(context.Background(), srv.URL); err == nil {
		t.Fatal("expected an error")
	}
}
