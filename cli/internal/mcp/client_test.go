package mcp

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestToolName(t *testing.T) {
	for in, want := range map[string]string{
		"create_board_item":      "create-board-item-tool",
		"create-board-item":      "create-board-item-tool",
		"create-board-item-tool": "create-board-item-tool",
		" Search ":               "search",
		"fetch":                  "fetch",
		"sse-tool":               "sse-tool",
	} {
		if got := ToolName(in); got != want {
			t.Errorf("ToolName(%q) = %q, want %q", in, got, want)
		}
	}
}

type renewing struct {
	token  string
	renews int
}

func (r *renewing) Token(context.Context) (string, error) { return r.token, nil }
func (r *renewing) Renew(context.Context) (string, error) {
	r.renews++
	r.token = "fresh"
	return r.token, nil
}

func TestCallStructuredErrorsAndRenew(t *testing.T) {
	var calls []map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/mcp" || r.Header.Get("Accept") == "" {
			t.Errorf("bad request %s %v", r.URL.Path, r.Header)
		}
		if r.Header.Get("Authorization") != "Bearer fresh" {
			w.WriteHeader(401)
			w.Write([]byte(`{"message":"Unauthenticated."}`))
			return
		}
		var msg map[string]any
		json.NewDecoder(r.Body).Decode(&msg)
		calls = append(calls, msg)
		params := msg["params"].(map[string]any)
		switch params["name"] {
		case "list-workspaces-tool":
			json.NewEncoder(w).Encode(map[string]any{"jsonrpc": "2.0", "id": msg["id"], "result": map[string]any{
				"content":           []map[string]any{{"type": "text", "text": "{\"count\":1}"}},
				"structuredContent": map[string]any{"count": 1, "workspaces": []any{map[string]any{"slug": "acme"}}},
			}})
		case "create-label-tool":
			json.NewEncoder(w).Encode(map[string]any{"jsonrpc": "2.0", "id": msg["id"], "result": map[string]any{
				"content": []map[string]any{{"type": "text", "text": "No label \"x\" in this project."}},
				"isError": true,
			}})
		case "sse-tool":
			w.Header().Set("Content-Type", "text/event-stream")
			w.Write([]byte("event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"structuredContent\":{\"ok\":true}}}\n\n"))
		default:
			json.NewEncoder(w).Encode(map[string]any{"jsonrpc": "2.0", "id": msg["id"], "error": map[string]any{"code": -32602, "message": "Tool not found"}})
		}
	}))
	defer srv.Close()

	tokens := &renewing{token: "stale"}
	c := New(srv.URL, tokens, "test")

	res, err := c.Call(context.Background(), "list_workspaces", nil)
	if err != nil {
		t.Fatal(err)
	}
	if tokens.renews != 1 {
		t.Fatalf("expected one renew after 401, got %d", tokens.renews)
	}
	if res.Structured["count"].(float64) != 1 {
		t.Fatalf("structured = %v", res.Structured)
	}
	if args := calls[0]["params"].(map[string]any)["arguments"]; args == nil {
		t.Fatal("arguments must always be an object")
	}

	_, err = c.Call(context.Background(), "create-label", map[string]any{"name": "x"})
	var toolErr *ToolError
	if !errors.As(err, &toolErr) || !strings.Contains(toolErr.Message, "No label") {
		t.Fatalf("expected a ToolError, got %v", err)
	}

	_, err = c.Call(context.Background(), "nope", nil)
	var rpcErr *RPCError
	if !errors.As(err, &rpcErr) || rpcErr.Code != -32602 {
		t.Fatalf("expected an RPCError, got %v", err)
	}

	res, err = c.Call(context.Background(), "sse-tool", nil)
	if err != nil || res.Structured["ok"] != true {
		t.Fatalf("sse answer not parsed: %v %v", res, err)
	}
}

func TestStaticTokenCannotRenew(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(401) }))
	defer srv.Close()
	c := New(srv.URL, StaticToken("t"), "test")
	if _, err := c.Call(context.Background(), "x", nil); err == nil || !strings.Contains(err.Error(), "brainitive login") {
		t.Fatalf("got %v", err)
	}
}

func TestForwardNotificationAndSession(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var msg map[string]any
		json.NewDecoder(r.Body).Decode(&msg)
		if msg["method"] == "initialize" {
			w.Header().Set("MCP-Session-Id", "s-1")
			json.NewEncoder(w).Encode(map[string]any{"jsonrpc": "2.0", "id": msg["id"], "result": map[string]any{"protocolVersion": "2025-06-18"}})
			return
		}
		if r.Header.Get("MCP-Session-Id") != "s-1" {
			t.Errorf("session id not forwarded")
		}
		w.WriteHeader(202)
	}))
	defer srv.Close()
	c := New(srv.URL, StaticToken("t"), "test")
	out, status, err := c.Forward(context.Background(), []byte(`{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}`))
	if err != nil || status != 200 || !strings.Contains(string(out), "protocolVersion") || c.SessionID != "s-1" {
		t.Fatalf("initialize: %s %d %v session=%q", out, status, err, c.SessionID)
	}
	out, status, err = c.Forward(context.Background(), []byte(`{"jsonrpc":"2.0","method":"notifications/initialized"}`))
	if err != nil || status != 202 || out != nil {
		t.Fatalf("notification: %s %d %v", out, status, err)
	}
}

func TestLastEvent(t *testing.T) {
	got := lastEvent([]byte("data: first\n\n: comment\ndata: {\"a\":\ndata: 1}\n\n"))
	if string(got) != "{\"a\":\n1}" {
		t.Fatalf("lastEvent = %q", got)
	}
}
