package cli

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"sync"

	"github.com/spf13/cobra"
)

type cobraContext = context.Context

func (a *App) mcpCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "mcp",
		Short: "Run as a local MCP server on stdio, bridged to your Brainitive account (for Claude Code, Cursor, Codex, Copilot…)",
		Long: `Speak MCP over stdin/stdout and forward every message to your server's /mcp with your
login. AI tools that only know local (stdio) MCP servers, or can't do OAuth themselves, get
the whole Brainitive workspace this way.

Claude Code:   claude mcp add brainitive -- brainitive mcp
Cursor / VS Code / Codex (mcp.json):
  { "mcpServers": { "brainitive": { "command": "brainitive", "args": ["mcp"] } } }

In CI, set BRAINITIVE_TOKEN and BRAINITIVE_SERVER instead of logging in.`,
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			return a.proxy(cmd.Context(), os.Stdin, os.Stdout)
		},
	}
}

// proxy reads newline-delimited JSON-RPC messages and forwards each to the server. Requests
// get their response written back; notifications get nothing (per the stdio transport).
func (a *App) proxy(ctx context.Context, in io.Reader, out io.Writer) error {
	client := a.mcp()
	var mu sync.Mutex
	write := func(msg []byte) {
		mu.Lock()
		defer mu.Unlock()
		_, _ = out.Write(append(msg, '\n'))
	}

	scanner := bufio.NewScanner(in)
	scanner.Buffer(make([]byte, 1<<20), 64<<20)
	var wg sync.WaitGroup
	for scanner.Scan() {
		line := append([]byte(nil), scanner.Bytes()...)
		if len(line) == 0 {
			continue
		}
		var probe struct {
			ID     json.RawMessage `json:"id"`
			Method string          `json:"method"`
		}
		if err := json.Unmarshal(line, &probe); err != nil {
			write(rpcError(nil, -32700, "Parse error: "+err.Error()))
			continue
		}
		// Keep ordering simple and predictable: one request at a time (tools/call is the
		// slow path anyway), but don't block on a notification's empty answer.
		wg.Add(1)
		go func() {
			defer wg.Done()
			res, _, err := client.Forward(ctx, line)
			if err != nil {
				if probe.ID != nil && string(probe.ID) != "null" {
					write(rpcError(probe.ID, -32000, err.Error()))
				} else {
					fmt.Fprintln(os.Stderr, "brainitive mcp:", err)
				}
				return
			}
			if res = bytes.TrimSpace(res); len(res) > 0 && probe.ID != nil && string(probe.ID) != "null" {
				write(res)
			}
		}()
		wg.Wait()
	}
	wg.Wait()
	return scanner.Err()
}

func rpcError(id json.RawMessage, code int, message string) []byte {
	if id == nil {
		id = json.RawMessage("null")
	}
	b, _ := json.Marshal(map[string]any{"jsonrpc": "2.0", "id": id, "error": map[string]any{"code": code, "message": message}})
	return b
}
