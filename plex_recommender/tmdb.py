"""Minimal TMDB client used to discover titles that are not in your library."""

from __future__ import annotations

from functools import lru_cache

import requests

from .models import MOVIE

BASE = "https://api.themoviedb.org/3"


class TMDBClient:
    def __init__(self, api_key: str, language: str = "da-DK", timeout: int = 20):
        self.session = requests.Session()
        self.params: dict[str, str] = {}
        # v4 "API Read Access Token" is a JWT; v3 key is a short hex string.
        if api_key.startswith("eyJ"):
            self.session.headers["Authorization"] = f"Bearer {api_key}"
        else:
            self.params["api_key"] = api_key
        self.language = language
        self.timeout = timeout

    def _get(self, path: str, **params) -> dict:
        r = self.session.get(
            f"{BASE}{path}", params={**self.params, **params}, timeout=self.timeout
        )
        r.raise_for_status()
        return r.json()

    @staticmethod
    def kind(media_type: str) -> str:
        return "movie" if media_type == MOVIE else "tv"

    def recommendations(self, media_type: str, tmdb_id: str, pages: int = 1) -> list[dict]:
        out: list[dict] = []
        for page in range(1, pages + 1):
            data = self._get(
                f"/{self.kind(media_type)}/{tmdb_id}/recommendations",
                language=self.language,
                page=page,
            )
            out.extend(data.get("results", []))
            if page >= data.get("total_pages", 1):
                break
        return out

    def find_tmdb_id(self, media_type: str, imdb_id: str | None, tvdb_id: str | None) -> str | None:
        """Resolve a TMDB id from an IMDb or TVDB id (for items lacking a tmdb guid)."""
        for ext_id, source in ((imdb_id, "imdb_id"), (tvdb_id, "tvdb_id")):
            if not ext_id:
                continue
            data = self._get(f"/find/{ext_id}", external_source=source)
            key = "movie_results" if media_type == MOVIE else "tv_results"
            if data.get(key):
                return str(data[key][0]["id"])
        return None

    @lru_cache(maxsize=4)
    def genre_names(self, media_type: str) -> dict[int, list[str]]:
        """Genre id -> names in both English and the configured language.

        Plex genre tags may be English or localized, so we match against both.
        TV genres like "Sci-Fi & Fantasy" are also split into their parts.
        """
        names: dict[int, list[str]] = {}
        for lang in {"en-US", self.language}:
            data = self._get(f"/genre/{self.kind(media_type)}/list", language=lang)
            for g in data.get("genres", []):
                parts = [g["name"]] + [p.strip() for p in g["name"].split("&")]
                if "Sci-Fi" in g["name"]:
                    parts += ["Science Fiction"]
                names.setdefault(g["id"], []).extend(parts)
        return names
