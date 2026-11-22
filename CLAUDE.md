# CLAUDE.md

Laravel package `emilmoe/plex-recommender` (namespace `EmilMoe\PlexRecommender`).

- `src/Clients/PlexClient.php` Plex HTTP API (Laravel Http client); `Data/MediaItem::fromPlex` parses metadata
- `src/TasteProfile.php` engagement weighting + profile + scoring
- `src/Clients/TmdbClient.php` TMDB (recommendations, external id lookup, genres)
- `src/RecommenderService.php` all operations; used by MCP tools, `php artisan plex`, and the facade
- `src/Mcp/` laravel/mcp server (`PlexServer`) + one class per tool in `Tools/`
- `src/Console/InstallCommand.php` writes `.mcp.json` + copies `stubs/claude/commands/*` into the host app
- Tests: `vendor/bin/phpunit` (Orchestra Testbench + `Http::fake`, see `tests/Fixtures.php`)

User speaks Danish: user-facing text (README, commands, CLI messages) in Danish; code/comments in English.
