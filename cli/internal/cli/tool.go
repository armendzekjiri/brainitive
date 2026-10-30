package cli

import (
	"encoding/json"
	"fmt"
	"strconv"
	"strings"

	"github.com/spf13/cobra"

	"github.com/armendzekjiri/brainitive/cli/internal/mcp"
	"github.com/armendzekjiri/brainitive/cli/internal/output"
)

func (a *App) toolsCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "tools [filter]",
		Short: "List every tool the server offers (what `brainitive tool` and AI agents can call)",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			tools, err := a.mcp().ListTools(cmd.Context())
			if err != nil {
				return err
			}
			filter := ""
			if len(args) == 1 {
				filter = strings.ToLower(args[0])
			}
			if a.jsonMode() {
				a.out.JSONValue(tools)
				return nil
			}
			var rows [][]string
			for _, t := range tools {
				name := strings.TrimSuffix(t.Name, "-tool")
				if filter != "" && !strings.Contains(name, filter) && !strings.Contains(strings.ToLower(t.Description), filter) {
					continue
				}
				rows = append(rows, []string{name, output.Clip(t.Description, 110)})
			}
			a.out.Table([]string{"tool", "description"}, rows)
			a.out.Line("\n%d tools. Call one: brainitive tool <name> key=value … (--help for details)", len(rows))
			return nil
		},
	}
}

func (a *App) toolCommand() *cobra.Command {
	var jsonArgs string
	var describe bool
	cmd := &cobra.Command{
		Use:   "tool <name> [key=value ...]",
		Short: "Call any MCP tool directly (the escape hatch: everything the server can do)",
		Long: `Call any tool of the server by name, with arguments as key=value pairs or --args JSON.
Values that look like numbers, true/false or JSON arrays / objects are sent as such; use
key:="…" to force a string. The workspace / project / board context is filled in from your
defaults when the tool asks for it and you didn't pass it.

  brainitive tool list-project-members
  brainitive tool create-label name=urgent color=#ef4444
  brainitive tool create-board-items --args '{"items":[{"title":"A"},{"title":"B","column":"Done"}]}'
  brainitive tool get-sprint sprint_id=3 --json
  brainitive tool create-item-field --describe`,
		Args: cobra.MinimumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			name := mcp.ToolName(args[0])

			if describe {
				tools, err := a.mcp().ListTools(ctx)
				if err != nil {
					return err
				}
				for _, t := range tools {
					if t.Name == name {
						if a.jsonMode() {
							a.out.JSONValue(t)
							return nil
						}
						a.out.Line("%s\n\n%s\n", strings.TrimSuffix(t.Name, "-tool"), t.Description)
						var schema map[string]any
						_ = json.Unmarshal(t.InputSchema, &schema)
						required := map[string]bool{}
						if req, ok := schema["required"].([]any); ok {
							for _, r := range req {
								required[output.Str(r)] = true
							}
						}
						var rows [][]string
						for key, raw := range output.Map(schema["properties"]) {
							prop := output.Map(raw)
							mark := ""
							if required[key] {
								mark = "required"
							}
							rows = append(rows, []string{key, output.Str(prop["type"]), mark, output.Str(prop["description"])})
						}
						sortRows(rows)
						a.out.Table([]string{"argument", "type", "", "description"}, rows)
						return nil
					}
				}
				return fmt.Errorf("no tool %q. See `brainitive tools`", args[0])
			}

			payload := map[string]any{}
			if jsonArgs != "" {
				if err := json.Unmarshal([]byte(jsonArgs), &payload); err != nil {
					return fmt.Errorf("--args is not valid JSON: %w", err)
				}
			}
			for _, pair := range args[1:] {
				key, value, err := parsePair(pair)
				if err != nil {
					return err
				}
				payload[key] = value
			}
			if err := a.fillContext(ctx, name, payload); err != nil {
				return err
			}
			if needsRequestID(name) {
				if _, ok := payload["request_id"]; !ok {
					payload["request_id"] = requestID()
				}
			}

			res, err := a.mcp().Call(ctx, name, payload)
			if err != nil {
				return err
			}
			if res.Structured != nil {
				a.out.JSONValue(res.Structured)
			} else {
				fmt.Fprintln(a.out.Out, res.Text)
			}
			return nil
		},
	}
	cmd.Flags().StringVar(&jsonArgs, "args", "", "arguments as a JSON object (key=value pairs override it)")
	cmd.Flags().BoolVar(&describe, "describe", false, "show the tool's arguments instead of calling it")
	return cmd
}

// fillContext adds workspace / project / board from the defaults when the tool takes them.
func (a *App) fillContext(ctx cobraContext, tool string, payload map[string]any) error {
	tools, err := a.mcp().ListTools(ctx)
	if err != nil {
		return err
	}
	var props map[string]any
	for _, t := range tools {
		if t.Name == tool {
			var schema map[string]any
			_ = json.Unmarshal(t.InputSchema, &schema)
			props = output.Map(schema["properties"])
		}
	}
	if props == nil {
		return fmt.Errorf("no tool %q. See `brainitive tools`", strings.TrimSuffix(tool, "-tool"))
	}
	_, wantsWS := props["workspace"]
	_, wantsProject := props["project"]
	_, wantsBoard := props["board"]
	switch {
	case wantsBoard && payload["board"] == nil:
		ws, proj, board, err := a.boardSlug(ctx)
		if err != nil {
			return err
		}
		setDefault(payload, "workspace", ws)
		setDefault(payload, "project", proj)
		setDefault(payload, "board", board)
	case wantsProject && payload["project"] == nil:
		ws, proj, err := a.projectSlug(ctx)
		if err != nil {
			return err
		}
		setDefault(payload, "workspace", ws)
		setDefault(payload, "project", proj)
	case wantsWS && payload["workspace"] == nil:
		ws, err := a.workspaceSlug(ctx)
		if err != nil {
			return err
		}
		payload["workspace"] = ws
	}
	return nil
}

func setDefault(m map[string]any, k, v string) {
	if _, ok := m[k]; !ok {
		m[k] = v
	}
}

func needsRequestID(tool string) bool {
	switch tool {
	case "create-workspace-tool", "create-project-tool", "create-board-tool", "create-board-column-tool", "create-task-list-tool",
		"create-board-items-tool", "capture-to-inbox-tool", "add-item-comment-tool", "create-sprint-tool", "update-sprint-tool",
		"move-items-to-sprint-tool", "start-sprint-tool", "complete-sprint-tool", "update-plan-tool":
		return true
	}
	return false
}

// parsePair turns "key=value" into a typed value: numbers, booleans, JSON arrays / objects,
// else a string. "key:=value" always keeps the string.
func parsePair(pair string) (string, any, error) {
	if i := strings.Index(pair, ":="); i > 0 {
		return pair[:i], pair[i+2:], nil
	}
	i := strings.Index(pair, "=")
	if i <= 0 {
		return "", nil, fmt.Errorf("expected key=value, got %q", pair)
	}
	key, raw := pair[:i], pair[i+1:]
	switch {
	case raw == "true":
		return key, true, nil
	case raw == "false":
		return key, false, nil
	case raw == "null":
		return key, nil, nil
	case strings.HasPrefix(raw, "[") || strings.HasPrefix(raw, "{"):
		var v any
		if err := json.Unmarshal([]byte(raw), &v); err == nil {
			return key, v, nil
		}
	}
	if n, err := strconv.ParseInt(raw, 10, 64); err == nil {
		return key, n, nil
	}
	if f, err := strconv.ParseFloat(raw, 64); err == nil && strings.ContainsAny(raw, ".eE") {
		return key, f, nil
	}
	return key, raw, nil
}

func sortRows(rows [][]string) {
	for i := 1; i < len(rows); i++ {
		for j := i; j > 0 && rows[j][0] < rows[j-1][0]; j-- {
			rows[j], rows[j-1] = rows[j-1], rows[j]
		}
	}
}
