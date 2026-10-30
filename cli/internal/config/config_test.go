package config

import (
	"os"
	"path/filepath"
	"testing"
)

func TestNormalizeServer(t *testing.T) {
	cases := map[string]string{
		"":                                   DefaultServer,
		"brainitive.example.com":             "https://brainitive.example.com",
		"https://brainitive.example.com/":    "https://brainitive.example.com",
		"https://brainitive.example.com/mcp": "https://brainitive.example.com",
		"localhost:8000":                     "http://localhost:8000",
		"http://127.0.0.1:8000/anything":     "http://127.0.0.1:8000",
		"  HTTPS://Brainitive.app/x ":        "https://Brainitive.app",
	}
	for in, want := range cases {
		if got := NormalizeServer(in); got != want {
			t.Errorf("NormalizeServer(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestLoadSaveRoundTrip(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "nested", "config.json")
	t.Setenv("BRAINITIVE_CONFIG", path)

	cfg, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Server != DefaultServer {
		t.Fatalf("fresh config server = %q", cfg.Server)
	}

	cfg.Server = "https://example.test"
	cfg.Defaults.Workspace = "acme"
	cfg.Session = &Session{ClientID: "c1", AccessToken: "tok", ExpiresAt: 42}
	if err := cfg.Save(); err != nil {
		t.Fatal(err)
	}

	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	if perm := info.Mode().Perm(); perm&0o077 != 0 {
		t.Errorf("config is readable by others: %o", perm)
	}

	again, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if again.Server != "https://example.test" || again.Defaults.Workspace != "acme" || again.Session == nil || again.Session.AccessToken != "tok" {
		t.Errorf("round trip lost data: %+v", again)
	}
}

func TestLoadRejectsInvalidJSON(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.json")
	t.Setenv("BRAINITIVE_CONFIG", path)
	if err := os.WriteFile(path, []byte("{nope"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := Load(); err == nil {
		t.Fatal("expected an error for invalid JSON")
	}
}
