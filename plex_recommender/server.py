"""MCP server exposing your Plex library and taste profile to Claude Code.

Run:  python3 -m plex_recommender.server   (stdio transport; see .mcp.json)
"""

from __future__ import annotations

from functools import lru_cache

try:  # mcp >= 2
    from mcp.server.mcpserver import MCPServer as _Server
except ImportError:  # mcp 1.x
    from mcp.server.fastmcp import FastMCP as _Server

from .config import Settings
from .service import RecommenderService

mcp = _Server(
    "plex-recommender",
    instructions=(
        "Tools for reading the user's Plex library and watch history and recommending "
        "movies and TV shows they haven't seen. Typical flow: taste_profile -> "
        "unwatched_in_library (things they already own) and recommend_new (things they "
        "don't own, via TMDB). When you suggest titles from your own knowledge, ALWAYS "
        "run check_owned on them first and drop titles that are already watched. "
        "media_type is 'movie' or 'show'. The user speaks Danish."
    ),
)


@lru_cache(maxsize=1)
def service() -> RecommenderService:
    return RecommenderService(Settings.from_env())


@mcp.tool()
def library_overview() -> dict:
    """List Plex libraries and counts of watched / in-progress / unwatched movies and shows."""
    return service().library_overview()


@mcp.tool()
def taste_profile(media_type: str | None = None, top_n: int = 10) -> dict:
    """The user's taste profile derived from what they watch most: weighted top genres,
    directors, actors, decades and the titles they are most engaged with.
    media_type: 'movie', 'show' or omit for both."""
    return service().taste_profile(media_type, top_n)


@mcp.tool()
def most_watched(media_type: str, limit: int = 20, days: int | None = None) -> list[dict]:
    """Most watched titles. Without `days`: by overall engagement (rewatches, episodes
    watched, own rating, recency). With `days`: by number of plays in the last N days."""
    return service().most_watched(media_type, limit, days)


@mcp.tool()
def recent_history(limit: int = 30) -> list[dict]:
    """The most recent plays from Plex watch history, newest first."""
    return service().recent_history(limit)


@mcp.tool()
def unwatched_in_library(
    media_type: str, limit: int = 20, genre: str | None = None, include_in_progress: bool = False
) -> list[dict]:
    """Titles the user OWNS but hasn't watched, ranked by fit with their taste profile,
    with reasons. Optional genre filter (Plex genre name, e.g. 'Thriller')."""
    return service().unwatched_in_library(media_type, limit, genre, include_in_progress)


@mcp.tool()
def recommend_new(media_type: str, limit: int = 20, seeds: int = 15, min_votes: int = 50) -> dict:
    """Titles NOT in the library, found via TMDB recommendations for the user's most
    watched titles and re-ranked by their genre profile and rating. Requires TMDB_API_KEY."""
    return service().recommend_new(media_type, limit, seeds, min_votes)


@mcp.tool()
def check_owned(titles: list[str]) -> list[dict]:
    """Check a list of titles (optionally 'Title (Year)') against the library. Returns
    whether each is in the library and its watch status. Use this to filter your own
    suggestions."""
    return service().check_owned(titles)


@mcp.tool()
def search_library(query: str, limit: int = 20) -> list[dict]:
    """Search the library by title, exact genre, actor or director."""
    return service().search_library(query, limit)


@mcp.tool()
def refresh_cache() -> str:
    """Clear the cached library data (use after adding media or watching something)."""
    service().refresh()
    return "Cache ryddet."


def main() -> None:
    mcp.run()


if __name__ == "__main__":
    main()
