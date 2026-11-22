# CLAUDE.md

Laravel package `emilmoe/plex-recommender` (namespace `EmilMoe\PlexRecommender`).

- `src/Clients/PlexClient.php` Plex HTTP API (Laravel Http client); `Data/MediaItem::fromPlex` parses metadata
- `src/TasteProfile.php` engagement weighting + profile + scoring
- `src/Clients/TmdbClient.php` TMDB (recommendations, external id lookup, genres)
- `src/RecommenderService.php` all operations; used by MCP tools, `php artisan plex`, and the facade
- `src/Mcp/` laravel/mcp server (`PlexServer`) + one class per tool in `Tools/`
- `src/Claude/` "Spørg Claude": `ClaudeAdvisor` builds the prompt and checks answers against the library; drivers `ClaudeCodeDriver` (local `claude -p`, no API key) and `AnthropicApiDriver` (optional `anthropic-ai/sdk`). Runs as queued `AskClaudeJob`; status/progress, history and results are shared via cache. Tests fake `Process`/`Queue`
- `src/Settings.php` all runtime settings: values saved from UI (encrypted JSON) win, config/.env are defaults
- `src/Auth/PlexTv.php` plex.tv PIN login + server discovery; CLI `plex:login`
- `src/Filament/` plugin (`PlexRecommenderPlugin`) with pages Recommendations, TasteProfile, PlexSettings (popup login + wire:poll); views in `resources/views/filament`, hand-written CSS in `resources/dist` (no build step, uses Filament CSS vars). Posters via signed route `PosterController`.
- Optional standalone (non-Filament) login page: `routes/web.php`, disabled by default
- Cache library as arrays, never objects (Laravel 13 disallows unserializing objects)
- `src/Console/InstallCommand.php` writes `.mcp.json` + copies `stubs/claude/commands/*` into the host app
- Tests: `vendor/bin/phpunit` (Orchestra Testbench + `Http::fake`, see `tests/Fixtures.php`)

User speaks Danish: user-facing text (README, commands, CLI messages) in Danish; code/comments in English.
