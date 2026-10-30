// Package api covers the one thing MCP can't do: upload a file. The browser-extension API
// (/api/ext/v1, scope `capture`) creates an issue with a screenshot in one multipart request.
package api

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/armendzekjiri/brainitive/cli/internal/mcp"
)

// Client is the extension API client.
type Client struct {
	Server string
	Tokens mcp.TokenSource
	HTTP   *http.Client
}

// New makes a client for a server origin.
func New(server string, tokens mcp.TokenSource) *Client {
	return &Client{Server: strings.TrimRight(server, "/"), Tokens: tokens, HTTP: &http.Client{Timeout: 120 * time.Second}}
}

// Board is a board the user may create issues on, with its numeric id.
type Board struct {
	ID      int64  `json:"id"`
	Name    string `json:"name"`
	Columns []struct {
		ID   int64  `json:"id"`
		Name string `json:"name"`
	} `json:"columns"`
}

// Project is a project of the destinations listing.
type Project struct {
	ID     int64   `json:"id"`
	Name   string  `json:"name"`
	Key    string  `json:"key"`
	Boards []Board `json:"boards"`
}

// Workspace is a workspace of the destinations listing.
type Workspace struct {
	ID       int64     `json:"id"`
	Name     string    `json:"name"`
	Projects []Project `json:"projects"`
}

// Logout revokes the current access token and its refresh token on the server.
func (c *Client) Logout(ctx context.Context) error {
	var out struct {
		Message string `json:"message"`
	}
	return c.do(ctx, http.MethodDelete, "/api/cli/logout", "", nil, &out)
}

// Destinations lists where the user can save things (numeric ids).
func (c *Client) Destinations(ctx context.Context) ([]Workspace, error) {
	var out struct {
		Workspaces []Workspace `json:"workspaces"`
	}
	if err := c.do(ctx, http.MethodGet, "/api/ext/v1/destinations", "", nil, &out); err != nil {
		return nil, err
	}
	return out.Workspaces, nil
}

// FindBoard locates a board by project key and board name (both case-insensitive).
func FindBoard(workspaces []Workspace, projectKey, boardName string) (*Board, error) {
	for _, ws := range workspaces {
		for _, p := range ws.Projects {
			if !strings.EqualFold(p.Key, projectKey) {
				continue
			}
			for i := range p.Boards {
				if strings.EqualFold(p.Boards[i].Name, boardName) {
					return &p.Boards[i], nil
				}
			}
		}
	}
	return nil, fmt.Errorf("couldn't find the board %q in project %s among the boards you can create issues on", boardName, projectKey)
}

// IssueResult is what the issue endpoint returns.
type IssueResult struct {
	ItemID   int64          `json:"item_id"`
	ItemKey  string         `json:"item_key"`
	Location map[string]any `json:"location"`
}

// CreateIssueWithScreenshot creates an issue (Markdown description) with an image attached.
func (c *Client) CreateIssueWithScreenshot(ctx context.Context, boardID int64, title, description string, columnID int64, assignToMe bool, screenshot string) (*IssueResult, error) {
	file, err := os.Open(screenshot)
	if err != nil {
		return nil, err
	}
	defer file.Close()

	var body bytes.Buffer
	mw := multipart.NewWriter(&body)
	_ = mw.WriteField("title", title)
	if description != "" {
		_ = mw.WriteField("description", description)
	}
	if columnID > 0 {
		_ = mw.WriteField("column_id", fmt.Sprint(columnID))
	}
	if assignToMe {
		_ = mw.WriteField("assign_to_me", "1")
	}
	part, err := mw.CreateFormFile("screenshot", filepath.Base(screenshot))
	if err != nil {
		return nil, err
	}
	if _, err := io.Copy(part, file); err != nil {
		return nil, err
	}
	_ = mw.Close()

	var out IssueResult
	if err := c.do(ctx, http.MethodPost, fmt.Sprintf("/api/ext/v1/boards/%d/items", boardID), mw.FormDataContentType(), body.Bytes(), &out); err != nil {
		return nil, err
	}
	return &out, nil
}

func (c *Client) do(ctx context.Context, method, path, contentType string, body []byte, out any) error {
	token, err := c.Tokens.Token(ctx)
	if err != nil {
		return err
	}
	req, _ := http.NewRequestWithContext(ctx, method, c.Server+path, bytes.NewReader(body))
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", "Bearer "+token)
	if contentType != "" {
		req.Header.Set("Content-Type", contentType)
	}
	res, err := c.HTTP.Do(req)
	if err != nil {
		return fmt.Errorf("couldn't reach %s: %w", c.Server, err)
	}
	defer res.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(res.Body, 8<<20))
	if res.StatusCode == http.StatusUnauthorized {
		return errors.New("not signed in. Run `brainitive login`")
	}
	if res.StatusCode == http.StatusForbidden {
		return errors.New("this token can't use the capture API (sign in again with `brainitive login` to get the capture scope)")
	}
	if res.StatusCode >= 300 {
		var e struct {
			Message string              `json:"message"`
			Errors  map[string][]string `json:"errors"`
		}
		_ = json.Unmarshal(raw, &e)
		msg := e.Message
		for _, list := range e.Errors {
			msg = strings.Join(list, " ")
			break
		}
		if msg == "" {
			msg = fmt.Sprintf("HTTP %d", res.StatusCode)
		}
		return errors.New(msg)
	}
	return json.Unmarshal(raw, out)
}
