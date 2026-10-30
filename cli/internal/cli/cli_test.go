package cli

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"

	"github.com/spf13/cobra"

	"github.com/armendzekjiri/brainitive/cli/internal/config"
	"github.com/armendzekjiri/brainitive/cli/internal/output"
)

func TestParsePair(t *testing.T) {
	cases := []struct {
		in   string
		key  string
		want any
	}{
		{"name=urgent", "name", "urgent"},
		{"color=#ef4444", "color", "#ef4444"},
		{"wip_limit=3", "wip_limit", int64(3)},
		{"budget=12.5", "budget", 12.5},
		{"is_done=true", "is_done", true},
		{"parent_id=null", "parent_id", nil},
		{`labels=["a","b"]`, "labels", []any{"a", "b"}},
		{`fields={"Customer":"ACME"}`, "fields", map[string]any{"Customer": "ACME"}},
		{"key:=0042", "key", "0042"},
		{"title=a=b", "title", "a=b"},
	}
	for _, c := range cases {
		key, got, err := parsePair(c.in)
		if err != nil || key != c.key {
			t.Fatalf("parsePair(%q) = %q, %v, %v", c.in, key, got, err)
		}
		gotJSON, _ := json.Marshal(got)
		wantJSON, _ := json.Marshal(c.want)
		if string(gotJSON) != string(wantJSON) {
			t.Errorf("parsePair(%q) = %s, want %s", c.in, gotJSON, wantJSON)
		}
	}
	if _, _, err := parsePair("novalue"); err == nil {
		t.Error("expected an error without '='")
	}
}

func TestAssetNameAndChecksum(t *testing.T) {
	if AssetName("darwin", "arm64") != "brainitive_darwin_arm64.tar.gz" || AssetName("windows", "amd64") != "brainitive_windows_amd64.zip" {
		t.Fatal("asset names must match the release workflow")
	}
	archive := []byte("hello")
	sum := sha256.Sum256(archive)
	sums := "deadbeef  other.tar.gz\n" + hex.EncodeToString(sum[:]) + "  brainitive_linux_amd64.tar.gz\n"
	if !checksumMatches([]byte(sums), "brainitive_linux_amd64.tar.gz", archive) {
		t.Fatal("matching checksum rejected")
	}
	if checksumMatches([]byte(sums), "brainitive_linux_amd64.tar.gz", []byte("tampered")) {
		t.Fatal("wrong checksum accepted")
	}
}

func TestExtractBinaryFromTarGz(t *testing.T) {
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	for name, body := range map[string]string{"README.md": "docs", "brainitive": "#!binary"} {
		tw.WriteHeader(&tar.Header{Name: name, Mode: 0o755, Size: int64(len(body)), Typeflag: tar.TypeReg})
		tw.Write([]byte(body))
	}
	tw.Close()
	gz.Close()
	got, err := extractBinary(buf.Bytes(), "brainitive_linux_amd64.tar.gz")
	if err != nil || string(got) != "#!binary" {
		t.Fatalf("extractBinary = %q, %v", got, err)
	}
}

// A fake server with two workspaces, one project each, to exercise context resolution and
// a command end to end through cobra.
func fakeServer(t *testing.T) (*httptest.Server, *[]map[string]any) {
	var calls []map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var msg map[string]any
		json.NewDecoder(r.Body).Decode(&msg)
		params, _ := msg["params"].(map[string]any)
		name, _ := params["name"].(string)
		args, _ := params["arguments"].(map[string]any)
		calls = append(calls, map[string]any{"name": name, "args": args})
		reply := func(structured map[string]any) {
			json.NewEncoder(w).Encode(map[string]any{"jsonrpc": "2.0", "id": msg["id"], "result": map[string]any{"content": []any{}, "structuredContent": structured}})
		}
		switch name {
		case "list-workspaces-tool":
			reply(map[string]any{"workspaces": []any{map[string]any{"slug": "acme", "name": "Acme", "role": "admin"}, map[string]any{"slug": "home", "name": "Home", "role": "admin"}}, "count": 2})
		case "list-projects-tool":
			reply(map[string]any{"projects": []any{map[string]any{"slug": "shop", "name": "Shop", "key": "SHOP"}}, "count": 1})
		case "list-boards-tool":
			reply(map[string]any{"boards": []any{map[string]any{"slug": "main-board", "name": "Main Board"}}, "count": 1})
		case "get-board-tool":
			reply(map[string]any{"columns": []any{}})
		case "list-task-lists-tool":
			reply(map[string]any{"task_lists": []any{map[string]any{"slug": "todo"}}})
		case "get-task-list-tool":
			reply(map[string]any{"tasks": []any{}})
		case "search":
			reply(map[string]any{"results": []any{}})
		case "capture-to-inbox-tool":
			reply(map[string]any{"created": true, "capture_id": "u1", "location": map[string]any{"title": "Call the bank", "breadcrumb": "Captures", "url": srv0 + "/captures"}})
		case "create-board-item-tool":
			reply(map[string]any{"created": true, "item_id": 7, "item_key": "SHOP-7", "column": "To Do", "location": map[string]any{"breadcrumb": "Acme › Shop › Main Board", "url": srv0 + "/i/7"}})
		default:
			json.NewEncoder(w).Encode(map[string]any{"jsonrpc": "2.0", "id": msg["id"], "error": map[string]any{"code": -32601, "message": "unknown tool " + name}})
		}
	}))
	srv0 = srv.URL
	return srv, &calls
}

var srv0 string

func run(t *testing.T, server string, args ...string) (string, error) {
	t.Helper()
	t.Setenv("BRAINITIVE_CONFIG", filepath.Join(t.TempDir(), "config.json"))
	t.Setenv("BRAINITIVE_TOKEN", "test-token")
	t.Setenv("BRAINITIVE_SERVER", server)
	t.Setenv("BRAINITIVE_WORKSPACE", "")
	cfg := &config.Config{Server: server}
	_ = cfg.Save()

	app := &App{Version: "test"}
	root := app.rootCommand()
	var buf bytes.Buffer
	root.SetArgs(args)
	root.SetOut(&buf)
	root.SetErr(&buf)
	root.SetContext(context.Background())
	// The printer writes to os.Stdout by default; capture through a hook instead.
	root.PersistentPreRunE = func(cmd *cobra.Command, _ []string) error {
		cfg, err := config.Load()
		if err != nil {
			return err
		}
		app.cfg = cfg
		app.out = &output.Printer{JSON: app.jsonMode(), Out: &buf, Err: &buf}
		return nil
	}
	err := root.Execute()
	return buf.String(), err
}

func TestCaptureNeedsNoContext(t *testing.T) {
	srv, calls := fakeServer(t)
	defer srv.Close()
	out, err := run(t, srv.URL, "capture", "Call the bank", "--url", "https://bank.example")
	if err != nil {
		t.Fatal(err, out)
	}
	last := (*calls)[len(*calls)-1]
	if last["name"] != "capture-to-inbox-tool" {
		t.Fatalf("called %v", last)
	}
	args := last["args"].(map[string]any)
	if args["text"] != "Call the bank" || args["url"] != "https://bank.example" || args["source"] != "CLI" || !strings.HasPrefix(args["request_id"].(string), "cli-") {
		t.Fatalf("args = %v", args)
	}
	if !strings.Contains(out, "Captured Call the bank") || !strings.Contains(out, "/captures") {
		t.Fatalf("output = %q", out)
	}
}

func TestIssueCreateAsksForWorkspaceWhenAmbiguous(t *testing.T) {
	srv, _ := fakeServer(t)
	defer srv.Close()
	_, err := run(t, srv.URL, "issue", "create", "Something")
	if err == nil || !strings.Contains(err.Error(), "which workspace") || !strings.Contains(err.Error(), "acme, home") {
		t.Fatalf("expected a which-workspace error, got %v", err)
	}
}

func TestIssueCreateResolvesSingleProjectAndBoard(t *testing.T) {
	srv, calls := fakeServer(t)
	defer srv.Close()
	out, err := run(t, srv.URL, "-w", "acme", "issue", "create", "Fix", "checkout", "--type", "Bug", "--label", "ui", "--label", "urgent", "--points", "3", "--json")
	if err != nil {
		t.Fatal(err, out)
	}
	last := (*calls)[len(*calls)-1]
	args := last["args"].(map[string]any)
	if last["name"] != "create-board-item-tool" || args["workspace"] != "acme" || args["project"] != "shop" || args["board"] != "main-board" {
		t.Fatalf("context not resolved: %v", last)
	}
	if args["title"] != "Fix checkout" || args["type"] != "Bug" || args["story_points"].(float64) != 3 || len(args["labels"].([]any)) != 2 {
		t.Fatalf("args = %v", args)
	}
	var parsed map[string]any
	if err := json.Unmarshal([]byte(out), &parsed); err != nil || parsed["item_key"] != "SHOP-7" {
		t.Fatalf("--json must print the structured result verbatim: %q", out)
	}
}

func TestToolCommandFillsContextAndTypesValues(t *testing.T) {
	srv, calls := fakeServer(t)
	defer srv.Close()
	// tools/list is needed for context filling; the fake answers it with the method name.
	toolsSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var msg map[string]any
		json.NewDecoder(r.Body).Decode(&msg)
		if msg["method"] == "tools/list" {
			json.NewEncoder(w).Encode(map[string]any{"jsonrpc": "2.0", "id": msg["id"], "result": map[string]any{"tools": []any{
				map[string]any{"name": "create-board-item-tool", "description": "x", "inputSchema": map[string]any{"type": "object", "properties": map[string]any{"workspace": map[string]any{}, "project": map[string]any{}, "board": map[string]any{}, "title": map[string]any{}}}},
				map[string]any{"name": "list-workspaces-tool", "description": "x", "inputSchema": map[string]any{"type": "object", "properties": map[string]any{}}},
				map[string]any{"name": "list-projects-tool", "description": "x", "inputSchema": map[string]any{"type": "object", "properties": map[string]any{"workspace": map[string]any{}}}},
				map[string]any{"name": "list-boards-tool", "description": "x", "inputSchema": map[string]any{"type": "object", "properties": map[string]any{"workspace": map[string]any{}, "project": map[string]any{}}}},
			}}})
			return
		}
		// Everything else: hand over to the shared fake.
		body, _ := json.Marshal(msg)
		res, err := http.Post(srv.URL+"/mcp", "application/json", bytes.NewReader(body))
		if err != nil {
			w.WriteHeader(500)
			return
		}
		defer res.Body.Close()
		w.WriteHeader(res.StatusCode)
		var raw bytes.Buffer
		raw.ReadFrom(res.Body)
		w.Write(raw.Bytes())
	}))
	defer toolsSrv.Close()

	out, err := run(t, toolsSrv.URL, "-w", "acme", "tool", "create_board_item", "title=Hi", "story_points=5")
	if err != nil {
		t.Fatal(err, out)
	}
	last := (*calls)[len(*calls)-1]
	args := last["args"].(map[string]any)
	if args["board"] != "main-board" || args["story_points"].(float64) != 5 || args["title"] != "Hi" {
		t.Fatalf("args = %v", args)
	}
	if !strings.Contains(out, `"item_key": "SHOP-7"`) {
		t.Fatalf("tool output must be the JSON result: %q", out)
	}
}

func TestProxyForwardsRequestsAndSwallowsNotifications(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var msg map[string]any
		json.NewDecoder(r.Body).Decode(&msg)
		if msg["id"] == nil {
			w.WriteHeader(202)
			return
		}
		json.NewEncoder(w).Encode(map[string]any{"jsonrpc": "2.0", "id": msg["id"], "result": map[string]any{"echo": msg["method"]}})
	}))
	defer srv.Close()
	t.Setenv("BRAINITIVE_TOKEN", "t")
	app := &App{Version: "test", cfg: &config.Config{Server: srv.URL}}
	in := strings.NewReader(`{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}` + "\n" +
		`{"jsonrpc":"2.0","method":"notifications/initialized"}` + "\n" +
		`not json` + "\n" +
		`{"jsonrpc":"2.0","id":"abc","method":"tools/list"}` + "\n")
	var out bytes.Buffer
	if err := app.proxy(context.Background(), in, &out); err != nil {
		t.Fatal(err)
	}
	lines := strings.Split(strings.TrimSpace(out.String()), "\n")
	if len(lines) != 3 {
		t.Fatalf("expected 3 output lines (2 responses + 1 parse error), got %d: %s", len(lines), out.String())
	}
	if !strings.Contains(lines[0], `"echo":"initialize"`) || !strings.Contains(lines[1], "Parse error") || !strings.Contains(lines[2], `"id":"abc"`) {
		t.Fatalf("unexpected proxy output: %s", out.String())
	}
}

func TestQuickSearchAndCapture(t *testing.T) {
	srv, calls := fakeServer(t)
	defer srv.Close()
	for _, tc := range []struct{ flag, text, tool, field string }{
		{"-s", "everything here", "search", "query"},
		{"-s", "issues", "search", "query"},
		{"-c", "search", "capture-to-inbox-tool", "text"},
		{"-c", "Call the bank", "capture-to-inbox-tool", "text"},
	} {
		out, err := run(t, srv.URL, tc.flag, tc.text)
		if err != nil {
			t.Fatalf("%s: %v (%s)", tc.flag, err, out)
		}
		last := (*calls)[len(*calls)-1]
		args := last["args"].(map[string]any)
		if last["name"] != tc.tool || args[tc.field] != tc.text {
			t.Fatalf("%s: %v", tc.flag, last)
		}
	}
	if _, err := run(t, srv.URL, "-s", " "); err == nil {
		t.Fatal("empty search must fail")
	}
	if _, err := run(t, srv.URL, "-c", " "); err == nil {
		t.Fatal("empty capture must fail")
	}
	if _, err := run(t, srv.URL, "-s", "query", "-c", "note"); err == nil {
		t.Fatal("conflicting shortcuts must fail")
	}
}

func TestQuickListActions(t *testing.T) {
	srv, calls := fakeServer(t)
	defer srv.Close()
	for _, tc := range []struct{ flag, tool string }{
		{"-W", "list-workspaces-tool"}, {"-P", "list-projects-tool"},
		{"-B", "list-boards-tool"}, {"-i", "get-board-tool"}, {"-t", "get-task-list-tool"},
	} {
		out, err := run(t, srv.URL, "-w", "acme", "-p", "shop", "-b", "main-board", tc.flag, "-j")
		if err != nil {
			t.Fatalf("%s: %v (%s)", tc.flag, err, out)
		}
		last := (*calls)[len(*calls)-1]
		if last["name"] != tc.tool {
			t.Fatalf("%s called %v", tc.flag, last)
		}
		var parsed map[string]any
		if json.Unmarshal([]byte(out), &parsed) != nil {
			t.Fatalf("%s must output JSON: %q", tc.flag, out)
		}
	}
	if _, err := run(t, srv.URL, "-W", "-i"); err == nil {
		t.Fatal("multiple actions must fail")
	}
}
