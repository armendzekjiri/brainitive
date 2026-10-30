package cli

import (
	"context"
	"errors"
	"fmt"
	"strconv"
	"strings"

	"github.com/spf13/cobra"

	"github.com/armendzekjiri/brainitive/cli/internal/api"
	"github.com/armendzekjiri/brainitive/cli/internal/output"
)

func (a *App) issuesCommand() *cobra.Command {
	var column string
	cmd := &cobra.Command{
		Use:   "issues",
		Short: "List the cards of a board by column",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			payload, err := a.boardArgs(cmd.Context())
			if err != nil {
				return err
			}
			res, err := a.call(cmd.Context(), "get-board", payload)
			if err != nil || a.jsonMode() {
				return err
			}
			var rows [][]string
			for _, c := range output.List(res.Structured["columns"]) {
				name := output.Str(c["name"])
				if column != "" && !strings.EqualFold(name, column) && !strings.EqualFold(output.Str(c["slug"]), column) {
					continue
				}
				for _, i := range output.List(c["items"]) {
					rows = append(rows, []string{output.Str(i["key"]), output.Str(i["id"]), name, output.Str(i["title"]), output.Str(i["due_date"])})
				}
			}
			a.out.Table([]string{"key", "id", "column", "title", "due"}, rows)
			return nil
		},
	}
	cmd.Flags().StringVar(&column, "column", "", "only this column (name or slug)")
	return cmd
}

// itemID accepts a numeric id or a key like SHOP-12 (looked up on the board).
func (a *App) itemID(ctx context.Context, board map[string]any, ref string) (int64, error) {
	if n, err := strconv.ParseInt(ref, 10, 64); err == nil {
		return n, nil
	}
	res, err := a.mcp().Call(ctx, "get-board", board)
	if err != nil {
		return 0, err
	}
	for _, c := range output.List(res.Structured["columns"]) {
		for _, i := range output.List(c["items"]) {
			if strings.EqualFold(output.Str(i["key"]), ref) {
				return output.Int(i["id"]), nil
			}
		}
	}
	return 0, fmt.Errorf("no card %s on this board (subtasks are addressed by id)", strings.ToUpper(ref))
}

// columnID resolves a column name / slug / id on the board.
func (a *App) columnID(ctx context.Context, board map[string]any, ref string) (int64, error) {
	if n, err := strconv.ParseInt(ref, 10, 64); err == nil {
		return n, nil
	}
	res, err := a.mcp().Call(ctx, "get-board", board)
	if err != nil {
		return 0, err
	}
	var names []string
	for _, c := range output.List(res.Structured["columns"]) {
		names = append(names, output.Str(c["name"]))
		if strings.EqualFold(output.Str(c["name"]), ref) || strings.EqualFold(output.Str(c["slug"]), ref) {
			return output.Int(c["column_id"]), nil
		}
	}
	return 0, fmt.Errorf("no column %q. Columns: %s", ref, strings.Join(names, ", "))
}

func (a *App) issueCommand() *cobra.Command {
	cmd := &cobra.Command{Use: "issue", Short: "Create, read, update, move and comment on cards"}

	cmd.AddCommand(&cobra.Command{
		Use:   "show <key|id>",
		Short: "Print a card",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			board, err := a.boardArgs(cmd.Context())
			if err != nil {
				return err
			}
			id, err := a.itemID(cmd.Context(), board, args[0])
			if err != nil {
				return err
			}
			board["item_id"] = id
			res, err := a.call(cmd.Context(), "get-board-item", board)
			if err != nil || a.jsonMode() {
				return err
			}
			s := res.Structured
			a.out.Line("%s  %s", output.Str(s["key"]), output.Str(s["title"]))
			a.out.Line("Column: %s   Type: %s   Priority: %s   Assignee: %s", orDash(output.Str(s["column"])), orDash(output.Str(s["type"])), orDash(output.Str(s["priority"])), orDash(output.Str(s["assignee"])))
			a.out.Line("Start: %s   Due: %s   Done: %s", orDash(output.Str(s["start_date"])), orDash(output.Str(s["due_date"])), output.Str(s["completed"]))
			if d := output.Str(s["description"]); d != "" {
				a.out.Line("\n%s", d)
			}
			a.out.Line("\n%s", output.Str(output.Map(s["location"])["url"]))
			return nil
		},
	})

	var desc, file, column, kind, priority, assignee, start, due, screenshot string
	var labels []string
	var points int
	var parent int64
	create := &cobra.Command{
		Use:   "create <title...>",
		Short: "Create a card (optionally with a screenshot attached)",
		Example: `  brainitive issue create Checkout button misaligned --type Bug --priority High --label ui
  brainitive issue create "Write release notes" --column "In Progress" --assignee me --due 2026-11-30
  brainitive issue create "Crash on save" -f crash.md --screenshot ~/Desktop/shot.png`,
		Args: cobra.MinimumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			board, err := a.boardArgs(ctx)
			if err != nil {
				return err
			}
			body := desc
			if file != "" {
				body, err = readBody(nil, file)
				if err != nil {
					return err
				}
			} else if body == "" && stdinPiped() {
				body, _ = readStdin()
			}
			title := strings.Join(args, " ")

			if screenshot != "" {
				return a.createWithScreenshot(ctx, board, title, body, column, assignee, screenshot)
			}

			payload := map[string]any{"title": title}
			for k, v := range board {
				payload[k] = v
			}
			set := func(k, v string) {
				if v != "" {
					payload[k] = v
				}
			}
			set("description", body)
			set("column", column)
			set("type", kind)
			set("priority", priority)
			set("assignee", assignee)
			set("start_date", start)
			set("due_date", due)
			if len(labels) > 0 {
				payload["labels"] = labels
			}
			if points > 0 {
				payload["story_points"] = points
			}
			if parent > 0 {
				payload["parent_id"] = parent
			}
			res, err := a.call(ctx, "create-board-item", payload)
			if err != nil || a.jsonMode() {
				return err
			}
			a.located("Created", res, "item_key")
			return nil
		},
	}
	create.Flags().StringVarP(&desc, "desc", "d", "", "description (Markdown)")
	create.Flags().StringVarP(&file, "file", "f", "", "description from a Markdown file ('-' for stdin)")
	create.Flags().StringVar(&column, "column", "", "column (status) name; default: the first")
	create.Flags().StringVar(&kind, "type", "", "item type, e.g. Bug")
	create.Flags().StringVar(&priority, "priority", "", "priority, e.g. High")
	create.Flags().StringSliceVar(&labels, "label", nil, "label name (repeatable)")
	create.Flags().StringVar(&assignee, "assignee", "", "\"me\" or a member email")
	create.Flags().StringVar(&start, "start", "", "start date YYYY-MM-DD")
	create.Flags().StringVar(&due, "due", "", "due date YYYY-MM-DD")
	create.Flags().IntVar(&points, "points", 0, "story points")
	create.Flags().Int64Var(&parent, "parent", 0, "parent card id (makes a subtask)")
	create.Flags().StringVar(&screenshot, "screenshot", "", "attach an image (png, jpg, webp ≤ 10 MB)")
	cmd.AddCommand(create)

	var toColumn string
	move := &cobra.Command{
		Use:     "move <key|id>",
		Short:   "Move a card to another column",
		Example: `  brainitive issue move SHOP-12 --to Done`,
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if toColumn == "" {
				return errors.New("--to <column> is required")
			}
			ctx := cmd.Context()
			board, err := a.boardArgs(ctx)
			if err != nil {
				return err
			}
			id, err := a.itemID(ctx, board, args[0])
			if err != nil {
				return err
			}
			col, err := a.columnID(ctx, board, toColumn)
			if err != nil {
				return err
			}
			board["item_id"] = id
			board["column_id"] = col
			res, err := a.call(ctx, "move-board-item", board)
			if err != nil || a.jsonMode() {
				return err
			}
			a.out.Line("Moved %s → %s", strings.ToUpper(args[0]), toColumn)
			_ = res
			return nil
		},
	}
	move.Flags().StringVar(&toColumn, "to", "", "destination column (name, slug or id)")
	cmd.AddCommand(move)

	comment := &cobra.Command{
		Use:   "comment <key|id> <text...>",
		Short: "Comment on a card (Markdown; @email mentions people)",
		Args:  cobra.MinimumNArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			board, err := a.boardArgs(ctx)
			if err != nil {
				return err
			}
			id, err := a.itemID(ctx, board, args[0])
			if err != nil {
				return err
			}
			board["item_id"] = id
			board["markdown"] = strings.Join(args[1:], " ")
			board["request_id"] = requestID()
			res, err := a.call(ctx, "add-item-comment", board)
			if err != nil || a.jsonMode() {
				return err
			}
			a.located("Commented on", res, "")
			return nil
		},
	}
	cmd.AddCommand(comment)

	var uTitle, uDesc, uType, uPriority, uAssignee, uDue, uStart string
	var uLabels []string
	var uPoints int
	update := &cobra.Command{
		Use:   "update <key|id>",
		Short: "Change a card's fields (only what you pass)",
		Example: `  brainitive issue update SHOP-12 --priority Highest --label bug --label urgent
  brainitive issue update 42 --assignee dana@example.com --due 2026-12-01`,
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			board, err := a.boardArgs(ctx)
			if err != nil {
				return err
			}
			id, err := a.itemID(ctx, board, args[0])
			if err != nil {
				return err
			}
			board["item_id"] = id
			changed := false
			set := func(flag, key, v string) {
				if cmd.Flags().Changed(flag) {
					board[key] = v
					changed = true
				}
			}
			set("title", "title", uTitle)
			set("desc", "description", uDesc)
			set("type", "type", uType)
			set("priority", "priority", uPriority)
			set("assignee", "assignee", uAssignee)
			set("due", "due_date", uDue)
			set("start", "start_date", uStart)
			if cmd.Flags().Changed("label") {
				board["labels"] = uLabels
				changed = true
			}
			if cmd.Flags().Changed("points") {
				board["story_points"] = uPoints
				changed = true
			}
			if !changed {
				return errors.New("nothing to change: pass --title, --desc, --type, --priority, --label, --assignee, --start, --due or --points")
			}
			res, err := a.call(ctx, "update-board-item", board)
			if err != nil || a.jsonMode() {
				return err
			}
			a.located("Updated", res, "item_key")
			return nil
		},
	}
	update.Flags().StringVar(&uTitle, "title", "", "new title")
	update.Flags().StringVarP(&uDesc, "desc", "d", "", "new description (Markdown)")
	update.Flags().StringVar(&uType, "type", "", "item type")
	update.Flags().StringVar(&uPriority, "priority", "", "priority")
	update.Flags().StringSliceVar(&uLabels, "label", nil, "the full label list (repeatable; pass --label \"\" to clear)")
	update.Flags().StringVar(&uAssignee, "assignee", "", "\"me\", an email, or \"\" to unassign")
	update.Flags().StringVar(&uStart, "start", "", "start date YYYY-MM-DD")
	update.Flags().StringVar(&uDue, "due", "", "due date YYYY-MM-DD")
	update.Flags().IntVar(&uPoints, "points", 0, "story points")
	cmd.AddCommand(update)
	return cmd
}

// createWithScreenshot goes through the extension API, the one endpoint that takes a file.
func (a *App) createWithScreenshot(ctx context.Context, board map[string]any, title, body, column, assignee, screenshot string) error {
	// Numeric ids: project key from list_projects, board id from the extension's destinations.
	projects, err := a.mcp().Call(ctx, "list-projects", map[string]any{"workspace": board["workspace"]})
	if err != nil {
		return err
	}
	var projectKey string
	for _, p := range output.List(projects.Structured["projects"]) {
		if output.Str(p["slug"]) == board["project"] {
			projectKey = output.Str(p["key"])
		}
	}
	boards, err := a.mcp().Call(ctx, "list-boards", map[string]any{"workspace": board["workspace"], "project": board["project"]})
	if err != nil {
		return err
	}
	var boardName string
	for _, b := range output.List(boards.Structured["boards"]) {
		if output.Str(b["slug"]) == board["board"] {
			boardName = output.Str(b["name"])
		}
	}
	destinations, err := a.extAPI().Destinations(ctx)
	if err != nil {
		return err
	}
	target, err := api.FindBoard(destinations, projectKey, boardName)
	if err != nil {
		return err
	}
	var columnID int64
	if column != "" {
		for _, c := range target.Columns {
			if strings.EqualFold(c.Name, column) {
				columnID = c.ID
			}
		}
		if columnID == 0 {
			return fmt.Errorf("no column %q on %s", column, boardName)
		}
	}
	if assignee != "" && !strings.EqualFold(assignee, "me") {
		return errors.New("with --screenshot the card can only be assigned to you (--assignee me); assign others afterwards with `issue update`")
	}
	res, err := a.extAPI().CreateIssueWithScreenshot(ctx, target.ID, title, body, columnID, strings.EqualFold(assignee, "me"), screenshot)
	if err != nil {
		return err
	}
	if a.jsonMode() {
		a.out.JSONValue(res)
		return nil
	}
	a.out.Line("Created %s with screenshot  (%s)\n%s", res.ItemKey, output.Str(res.Location["breadcrumb"]), output.Str(res.Location["url"]))
	return nil
}
