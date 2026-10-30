// Package auth signs the CLI in to a Brainitive server the way the browser extension does:
// OAuth 2.1 with PKCE, the client registered on the fly (Dynamic Client Registration), the
// authorization code delivered to a loopback port. No API key to copy, works on any server.
package auth

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"html"
	"io"
	"net"
	"net/http"
	"net/url"
	"os/exec"
	"runtime"
	"strings"
	"time"

	"github.com/armendzekjiri/brainitive/cli/internal/config"
)

// Scope: everything the MCP server offers plus the extension API (screenshots on issues).
const Scope = "mcp:use capture"

// Metadata is the part of /.well-known/oauth-authorization-server the CLI needs.
type Metadata struct {
	AuthorizationEndpoint string `json:"authorization_endpoint"`
	TokenEndpoint         string `json:"token_endpoint"`
	RegistrationEndpoint  string `json:"registration_endpoint"`
}

type tokenResponse struct {
	AccessToken  string `json:"access_token"`
	RefreshToken string `json:"refresh_token"`
	ExpiresIn    int64  `json:"expires_in"`
	Error        string `json:"error"`
	ErrorDesc    string `json:"error_description"`
	Message      string `json:"message"`
}

// HTTPClient is what the package uses for every request (tests swap it).
var HTTPClient = &http.Client{Timeout: 30 * time.Second}

// OpenBrowser opens the sign-in page; tests replace it.
var OpenBrowser = func(u string) error {
	switch runtime.GOOS {
	case "darwin":
		return exec.Command("open", u).Start()
	case "windows":
		return exec.Command("rundll32", "url.dll,FileProtocolHandler", u).Start()
	default:
		return exec.Command("xdg-open", u).Start()
	}
}

// Discover reads the server's OAuth metadata.
func Discover(ctx context.Context, server string) (*Metadata, error) {
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, server+"/.well-known/oauth-authorization-server", nil)
	req.Header.Set("Accept", "application/json")
	res, err := HTTPClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("couldn't reach %s: %w", server, err)
	}
	defer res.Body.Close()
	var m Metadata
	if res.StatusCode != http.StatusOK || json.NewDecoder(res.Body).Decode(&m) != nil || m.AuthorizationEndpoint == "" || m.TokenEndpoint == "" || m.RegistrationEndpoint == "" {
		return nil, fmt.Errorf("%s doesn't look like a Brainitive server (no OAuth metadata)", server)
	}
	// Mix-up defence (RFC 8414 §3.3): every endpoint must live on the server we were given — the
	// authorize URL is handed to the OS browser and the code + verifier go to the token endpoint.
	for _, endpoint := range []string{m.AuthorizationEndpoint, m.TokenEndpoint, m.RegistrationEndpoint} {
		if !sameOrigin(server, endpoint) {
			return nil, fmt.Errorf("%s's OAuth metadata points at another host (%s); refusing to continue", server, endpoint)
		}
	}
	return &m, nil
}

// sameOrigin reports whether endpoint is an http(s) URL on the server's host (http only when the
// server itself is plain http, i.e. a local address).
func sameOrigin(server, endpoint string) bool {
	s, err1 := url.Parse(server)
	e, err2 := url.Parse(endpoint)
	if err1 != nil || err2 != nil || e.Host == "" {
		return false
	}
	if e.Scheme != "https" && !(e.Scheme == "http" && s.Scheme == "http") {
		return false
	}
	return strings.EqualFold(e.Host, s.Host)
}

// Register creates an OAuth client for this redirect URI and returns its id.
func Register(ctx context.Context, m *Metadata, redirectURI string) (string, error) {
	body, _ := json.Marshal(map[string]any{
		"client_name":   "Brainitive CLI",
		"redirect_uris": []string{redirectURI},
	})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, m.RegistrationEndpoint, strings.NewReader(string(body)))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	res, err := HTTPClient.Do(req)
	if err != nil {
		return "", err
	}
	defer res.Body.Close()
	var out struct {
		ClientID  string `json:"client_id"`
		ErrorDesc string `json:"error_description"`
	}
	_ = json.NewDecoder(res.Body).Decode(&out)
	if res.StatusCode >= 300 || out.ClientID == "" {
		if out.ErrorDesc == "" {
			out.ErrorDesc = "the server refused to register the CLI"
		}
		return "", errors.New(out.ErrorDesc)
	}
	return out.ClientID, nil
}

// PKCE holds a verifier and its S256 challenge.
type PKCE struct {
	Verifier  string
	Challenge string
}

// NewPKCE makes a fresh verifier / challenge pair.
func NewPKCE() (PKCE, error) {
	verifier, err := randomString(64)
	if err != nil {
		return PKCE{}, err
	}
	return PKCE{Verifier: verifier, Challenge: Challenge(verifier)}, nil
}

// Challenge is base64url(sha256(verifier)) without padding (RFC 7636).
func Challenge(verifier string) string {
	sum := sha256.Sum256([]byte(verifier))
	return base64.RawURLEncoding.EncodeToString(sum[:])
}

func randomString(n int) (string, error) {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return base64.RawURLEncoding.EncodeToString(b)[:n], nil
}

// Login runs the whole flow and returns a session ready to store. `status` gets progress lines.
func Login(ctx context.Context, server string, status func(string)) (*config.Session, error) {
	m, err := Discover(ctx, server)
	if err != nil {
		return nil, err
	}

	// Listen first so the redirect URI (with its port) is known when the client registers.
	listener, err := listen()
	if err != nil {
		return nil, fmt.Errorf("couldn't open a local port for the sign-in callback: %w", err)
	}
	defer listener.Close()
	redirectURI := fmt.Sprintf("http://127.0.0.1:%d/callback", listener.Addr().(*net.TCPAddr).Port)

	clientID, err := Register(ctx, m, redirectURI)
	if err != nil {
		return nil, err
	}

	pkce, err := NewPKCE()
	if err != nil {
		return nil, err
	}
	state, err := randomString(32)
	if err != nil {
		return nil, err
	}

	authorize, _ := url.Parse(m.AuthorizationEndpoint)
	q := url.Values{
		"response_type":         {"code"},
		"client_id":             {clientID},
		"redirect_uri":          {redirectURI},
		"scope":                 {Scope},
		"state":                 {state},
		"code_challenge":        {pkce.Challenge},
		"code_challenge_method": {"S256"},
	}
	authorize.RawQuery = q.Encode()

	codeCh := make(chan string, 1)
	errCh := make(chan error, 1)
	srv := &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/callback" {
			http.NotFound(w, r)
			return
		}
		params := r.URL.Query()
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		// Only a response that carries our state may end the flow: a stray request (a drive-by
		// page hitting the well-known port, a wrong state) is answered and ignored, so the real
		// redirect a second later still finds the listener.
		if params.Get("state") != state {
			w.WriteHeader(http.StatusBadRequest)
			fmt.Fprint(w, page("Not this sign-in", "This window doesn't belong to the current sign-in. Go back to the terminal and run the command again."))
			return
		}
		if e := params.Get("error"); e != "" {
			msg := params.Get("error_description")
			if e == "access_denied" || msg == "" {
				msg = "Sign-in was cancelled."
			}
			fmt.Fprint(w, page("Sign-in didn't complete", msg))
			errCh <- errors.New(msg)
			return
		}
		if params.Get("code") == "" {
			fmt.Fprint(w, page("Sign-in didn't complete", "The response didn't match the request. Run the command again."))
			errCh <- errors.New("sign-in failed: the response didn't match the request")
			return
		}
		fmt.Fprint(w, page("You're signed in", "You can close this window and go back to the terminal."))
		codeCh <- params.Get("code")
	})}
	go func() { _ = srv.Serve(listener) }()
	defer srv.Close()

	status("Opening your browser to sign in to " + server + " …")
	if err := OpenBrowser(authorize.String()); err != nil {
		status("Couldn't open a browser. Open this address yourself:")
	} else {
		status("If the browser didn't open, use this address:")
	}
	status("  " + authorize.String())

	var code string
	select {
	case code = <-codeCh:
	case err := <-errCh:
		return nil, err
	case <-ctx.Done():
		return nil, errors.New("sign-in timed out")
	}

	tokens, err := tokenRequest(ctx, m.TokenEndpoint, url.Values{
		"grant_type":    {"authorization_code"},
		"client_id":     {clientID},
		"redirect_uri":  {redirectURI},
		"code_verifier": {pkce.Verifier},
		"code":          {code},
	})
	if err != nil {
		return nil, err
	}

	return &config.Session{
		ClientID:      clientID,
		RedirectURI:   redirectURI,
		TokenEndpoint: m.TokenEndpoint,
		AccessToken:   tokens.AccessToken,
		RefreshToken:  tokens.RefreshToken,
		ExpiresAt:     time.Now().Unix() + tokens.ExpiresIn,
	}, nil
}

// Refresh exchanges the refresh token for a new pair. Refresh tokens are single use.
func Refresh(ctx context.Context, s *config.Session) error {
	if s.RefreshToken == "" {
		return errors.New("your Brainitive session expired. Run `brainitive login` again")
	}
	tokens, err := tokenRequest(ctx, s.TokenEndpoint, url.Values{
		"grant_type":    {"refresh_token"},
		"client_id":     {s.ClientID},
		"refresh_token": {s.RefreshToken},
		"scope":         {Scope},
	})
	if err != nil {
		return fmt.Errorf("your Brainitive session expired (%v). Run `brainitive login` again", err)
	}
	s.AccessToken = tokens.AccessToken
	if tokens.RefreshToken != "" {
		s.RefreshToken = tokens.RefreshToken
	}
	s.ExpiresAt = time.Now().Unix() + tokens.ExpiresIn
	return nil
}

// Expiring reports whether the access token ends within a minute.
func Expiring(s *config.Session) bool {
	return s.ExpiresAt-60 < time.Now().Unix()
}

func tokenRequest(ctx context.Context, endpoint string, form url.Values) (*tokenResponse, error) {
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Accept", "application/json")
	res, err := HTTPClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(res.Body, 1<<20))
	var t tokenResponse
	_ = json.Unmarshal(raw, &t)
	if res.StatusCode >= 300 || t.AccessToken == "" {
		code := t.Error
		if code == "" {
			code = "token_error"
		}
		desc := t.ErrorDesc
		if desc == "" {
			desc = t.Message
		}
		if desc == "" {
			desc = "sign-in failed"
		}
		return nil, fmt.Errorf("%s: %s", code, desc)
	}
	return &t, nil
}

// listen prefers a stable port (so a server that caches the client by redirect URI can reuse
// it) and falls back to any free one.
func listen() (net.Listener, error) {
	for _, port := range []int{52817, 52818, 52819} {
		if l, err := net.Listen("tcp", fmt.Sprintf("127.0.0.1:%d", port)); err == nil {
			return l, nil
		}
	}
	return net.Listen("tcp", "127.0.0.1:0")
}

func page(title, text string) string {
	title, text = html.EscapeString(title), html.EscapeString(text)
	return `<!doctype html><meta charset="utf-8"><title>` + title + `</title>
<body style="font-family:system-ui,sans-serif;display:grid;place-items:center;height:100vh;margin:0;background:#f6f8fa;color:#485E72">
<div style="text-align:center"><h1 style="font-size:1.5rem">` + title + `</h1><p>` + text + `</p></div></body>`
}
