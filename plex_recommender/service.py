"""High-level operations shared by the MCP server and the CLI."""

from __future__ import annotations

import difflib
import re
import time
from collections import Counter, defaultdict

from .config import Settings
from .models import MOVIE, SHOW, MediaItem, normalize_title
from .plex import PlexClient
from .profile import TasteProfile
from .tmdb import TMDBClient

VALID_TYPES = (MOVIE, SHOW)


def _check_type(media_type: str) -> str:
    t = {"movies": MOVIE, "film": MOVIE, "shows": SHOW, "tv": SHOW, "serier": SHOW, "series": SHOW}.get(
        media_type.lower(), media_type.lower()
    )
    if t not in VALID_TYPES:
        raise ValueError(f"media_type skal være 'movie' eller 'show', fik {media_type!r}")
    return t


class RecommenderService:
    def __init__(self, settings: Settings, plex: PlexClient | None = None, tmdb: TMDBClient | None = None):
        self.settings = settings
        self.plex = plex or PlexClient(settings.plex_url, settings.plex_token, settings.plex_verify_ssl)
        self.tmdb = tmdb
        if self.tmdb is None and settings.tmdb_api_key:
            self.tmdb = TMDBClient(settings.tmdb_api_key, settings.language)
        self._cache: dict[str, tuple[float, list[MediaItem]]] = {}

    # ------------------------------------------------------------------ data
    def items(self, media_type: str) -> list[MediaItem]:
        media_type = _check_type(media_type)
        hit = self._cache.get(media_type)
        if hit and time.time() - hit[0] < self.settings.cache_ttl_seconds:
            return hit[1]
        items = self.plex.library_items(media_type)
        self._cache[media_type] = (time.time(), items)
        return items

    def all_items(self) -> list[MediaItem]:
        return self.items(MOVIE) + self.items(SHOW)

    def refresh(self) -> None:
        self._cache.clear()

    def profile(self, media_type: str | None = None) -> TasteProfile:
        items = self.items(media_type) if media_type else self.all_items()
        return TasteProfile.build(items)

    # ------------------------------------------------------------- operations
    def library_overview(self) -> dict:
        stats = {}
        for t in VALID_TYPES:
            items = self.items(t)
            c = Counter(i.status for i in items)
            stats[t] = {"total": len(items), **c}
        return {"libraries": self.plex.libraries(), "stats": stats, "tmdb_enabled": self.tmdb is not None}

    def taste_profile(self, media_type: str | None = None, top_n: int = 10) -> dict:
        return self.profile(media_type).summary(top_n)

    def most_watched(self, media_type: str, limit: int = 20, days: int | None = None) -> list[dict]:
        """Most watched titles: by engagement (all time) or by plays in the last N days."""
        media_type = _check_type(media_type)
        if not days:
            prof = TasteProfile.build(self.items(media_type))
            return [{**i.brief(), "engagement": round(w, 2)} for i, w in prof.top_titles[:limit]]

        since = int(time.time() - days * 86400)
        history = self.plex.history(limit=5000, since=since, account_id=self.settings.plex_account_id)
        plays: Counter[str] = Counter()
        last: dict[str, int] = {}
        for h in history:
            if media_type == MOVIE and h.get("type") == "movie":
                key = str(h.get("ratingKey"))
            elif media_type == SHOW and h.get("type") == "episode":
                key = str(h.get("grandparentRatingKey") or h.get("grandparentKey", "").rsplit("/", 1)[-1])
            else:
                continue
            plays[key] += 1
            last[key] = max(last.get(key, 0), int(h.get("viewedAt", 0)))
        by_key = {i.rating_key: i for i in self.items(media_type)}
        out = []
        for key, n in plays.most_common(limit):
            item = by_key.get(key)
            entry = item.brief() if item else {"rating_key": key}
            out.append({**entry, "plays_in_period": n, "last_viewed_at": last[key]})
        return out

    def recent_history(self, limit: int = 30) -> list[dict]:
        out = []
        for h in self.plex.history(limit=limit, account_id=self.settings.plex_account_id):
            if h.get("type") == "episode":
                title = (
                    f"{h.get('grandparentTitle', '?')} S{int(h.get('parentIndex', 0)):02d}"
                    f"E{int(h.get('index', 0)):02d} – {h.get('title', '')}"
                )
            else:
                title = h.get("title", "")
                if h.get("year"):
                    title += f" ({h['year']})"
            out.append(
                {
                    "title": title,
                    "type": h.get("type"),
                    "viewed_at": time.strftime("%Y-%m-%d %H:%M", time.localtime(int(h.get("viewedAt", 0)))),
                }
            )
        return out

    def unwatched_in_library(
        self,
        media_type: str,
        limit: int = 20,
        genre: str | None = None,
        include_in_progress: bool = False,
    ) -> list[dict]:
        """Rank titles you own but haven't watched by how well they fit your taste."""
        media_type = _check_type(media_type)
        items = self.items(media_type)
        prof = TasteProfile.build(self.all_items())
        candidates = []
        for item in items:
            if item.status == "watched" or (item.status == "in_progress" and not include_in_progress):
                continue
            if genre and genre.lower() not in {g.lower() for g in item.genres}:
                continue
            score, reasons = prof.score(item)
            candidates.append((score, item, reasons))
        candidates.sort(key=lambda t: -t[0])
        return [
            {**item.brief(), "score": score, "why": reasons, "summary": item.summary[:300]}
            for score, item, reasons in candidates[:limit]
        ]

    def recommend_new(
        self, media_type: str, limit: int = 20, seeds: int = 15, min_votes: int = 50
    ) -> dict:
        """Titles NOT in your library, via TMDB recommendations of your most-watched titles."""
        media_type = _check_type(media_type)
        if self.tmdb is None:
            return {
                "error": "TMDB_API_KEY er ikke sat. Brug taste_profile + check_owned i stedet, "
                "eller tilføj en gratis nøgle fra themoviedb.org."
            }
        items = self.items(media_type)
        owned_tmdb = {i.tmdb_id for i in items if i.tmdb_id}
        owned_titles = {(normalize_title(i.title), i.year) for i in items}
        prof = TasteProfile.build(self.all_items())
        seed_items = [(i, w) for i, w in prof.top_titles if i.type == media_type][:seeds]
        genre_map = self.tmdb.genre_names(media_type)

        scores: dict[int, float] = defaultdict(float)
        because: dict[int, list[str]] = defaultdict(list)
        info: dict[int, dict] = {}
        used_seeds = []
        for item, weight in seed_items:
            tmdb_id = item.tmdb_id or self.tmdb.find_tmdb_id(media_type, item.imdb_id, item.tvdb_id)
            if not tmdb_id:
                continue
            used_seeds.append(item.label)
            for pos, rec in enumerate(self.tmdb.recommendations(media_type, tmdb_id)):
                rid = rec["id"]
                scores[rid] += weight * (1.0 - pos / 25)
                because[rid].append(item.title)
                info[rid] = rec

        results = []
        for rid, base in scores.items():
            rec = info[rid]
            title = rec.get("title") or rec.get("name") or ""
            orig = rec.get("original_title") or rec.get("original_name") or ""
            date = rec.get("release_date") or rec.get("first_air_date") or ""
            year = int(date[:4]) if date[:4].isdigit() else None
            if str(rid) in owned_tmdb:
                continue
            if any((normalize_title(t), year) in owned_titles for t in (title, orig) if t):
                continue
            if rec.get("vote_count", 0) < min_votes:
                continue
            genres = [n for gid in rec.get("genre_ids", []) for n in genre_map.get(gid, [])[:1]]
            all_genre_names = [n for gid in rec.get("genre_ids", []) for n in genre_map.get(gid, [])]
            gm = prof.genre_match(all_genre_names)
            quality = rec.get("vote_average", 0) / 10
            score = base * (0.6 + 0.8 * gm) * (0.7 + 0.6 * quality)
            results.append(
                {
                    "title": title,
                    "original_title": orig if orig != title else None,
                    "year": year,
                    "genres": genres,
                    "tmdb_rating": rec.get("vote_average"),
                    "votes": rec.get("vote_count"),
                    "because_you_watched": because[rid][:4],
                    "score": round(score, 3),
                    "overview": (rec.get("overview") or "")[:300],
                    "url": f"https://www.themoviedb.org/{TMDBClient.kind(media_type)}/{rid}",
                }
            )
        results.sort(key=lambda r: -r["score"])
        return {"seeds_used": used_seeds, "recommendations": results[:limit]}

    def check_owned(self, titles: list[str]) -> list[dict]:
        """Check whether titles (optionally with '(year)') are in the library / watched.

        Useful when Claude suggests titles from its own knowledge: filter out what
        you already have or have seen.
        """
        items = self.all_items()
        index: dict[str, list[MediaItem]] = defaultdict(list)
        for i in items:
            index[normalize_title(i.title)].append(i)
        keys = list(index)
        out = []
        for raw in titles:
            m = re.match(r"^(.*?)\s*\((\d{4})\)\s*$", raw.strip())
            name, year = (m.group(1), int(m.group(2))) if m else (raw.strip(), None)
            norm = normalize_title(name)
            matches = index.get(norm) or [
                i for k in difflib.get_close_matches(norm, keys, n=2, cutoff=0.88) for i in index[k]
            ]
            if year:
                matches = [i for i in matches if not i.year or abs(i.year - year) <= 1] or []
            if not matches:
                out.append({"query": raw, "in_library": False})
            else:
                best = matches[0]
                out.append({"query": raw, "in_library": True, "match": best.label, **best.brief()})
        return out

    def search_library(self, query: str, limit: int = 20) -> list[dict]:
        q = query.lower()
        hits = [
            i
            for i in self.all_items()
            if q in i.title.lower()
            or any(q == g.lower() for g in i.genres)
            or any(q in p.lower() for p in i.actors + i.directors)
        ]
        return [i.brief() for i in hits[:limit]]
