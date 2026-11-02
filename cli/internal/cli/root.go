// Package cli wires the commands. Every command is a thin, typed wrapper around one MCP tool
// call; `brainitive tool <name>` reaches any tool the server has, wrapped or not.
package cli

import (
	"context"
	"errors"
	"fmt"
	"os"
	"strings"

	"github.com/spf13/cobra"

	"github.com/armendzekjiri/brainitive/cli/internal/api"
	"github.com/armendzekjiri/brainitive/cli/internal/auth"
	"github.com/armendzekjiri/brainitive/cli/internal/config"
	"github.com/armendzekjiri/brainitive/cli/internal/mcp"
	"github.com/armendzekjiri/brainitive/cli/internal/output"
)

// App is the state shared by the commands of one invocation.
type App struct {
	Version string
	cfg     *config.Config
	out     *output.Printer

	jsonOut   bool
	server    string
	workspace string
	project   string
	board     string

	client *mcp.Client
	ext    *api.Client

	// unsaved: a refreshed session is only in memory (its save failed); see sessionTokens.refresh.
	unsaved bool
}

var errNotSignedIn = errors.New("not signed in. Run `brainitive login` (or set BRAINITIVE_TOKEN)")

// Execute runs the CLI.
func Execute(version string) error {
	app := &App{Version: version}
	root := app.rootCommand()
	return root.Execute()
}

func (a *App) rootCommand() *cobra.Command {
	var quickSearch, quickCapture string
	var quickWorkspaces, quickProjects, quickBoards, quickIssues, quickTasks bool
	search, capture := a.searchCommand(), a.captureCommand()
	workspaces, projects, boards := a.workspacesCommand(), a.projectsCommand(), a.boardsCommand()
	issues, tasks := a.issuesCommand(), a.tasksCommand()
	root := &cobra.Command{
		Use:   "brainitive",
		Short: "Brainitive from your terminal: capture, issues, tasks, snippets, docs, search — and an MCP bridge for AI agents",
		Long: `Brainitive from your terminal.

Sign in once with "brainitive login" (your browser opens; works with Brainitive Cloud and
self-hosted servers). Then capture thoughts, create issues and tasks, save snippets and
bookmarks, search everything, and give AI agents the whole workspace with "brainitive mcp".

Defaults: set them once with "brainitive use <workspace>[/<project>[/<board>]]" or pass
--workspace / --project / --board. With only one workspace or project, nothing is needed.

Quick shortcuts: -s search, -c capture, -W workspaces, -P projects, -B boards,
-i issues and -t tasks. Add -j for JSON output.
Every command prints a table for people, or the raw result with --json for scripts.`,
		SilenceUsage:  true,
		SilenceErrors: true,
		PersistentPreRunE: func(cmd *cobra.Command, args []string) error {
			cfg, err := config.Load()
			if err != nil {
				return err
			}
			a.cfg = cfg
			a.out = output.New(a.jsonMode())
			return nil
		},
	}
	root.Flags().StringVarP(&quickSearch, "quick-search", "s", "", "search for text")
	root.Flags().StringVarP(&quickCapture, "quick-capture", "c", "", "capture text to your inbox")
	root.Flags().BoolVarP(&quickWorkspaces, "quick-workspaces", "W", false, "list workspaces")
	root.Flags().BoolVarP(&quickProjects, "quick-projects", "P", false, "list projects")
	root.Flags().BoolVarP(&quickBoards, "quick-boards", "B", false, "list boards")
	root.Flags().BoolVarP(&quickIssues, "quick-issues", "i", false, "list board issues")
	root.Flags().BoolVarP(&quickTasks, "quick-tasks", "t", false, "list open tasks")
	root.RunE = func(cmd *cobra.Command, args []string) error {
		searchSet := cmd.Flags().Changed("quick-search")
		captureSet := cmd.Flags().Changed("quick-capture")
		selected := 0
		for _, enabled := range []bool{searchSet, captureSet, quickWorkspaces, quickProjects, quickBoards, quickIssues, quickTasks} {
			if enabled {
				selected++
			}
		}
		if selected > 1 {
			return errors.New("choose one quick action at a time")
		}
		if selected == 0 {
			return cmd.Help()
		}
		if len(args) != 0 {
			return errors.New("quick actions do not accept extra arguments")
		}
		for _, action := range []struct {
			enabled bool
			command *cobra.Command
		}{
			{quickWorkspaces, workspaces}, {quickProjects, projects}, {quickBoards, boards},
			{quickIssues, issues}, {quickTasks, tasks},
		} {
			if action.enabled {
				action.command.SetContext(cmd.Context())
				return action.command.RunE(action.command, nil)
			}
		}

		if searchSet {
			if strings.TrimSpace(quickSearch) == "" {
				return errors.New("search text cannot be empty")
			}
			search.SetContext(cmd.Context())
			return search.RunE(search, []string{quickSearch})
		}
		if strings.TrimSpace(quickCapture) == "" {
			return errors.New("capture text cannot be empty")
		}
		capture.SetContext(cmd.Context())
		return capture.RunE(capture, []string{quickCapture})
	}
	root.PersistentFlags().BoolVarP(&a.jsonOut, "json", "j", false, "print the raw result as JSON (for scripts)")
	root.PersistentFlags().StringVar(&a.server, "server", "", "Brainitive server (default: the one you signed in to, or $BRAINITIVE_SERVER)")
	root.PersistentFlags().StringVarP(&a.workspace, "workspace", "w", "", "workspace slug (default: from brainitive use)")
	root.PersistentFlags().StringVarP(&a.project, "project", "p", "", "project slug")
	root.PersistentFlags().StringVarP(&a.board, "board", "b", "", "board slug")

	root.AddCommand(
		a.loginCommand(), a.logoutCommand(), a.whoamiCommand(), a.useCommand(), a.configCommand(),
		workspaces, projects, a.projectCommand(), boards, a.pagesCommand(), a.pageCommand(),
		search, capture,
		a.snippetsCommand(), a.snippetCommand(), a.bookmarksCommand(), a.bookmarkCommand(),
		tasks, a.taskCommand(),
		issues, a.issueCommand(), a.sprintsCommand(),
		a.toolsCommand(), a.toolCommand(), a.mcpCommand(),
		a.updateCommand(), a.versionCommand(),
	)
	return root
}

func (a *App) jsonMode() bool {
	return a.jsonOut
}

func (a *App) serverURL() string {
	if a.server != "" {
		return config.NormalizeServer(a.server)
	}
	if env := os.Getenv("BRAINITIVE_SERVER"); env != "" {
		return config.NormalizeServer(env)
	}
	return a.cfg.Server
}

// sessionTokens is the TokenSource backed by the config file (refreshed and saved as needed)
// or by BRAINITIVE_TOKEN, for requests to one server. The stored session's tokens are only handed
// out for the server that issued them (Codex CLI/extension review 1); BRAINITIVE_TOKEN is the
// caller's own pairing with --server / BRAINITIVE_SERVER (else the configured server).
type sessionTokens struct {
	app    *App
	server string
	// sessionOnly ignores BRAINITIVE_TOKEN: logout and the call right after login use the session.
	sessionOnly bool
}

func (s *sessionTokens) session() (*config.Session, error) {
	sess := s.app.cfg.Session
	if sess == nil || sess.AccessToken == "" {
		return nil, errNotSignedIn
	}
	if sess.Server != s.server {
		return nil, fmt.Errorf("you're signed in to %s, not %s. Run `brainitive login --server %s` to sign in there (each server has its own sign-in)", sess.Server, s.server, s.server)
	}
	return sess, nil
}

func (s *sessionTokens) Token(ctx context.Context) (string, error) {
	if env := os.Getenv("BRAINITIVE_TOKEN"); env != "" && !s.sessionOnly {
		return env, nil
	}
	sess, err := s.session()
	if err != nil {
		return "", err
	}
	if auth.Expiring(sess) {
		return s.refresh(ctx, sess)
	}
	if s.app.unsaved {
		// An earlier refresh in this process couldn't be saved: no token until it is.
		if err := s.save(); err != nil {
			return "", err
		}
	}
	return sess.AccessToken, nil
}

// refresh rotates the session's tokens and saves them before the new access token is used.
// Refresh tokens are single use: if the save fails, the file still holds the consumed one, so
// carrying on would lose the session at the next run without saying why (Codex CLI/extension
// review 11). The rotated pair stays in memory and the next call in this process retries the save.
func (s *sessionTokens) refresh(ctx context.Context, sess *config.Session) (string, error) {
	if err := auth.Refresh(ctx, sess); err != nil {
		return "", err
	}
	s.app.unsaved = true
	if err := s.save(); err != nil {
		return "", err
	}
	return sess.AccessToken, nil
}

func (s *sessionTokens) save() error {
	if err := s.app.cfg.Save(); err != nil {
		path, _ := config.Path()
		return fmt.Errorf("the session was refreshed but couldn't save it to %s (%v); the old refresh token is used up. Fix the file's location / permissions, then run `brainitive login` again", path, err)
	}
	s.app.unsaved = false
	return nil
}

func (s *sessionTokens) Renew(ctx context.Context) (string, error) {
	if os.Getenv("BRAINITIVE_TOKEN") != "" && !s.sessionOnly {
		return "", errors.New("BRAINITIVE_TOKEN was rejected (expired or revoked). Make a new one, or unset it and run `brainitive login`")
	}
	sess, err := s.session()
	if err != nil {
		return "", err
	}
	return s.refresh(ctx, sess)
}

func (a *App) mcp() *mcp.Client {
	if a.client == nil {
		server := a.serverURL()
		a.client = mcp.New(server, &sessionTokens{app: a, server: server}, a.Version)
	}
	return a.client
}

func (a *App) extAPI() *api.Client {
	if a.ext == nil {
		server := a.serverURL()
		a.ext = api.New(server, &sessionTokens{app: a, server: server})
	}
	return a.ext
}

// call runs a tool and, in JSON mode, prints the structured result and returns handled=true.
func (a *App) call(ctx context.Context, tool string, args map[string]any) (*mcp.Result, error) {
	res, err := a.mcp().Call(ctx, tool, args)
	if err != nil {
		return nil, err
	}
	if a.jsonMode() {
		if res.Structured != nil {
			a.out.JSONValue(res.Structured)
		} else {
			a.out.JSONValue(map[string]any{"text": res.Text})
		}
	}
	return res, nil
}

// located prints the standard "Created X → url" line from a write tool's location.
func (a *App) located(verb string, res *mcp.Result, keyField string) {
	loc := output.Map(res.Structured["location"])
	label := output.Str(res.Structured[keyField])
	if label == "" {
		label = output.Str(loc["title"])
	}
	where := output.Str(loc["breadcrumb"])
	url := output.Str(loc["url"])
	switch {
	case where != "" && url != "":
		a.out.Line("%s %s  (%s)\n%s", verb, label, where, url)
	case url != "":
		a.out.Line("%s %s\n%s", verb, label, url)
	default:
		a.out.Line("%s %s", verb, label)
	}
}

// readBody returns text from --file / -f, from stdin when piped, or the joined args.
func readBody(args []string, file string) (string, error) {
	if file == "-" {
		data, err := readStdin()
		return data, err
	}
	if file != "" {
		data, err := os.ReadFile(file)
		if err != nil {
			return "", err
		}
		return string(data), nil
	}
	if len(args) > 0 {
		return strings.Join(args, " "), nil
	}
	if stdinPiped() {
		return readStdin()
	}
	return "", nil
}

func stdinPiped() bool {
	info, err := os.Stdin.Stat()
	return err == nil && info.Mode()&os.ModeCharDevice == 0
}

func readStdin() (string, error) {
	var sb strings.Builder
	buf := make([]byte, 32*1024)
	for {
		n, err := os.Stdin.Read(buf)
		sb.Write(buf[:n])
		if err != nil {
			break
		}
	}
	return strings.TrimRight(sb.String(), "\n"), nil
}

func (a *App) versionCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "version",
		Short: "Print the CLI version",
		Run: func(cmd *cobra.Command, args []string) {
			fmt.Fprintln(cmd.OutOrStdout(), "brainitive", a.Version)
		},
	}
}

// requestID makes an id for idempotent writes (a retry of the same command is a new write;
// the id protects against the server applying one request twice).
func requestID() string {
	return "cli-" + randomHex(12)
}
