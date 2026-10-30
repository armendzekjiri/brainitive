package cli

import (
	"errors"
	"path/filepath"
	"strings"

	"github.com/spf13/cobra"

	"github.com/armendzekjiri/brainitive/cli/internal/output"
)

// ─── Inbox ──────────────────────────────────────────────────────────────────

func (a *App) captureCommand() *cobra.Command {
	var url, kind, file string
	cmd := &cobra.Command{
		Use:   "capture [text...]",
		Short: "Save a thought or link to your inbox to sort later (the fastest way in)",
		Long: `Save a note to your personal capture inbox. No workspace needed: sort it into an issue,
task, doc, bookmark or snippet later in Brainitive (Captures in the sidebar).

  brainitive capture "Ask Dana about the Q4 budget"
  brainitive capture --url https://example.com/article "read later"
  pbpaste | brainitive capture
  brainitive capture -f notes.txt --type task`,
		RunE: func(cmd *cobra.Command, args []string) error {
			text, err := readBody(args, file)
			if err != nil {
				return err
			}
			if strings.TrimSpace(text) == "" && url == "" {
				return errors.New("nothing to capture: pass some text, --url, --file, or pipe text in")
			}
			if strings.TrimSpace(text) == "" {
				text = url
			}
			payload := map[string]any{"text": text, "source": "CLI", "request_id": requestID()}
			if url != "" {
				payload["url"] = url
			}
			if kind != "" {
				payload["intended_type"] = kind
			}
			res, err := a.call(cmd.Context(), "capture-to-inbox", payload)
			if err != nil || a.jsonMode() {
				return err
			}
			a.located("Captured", res, "")
			return nil
		},
	}
	cmd.Flags().StringVar(&url, "url", "", "a link the note is about")
	cmd.Flags().StringVar(&kind, "type", "", "what it should become: issue, task, page, bookmark or snippet")
	cmd.Flags().StringVarP(&file, "file", "f", "", "read the text from a file ('-' for stdin)")
	return cmd
}

// ─── Snippets ───────────────────────────────────────────────────────────────

func (a *App) snippetsCommand() *cobra.Command {
	var collection string
	cmd := &cobra.Command{
		Use:   "snippets",
		Short: "List snippet collections and their snippets",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ws, err := a.workspaceSlug(cmd.Context())
			if err != nil {
				return err
			}
			res, err := a.call(cmd.Context(), "list-snippets", map[string]any{"workspace": ws})
			if err != nil || a.jsonMode() {
				return err
			}
			var rows [][]string
			for _, c := range output.List(res.Structured["collections"]) {
				if collection != "" && output.Str(c["slug"]) != collection {
					continue
				}
				snippets := output.List(c["snippets"])
				if len(snippets) == 0 {
					rows = append(rows, []string{output.Str(c["slug"]), "", "", "(empty)"})
				}
				for _, s := range snippets {
					rows = append(rows, []string{output.Str(c["slug"]), output.Str(s["slug"]), output.Str(s["language"]), output.Str(s["title"])})
				}
			}
			a.out.Table([]string{"collection", "snippet", "language", "title"}, rows)
			return nil
		},
	}
	cmd.Flags().StringVar(&collection, "collection", "", "only this collection slug")
	return cmd
}

func (a *App) snippetCommand() *cobra.Command {
	cmd := &cobra.Command{Use: "snippet", Short: "Save code or text as a snippet"}

	var file, lang, collection string
	add := &cobra.Command{
		Use:   "add <title>",
		Short: "Save a snippet from a file or stdin",
		Example: `  brainitive snippet add "Reset Postgres sequence" -f reset.sql
  kubectl get pods | brainitive snippet add "pods today" --lang text
  brainitive snippet add "deploy" -f deploy.sh --collection ops`,
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			body, err := readBody(nil, file)
			if err != nil {
				return err
			}
			if strings.TrimSpace(body) == "" {
				return errors.New("nothing to save: pass --file or pipe the content in")
			}
			payload, err := a.collectionSlug(cmd.Context(), collection)
			if err != nil {
				return err
			}
			payload["title"] = args[0]
			payload["content"] = body
			if lang == "" && file != "" && file != "-" {
				lang = languageFor(file)
			}
			if lang != "" {
				payload["language"] = lang
			}
			res, err := a.call(cmd.Context(), "create-snippet", payload)
			if err != nil || a.jsonMode() {
				return err
			}
			a.located("Saved snippet", res, "")
			return nil
		},
	}
	add.Flags().StringVarP(&file, "file", "f", "", "file to save ('-' for stdin; stdin is also read when piped)")
	add.Flags().StringVar(&lang, "lang", "", "syntax language (guessed from the file extension)")
	add.Flags().StringVar(&collection, "collection", "", "collection slug (default from `brainitive use --collection`)")
	cmd.AddCommand(add)

	var visibility string
	newCollection := &cobra.Command{
		Use:   "new-collection <name>",
		Short: "Create a snippet collection",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ws, err := a.workspaceSlug(cmd.Context())
			if err != nil {
				return err
			}
			payload := map[string]any{"workspace": ws, "name": args[0]}
			if visibility != "" {
				payload["visibility"] = visibility
			}
			res, err := a.call(cmd.Context(), "create-snippet-collection", payload)
			if err != nil || a.jsonMode() {
				return err
			}
			a.located("Created collection", res, "collection_slug")
			return nil
		},
	}
	newCollection.Flags().StringVar(&visibility, "visibility", "", "private (default) or public (whole workspace)")
	cmd.AddCommand(newCollection)
	return cmd
}

// languageFor guesses a snippet language from a file name.
func languageFor(file string) string {
	ext := strings.ToLower(strings.TrimPrefix(filepath.Ext(file), "."))
	switch ext {
	case "js", "mjs", "cjs":
		return "javascript"
	case "ts", "tsx":
		return "typescript"
	case "py":
		return "python"
	case "rb":
		return "ruby"
	case "sh", "bash", "zsh":
		return "bash"
	case "yml":
		return "yaml"
	case "md":
		return "markdown"
	case "txt", "":
		return "plaintext"
	case "kt":
		return "kotlin"
	case "rs":
		return "rust"
	case "cs":
		return "csharp"
	}
	return ext
}

// ─── Bookmarks ──────────────────────────────────────────────────────────────

func (a *App) bookmarksCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "bookmarks",
		Short: "List bookmark groups",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ws, err := a.workspaceSlug(cmd.Context())
			if err != nil {
				return err
			}
			res, err := a.call(cmd.Context(), "list-bookmarks", map[string]any{"workspace": ws})
			if err != nil || a.jsonMode() {
				return err
			}
			var rows [][]string
			for _, r := range output.List(res.Structured["bookmarks"]) {
				rows = append(rows, []string{output.Str(r["slug"]), output.Str(r["name"]), output.Str(r["items_count"]), output.Str(r["description"])})
			}
			a.out.Table([]string{"group", "name", "items", "description"}, rows)
			return nil
		},
	}
}

func (a *App) bookmarkCommand() *cobra.Command {
	cmd := &cobra.Command{Use: "bookmark", Short: "Save a link or a note to a bookmark group"}

	var title, group string
	add := &cobra.Command{
		Use:   "add <url>",
		Short: "Save a link",
		Example: `  brainitive bookmark add https://go.dev/doc/effective_go --title "Effective Go"
  brainitive bookmark add https://example.com --group reading`,
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			payload, err := a.bookmarkGroupSlug(cmd.Context(), group)
			if err != nil {
				return err
			}
			payload["type"] = "link"
			payload["url"] = args[0]
			if title != "" {
				payload["title"] = title
			}
			res, err := a.call(cmd.Context(), "create-bookmark-item", payload)
			if err != nil || a.jsonMode() {
				return err
			}
			a.located("Saved bookmark", res, "")
			return nil
		},
	}
	add.Flags().StringVar(&title, "title", "", "title (the page title is fetched when omitted)")
	add.Flags().StringVar(&group, "group", "", "bookmark group slug (default from `brainitive use --bookmarks`)")
	cmd.AddCommand(add)

	var noteTitle, noteGroup string
	note := &cobra.Command{
		Use:   "note <text...>",
		Short: "Save a text note to a bookmark group",
		Args:  cobra.MinimumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			payload, err := a.bookmarkGroupSlug(cmd.Context(), noteGroup)
			if err != nil {
				return err
			}
			payload["type"] = "note"
			payload["content"] = strings.Join(args, " ")
			if noteTitle != "" {
				payload["title"] = noteTitle
			}
			res, err := a.call(cmd.Context(), "create-bookmark-item", payload)
			if err != nil || a.jsonMode() {
				return err
			}
			a.located("Saved note", res, "")
			return nil
		},
	}
	note.Flags().StringVar(&noteTitle, "title", "", "note title")
	note.Flags().StringVar(&noteGroup, "group", "", "bookmark group slug")
	cmd.AddCommand(note)

	newGroup := &cobra.Command{
		Use:   "new-group <name>",
		Short: "Create a bookmark group",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ws, err := a.workspaceSlug(cmd.Context())
			if err != nil {
				return err
			}
			res, err := a.call(cmd.Context(), "create-bookmark", map[string]any{"workspace": ws, "name": args[0]})
			if err != nil || a.jsonMode() {
				return err
			}
			a.located("Created group", res, "bookmark_slug")
			return nil
		},
	}
	cmd.AddCommand(newGroup)
	return cmd
}

// ─── Tasks ──────────────────────────────────────────────────────────────────

func (a *App) tasksCommand() *cobra.Command {
	var list string
	var all bool
	cmd := &cobra.Command{
		Use:   "tasks",
		Short: "List the tasks of a task list (open ones by default)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			payload, err := a.taskListSlug(cmd.Context(), list)
			if err != nil {
				return err
			}
			res, err := a.call(cmd.Context(), "get-task-list", payload)
			if err != nil || a.jsonMode() {
				return err
			}
			var rows [][]string
			for _, t := range output.List(res.Structured["tasks"]) {
				status := output.Str(t["status"])
				if !all && status == "done" {
					continue
				}
				mark := "[ ]"
				if status == "done" {
					mark = "[x]"
				}
				rows = append(rows, []string{output.Str(t["id"]), mark, output.Str(t["title"]), output.Str(t["due_date"]), output.Str(t["recurrence"])})
			}
			a.out.Table([]string{"id", "", "title", "due", "repeats"}, rows)
			return nil
		},
	}
	cmd.Flags().StringVar(&list, "list", "", "task list slug (default from `brainitive use --list`)")
	cmd.Flags().BoolVar(&all, "all", false, "include done tasks")
	return cmd
}

func (a *App) taskCommand() *cobra.Command {
	cmd := &cobra.Command{Use: "task", Short: "Add or complete tasks"}

	var list, due, every string
	add := &cobra.Command{
		Use:   "add <title...>",
		Short: "Add a task",
		Example: `  brainitive task add Renew the domain --due 2026-12-01
  brainitive task add "Water plants" --every weekly --list home`,
		Args: cobra.MinimumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			payload, err := a.taskListSlug(cmd.Context(), list)
			if err != nil {
				return err
			}
			payload["title"] = strings.Join(args, " ")
			if due != "" {
				payload["due_date"] = due
			}
			if every != "" {
				payload["recurrence"] = every
			}
			res, err := a.call(cmd.Context(), "create-task", payload)
			if err != nil || a.jsonMode() {
				return err
			}
			a.located("Added task #"+output.Str(res.Structured["task_id"]), res, "")
			return nil
		},
	}
	add.Flags().StringVar(&list, "list", "", "task list slug")
	add.Flags().StringVar(&due, "due", "", "due date YYYY-MM-DD")
	add.Flags().StringVar(&every, "every", "", "repeat: daily, weekly, monthly or yearly")
	cmd.AddCommand(add)

	var doneList string
	done := &cobra.Command{
		Use:   "done <id>...",
		Short: "Mark tasks done",
		Args:  cobra.MinimumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			payload, err := a.taskListSlug(cmd.Context(), doneList)
			if err != nil {
				return err
			}
			for _, id := range args {
				p := map[string]any{"task_id": output.Int(id), "status": "done"}
				for k, v := range payload {
					p[k] = v
				}
				if _, err := a.call(cmd.Context(), "update-task", p); err != nil {
					return err
				}
				a.out.Line("Done: #%s", id)
			}
			return nil
		},
	}
	done.Flags().StringVar(&doneList, "list", "", "task list slug")
	cmd.AddCommand(done)

	lists := &cobra.Command{
		Use:   "lists",
		Short: "List the task lists of the project",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			args2, err := a.projectArgs(cmd.Context())
			if err != nil {
				return err
			}
			res, err := a.call(cmd.Context(), "list-task-lists", args2)
			if err != nil || a.jsonMode() {
				return err
			}
			var rows [][]string
			for _, r := range output.List(res.Structured["task_lists"]) {
				rows = append(rows, []string{output.Str(r["slug"]), output.Str(r["name"]), output.Str(r["open"]), output.Str(r["total"])})
			}
			a.out.Table([]string{"slug", "name", "open", "total"}, rows)
			return nil
		},
	}
	cmd.AddCommand(lists)
	return cmd
}
