package cli

import (
	"context"
	"errors"
	"fmt"
	"os"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/armendzekjiri/brainitive/cli/internal/auth"
	"github.com/armendzekjiri/brainitive/cli/internal/config"
	"github.com/armendzekjiri/brainitive/cli/internal/output"
)

func (a *App) loginCommand() *cobra.Command {
	var server string
	cmd := &cobra.Command{
		Use:   "login",
		Short: "Sign in through your browser (Brainitive Cloud or your own server)",
		Long: `Sign in through your browser. The CLI registers itself with the server (OAuth 2.1 with
PKCE), you approve the access on the Brainitive page, and the tokens are stored in your
config file, readable only by you. Nothing to copy.

  brainitive login                                  # Brainitive Cloud
  brainitive login --server brainitive.example.com  # self-hosted

For scripts and CI, set BRAINITIVE_TOKEN (and BRAINITIVE_SERVER) instead: a token from
Profile → Connected apps, or "php artisan mcp:get-token <email>" on the server.`,
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			target := a.cfg.Server
			switch {
			case server != "":
				target = config.NormalizeServer(server)
			case a.server != "":
				target = config.NormalizeServer(a.server)
			case os.Getenv("BRAINITIVE_SERVER") != "":
				target = config.NormalizeServer(os.Getenv("BRAINITIVE_SERVER"))
			}

			ctx, cancel := context.WithTimeout(cmd.Context(), 5*time.Minute)
			defer cancel()
			session, err := auth.Login(ctx, target, func(line string) { a.out.Note("%s", line) })
			if err != nil {
				return err
			}

			if target != a.cfg.Server {
				// A different server: the old defaults mean nothing there.
				a.cfg.Defaults = config.Defaults{}
			}
			a.cfg.Server = target
			a.cfg.Session = session
			a.client = nil
			if res, err := a.mcp().Call(ctx, "list-workspaces", nil); err == nil {
				rows := output.List(res.Structured["workspaces"])
				if len(rows) == 1 {
					a.cfg.Defaults.Workspace = output.Str(rows[0]["slug"])
				}
				a.out.Note("Signed in to %s. %d workspace(s).", target, len(rows))
			} else {
				a.out.Note("Signed in to %s.", target)
			}
			if err := a.cfg.Save(); err != nil {
				return err
			}
			path, _ := config.Path()
			a.out.Note("Session saved in %s", path)
			if a.cfg.Defaults.Workspace == "" {
				a.out.Note("Next: `brainitive workspaces`, then `brainitive use <workspace>/<project>`.")
			}
			return nil
		},
	}
	cmd.Flags().StringVar(&server, "server", "", "server to sign in to, e.g. brainitive.example.com (default: Brainitive Cloud)")
	return cmd
}

func (a *App) logoutCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "logout",
		Short: "Forget the stored session",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if a.cfg.Session == nil {
				a.out.Note("Not signed in.")
				return nil
			}
			// Revoke the access + refresh token on the server (DELETE /api/cli/logout), then forget
			// the file. If the server can't be reached, the file still goes and we say so.
			revoked := a.extAPI().Logout(cmd.Context()) == nil
			a.cfg.Session = nil
			if err := a.cfg.Save(); err != nil {
				return err
			}
			if revoked {
				a.out.Note("Signed out of %s (token revoked).", a.cfg.Server)
			} else {
				a.out.Note("Signed out of %s locally; the server couldn't be reached. Revoke the CLI under Profile → Connected apps.", a.cfg.Server)
			}
			return nil
		},
	}
}

func (a *App) whoamiCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "whoami",
		Short: "Show the server, the signed-in account and the defaults",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			res, err := a.mcp().Call(ctx, "list-workspaces", nil)
			if err != nil {
				return err
			}
			rows := output.List(res.Structured["workspaces"])
			if a.jsonMode() {
				a.out.JSONValue(map[string]any{
					"server":     a.serverURL(),
					"defaults":   a.cfg.Defaults,
					"workspaces": rows,
					"token_from": tokenOrigin(),
				})
				return nil
			}
			a.out.Line("Server:     %s", a.serverURL())
			a.out.Line("Auth:       %s", tokenOrigin())
			a.out.Line("Workspaces: %d", len(rows))
			for _, r := range rows {
				a.out.Line("  %-24s %s (%s)", output.Str(r["slug"]), output.Str(r["name"]), output.Str(r["role"]))
			}
			d := a.cfg.Defaults
			a.out.Line("Defaults:   workspace=%s project=%s board=%s", orDash(d.Workspace), orDash(d.Project), orDash(d.Board))
			if d.TaskList != "" || d.Collection != "" || d.Bookmarks != "" {
				a.out.Line("            list=%s collection=%s bookmarks=%s", orDash(d.TaskList), orDash(d.Collection), orDash(d.Bookmarks))
			}
			return nil
		},
	}
}

func tokenOrigin() string {
	if os.Getenv("BRAINITIVE_TOKEN") != "" {
		return "BRAINITIVE_TOKEN"
	}
	return "browser sign-in (brainitive login)"
}

func orDash(s string) string {
	if s == "" {
		return "-"
	}
	return s
}

func (a *App) useCommand() *cobra.Command {
	var list, collection, bookmarks string
	cmd := &cobra.Command{
		Use:   "use <workspace>[/<project>[/<board>]]",
		Short: "Set the default workspace, project and board for the other commands",
		Example: `  brainitive use acme
  brainitive use acme/shop
  brainitive use acme/shop/main-board --list todo --collection scripts`,
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			parts := strings.Split(strings.Trim(args[0], "/"), "/")
			if len(parts) > 3 || parts[0] == "" {
				return errors.New("use <workspace>[/<project>[/<board>]]")
			}
			ctx := cmd.Context()
			ws := parts[0]
			known, err := a.slugs(ctx, "list-workspaces", nil, "workspaces")
			if err != nil {
				return err
			}
			if !contains(known, ws) {
				return fmt.Errorf("no workspace %q. Yours: %s", ws, strings.Join(known, ", "))
			}
			a.cfg.Defaults = config.Defaults{Workspace: ws}
			if len(parts) > 1 {
				projects, err := a.slugs(ctx, "list-projects", map[string]any{"workspace": ws}, "projects")
				if err != nil {
					return err
				}
				if !contains(projects, parts[1]) {
					return fmt.Errorf("no project %q in %s. Projects: %s", parts[1], ws, strings.Join(projects, ", "))
				}
				a.cfg.Defaults.Project = parts[1]
			}
			if len(parts) > 2 {
				boards, err := a.slugs(ctx, "list-boards", map[string]any{"workspace": ws, "project": parts[1]}, "boards")
				if err != nil {
					return err
				}
				if !contains(boards, parts[2]) {
					return fmt.Errorf("no board %q in %s. Boards: %s", parts[2], parts[1], strings.Join(boards, ", "))
				}
				a.cfg.Defaults.Board = parts[2]
			}
			a.cfg.Defaults.TaskList, a.cfg.Defaults.Collection, a.cfg.Defaults.Bookmarks = list, collection, bookmarks
			if err := a.cfg.Save(); err != nil {
				return err
			}
			a.out.Note("Defaults saved: %s", strings.Join(parts, "/"))
			return nil
		},
	}
	cmd.Flags().StringVar(&list, "list", "", "default task list slug")
	cmd.Flags().StringVar(&collection, "collection", "", "default snippet collection slug")
	cmd.Flags().StringVar(&bookmarks, "bookmarks", "", "default bookmark group slug")
	return cmd
}

func (a *App) configCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "config",
		Short: "Show where the config lives and what it holds (tokens hidden)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			path, _ := config.Path()
			signedIn := a.cfg.Session != nil && a.cfg.Session.AccessToken != ""
			if a.jsonMode() {
				a.out.JSONValue(map[string]any{"path": path, "server": a.cfg.Server, "signed_in": signedIn, "defaults": a.cfg.Defaults})
				return nil
			}
			a.out.Line("Config:    %s", path)
			a.out.Line("Server:    %s", a.cfg.Server)
			a.out.Line("Signed in: %v", signedIn)
			a.out.Line("Defaults:  workspace=%s project=%s board=%s list=%s collection=%s bookmarks=%s",
				orDash(a.cfg.Defaults.Workspace), orDash(a.cfg.Defaults.Project), orDash(a.cfg.Defaults.Board),
				orDash(a.cfg.Defaults.TaskList), orDash(a.cfg.Defaults.Collection), orDash(a.cfg.Defaults.Bookmarks))
			a.out.Line("Env:       BRAINITIVE_SERVER, BRAINITIVE_TOKEN, BRAINITIVE_WORKSPACE, BRAINITIVE_PROJECT, BRAINITIVE_BOARD, BRAINITIVE_CONFIG")
			return nil
		},
	}
}

func contains(list []string, s string) bool {
	for _, x := range list {
		if x == s {
			return true
		}
	}
	return false
}
