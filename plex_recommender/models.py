"""Data models shared by the Plex client, profile builder and recommenders."""

from __future__ import annotations

import re
import unicodedata
from dataclasses import dataclass, field

MOVIE = "movie"
SHOW = "show"


def normalize_title(title: str) -> str:
    """Lowercase, strip accents/punctuation and leading articles for matching."""
    t = unicodedata.normalize("NFKD", title).encode("ascii", "ignore").decode()
    t = t.lower().replace("&", " and ")
    t = re.sub(r"[^a-z0-9 ]+", " ", t)
    t = re.sub(r"^(the|a|an|den|det|de) ", "", t.strip())
    return re.sub(r"\s+", " ", t).strip()


@dataclass
class MediaItem:
    rating_key: str
    title: str
    type: str  # "movie" | "show"
    year: int | None = None
    library: str = ""
    genres: list[str] = field(default_factory=list)
    directors: list[str] = field(default_factory=list)
    actors: list[str] = field(default_factory=list)
    tmdb_id: str | None = None
    imdb_id: str | None = None
    tvdb_id: str | None = None
    view_count: int = 0
    last_viewed_at: int | None = None
    leaf_count: int = 0  # episodes (shows)
    viewed_leaf_count: int = 0  # watched episodes (shows)
    rating: float | None = None  # critic rating 0-10
    audience_rating: float | None = None  # 0-10
    user_rating: float | None = None  # 0-10, your own stars
    summary: str = ""
    added_at: int | None = None

    @property
    def watched(self) -> bool:
        if self.type == SHOW:
            return self.viewed_leaf_count > 0
        return self.view_count > 0

    @property
    def completion(self) -> float:
        if self.type == SHOW:
            return self.viewed_leaf_count / self.leaf_count if self.leaf_count else 0.0
        return 1.0 if self.view_count > 0 else 0.0

    @property
    def status(self) -> str:
        if not self.watched:
            return "unwatched"
        if self.type == SHOW and self.completion < 1.0:
            return "in_progress"
        return "watched"

    @property
    def label(self) -> str:
        return f"{self.title} ({self.year})" if self.year else self.title

    def brief(self) -> dict:
        d = {
            "title": self.title,
            "year": self.year,
            "type": self.type,
            "status": self.status,
            "genres": self.genres,
        }
        if self.type == SHOW:
            d["episodes_watched"] = f"{self.viewed_leaf_count}/{self.leaf_count}"
        else:
            d["view_count"] = self.view_count
        if self.user_rating is not None:
            d["your_rating"] = self.user_rating
        return d
