# CLAUDE.md

Plex recommender: Python package `plex_recommender/` + MCP server for Claude Code.

- `plex.py` Plex HTTP API client (`parse_item` turns Plex JSON into `MediaItem`)
- `profile.py` engagement weighting + `TasteProfile` (genres/directors/actors/decades) and scoring
- `tmdb.py` TMDB client (recommendations, external id lookup, genre names)
- `service.py` `RecommenderService`: all operations, shared by `server.py` (MCP tools) and `__main__.py` (CLI)
- Config via env / `.env` (`config.py`). Never commit `.env` or tokens.
- Tests: `python3 -m pytest` (fakes for Plex and TMDB, no network).
- Server must work with both mcp 1.x (`FastMCP`) and 2.x (`MCPServer`).

When recommending to the user (they speak Danish): use the `plex` MCP tools, always run
`check_owned` on titles you suggest from your own knowledge, and answer in Danish.
