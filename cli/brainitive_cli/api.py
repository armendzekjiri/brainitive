"""HTTP client wrapper for the Brainitive CLI API."""

from typing import Any, Optional

import httpx

from . import config


class APIError(Exception):
    def __init__(self, message: str, status_code: int = 0):
        super().__init__(message)
        self.status_code = status_code


def _client(token: Optional[str] = None) -> httpx.Client:
    base_url = config.get_url() or ""
    headers = {"Accept": "application/json", "Content-Type": "application/json"}
    if token:
        headers["Authorization"] = f"Bearer {token}"
    elif config.get_token():
        headers["Authorization"] = f"Bearer {config.get_token()}"
    return httpx.Client(base_url=base_url, headers=headers, timeout=15)


def _handle(resp: httpx.Response) -> Any:
    if resp.status_code == 401:
        raise APIError("Not authenticated. Run: brainitive login", 401)
    if resp.status_code == 403:
        raise APIError("Permission denied.", 403)
    if resp.status_code == 404:
        raise APIError("Not found.", 404)
    if resp.status_code == 422:
        errors = resp.json().get("errors", {})
        msgs = [v[0] for v in errors.values()] if errors else [resp.json().get("message", "Validation error")]
        raise APIError(", ".join(msgs), 422)
    if not resp.is_success:
        try:
            msg = resp.json().get("message", resp.text)
        except Exception:
            msg = resp.text
        raise APIError(f"HTTP {resp.status_code}: {msg}", resp.status_code)
    return resp.json()


# ─── Auth ─────────────────────────────────────────────────────────────────────

def login(url: str, email: str, password: str) -> dict:
    with httpx.Client(base_url=url.rstrip("/"), headers={"Accept": "application/json"}, timeout=10) as c:
        resp = c.post("/api/cli/login", json={"email": email, "password": password})
    return _handle(resp)


def logout() -> dict:
    with _client() as c:
        resp = c.delete("/api/cli/logout")
    return _handle(resp)


def me() -> dict:
    with _client() as c:
        resp = c.get("/api/cli/me")
    return _handle(resp)


# ─── Workspaces ───────────────────────────────────────────────────────────────

def workspaces() -> list[dict]:
    with _client() as c:
        resp = c.get("/api/cli/workspaces")
    return _handle(resp)["workspaces"]


# ─── Task Lists ───────────────────────────────────────────────────────────────

def lists(workspace: Optional[str] = None) -> list[dict]:
    params = {}
    if workspace:
        params["workspace"] = workspace
    with _client() as c:
        resp = c.get("/api/cli/lists", params=params)
    return _handle(resp)["lists"]


def show_list(slug: str) -> dict:
    with _client() as c:
        resp = c.get(f"/api/cli/lists/{slug}")
    return _handle(resp)["list"]


# ─── Items ────────────────────────────────────────────────────────────────────

def add_item(list_slug: str, title: str, due_date: Optional[str] = None,
             recurrence: Optional[str] = None, assignee_id: Optional[int] = None) -> dict:
    payload: dict = {"title": title}
    if due_date:
        payload["due_date"] = due_date
    if recurrence:
        payload["recurrence"] = recurrence
    if assignee_id:
        payload["assignee_id"] = assignee_id
    with _client() as c:
        resp = c.post(f"/api/cli/lists/{list_slug}/items", json=payload)
    return _handle(resp)["item"]


def update_item(list_slug: str, item_id: int, **kwargs) -> dict:
    with _client() as c:
        resp = c.patch(f"/api/cli/lists/{list_slug}/items/{item_id}", json=kwargs)
    data = _handle(resp)
    return data


def delete_item(list_slug: str, item_id: int) -> None:
    with _client() as c:
        resp = c.delete(f"/api/cli/lists/{list_slug}/items/{item_id}")
    _handle(resp)
