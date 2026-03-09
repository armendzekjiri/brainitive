"""
Brainitive CLI — beautiful terminal client for Brainitive workspaces.

Usage:
    brainitive login
    brainitive logout
    brainitive lists [--workspace SLUG]
    brainitive show  <list-slug>
    brainitive add   <list-slug> [TITLE] [--due YYYY-MM-DD] [--repeat FREQ]
    brainitive done  <list-slug> <item-id>
    brainitive undo  <list-slug> <item-id>
    brainitive rm    <list-slug> <item-id>
    brainitive tui   <list-slug>
"""

import sys
from datetime import date, datetime, timedelta
from typing import Optional

import typer
from rich import box
from rich.columns import Columns
from rich.console import Console
from rich.panel import Panel
from rich.prompt import Confirm, Prompt
from rich.table import Table
from rich.text import Text
from rich.theme import Theme

from . import __version__, api, config
from .api import APIError

# ─── Theme & Console ──────────────────────────────────────────────────────────

THEME = Theme({
    "brand":    "bold #0E83DC",
    "success":  "bold #69A748",
    "warning":  "bold #f59e0b",
    "danger":   "bold red",
    "muted":    "#6b7280",
    "title":    "bold #485E72",
    "done":     "strike #9ca3af",
    "overdue":  "bold red",
    "today":    "bold #f59e0b",
    "soon":     "#0E83DC",
    "repeat":   "#8b5cf6",
})

console = Console(theme=THEME)
err     = Console(stderr=True, theme=THEME)

app = typer.Typer(
    name="brainitive",
    help="🧠  Brainitive — terminal client",
    add_completion=True,
    rich_markup_mode="rich",
    no_args_is_help=True,
)

# ─── Helpers ──────────────────────────────────────────────────────────────────

LOGO = """[brand]
  ██████╗ ██████╗  █████╗ ██╗███╗   ██╗██╗████████╗██╗██╗   ██╗███████╗
  ██╔══██╗██╔══██╗██╔══██╗██║████╗  ██║██║╚══██╔══╝██║██║   ██║██╔════╝
  ██████╔╝██████╔╝███████║██║██╔██╗ ██║██║   ██║   ██║██║   ██║█████╗
  ██╔══██╗██╔══██╗██╔══██║██║██║╚██╗██║██║   ██║   ██║╚██╗ ██╔╝██╔══╝
  ██████╔╝██║  ██║██║  ██║██║██║ ╚████║██║   ██║   ██║ ╚████╔╝ ███████╗
  ╚═════╝ ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝╚═╝  ╚═══╝╚═╝   ╚═╝   ╚═╝  ╚═══╝  ╚══════╝[/brand]"""


def header(subtitle: str = "") -> None:
    parts = [f"[brand]🧠  Brainitive CLI[/brand]  [muted]v{__version__}[/muted]"]
    if subtitle:
        parts.append(f"[muted]·[/muted]  [title]{subtitle}[/title]")
    console.print(Panel(" ".join(parts), border_style="#0E83DC", padding=(0, 1)))


def require_login() -> None:
    if not config.is_logged_in():
        err.print("[danger]✗[/danger] Not logged in. Run: [brand]brainitive login[/brand]")
        raise typer.Exit(1)


def _due_label(due_str: Optional[str]) -> Text:
    if not due_str:
        return Text("—", style="muted")
    try:
        d = date.fromisoformat(due_str[:10])
    except ValueError:
        return Text(due_str[:10], style="muted")
    today = date.today()
    if d < today:
        return Text(d.strftime("%-d %b"), style="overdue")
    if d == today:
        return Text("Today", style="today")
    if d == today + timedelta(days=1):
        return Text("Tomorrow", style="soon")
    if d <= today + timedelta(days=7):
        return Text(d.strftime("%-d %b"), style="soon")
    return Text(d.strftime("%-d %b"), style="muted")


def _repeat_label(rec: Optional[str]) -> Text:
    icons = {"daily": "↺ daily", "weekly": "↺ weekly", "monthly": "↺ monthly", "yearly": "↺ yearly"}
    if rec and rec in icons:
        return Text(icons[rec], style="repeat")
    return Text("—", style="muted")


def _status_icon(status: str) -> Text:
    return Text("●", style="success") if status == "done" else Text("○", style="muted")


def _build_items_table(items: list[dict], show_id: bool = True) -> Table:
    t = Table(box=box.SIMPLE_HEAD, show_header=True, header_style="title",
              pad_edge=False, expand=False)
    if show_id:
        t.add_column("ID",     justify="right", style="muted", no_wrap=True, width=5)
    t.add_column("",           justify="center", width=2)
    t.add_column("Title",      min_width=24, no_wrap=False)
    t.add_column("Due",        width=10)
    t.add_column("Repeat",     width=11)
    t.add_column("Assignee",   width=14)

    for it in items:
        is_done = it["status"] == "done"
        title_text = Text(it["title"], style="done" if is_done else "")
        assignee = it.get("assignee") or {}
        assignee_name = Text(assignee.get("name", "—")[:13], style="muted")

        row_parts: list = []
        if show_id:
            row_parts.append(str(it["id"]))
        row_parts += [
            _status_icon(it["status"]),
            title_text,
            _due_label(it.get("due_date")),
            _repeat_label(it.get("recurrence")),
            assignee_name,
        ]
        t.add_row(*row_parts)

    return t


# ─── Commands ─────────────────────────────────────────────────────────────────

@app.command()
def login(
    url: Optional[str] = typer.Option(None, "--url", "-u", help="App URL (e.g. https://app.example.com)"),
) -> None:
    """Authenticate and store a token locally."""
    header("Login")

    if config.is_logged_in():
        user = config.get_user() or {}
        console.print(f"[muted]Already logged in as[/muted] [success]{user.get('name', '?')}[/success]  [muted]({user.get('email', '')})[/muted]")
        if not Confirm.ask("Log in again?", default=False):
            raise typer.Exit()

    app_url = url or Prompt.ask("[brand]App URL[/brand]", default=config.get_url() or "http://localhost:8000")
    email   = Prompt.ask("[brand]Email[/brand]")
    password = Prompt.ask("[brand]Password[/brand]", password=True)

    with console.status("[muted]Authenticating…[/muted]"):
        try:
            data = api.login(app_url, email, password)
        except APIError as e:
            err.print(f"[danger]✗[/danger] {e}")
            raise typer.Exit(1)

    config.set_auth(app_url, data["token"], data["user"])
    console.print(f"\n[success]✓[/success] Logged in as [title]{data['user']['name']}[/title]  [muted]({data['user']['email']})[/muted]")
    console.print(f"[muted]  Token saved to ~/.brainitive.json[/muted]\n")


@app.command()
def logout() -> None:
    """Revoke the stored token and clear local credentials."""
    require_login()
    header("Logout")
    try:
        api.logout()
    except APIError:
        pass
    config.clear_auth()
    console.print("[success]✓[/success] Logged out.")


@app.command()
def whoami() -> None:
    """Show the currently authenticated user."""
    require_login()
    try:
        data = api.me()
    except APIError as e:
        err.print(f"[danger]✗[/danger] {e}")
        raise typer.Exit(1)
    u = data["user"]
    console.print(f"[title]{u['name']}[/title]  [muted]<{u['email']}>[/muted]")


@app.command(name="lists")
def cmd_lists(
    workspace: Optional[str] = typer.Option(None, "--workspace", "-w", help="Filter by workspace slug"),
) -> None:
    """List all task lists you have access to."""
    require_login()
    header("Task Lists")

    with console.status("[muted]Loading lists…[/muted]"):
        try:
            data = api.lists(workspace)
        except APIError as e:
            err.print(f"[danger]✗[/danger] {e}")
            raise typer.Exit(1)

    if not data:
        console.print("[muted]No task lists found.[/muted]  Create one in the web app first.")
        return

    t = Table(box=box.SIMPLE_HEAD, show_header=True, header_style="title", expand=False, pad_edge=False)
    t.add_column("Slug",        style="brand",   no_wrap=True)
    t.add_column("Name",        style="title",   min_width=18)
    t.add_column("Project",     style="muted",   min_width=14)
    t.add_column("Workspace",   style="muted",   min_width=14)
    t.add_column("Todo",        justify="right")
    t.add_column("Done",        justify="right", style="success")

    for lst in data:
        proj = lst.get("project") or {}
        ws   = proj.get("workspace") or {}
        t.add_row(
            lst["slug"],
            lst["name"],
            proj.get("name", "—"),
            ws.get("name", "—"),
            str(lst.get("todo_count", "?")),
            str(lst.get("done_count", "?")),
        )

    console.print(t)
    console.print(f"[muted]  {len(data)} list(s)  ·  run [/muted][brand]brainitive show <slug>[/brand][muted] to see items[/muted]\n")


@app.command()
def show(
    list_slug: str = typer.Argument(..., help="List slug"),
    filter_: Optional[str] = typer.Option(None, "--filter", "-f",
                                           help="Filter items: todo | done | all (default: all)"),
) -> None:
    """Show all items in a task list."""
    require_login()

    with console.status("[muted]Loading…[/muted]"):
        try:
            lst = api.show_list(list_slug)
        except APIError as e:
            err.print(f"[danger]✗[/danger] {e}")
            raise typer.Exit(1)

    proj = lst.get("project") or {}
    ws   = (proj.get("workspace") or {}).get("name", "")
    desc = lst.get("description") or ""

    header(f"{lst['name']}  [muted]·[/muted]  {proj.get('name', '')}  [muted]·[/muted]  {ws}")
    if desc:
        console.print(f"[muted]  {desc}[/muted]\n")

    items = lst.get("items") or []

    f = (filter_ or "all").lower()
    if f == "todo":
        items = [i for i in items if i["status"] == "todo"]
    elif f == "done":
        items = [i for i in items if i["status"] == "done"]

    if not items:
        console.print("[muted]  No items.[/muted]\n")
    else:
        console.print(_build_items_table(items))

    todo = sum(1 for i in (lst.get("items") or []) if i["status"] == "todo")
    done = sum(1 for i in (lst.get("items") or []) if i["status"] == "done")
    console.print(
        f"[muted]  {todo} to do  ·  [success]{done} done[/success][/muted]"
        f"[muted]  ·  [brand]brainitive add {list_slug}[/brand] to add  ·  "
        f"[brand]brainitive done {list_slug} <id>[/brand] to complete[/muted]\n"
    )


@app.command()
def add(
    list_slug: str          = typer.Argument(..., help="List slug"),
    title: Optional[str]    = typer.Argument(None, help="Item title (prompted if omitted)"),
    due: Optional[str]      = typer.Option(None, "--due", "-d", help="Due date YYYY-MM-DD"),
    repeat: Optional[str]   = typer.Option(None, "--repeat", "-r",
                                            help="Recurrence: daily | weekly | monthly | yearly"),
) -> None:
    """Add a new item to a task list."""
    require_login()
    header(f"Add item → {list_slug}")

    if not title:
        title = Prompt.ask("[brand]Title[/brand]")
    if not title:
        err.print("[danger]✗[/danger] Title is required.")
        raise typer.Exit(1)

    if not due:
        raw = Prompt.ask("[brand]Due date[/brand] [muted](YYYY-MM-DD, leave blank to skip)[/muted]", default="")
        due = raw.strip() or None

    if not repeat:
        raw = Prompt.ask("[brand]Repeat[/brand] [muted](daily/weekly/monthly/yearly, leave blank to skip)[/muted]", default="")
        repeat = raw.strip() or None

    with console.status("[muted]Adding…[/muted]"):
        try:
            item = api.add_item(list_slug, title, due_date=due, recurrence=repeat)
        except APIError as e:
            err.print(f"[danger]✗[/danger] {e}")
            raise typer.Exit(1)

    parts = [f"[success]✓[/success] Added: [title]{item['title']}[/title]"]
    if item.get("due_date"):
        parts.append(f"[muted]due[/muted] {_due_label(item['due_date'])}")
    if item.get("recurrence"):
        parts.append(f"[muted]repeats[/muted] {_repeat_label(item['recurrence'])}")
    console.print("  ".join(str(p) for p in parts))
    console.print(f"  [muted]ID: {item['id']}[/muted]\n")


@app.command()
def done(
    list_slug: str  = typer.Argument(..., help="List slug"),
    item_id: int    = typer.Argument(..., help="Item ID"),
) -> None:
    """Mark an item as done (recurring items auto-advance the due date)."""
    require_login()

    with console.status("[muted]Marking done…[/muted]"):
        try:
            result = api.update_item(list_slug, item_id, status="done")
        except APIError as e:
            err.print(f"[danger]✗[/danger] {e}")
            raise typer.Exit(1)

    item = result.get("item", {})
    bounced = result.get("recurrence_bounced", False)

    if bounced:
        next_date = result.get("next_due_date", "")
        console.print(f"[success]✓[/success] Completed!  [repeat]↺ Recurring[/repeat] — "
                      f"next due: [today]{next_date}[/today]")
        console.print(f"  [muted]Item reset to To Do.[/muted]\n")
    else:
        console.print(f"[success]✓[/success] Done: [done]{item.get('title', '')}[/done]\n")


@app.command()
def undo(
    list_slug: str  = typer.Argument(..., help="List slug"),
    item_id: int    = typer.Argument(..., help="Item ID"),
) -> None:
    """Mark a done item back as to-do."""
    require_login()

    with console.status("[muted]Undoing…[/muted]"):
        try:
            result = api.update_item(list_slug, item_id, status="todo")
        except APIError as e:
            err.print(f"[danger]✗[/danger] {e}")
            raise typer.Exit(1)

    item = result.get("item", {})
    console.print(f"[success]✓[/success] Back to To Do: [title]{item.get('title', '')}[/title]\n")


@app.command()
def rm(
    list_slug: str  = typer.Argument(..., help="List slug"),
    item_id: int    = typer.Argument(..., help="Item ID"),
    force: bool     = typer.Option(False, "--force", "-f", help="Skip confirmation"),
) -> None:
    """Delete an item from a list."""
    require_login()

    if not force:
        if not Confirm.ask(f"[danger]Delete item {item_id} from {list_slug}?[/danger]", default=False):
            raise typer.Exit()

    with console.status("[muted]Deleting…[/muted]"):
        try:
            api.delete_item(list_slug, item_id)
        except APIError as e:
            err.print(f"[danger]✗[/danger] {e}")
            raise typer.Exit(1)

    console.print(f"[success]✓[/success] Item {item_id} deleted.\n")


# ─── Interactive TUI ──────────────────────────────────────────────────────────

@app.command()
def tui(
    list_slug: str = typer.Argument(..., help="List slug to open interactively"),
) -> None:
    """
    Interactive terminal UI for a task list.

    Keys: [j/k] navigate  [space/enter] toggle done  [a] add  [d] delete  [r] refresh  [q] quit
    """
    require_login()
    _run_tui(list_slug)


def _run_tui(list_slug: str) -> None:
    """Keyboard-driven interactive list viewer."""
    import tty, termios, os  # noqa

    def get_key() -> str:
        fd = sys.stdin.fileno()
        old = termios.tcgetattr(fd)
        try:
            tty.setraw(fd)
            ch = sys.stdin.read(1)
            if ch == "\x1b":
                ch2 = sys.stdin.read(2)
                return ch + ch2
            return ch
        finally:
            termios.tcsetattr(fd, termios.TCSADRAIN, old)

    def load() -> tuple[dict, list[dict]]:
        lst = api.show_list(list_slug)
        items = lst.get("items") or []
        return lst, items

    def render(lst: dict, items: list[dict], cursor: int, status_msg: str = "") -> None:
        console.clear()

        proj = lst.get("project") or {}
        ws   = (proj.get("workspace") or {}).get("name", "")
        desc = lst.get("description") or ""

        console.print(Panel(
            f"[brand]🧠  {lst['name']}[/brand]  [muted]·  {proj.get('name','')}  ·  {ws}[/muted]"
            + (f"\n[muted]{desc}[/muted]" if desc else ""),
            border_style="#0E83DC", padding=(0, 1),
        ))

        if not items:
            console.print("\n[muted]  No items. Press [brand]a[/brand] to add one.[/muted]\n")
        else:
            t = Table(box=box.SIMPLE_HEAD, show_header=True, header_style="title",
                      pad_edge=False, expand=True)
            t.add_column("",       width=3,  justify="center")
            t.add_column("",       width=2,  justify="center")
            t.add_column("Title",  min_width=24)
            t.add_column("Due",    width=10)
            t.add_column("Repeat", width=11)
            t.add_column("ID",     width=6,  justify="right", style="muted")

            for idx, it in enumerate(items):
                is_done   = it["status"] == "done"
                is_cursor = idx == cursor
                pointer   = Text("▶", style="brand") if is_cursor else Text(" ")
                title_sty = "done" if is_done else ("bold" if is_cursor else "")
                t.add_row(
                    pointer,
                    _status_icon(it["status"]),
                    Text(it["title"], style=title_sty),
                    _due_label(it.get("due_date")),
                    _repeat_label(it.get("recurrence")),
                    str(it["id"]),
                )

            console.print(t)

        todo = sum(1 for i in items if i["status"] == "todo")
        done_ = len(items) - todo
        console.print(f"\n[muted]  {todo} to do  ·  [success]{done_} done[/success][/muted]")
        if status_msg:
            console.print(f"  [success]{status_msg}[/success]")
        console.print(
            "\n[muted]  [brand]j/k[/brand] navigate  "
            "[brand]space[/brand] toggle  "
            "[brand]a[/brand] add  "
            "[brand]D[/brand] delete  "
            "[brand]r[/brand] refresh  "
            "[brand]q[/brand] quit[/muted]\n"
        )

    try:
        lst, items = load()
    except APIError as e:
        err.print(f"[danger]✗[/danger] {e}")
        raise typer.Exit(1)

    cursor = 0
    status_msg = ""

    while True:
        render(lst, items, cursor, status_msg)
        status_msg = ""

        key = get_key()

        if key in ("q", "Q", "\x03"):       # quit
            console.clear()
            break

        elif key in ("j", "\x1b[B"):        # down
            if items:
                cursor = min(cursor + 1, len(items) - 1)

        elif key in ("k", "\x1b[A"):        # up
            if items:
                cursor = max(cursor - 1, 0)

        elif key in (" ", "\r", "\n"):      # toggle done
            if items:
                it = items[cursor]
                try:
                    result = api.update_item(list_slug, it["id"], status="todo" if it["status"] == "done" else "done")
                    if result.get("recurrence_bounced"):
                        nd = result.get("next_due_date", "")
                        status_msg = f"↺ Recurring — next due: {nd}"
                    else:
                        new_status = result["item"]["status"]
                        status_msg = "✓ Done!" if new_status == "done" else "↩ Back to To Do"
                    lst, items = load()
                    cursor = min(cursor, max(0, len(items) - 1))
                except APIError as e:
                    status_msg = f"Error: {e}"

        elif key in ("a", "A"):             # add
            console.clear()
            console.print(Panel("[brand]Add Item[/brand]", border_style="#0E83DC", padding=(0, 1)))
            try:
                title  = Prompt.ask("[brand]Title[/brand]")
                due    = Prompt.ask("[brand]Due date[/brand] [muted](YYYY-MM-DD, blank to skip)[/muted]", default="") or None
                repeat = Prompt.ask("[brand]Repeat[/brand] [muted](daily/weekly/monthly/yearly, blank to skip)[/muted]", default="") or None
                api.add_item(list_slug, title, due_date=due, recurrence=repeat)
                lst, items = load()
                cursor = len(items) - 1
                status_msg = f"✓ Added: {title}"
            except (APIError, KeyboardInterrupt) as e:
                status_msg = f"Cancelled" if isinstance(e, KeyboardInterrupt) else str(e)

        elif key in ("D",):                 # delete
            if items:
                it = items[cursor]
                try:
                    api.delete_item(list_slug, it["id"])
                    status_msg = f"✓ Deleted: {it['title']}"
                    lst, items = load()
                    cursor = min(cursor, max(0, len(items) - 1))
                except APIError as e:
                    status_msg = f"Error: {e}"

        elif key in ("r", "R"):             # refresh
            try:
                lst, items = load()
                cursor = min(cursor, max(0, len(items) - 1))
                status_msg = "✓ Refreshed"
            except APIError as e:
                status_msg = f"Error: {e}"


# ─── Entry point ──────────────────────────────────────────────────────────────

def main() -> None:
    app()


if __name__ == "__main__":
    main()
