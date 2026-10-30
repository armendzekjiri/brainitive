package cli

import (
	"errors"
	"fmt"
	"strings"

	"github.com/spf13/cobra"

	"github.com/armendzekjiri/brainitive/cli/internal/output"
)

func (a *App) workspacesCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "workspaces",
		Short: "List your workspaces",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			res, err := a.call(cmd.Context(), "list-workspaces", nil)
			if err != nil || a.jsonMode() {
				return err
			}
			var rows [][]string
			for _, r := range output.List(res.Structured["workspaces"]) {
				rows = append(rows, []string{output.Str(r["slug"]), output.Str(r["name"]), output.Str(r["role"]), output.Str(r["projects_count"])})
			}
			a.out.Table([]string{"slug", "name", "role", "projects"}, rows)
			return nil
		},
	}
}

func (a *App) projectsCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "projects",
		Short: "List the projects of a workspace",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ws, err := a.workspaceSlug(cmd.Context())
			if err != nil {
				return err
			}
			res, err := a.call(cmd.Context(), "list-projects", map[string]any{"workspace": ws})
			if err != nil || a.jsonMode() {
				return err
			}
			var rows [][]string
			for _, r := range output.List(res.Structured["projects"]) {
				rows = append(rows, []string{output.Str(r["slug"]), output.Str(r["key"]), output.Str(r["name"]), output.Str(r["description"])})
			}
			a.out.Table([]string{"slug", "key", "name", "description"}, rows)
			return nil
		},
	}
}

func (a *App) boardsCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "boards",
		Short: "List the boards of a project",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			args2, err := a.projectArgs(cmd.Context())
			if err != nil {
				return err
			}
			res, err := a.call(cmd.Context(), "list-boards", args2)
			if err != nil || a.jsonMode() {
				return err
			}
			var rows [][]string
			for _, r := range output.List(res.Structured["boards"]) {
				rows = append(rows, []string{output.Str(r["slug"]), output.Str(r["name"]), output.Str(r["items_count"]), output.Str(r["description"])})
			}
			a.out.Table([]string{"slug", "name", "cards", "description"}, rows)
			return nil
		},
	}
}

func (a *App) projectCommand() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "project",
		Short: "Project setup: see the configuration or create a project",
	}
	cmd.AddCommand(&cobra.Command{
		Use:   "config",
		Short: "Boards and columns, labels, types, priorities, custom fields and members of the project",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			args2, err := a.projectArgs(cmd.Context())
			if err != nil {
				return err
			}
			res, err := a.call(cmd.Context(), "get-project-config", args2)
			if err != nil || a.jsonMode() {
				return err
			}
			s := res.Structured
			p := output.Map(s["project"])
			a.out.Line("%s (%s)  key %s", output.Str(p["name"]), output.Str(p["slug"]), output.Str(p["key"]))
			for _, b := range output.List(s["boards"]) {
				var cols []string
				for _, c := range output.List(b["columns"]) {
					name := output.Str(c["name"])
					if c["is_done"] == true {
						name += " ✓"
					}
					cols = append(cols, fmt.Sprintf("%s [%s]", name, output.Str(c["column_id"])))
				}
				a.out.Line("\nBoard %s (%s, %s): %s", output.Str(b["name"]), output.Str(b["slug"]), output.Str(b["type"]), strings.Join(cols, " → "))
			}
			a.out.Line("\nLabels:     %s", names(output.List(s["labels"]), "name"))
			a.out.Line("Types:      %s", names(output.List(s["item_types"]), "name"))
			a.out.Line("Priorities: %s", names(output.List(s["priorities"]), "name"))
			fields := output.List(s["fields"])
			if len(fields) > 0 {
				a.out.Line("Fields:")
				for _, f := range fields {
					line := fmt.Sprintf("  %-20s %-9s [%s]", output.Str(f["name"]), output.Str(f["type"]), output.Str(f["field_id"]))
					if opts := output.List(f["options"]); len(opts) > 0 {
						line += "  " + names(opts, "label")
					}
					if f["archived"] == true {
						line += "  (archived)"
					}
					a.out.Line("%s", line)
				}
			}
			a.out.Line("Members:")
			for _, m := range output.List(s["members"]) {
				a.out.Line("  %-28s %-8s %s", output.Str(m["email"]), output.Str(m["role"]), output.Str(m["name"]))
			}
			return nil
		},
	})

	var key, description, boardName, boardType, columns string
	create := &cobra.Command{
		Use:   "create <name>",
		Short: "Create a project with its first board",
		Example: `  brainitive project create "Web shop" --key SHOP
  brainitive project create "Support" --key SUP --columns "New,Triage,In progress,Waiting,Closed" --type kanban`,
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ws, err := a.workspaceSlug(cmd.Context())
			if err != nil {
				return err
			}
			if key == "" {
				return errors.New("--key is required (the issue prefix, letters only, e.g. SHOP)")
			}
			payload := map[string]any{"workspace": ws, "name": args[0], "key": key, "request_id": requestID()}
			if description != "" {
				payload["description"] = description
			}
			if boardName != "" {
				payload["board_name"] = boardName
			}
			if boardType != "" {
				payload["board_type"] = boardType
			}
			if columns != "" {
				var cols []map[string]any
				parts := strings.Split(columns, ",")
				for i, c := range parts {
					col := map[string]any{"name": strings.TrimSpace(c)}
					if i == len(parts)-1 {
						col["is_done"] = true
					}
					cols = append(cols, col)
				}
				payload["columns"] = cols
			}
			res, err := a.call(cmd.Context(), "create-project", payload)
			if err != nil || a.jsonMode() {
				return err
			}
			a.located("Created project", res, "project_slug")
			a.out.Line("Board: %s. Set it as default: brainitive use %s/%s/%s", output.Str(res.Structured["board_slug"]), ws, output.Str(res.Structured["project_slug"]), output.Str(res.Structured["board_slug"]))
			return nil
		},
	}
	create.Flags().StringVar(&key, "key", "", "issue key prefix (letters, e.g. SHOP)")
	create.Flags().StringVar(&description, "description", "", "project description")
	create.Flags().StringVar(&boardName, "board", "Main Board", "name of the first board")
	create.Flags().StringVar(&boardType, "type", "", "board type: kanban or scrum")
	create.Flags().StringVar(&columns, "columns", "", "comma-separated statuses in order; the last one counts as done")
	cmd.AddCommand(create)
	return cmd
}

func names(rows []map[string]any, key string) string {
	var out []string
	for _, r := range rows {
		out = append(out, output.Str(r[key]))
	}
	if len(out) == 0 {
		return "-"
	}
	return strings.Join(out, ", ")
}

func (a *App) pagesCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "pages",
		Short: "List the docs (pages) of a project",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			args2, err := a.projectArgs(cmd.Context())
			if err != nil {
				return err
			}
			res, err := a.call(cmd.Context(), "list-pages", args2)
			if err != nil || a.jsonMode() {
				return err
			}
			var rows [][]string
			for _, r := range output.List(res.Structured["pages"]) {
				rows = append(rows, []string{output.Str(r["uuid"]), output.Str(r["type"]), output.Str(r["name"])})
			}
			a.out.Table([]string{"uuid", "type", "name"}, rows)
			return nil
		},
	}
}

func (a *App) pageCommand() *cobra.Command {
	cmd := &cobra.Command{Use: "page", Short: "Read or create a doc"}
	cmd.AddCommand(&cobra.Command{
		Use:   "get <uuid>",
		Short: "Print a doc as Markdown",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			args2, err := a.projectArgs(cmd.Context())
			if err != nil {
				return err
			}
			args2["page_uuid"] = args[0]
			res, err := a.call(cmd.Context(), "get-page", args2)
			if err != nil || a.jsonMode() {
				return err
			}
			a.out.Line("# %s\n\n%s", output.Str(res.Structured["title"]), output.Str(res.Structured["markdown"]))
			return nil
		},
	})
	var file, parent string
	var publish bool
	create := &cobra.Command{
		Use:   "create <title>",
		Short: "Create a doc from Markdown (--file, or piped stdin)",
		Example: `  brainitive page create "Release notes" --file notes.md --publish
  cat README.md | brainitive page create "README"`,
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			args2, err := a.projectArgs(cmd.Context())
			if err != nil {
				return err
			}
			body, err := readBody(nil, file)
			if err != nil {
				return err
			}
			args2["title"] = args[0]
			if body != "" {
				args2["markdown"] = body
			}
			if parent != "" {
				args2["parent_uuid"] = parent
			}
			args2["publish"] = publish
			res, err := a.call(cmd.Context(), "create-page", args2)
			if err != nil || a.jsonMode() {
				return err
			}
			a.located("Created doc", res, "")
			return nil
		},
	}
	create.Flags().StringVarP(&file, "file", "f", "", "Markdown file ('-' for stdin)")
	create.Flags().StringVar(&parent, "parent", "", "uuid of a parent page or folder")
	create.Flags().BoolVar(&publish, "publish", false, "publish right away (otherwise a draft only you see)")
	cmd.AddCommand(create)
	return cmd
}

func (a *App) searchCommand() *cobra.Command {
	var kind, status string
	var mine bool
	var limit int
	cmd := &cobra.Command{
		Use:   "search <words...>",
		Short: "Search everything you can see (issues, docs, tickets, tasks, snippets, bookmarks, files…)",
		Example: `  brainitive search invoice pdf
  brainitive search "payment failed" --type issues --open --mine
  brainitive search SHOP-12`,
		Args: cobra.MinimumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			payload := map[string]any{"query": strings.Join(args, " "), "limit": limit}
			if kind != "" {
				payload["type"] = kind
			}
			if status != "" {
				payload["status"] = status
			}
			if mine {
				payload["assigned_to_me"] = true
			}
			res, err := a.call(cmd.Context(), "search", payload)
			if err != nil || a.jsonMode() {
				return err
			}
			var rows [][]string
			for _, r := range output.List(res.Structured["results"]) {
				rows = append(rows, []string{output.Str(r["title"]), output.Str(r["subtitle"]), output.Str(r["url"])})
			}
			a.out.Table([]string{"title", "where", "url"}, rows)
			return nil
		},
	}
	cmd.Flags().StringVar(&kind, "type", "", "issues, docs, comments, tickets, tasks, plans, snippets, bookmarks, captures or files")
	cmd.Flags().StringVar(&status, "status", "", "open or done")
	cmd.Flags().BoolVar(&mine, "mine", false, "only things assigned to you")
	cmd.Flags().IntVar(&limit, "limit", 20, "maximum results (≤ 50)")
	cmd.Flags().Lookup("status").NoOptDefVal = "open"
	return cmd
}

func (a *App) sprintsCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "sprints",
		Short: "List the sprints of a Scrum board",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			args2, err := a.boardArgs(cmd.Context())
			if err != nil {
				return err
			}
			res, err := a.call(cmd.Context(), "list-sprints", args2)
			if err != nil || a.jsonMode() {
				return err
			}
			var rows [][]string
			for _, r := range output.List(res.Structured["sprints"]) {
				rows = append(rows, []string{output.Str(r["id"]), output.Str(r["name"]), output.Str(r["status"]), output.Str(r["start_date"]), output.Str(r["end_date"]), output.Str(r["issues"])})
			}
			a.out.Table([]string{"id", "name", "status", "start", "end", "issues"}, rows)
			a.out.Line("Backlog: %s issues  %s", output.Str(res.Structured["backlog_issues"]), output.Str(res.Structured["backlog_url"]))
			return nil
		},
	}
}
