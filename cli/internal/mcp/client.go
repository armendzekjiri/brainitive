// Package mcp is a small JSON-RPC client for a Brainitive server's /mcp endpoint (the
// streamable HTTP transport). Every CLI command is a tools/call; `brainitive mcp` forwards raw
// messages through the same client.
package mcp

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"sync/atomic"
	"time"
)

// TokenSource hands out a valid bearer token and can renew it after a 401.
type TokenSource interface {
	Token(ctx context.Context) (string, error)
	Renew(ctx context.Context) (string, error)
}

// StaticToken is a TokenSource for a token that can't be refreshed (BRAINITIVE_TOKEN).
type StaticToken string

func (t StaticToken) Token(context.Context) (string, error) { return string(t), nil }
func (t StaticToken) Renew(context.Context) (string, error) {
	return "", errors.New("the token was rejected (expired or revoked). Run `brainitive login` or set a fresh BRAINITIVE_TOKEN")
}

// Client calls tools on one server.
type Client struct {
	Server    string
	Tokens    TokenSource
	HTTP      *http.Client
	UserAgent string
	// SessionID is the MCP-Session-Id the server handed out on initialize (proxy mode).
	SessionID string

	next atomic.Int64
}

// New makes a client for a server origin (no trailing slash).
func New(server string, tokens TokenSource, version string) *Client {
	return &Client{
		Server:    strings.TrimRight(server, "/"),
		Tokens:    tokens,
		HTTP:      &http.Client{Timeout: 120 * time.Second},
		UserAgent: "brainitive-cli/" + version,
	}
}

// ToolError is what a tool returned with isError (the message is written for the caller).
type ToolError struct{ Message string }

func (e *ToolError) Error() string { return e.Message }

// RPCError is a JSON-RPC level failure (unknown tool, invalid params…).
type RPCError struct {
	Code    int
	Message string
}

func (e *RPCError) Error() string { return fmt.Sprintf("%s (code %d)", e.Message, e.Code) }

// Result is a tool's answer: the structured content when the tool has an output schema,
// the text content otherwise.
type Result struct {
	Structured map[string]any
	Text       string
}

// ToolName turns any of "create_board_item", "create-board-item", "create-board-item-tool"
// into the server's name "create-board-item-tool".
func ToolName(name string) string {
	n := strings.ToLower(strings.TrimSpace(name))
	n = strings.ReplaceAll(n, "_", "-")
	// Single-word tools keep their name: `search` and `fetch` follow the ChatGPT deep-research
	// contract; every other tool is named after its class (create-board-item-tool).
	if !strings.HasSuffix(n, "-tool") && strings.Contains(n, "-") {
		n += "-tool"
	}
	return n
}

// Call runs one tool.
func (c *Client) Call(ctx context.Context, tool string, args map[string]any) (*Result, error) {
	if args == nil {
		args = map[string]any{}
	}
	raw, err := c.Request(ctx, "tools/call", map[string]any{"name": ToolName(tool), "arguments": args})
	if err != nil {
		return nil, err
	}
	var out struct {
		Content []struct {
			Type string `json:"type"`
			Text string `json:"text"`
		} `json:"content"`
		StructuredContent map[string]any `json:"structuredContent"`
		IsError           bool           `json:"isError"`
	}
	if err := json.Unmarshal(raw, &out); err != nil {
		return nil, fmt.Errorf("unexpected answer from the server: %w", err)
	}
	var text []string
	for _, part := range out.Content {
		if part.Type == "text" && part.Text != "" {
			text = append(text, part.Text)
		}
	}
	if out.IsError {
		msg := strings.Join(text, "\n")
		if msg == "" {
			msg = "the tool reported an error"
		}
		return nil, &ToolError{Message: msg}
	}
	return &Result{Structured: out.StructuredContent, Text: strings.Join(text, "\n")}, nil
}

// Tool describes one entry of tools/list.
type Tool struct {
	Name        string          `json:"name"`
	Description string          `json:"description"`
	InputSchema json.RawMessage `json:"inputSchema"`
}

// ListTools returns the server's tool catalogue.
func (c *Client) ListTools(ctx context.Context) ([]Tool, error) {
	raw, err := c.Request(ctx, "tools/list", map[string]any{})
	if err != nil {
		return nil, err
	}
	var out struct {
		Tools []Tool `json:"tools"`
	}
	if err := json.Unmarshal(raw, &out); err != nil {
		return nil, err
	}
	return out.Tools, nil
}

// Request sends one JSON-RPC request and returns its `result`.
func (c *Client) Request(ctx context.Context, method string, params any) (json.RawMessage, error) {
	id := c.next.Add(1)
	body, _ := json.Marshal(map[string]any{"jsonrpc": "2.0", "id": id, "method": method, "params": params})
	res, _, err := c.Forward(ctx, body)
	if err != nil {
		return nil, err
	}
	var msg struct {
		Result json.RawMessage `json:"result"`
		Error  *struct {
			Code    int    `json:"code"`
			Message string `json:"message"`
		} `json:"error"`
	}
	if err := json.Unmarshal(res, &msg); err != nil {
		return nil, fmt.Errorf("unexpected answer from the server: %w", err)
	}
	if msg.Error != nil {
		return nil, &RPCError{Code: msg.Error.Code, Message: msg.Error.Message}
	}
	return msg.Result, nil
}

// Forward posts a raw JSON-RPC message (request or notification) and returns the server's
// JSON message (empty for a notification) and the status code. A 401 renews the token once.
func (c *Client) Forward(ctx context.Context, message []byte) ([]byte, int, error) {
	token, err := c.Tokens.Token(ctx)
	if err != nil {
		return nil, 0, err
	}
	res, status, err := c.post(ctx, message, token)
	if status == http.StatusUnauthorized {
		token, err = c.Tokens.Renew(ctx)
		if err != nil {
			return nil, status, err
		}
		res, status, err = c.post(ctx, message, token)
	}
	return res, status, err
}

func (c *Client) post(ctx context.Context, message []byte, token string) ([]byte, int, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.Server+"/mcp", bytes.NewReader(message))
	if err != nil {
		return nil, 0, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json, text/event-stream")
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("User-Agent", c.UserAgent)
	if c.SessionID != "" {
		req.Header.Set("MCP-Session-Id", c.SessionID)
	}
	res, err := c.HTTP.Do(req)
	if err != nil {
		return nil, 0, fmt.Errorf("couldn't reach %s: %w", c.Server, err)
	}
	defer res.Body.Close()
	if sid := res.Header.Get("MCP-Session-Id"); sid != "" {
		c.SessionID = sid
	}
	raw, err := io.ReadAll(io.LimitReader(res.Body, 64<<20))
	if err != nil {
		return nil, res.StatusCode, err
	}
	switch {
	case res.StatusCode == http.StatusUnauthorized:
		return nil, res.StatusCode, errors.New("not signed in. Run `brainitive login`")
	case res.StatusCode == http.StatusForbidden:
		return nil, res.StatusCode, errors.New("this account or token may not use the MCP server: " + firstLine(raw))
	case res.StatusCode == http.StatusAccepted || (res.StatusCode == http.StatusOK && len(bytes.TrimSpace(raw)) == 0):
		return nil, res.StatusCode, nil
	case res.StatusCode >= 300:
		return nil, res.StatusCode, fmt.Errorf("the server answered HTTP %d: %s", res.StatusCode, firstLine(raw))
	}
	if strings.HasPrefix(res.Header.Get("Content-Type"), "text/event-stream") {
		return lastEvent(raw), res.StatusCode, nil
	}
	return raw, res.StatusCode, nil
}

// lastEvent takes the final `data:` payload of an SSE body (the response message).
func lastEvent(raw []byte) []byte {
	var last []byte
	var current []string
	scanner := bufio.NewScanner(bytes.NewReader(raw))
	scanner.Buffer(make([]byte, 1<<20), 64<<20)
	flush := func() {
		if len(current) > 0 {
			last = []byte(strings.Join(current, "\n"))
			current = nil
		}
	}
	for scanner.Scan() {
		line := scanner.Text()
		switch {
		case line == "":
			flush()
		case strings.HasPrefix(line, "data:"):
			current = append(current, strings.TrimPrefix(strings.TrimPrefix(line, "data:"), " "))
		}
	}
	flush()
	return last
}

func firstLine(raw []byte) string {
	var body struct {
		Message string `json:"message"`
		Error   string `json:"error"`
	}
	if json.Unmarshal(raw, &body) == nil {
		if body.Message != "" {
			return body.Message
		}
		if body.Error != "" {
			return body.Error
		}
	}
	s := strings.TrimSpace(string(raw))
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		s = s[:i]
	}
	if len(s) > 200 {
		s = s[:200] + "…"
	}
	return s
}
