"""Configuration loaded from environment variables and an optional .env file."""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parent.parent


def load_dotenv(path: Path | None = None) -> None:
    """Minimal .env loader (no dependency). Existing env vars win."""
    path = path or PROJECT_ROOT / ".env"
    if not path.is_file():
        return
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        key = key.strip().removeprefix("export ").strip()
        value = value.strip().strip('"').strip("'")
        os.environ.setdefault(key, value)


@dataclass(frozen=True)
class Settings:
    plex_url: str
    plex_token: str
    tmdb_api_key: str | None = None
    language: str = "da-DK"
    plex_account_id: str | None = None
    plex_verify_ssl: bool = True
    cache_ttl_seconds: int = 600

    @classmethod
    def from_env(cls) -> "Settings":
        load_dotenv()
        url = os.environ.get("PLEX_URL", "").rstrip("/")
        token = os.environ.get("PLEX_TOKEN", "")
        if not url or not token:
            raise RuntimeError(
                "PLEX_URL og PLEX_TOKEN skal være sat (i miljøet eller i .env). "
                "Se README.md for hvordan du finder dit Plex-token."
            )
        return cls(
            plex_url=url,
            plex_token=token,
            tmdb_api_key=os.environ.get("TMDB_API_KEY") or None,
            language=os.environ.get("RECOMMENDER_LANGUAGE", "da-DK"),
            plex_account_id=os.environ.get("PLEX_ACCOUNT_ID") or None,
            plex_verify_ssl=os.environ.get("PLEX_VERIFY_SSL", "true").lower()
            not in ("0", "false", "no"),
            cache_ttl_seconds=int(os.environ.get("CACHE_TTL_SECONDS", "600")),
        )
