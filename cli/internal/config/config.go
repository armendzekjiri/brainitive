// Package config stores the CLI's state: the server, the signed-in session and the defaults
// (workspace, project, board…) in one JSON file that only the user can read.
package config

import (
	"encoding/json"
	"errors"
	"net/url"
	"os"
	"path/filepath"
	"runtime"
	"strings"
)

// Session is the OAuth session with one Brainitive server.
type Session struct {
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
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, append(data, '\n'), 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, path)
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
		if strings.HasPrefix(lower, "localhost") || strings.HasPrefix(lower, "127.0.0.1") || strings.HasPrefix(lower, "[::1]") {
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
