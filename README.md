# Plex Recommender til Laravel

En Laravel-pakke der læser hvad du har **set mest** i Plex og hvad du **har** i dit
bibliotek, bygger en smagsprofil og finder relevante film og serier du **ikke har set**:
både fra dit eget bibliotek og nye titler du ikke har endnu. Claude Code bruger det via
en MCP-server (`laravel/mcp`), så du bare kan skrive `/anbefal` i Claude Code.

## Installation

Pakken er ikke på Packagist. Tilføj repoet i dit projekts `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/EmilMoe/plex-test" }
]
```

```bash
composer require emilmoe/plex-recommender:dev-main     # eller dev-<branch>
php artisan plex:install
```

`plex:install` publicerer configen, tilføjer MCP-serveren `plex` til `.mcp.json`
(`php artisan mcp:start plex`) og lægger `/anbefal` og `/smagsprofil` i `.claude/commands/`.

### Forbind til Plex: én knap, ingen token

Gå til **`/plex`** i din app og tryk **Log ind med Plex**, ligesom i Overseerr/Jellyseerr.
Du sendes til plex.tv, logger ind og kommer tilbage. Derefter sker resten automatisk:

- dine servere hentes fra din Plex-konto (har du flere, vælger du én)
- den bedste forbindelse testes og vælges: lokal adresse først, så ekstern, relay til sidst
- serverens token gemmes **krypteret** med din `APP_KEY` i `storage/app/plex-recommender.json`

Uden browser, f.eks. på en server eller direkte fra Claude Code:

```bash
php artisan plex:login      # viser et link; log ind, så forbindes der automatisk
php artisan plex:logout
```

> `/plex` kræver som standard, at man er logget ind i din app (`web,auth`-middleware).
> Har appen ingen brugere, så sæt `PLEX_RECOMMENDER_MIDDLEWARE=web` i `.env`.
> Prefix og middleware kan ændres i `config/plex-recommender.php`, og viewet kan
> publiceres med `php artisan vendor:publish --tag=plex-recommender-views`.

Vil du hellere bruge en token manuelt, kan du stadig sætte `PLEX_URL` og `PLEX_TOKEN`
i `.env`. De vinder over login.

**TMDB (valgfri, anbefalet):** sæt `TMDB_API_KEY` i `.env`. En gratis nøgle fås på
themoviedb.org → Settings → API; både "API Key" (v3) og "API Read Access Token" virker.
Uden TMDB virker alt undtagen `recommend-new`, og Claude foreslår så selv titler og tjekker
dem med `check-owned`.

Krav: PHP 8.2+, Laravel 12 eller 13.

## Sådan virker det

1. **Indsamling:** henter alle film og serier fra dine Plex-biblioteker (visninger, sete
   afsnit, din rating, genrer, instruktører, skuespillere, TMDB/IMDb/TVDB-id'er) og din
   afspilningshistorik. Data caches i 10 minutter i Laravel-cachen.
2. **Smagsprofil:** hver set titel får en vægt:
   - film: antal gensyn (aftagende effekt)
   - serier: sete afsnit × hvor meget du har gennemført, så en serie du droppede efter 2
     afsnit tæller lidt
   - din egen stjerne-rating skalerer vægten
   - nyere visninger tæller mere (halveringstid 1 år, kan ændres i config)

   Vægtene lægges sammen pr. genre, instruktør, skuespiller og årti.
3. **Anbefalinger:**
   - **Fra dit bibliotek:** usete titler du ejer, scoret mod profilen, med begrundelse
   - **Værd at skaffe:** TMDB-anbefalinger til dine mest sete titler, rangeret efter hvor
     mange af dine favoritter der peger på dem, genre-match og score. Titler du allerede
     har, filtreres fra.
   - **Claudes egne forslag:** tjekkes mod dit bibliotek med `check-owned`

## Brug med Claude Code

Forbind Plex (se ovenfor), åbn projektet i Claude Code og godkend MCP-serveren `plex` (eller tjek med `/mcp`).

| Kommando | |
|---|---|
| `/anbefal` | Anbefalinger af både film og serier |
| `/anbefal serier mørk nordisk krimi` | Med type og stemning |
| `/anbefal film noget let til fredag` | |
| `/smagsprofil` | Analyse af din seersmag |

Eller spørg frit: *"Hvilke usete thrillere har jeg?"*, *"Hvad har jeg set mest de sidste
3 måneder?"*, *"Har jeg set Severance, Dark og The Bear?"*

### MCP-værktøjer

| Værktøj | Beskrivelse |
|---|---|
| `library-overview` | Biblioteker + antal sete/igangværende/usete |
| `taste-profile` | Top-genrer, instruktører, skuespillere, årtier, mest sete titler |
| `most-watched` | Mest sete, samlet eller de sidste N dage (`days`) |
| `recent-history` | Seneste afspilninger |
| `unwatched-in-library` | Usete titler du ejer, rangeret, med genre-filter |
| `recommend-new` | Nye titler du ikke ejer (TMDB) |
| `check-owned` | Tjek en liste titler mod biblioteket |
| `search-library` | Søg på titel, genre, skuespiller, instruktør |
| `refresh-cache` | Ryd cachen |

Vil du selv styre registreringen (f.eks. også som web-server), så sæt
`'mcp_handle' => null` i configen og registrér i `routes/ai.php`:

```php
use EmilMoe\PlexRecommender\Mcp\PlexServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('plex', PlexServer::class);
Mcp::web('/mcp/plex', PlexServer::class)->middleware('auth:sanctum');
```

## Artisan

```bash
php artisan plex overview
php artisan plex profile [movie|show]
php artisan plex most-watched show --days=90
php artisan plex history --limit=20
php artisan plex unwatched movie --genre=Thriller
php artisan plex recommend show
php artisan plex check "Dark (2017)" "The Bear"
php artisan plex refresh
```
Tilføj `--json` for rå JSON.

## I din egen kode

```php
use EmilMoe\PlexRecommender\Facades\PlexRecommender;

PlexRecommender::tasteProfile();                       // smagsprofil
PlexRecommender::unwatchedInLibrary('movie', 10);      // usete film du ejer
PlexRecommender::recommendNew('show', 10);             // nye serier via TMDB
PlexRecommender::checkOwned(['Dune (2021)']);
PlexRecommender::mostWatched('show', 10, days: 30);
```
Eller injicér `EmilMoe\PlexRecommender\RecommenderService`. Det gør det nemt at bygge en
side, et ugentligt mail-digest via scheduler osv. ovenpå.

## Tests

```bash
composer install && vendor/bin/phpunit
```
Testene bruger `Http::fake()`, så de kræver hverken Plex eller TMDB.
