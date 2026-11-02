// Package config stores the CLI's state: the server, the signed-in session and the defaults
// (workspace, project, board…) in one JSON file that only the user can read.
package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
)

// Session is the OAuth session with one Brainitive server.
type Session struct {
	// Server is the origin that issued the tokens. They are only ever sent there; another server
	// (--server, BRAINITIVE_SERVER) needs its own `brainitive login` (Codex CLI/extension review 1).
	Server        string `json:"server,omitempty"`
	ClientID      string `json:"client_id"`
	RedirectURI   string `json:"redirect_uri"`
	TokenEndpoint string `json:"token_endpoint"`
	AccessToken   string `json:"access_token"`
	RefreshToken  string `json:"refresh_token,omitempty"`
	ExpiresAt     int64  `json:"expires_at"` // unix seconds
	User          string `json:"user,omitempty"`
}

// Defaults are what commands use when no flag is given.
type Defaults struct {
	Workspace  string `json:"workspace,omitempty"`
	Project    string `json:"project,omitempty"`
	Board      string `json:"board,omitempty"`
	TaskList   string `json:"task_list,omitempty"`
	Collection string `json:"collection,omitempty"`
	Bookmarks  string `json:"bookmarks,omitempty"`
}

// Config is the whole file.
type Config struct {
	Server   string   `json:"server"`
	Session  *Session `json:"session,omitempty"`
	Defaults Defaults `json:"defaults"`
}

// DefaultServer is Brainitive Cloud; self-hosters pass --server once.
const DefaultServer = "https://www.brainitive.app"

const legacyCloudServer = "https://brainitive.app"

// Path is where the config lives: $BRAINITIVE_CONFIG, else ~/.config/brainitive/config.json
// (%APPDATA%\brainitive\config.json on Windows).
func Path() (string, error) {
	if p := os.Getenv("BRAINITIVE_CONFIG"); p != "" {
		return p, nil
	}
	var base string
	if runtime.GOOS == "windows" {
		base = os.Getenv("APPDATA")
	} else {
		base = os.Getenv("XDG_CONFIG_HOME")
	}
	if base == "" {
		home, err := os.UserHomeDir()
		if err != nil {
			return "", err
		}
		if runtime.GOOS == "windows" {
			base = filepath.Join(home, "AppData", "Roaming")
		} else {
			base = filepath.Join(home, ".config")
		}
	}
	return filepath.Join(base, "brainitive", "config.json"), nil
}

// Load reads the config, returning an empty one when the file doesn't exist yet.
func Load() (*Config, error) {
	cfg := &Config{Server: DefaultServer}
	path, err := Path()
	if err != nil {
		return cfg, err
	}
	data, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return cfg, nil
	}
	if err != nil {
		return cfg, err
	}
	if err := json.Unmarshal(data, cfg); err != nil {
		return cfg, errors.New("config file " + path + " is not valid JSON: " + err.Error())
	}
	if cfg.Server == "" || (cfg.Server == legacyCloudServer && cfg.Session == nil) {
		cfg.Server = DefaultServer
	}
	if cfg.Session != nil && cfg.Session.Server == "" {
		// Sessions saved before v1.49.0 carry no origin: they belong to the configured server.
		cfg.Session.Server = cfg.Server
	}
	return cfg, nil
}

// Save writes the config with owner-only permissions.
func (c *Config) Save() error {
	path, err := Path()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	data, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}
	return writePrivate(path, append(data, '\n'))
}

// writePrivate replaces path atomically with a file only the owner can read: a fresh, uniquely
// named temp file (O_EXCL, 0600 — never an existing file or a symlink someone left at a
// predictable name) is written, synced, chmodded 0600 and renamed over path. The rename also
// replaces an older, more permissive config.json (Codex CLI/extension review 7). On Windows the
// mode bits mean little; the file inherits the profile directory's ACL as before.
func writePrivate(path string, data []byte) error {
	f, err := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+".*.tmp")
	if err != nil {
		return err
	}
	tmp := f.Name()
	ok := false
	defer func() {
		if !ok {
			_ = f.Close()
			_ = os.Remove(tmp)
		}
	}()
	if err := f.Chmod(0o600); err != nil && runtime.GOOS != "windows" {
		return err
	}
	if _, err := f.Write(data); err != nil {
		return err
	}
	if err := f.Sync(); err != nil {
		return err
	}
	if err := f.Close(); err != nil {
		return err
	}
	if err := os.Rename(tmp, path); err != nil {
		return err
	}
	ok = true
	return nil
}

// NormalizeServer turns what a person types into an origin: "brainitive.example.com" →
// "https://brainitive.example.com"; local addresses without a scheme default to http.
func NormalizeServer(input string) string {
	s := strings.TrimSpace(input)
	if s == "" {
		return DefaultServer
	}
	lower := strings.ToLower(s)
	if !strings.HasPrefix(lower, "http://") && !strings.HasPrefix(lower, "https://") {
		// Only an exact loopback host gets the http default ("localhost.evil.test" is a remote host).
		if loopbackInput.MatchString(lower) {
			s = "http://" + s
		} else {
			s = "https://" + s
		}
	}
	// Origin only: drop any path, query or trailing slash.
	u, err := url.Parse(s)
	if err != nil || u.Host == "" {
		return strings.TrimRight(s, "/")
	}
	if strings.EqualFold(u.Scheme, "https") && strings.EqualFold(u.Host, "brainitive.app") {
		return DefaultServer
	}
	return strings.ToLower(u.Scheme) + "://" + u.Host
}

var loopbackInput = regexp.MustCompile(`^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?(/|$)`)

// IsLoopback reports whether host (no port) is exactly a loopback development address.
func IsLoopback(host string) bool {
	switch strings.ToLower(host) {
	case "localhost", "127.0.0.1", "::1":
		return true
	}
	return false
}

// CheckServer refuses a server origin the CLI must not send credentials to: anything but https,
// except plain http on an exact loopback address (localhost, 127.0.0.1, [::1]) for local
// development; credentials in the URL; a missing host. Bearer and refresh tokens would otherwise
// cross the network unencrypted (Codex CLI/extension review 6). There is no override flag.
func CheckServer(origin string) error {
	u, err := url.Parse(origin)
	if err != nil || u.Host == "" || u.Hostname() == "" {
		return fmt.Errorf("%q is not a valid server address", origin)
	}
	if u.User != nil {
		return fmt.Errorf("%s: a server address must not contain a user name or password", u.Redacted())
	}
	switch strings.ToLower(u.Scheme) {
	case "https":
		return nil
	case "http":
		if IsLoopback(u.Hostname()) {
			return nil
		}
		return fmt.Errorf("refusing plain http for %s: use https (plain http only works for localhost, 127.0.0.1 and [::1])", origin)
	}
	return fmt.Errorf("%q is not an http(s) server address", origin)
}
