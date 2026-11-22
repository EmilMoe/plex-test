"""Build a taste profile from what has been watched, and score titles against it."""

from __future__ import annotations

import math
import time
from collections import defaultdict
from dataclasses import dataclass, field

from .models import SHOW, MediaItem

RECENCY_HALF_LIFE_DAYS = 365


def engagement_weight(item: MediaItem, now: float | None = None) -> float:
    """How strongly a watched item says something about your taste.

    - Movies: rewatches count, with diminishing returns.
    - Shows: more episodes watched and higher completion count more; a show
      abandoned after two episodes is a weak signal.
    - Your own star rating scales the weight (a 2-star film counts little).
    - Recent viewing counts more than viewing from years ago.
    """
    if not item.watched:
        return 0.0
    if item.type == SHOW:
        w = math.log2(1 + item.viewed_leaf_count) * (0.5 + item.completion)
    else:
        w = math.log2(1 + item.view_count) * 2.0
    if item.user_rating is not None:
        w *= 0.25 + 1.5 * (item.user_rating / 10)
    if item.last_viewed_at:
        now = now or time.time()
        age_days = max(0.0, (now - item.last_viewed_at) / 86400)
        w *= 0.35 + 0.65 * 0.5 ** (age_days / RECENCY_HALF_LIFE_DAYS)
    return w


def _normalize(d: dict[str, float]) -> dict[str, float]:
    if not d:
        return {}
    top = max(d.values())
    return {k: v / top for k, v in sorted(d.items(), key=lambda kv: -kv[1])}


@dataclass
class TasteProfile:
    genres: dict[str, float] = field(default_factory=dict)
    directors: dict[str, float] = field(default_factory=dict)
    actors: dict[str, float] = field(default_factory=dict)
    decades: dict[str, float] = field(default_factory=dict)
    top_titles: list[tuple[MediaItem, float]] = field(default_factory=list)

    @classmethod
    def build(cls, items: list[MediaItem], now: float | None = None) -> "TasteProfile":
        genres: dict[str, float] = defaultdict(float)
        directors: dict[str, float] = defaultdict(float)
        actors: dict[str, float] = defaultdict(float)
        decades: dict[str, float] = defaultdict(float)
        weighted = []
        for item in items:
            w = engagement_weight(item, now)
            if w <= 0:
                continue
            weighted.append((item, w))
            for g in item.genres:
                genres[g.lower()] += w
            for d in item.directors:
                directors[d] += w
            for i, a in enumerate(item.actors):
                actors[a] += w * (1.0 - 0.15 * i)  # top-billed counts more
            if item.year:
                decades[f"{item.year // 10 * 10}s"] += w
        weighted.sort(key=lambda t: -t[1])
        return cls(
            genres=_normalize(genres),
            directors=_normalize(directors),
            actors=_normalize(actors),
            decades=_normalize(decades),
            top_titles=weighted,
        )

    def genre_match(self, genre_names: list[str]) -> float:
        """0..~1 score of how well a set of genres matches the profile."""
        names = {g.lower() for g in genre_names if g}
        if not names or not self.genres:
            return 0.0
        vals = sorted((self.genres.get(n, 0.0) for n in names), reverse=True)
        # Best genre dominates, remaining ones add a little.
        return min(1.0, vals[0] * 0.7 + sum(vals[1:3]) * 0.15)

    def score(self, item: MediaItem) -> tuple[float, list[str]]:
        """Score an (unwatched) item; returns (score, human-readable reasons)."""
        reasons: list[str] = []
        s = self.genre_match(item.genres) * 3.0
        top_genres = [g for g in item.genres if self.genres.get(g.lower(), 0) >= 0.4]
        if top_genres:
            reasons.append("genre: " + ", ".join(top_genres[:3]))

        d_hits = [(d, self.directors[d]) for d in item.directors if d in self.directors]
        if d_hits:
            s += max(v for _, v in d_hits) * 1.5
            reasons.append("instruktør: " + ", ".join(d for d, _ in d_hits))

        a_hits = [(a, self.actors[a]) for a in item.actors if a in self.actors]
        if a_hits:
            s += min(1.5, sum(v for _, v in a_hits))
            reasons.append("skuespillere: " + ", ".join(a for a, _ in a_hits[:3]))

        if item.year:
            s += self.decades.get(f"{item.year // 10 * 10}s", 0.0) * 0.4

        quality = item.audience_rating or item.rating
        if quality:
            s += (quality / 10) * 0.8
        return round(s, 3), reasons

    def summary(self, top_n: int = 10) -> dict:
        def top(d: dict[str, float]) -> list[dict]:
            return [{"name": k, "weight": round(v, 2)} for k, v in list(d.items())[:top_n]]

        return {
            "top_genres": top(self.genres),
            "top_directors": top(self.directors),
            "top_actors": top(self.actors),
            "favorite_decades": top(self.decades),
            "most_engaged_titles": [
                {**item.brief(), "engagement": round(w, 2)}
                for item, w in self.top_titles[:top_n]
            ],
        }
