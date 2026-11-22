"""Thin client for the Plex Media Server HTTP API."""

from __future__ import annotations

import re
from typing import Any, Iterator

import requests

from .models import MOVIE, SHOW, MediaItem

_TYPE_CODES = {MOVIE: 1, SHOW: 2}
_GUID_PATTERNS = {
    "tmdb_id": re.compile(r"(?:tmdb|themoviedb)://(\d+)"),
    "imdb_id": re.compile(r"imdb://(tt\d+)"),
    "tvdb_id": re.compile(r"(?:tvdb|thetvdb)://(\d+)"),
}


def _tags(meta: dict, key: str, limit: int | None = None) -> list[str]:
    tags = [t.get("tag", "") for t in meta.get(key, []) or [] if t.get("tag")]
    return tags[:limit] if limit else tags


def _float(v: Any) -> float | None:
    try:
        return float(v) if v is not None else None
    except (TypeError, ValueError):
        return None


def parse_item(meta: dict, library: str = "") -> MediaItem:
    """Convert a Plex Metadata JSON object to a MediaItem."""
    item = MediaItem(
        rating_key=str(meta.get("ratingKey", "")),
        title=meta.get("title", ""),
        type=meta.get("type", MOVIE),
        year=meta.get("year"),
        library=library or meta.get("librarySectionTitle", ""),
        genres=_tags(meta, "Genre"),
        directors=_tags(meta, "Director"),
        actors=_tags(meta, "Role", limit=5),
        view_count=int(meta.get("viewCount", 0) or 0),
        last_viewed_at=meta.get("lastViewedAt"),
        leaf_count=int(meta.get("leafCount", 0) or 0),
        viewed_leaf_count=int(meta.get("viewedLeafCount", 0) or 0),
        rating=_float(meta.get("rating")),
        audience_rating=_float(meta.get("audienceRating")),
        user_rating=_float(meta.get("userRating")),
        summary=meta.get("summary", "") or "",
        added_at=meta.get("addedAt"),
    )
    guid_strings = [g.get("id", "") for g in meta.get("Guid", []) or []]
    guid_strings.append(meta.get("guid", "") or "")
    for attr, pattern in _GUID_PATTERNS.items():
        for g in guid_strings:
            m = pattern.search(g)
            if m:
                setattr(item, attr, m.group(1))
                break
    return item


class PlexClient:
    def __init__(self, url: str, token: str, verify_ssl: bool = True, timeout: int = 30):
        self.url = url.rstrip("/")
        self.session = requests.Session()
        self.session.verify = verify_ssl
        self.session.headers.update(
            {
                "X-Plex-Token": token,
                "Accept": "application/json",
                "X-Plex-Client-Identifier": "plex-recommender",
                "X-Plex-Product": "plex-recommender",
            }
        )
        self.timeout = timeout

    def _get(self, path: str, params: dict | None = None, headers: dict | None = None) -> dict:
        r = self.session.get(
            f"{self.url}{path}", params=params, headers=headers, timeout=self.timeout
        )
        r.raise_for_status()
        return r.json().get("MediaContainer", {})

    def libraries(self) -> list[dict]:
        dirs = self._get("/library/sections").get("Directory", [])
        return [
            {"key": str(d["key"]), "title": d.get("title", ""), "type": d.get("type", "")}
            for d in dirs
        ]

    def library_items(self, media_type: str) -> list[MediaItem]:
        """All movies or shows across every library section of that type."""
        items: list[MediaItem] = []
        for lib in self.libraries():
            if lib["type"] != media_type:
                continue
            for meta in self._paged(
                f"/library/sections/{lib['key']}/all",
                {"type": _TYPE_CODES[media_type], "includeGuids": 1},
            ):
                items.append(parse_item(meta, lib["title"]))
        return items

    def history(
        self, limit: int = 100, since: int | None = None, account_id: str | None = None
    ) -> list[dict]:
        """Watch history entries, newest first. `since` is a unix timestamp."""
        params: dict[str, Any] = {"sort": "viewedAt:desc"}
        if since:
            params["viewedAt>"] = since
        if account_id:
            params["accountID"] = account_id
        out = []
        for entry in self._paged("/status/sessions/history/all", params, page_size=200):
            out.append(entry)
            if len(out) >= limit:
                break
        return out

    def _paged(self, path: str, params: dict, page_size: int = 500) -> Iterator[dict]:
        start = 0
        while True:
            container = self._get(
                path,
                params,
                headers={
                    "X-Plex-Container-Start": str(start),
                    "X-Plex-Container-Size": str(page_size),
                },
            )
            batch = container.get("Metadata", []) or []
            yield from batch
            total = int(container.get("totalSize", container.get("size", len(batch))) or 0)
            start += len(batch)
            if not batch or start >= total:
                return
