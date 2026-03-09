"""Config stored at ~/.brainitive.json"""

import json
import os
from pathlib import Path
from typing import Optional

CONFIG_PATH = Path.home() / ".brainitive.json"


def load() -> dict:
    if CONFIG_PATH.exists():
        try:
            return json.loads(CONFIG_PATH.read_text())
        except Exception:
            pass
    return {}


def save(data: dict) -> None:
    CONFIG_PATH.write_text(json.dumps(data, indent=2))
    CONFIG_PATH.chmod(0o600)


def get_token() -> Optional[str]:
    return load().get("token")


def get_url() -> Optional[str]:
    return load().get("url")


def get_user() -> Optional[dict]:
    return load().get("user")


def set_auth(url: str, token: str, user: dict) -> None:
    cfg = load()
    cfg.update({"url": url.rstrip("/"), "token": token, "user": user})
    save(cfg)


def clear_auth() -> None:
    cfg = load()
    cfg.pop("token", None)
    cfg.pop("user", None)
    save(cfg)


def is_logged_in() -> bool:
    cfg = load()
    return bool(cfg.get("token") and cfg.get("url"))
