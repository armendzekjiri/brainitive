package cli

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"os"
	"strings"

	"github.com/armendzekjiri/brainitive/cli/internal/output"
)

func randomHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// pick resolves one slug: the flag, then an env var, then the saved default, then the only
// candidate the server lists; otherwise an error that names the choices.
func pick(ctx context.Context, what, flag, env, saved string, list func(context.Context) ([]string, error)) (string, error) {
	if flag != "" {
		return flag, nil
	}
	if v := os.Getenv(env); v != "" {
		return v, nil
	}
	if saved != "" {
		return saved, nil
	}
	choices, err := list(ctx)
	if err != nil {
		return "", err
	}
	switch len(choices) {
	case 0:
		return "", fmt.Errorf("you have no %s yet. Create one in Brainitive first", what)
	case 1:
		return choices[0], nil
	}
	return "", fmt.Errorf("which %s? Pass --%s <slug> or set a default with `brainitive use`. Choices: %s", what, what, strings.Join(choices, ", "))
}

func (a *App) slugs(ctx context.Context, tool string, args map[string]any, key string) ([]string, error) {
	res, err := a.mcp().Call(ctx, tool, args)
	if err != nil {
		return nil, err
	}
	var out []string
	for _, row := range output.List(res.Structured[key]) {
		if s := output.Str(row["slug"]); s != "" {
			out = append(out, s)
		}
	}
	return out, nil
}

// workspaceSlug is the workspace to act in.
func (a *App) workspaceSlug(ctx context.Context) (string, error) {
	return pick(ctx, "workspace", a.workspace, "BRAINITIVE_WORKSPACE", a.cfg.Defaults.Workspace, func(ctx context.Context) ([]string, error) {
		return a.slugs(ctx, "list-workspaces", nil, "workspaces")
	})
}

// projectSlug is the workspace + project to act in.
func (a *App) projectSlug(ctx context.Context) (string, string, error) {
	ws, err := a.workspaceSlug(ctx)
	if err != nil {
		return "", "", err
	}
	// A saved project only applies inside the workspace it was saved with.
	saved := ""
	if a.workspace == "" || a.workspace == a.cfg.Defaults.Workspace {
		saved = a.cfg.Defaults.Project
	}
	proj, err := pick(ctx, "project", a.project, "BRAINITIVE_PROJECT", saved, func(ctx context.Context) ([]string, error) {
		return a.slugs(ctx, "list-projects", map[string]any{"workspace": ws}, "projects")
	})
	return ws, proj, err
}

// boardSlug is the workspace + project + board to act on.
func (a *App) boardSlug(ctx context.Context) (string, string, string, error) {
	ws, proj, err := a.projectSlug(ctx)
	if err != nil {
		return "", "", "", err
	}
	saved := ""
	if a.project == "" || a.project == a.cfg.Defaults.Project {
		saved = a.cfg.Defaults.Board
	}
	board, err := pick(ctx, "board", a.board, "BRAINITIVE_BOARD", saved, func(ctx context.Context) ([]string, error) {
		return a.slugs(ctx, "list-boards", map[string]any{"workspace": ws, "project": proj}, "boards")
	})
	return ws, proj, board, err
}

func (a *App) boardArgs(ctx context.Context) (map[string]any, error) {
	ws, proj, board, err := a.boardSlug(ctx)
	if err != nil {
		return nil, err
	}
	return map[string]any{"workspace": ws, "project": proj, "board": board}, nil
}

func (a *App) projectArgs(ctx context.Context) (map[string]any, error) {
	ws, proj, err := a.projectSlug(ctx)
	if err != nil {
		return nil, err
	}
	return map[string]any{"workspace": ws, "project": proj}, nil
}

// taskListSlug picks a task list of the project.
func (a *App) taskListSlug(ctx context.Context, flag string) (map[string]any, error) {
	args, err := a.projectArgs(ctx)
	if err != nil {
		return nil, err
	}
	list, err := pick(ctx, "list", flag, "BRAINITIVE_TASK_LIST", a.cfg.Defaults.TaskList, func(ctx context.Context) ([]string, error) {
		return a.slugs(ctx, "list-task-lists", args, "task_lists")
	})
	if err != nil {
		return nil, err
	}
	args["task_list"] = list
	return args, nil
}

// collectionSlug picks a snippet collection of the workspace.
func (a *App) collectionSlug(ctx context.Context, flag string) (map[string]any, error) {
	ws, err := a.workspaceSlug(ctx)
	if err != nil {
		return nil, err
	}
	col, err := pick(ctx, "collection", flag, "BRAINITIVE_COLLECTION", a.cfg.Defaults.Collection, func(ctx context.Context) ([]string, error) {
		return a.slugs(ctx, "list-snippets", map[string]any{"workspace": ws}, "collections")
	})
	if err != nil {
		return nil, err
	}
	return map[string]any{"workspace": ws, "collection": col}, nil
}

// bookmarkGroupSlug picks a bookmark group of the workspace.
func (a *App) bookmarkGroupSlug(ctx context.Context, flag string) (map[string]any, error) {
	ws, err := a.workspaceSlug(ctx)
	if err != nil {
		return nil, err
	}
	group, err := pick(ctx, "group", flag, "BRAINITIVE_BOOKMARKS", a.cfg.Defaults.Bookmarks, func(ctx context.Context) ([]string, error) {
		return a.slugs(ctx, "list-bookmarks", map[string]any{"workspace": ws}, "bookmarks")
	})
	if err != nil {
		return nil, err
	}
	return map[string]any{"workspace": ws, "bookmark": group}, nil
}
