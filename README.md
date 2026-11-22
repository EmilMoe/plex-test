# Plex Recommender

Hvad skal du se i aften? Pakken læser, hvad du har **set** og **bedømt** i Plex, og lader
**Claude** finde film og serier, du ikke har set, gerne de smalle titler, som
streamingtjenesternes "du vil måske også kunne lide" aldrig finder.

Pakken giver dig tre ting:
- **Et Filament-panel** med Claudes forslag som startside, din smagsprofil og alle indstillinger. Der er
  ingen `.env`-filer, der skal redigeres, og ingen tokens, du skal finde frem.
- **Claude Code-integration**: skriv `/anbefal serier mørk nordisk krimi` i Claude Code.
- **Artisan-kommandoer og en facade** til dine egne features.

![Anbefalinger](docs/rec-movies.png)

## Installation (for udvikleren)

Kræver PHP 8.2+, Laravel 12/13 og Filament 5. Pakken er ikke på Packagist endnu, så
tilføj repoet i dit projekts `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/EmilMoe/plex-test" }
]
```

```bash
composer require emilmoe/plex-recommender:dev-main   # eller dev-<branch>
php artisan plex:install
```

`plex:install` gør det hele:
- tilføjer `->plugin(PlexRecommenderPlugin::make())` til dit Filament-panel og fjerner
  Filaments Dashboard, så forslagssiden bliver startsiden
- publicerer CSS (`filament:assets`) og config
- tilføjer MCP-serveren `plex` til `.mcp.json` og lægger `/anbefal` og `/smagsprofil` i
  `.claude/commands/`

Har du ikke et Filament-panel endnu, så kør først `php artisan filament:install --panels`.

## Brug (for alle)

Åbn panelet. Du lander direkte på forslagene. Under **Plex** i menuen findes tre sider:

### 1. Indstillinger: forbind på ét minut

1. Tryk **Log ind med Plex**. Et vindue fra plex.tv åbner.
2. Log ind med din almindelige Plex-konto. Vinduet lukker af sig selv.
3. Færdig. Har du adgang til flere servere, vælger du den rigtige på en liste.

Bag om ser pakken selv, hvilke adresser serveren har, og vælger den hurtigste, der virker
(lokal før ekstern). Tokenet gemmes krypteret.

Ønsker du også forslag til titler, du *ikke* har, så indsæt en gratis TMDB-nøgle samme
sted. Der er et link og en **Test nøgle**-knap lige ved feltet. Under *Avanceret* kan du
bl.a. vælge, hvis historik der bruges, hvis I er flere på serveren.

![Indstillinger](docs/settings.png)

### 2. Forslag: "Hvad skal jeg se?" (startsiden)
- **Claudes forslag** (standard): Claude beskriver din smag med egne ord og finder titler,
  der passer. Vælg hvor smalt: *Også kendte*, *Mindre kendte* eller *Skjulte perler*. Klik
  på hurtige valg (stemning, længde, hvorfra) og på de opklarende spørgsmål, Claude selv
  stiller ud fra din smag, eller skriv hvad du er i humør til. Første gang spørges
  automatisk. Claude arbejder i baggrunden med en fremskridtsbjælke, så du kan gå til en
  anden side imens. *Nye forslag* spørger igen og undgår det, Claude har foreslået før.
  Alle svar gemmes i 30 dage og kan findes igen under *Tidligere søgninger*. Se
  [Claudes forslag](#claudes-forslag).
- **Uset i dit bibliotek**: det, du har, men ikke har set, sorteret efter hvor godt det
  passer til dig. Klik *Hvorfor?* for at se pointene.
- **Lignende titler**: TMDB's anbefalinger for dine favoritter (*Fordi du så Dark og
  Severance*).
- Skift mellem film og serier.
- **Klik på et kort** for detaljer: handling, instruktør, medvirkende, spilletid, trailer
  (afspilles på siden) og *Åbn i Plex*, hvis titlen ligger på din server.

### 3. Min smag
Dine yndlingsgenrer, instruktører, skuespillere og årtier, de film og serier du har set
mest (hver for sig), og hvad du har set for nylig.

![Min smag](docs/taste.png)

## Plugin-indstillinger

```php
use EmilMoe\PlexRecommender\Filament\PlexRecommenderPlugin;

$panel->plugin(
    PlexRecommenderPlugin::make()
        ->navigationGroup('Underholdning')                   // eller null
        ->canManageSettings(fn () => auth()->user()->is_admin) // hvem må ændre indstillinger
        // ->settingsPage(false)                             // skjul siden, kun .env
        // ->homePage(false)                                 // forslagssiden er ikke startsiden
);
```

## Sådan virker anbefalingerne

1. Alle film og serier hentes fra Plex med visninger, sete afsnit, din egen rating,
   genrer, instruktører og skuespillere.
2. Hver set titel får en vægt. Gensyn tæller, en serie du har set færdig tæller mere end
   en, du droppede efter to afsnit, din stjerne-rating tæller med (2 stjerner eller mindre
   trækker genre, instruktør og skuespillere ned), og nyere visninger
   vejer tungere (halveringstid 180 dage som standard). En serie vejer aldrig mere end en
   film, du har set tre gange, uanset hvor mange afsnit den har.
3. Vægtene giver en profil over genrer, instruktører, skuespillere og årtier. Usete titler
   scores mod den profil.
4. Til nye titler hentes TMDB's anbefalinger for dine mest sete titler. De rangeres efter,
   hvor mange af dine favoritter der peger på dem, genre-match og score. Titler, du
   allerede har, filtreres fra.

## Claudes forslag

Claude får:
- dine favoritter med instruktør, genre, sete afsnit og **dine egne stjerner fra Plex**
- det du har set de sidste 90 dage (vejer mest)
- det du gav 2 stjerner eller mindre, og serier du droppede efter et par afsnit
- alt du har set, og det Claude har foreslået før

Claude skriver først en kort beskrivelse af din smag (tone, temaer, tempo, lande) og finder
så titler, der passer. Bagefter slås hvert forslag op på TMDB:
- titler der ikke findes, sorteres fra (Claude kan tage fejl)
- titler med for mange stemmer til det valgte niveau sorteres fra: *Mindre kendte* højst
  8.000 stemmer (serier 3.000), *Skjulte perler* højst 1.500 (serier 600)
- titler du har set, sorteres fra, og titler du har, markeres *På din Plex*

TMDB-nøglen er derfor stærkt anbefalet. Uden den springes tjekket over.

**Vælg under Indstillinger → Spørg Claude:**

- **Claude Code på denne computer** (standard): kører `claude -p` med dit eget Claude-login
  og Sonnet som model. Ingen API-nøgle, men appen skal køre på samme computer som Claude Code,
  og forbruget tæller med i dit abonnement. Webserveren skal kunne finde `claude`. Finder den
  ikke programmet, så indsæt stien fra `which claude`. Claude Code skal være logget ind som
  den bruger, webserveren kører som. Tryk *Test forbindelse* for at tjekke. Et svar tager
  typisk under et minut, så webserverens timeout (nginx/Herd/Valet) skal være højere end det.
- **Claude API**: til når appen kører på en server. Kræver en API-nøgle fra
  console.anthropic.com (betales efter forbrug) og `composer require anthropic-ai/sdk`.
- **Slået fra**: siden åbner så på *Uset i dit bibliotek*.

**Kø (Horizon / queue:work).** Claude-kaldet kører som jobbet `AskClaudeJob`, så siden ikke
venter. Kør en worker (`php artisan horizon` eller `php artisan queue:work`). Bemærk:

- Med Claude Code skal workeren køre som en bruger, der er logget ind i Claude Code.
- Jobbet må køre op til 300 sekunder. Sæt `retry_after` for køforbindelsen i
  `config/queue.php` til over 300 (fx 330), ellers kan jobbet blive startet to gange. I
  Horizon skal supervisorens `timeout` også være mindst 300.
- Vælg evt. egen forbindelse eller kø med `PLEX_RECOMMENDER_QUEUE_CONNECTION` og
  `PLEX_RECOMMENDER_QUEUE`.
- Med `QUEUE_CONNECTION=sync` kører det direkte i forespørgslen som før (siden venter).

Fra terminalen: `php artisan plex claude show --niche=perler --wish="noget stille og nordisk"`.

## Claude Code (MCP)

Efter `plex:install` kender Claude Code MCP-serveren `plex` (`php artisan mcp:start plex`).
Godkend den første gang (eller tjek med `/mcp`).

| Kommando | |
|---|---|
| `/anbefal` | Anbefalinger af både film og serier |
| `/anbefal serier mørk nordisk krimi` | Med type og stemning |
| `/smagsprofil` | Analyse af din seersmag |

Du kan også spørge frit: *"Har jeg set Severance, Dark og The Bear?"* Claude tjekker sine
egne forslag mod dit bibliotek med `check-owned`.

MCP-værktøjer: `library-overview`, `taste-profile`, `most-watched`, `recent-history`,
`unwatched-in-library`, `recommend-new`, `check-owned`, `search-library`, `refresh-cache`.

## Artisan

```bash
php artisan plex:login                     # log ind uden browser (viser et link)
php artisan plex:logout
php artisan plex overview
php artisan plex profile [movie|show]
php artisan plex most-watched show --days=90
php artisan plex unwatched movie --genre=Thriller
php artisan plex recommend show
php artisan plex claude movie --niche=smal --wish="noget let" [--fresh]
php artisan plex check "Dark (2017)" "The Bear"
```
Tilføj `--json` for rå JSON.

## I din egen kode

```php
use EmilMoe\PlexRecommender\Facades\PlexRecommender;

PlexRecommender::unwatchedInLibrary('movie', 10);
PlexRecommender::recommendNew('show', 10);
PlexRecommender::tasteProfile();
PlexRecommender::checkOwned(['Dune (2021)']);
```

## Indstillinger fra .env (valgfrit)

Alt kan sættes i panelet. Værdier i `.env` bruges kun som standard, indtil der er gemt
noget i panelet:

| Variabel | Felt i panelet |
|---|---|
| `PLEX_URL`, `PLEX_TOKEN` | Plex → Manuel opsætning |
| `TMDB_API_KEY` | TMDB API-nøgle |
| `PLEX_RECOMMENDER_LANGUAGE` | Sprog |
| `PLEX_ACCOUNT_ID` | Avanceret → Hvis historik |
| `PLEX_VERIFY_SSL` | Avanceret → Kontrollér certifikat |
| `PLEX_RECOMMENDER_CACHE_TTL` | Avanceret → Opdater biblioteket hvert … |
| `PLEX_RECOMMENDER_CLAUDE` (`code`/`api`/`off`) | Spørg Claude → Hvordan |
| `PLEX_RECOMMENDER_CLAUDE_BINARY` | Spørg Claude → Sti til Claude Code |
| `PLEX_RECOMMENDER_CLAUDE_MODEL` | Spørg Claude → Model (standard `sonnet`) |
| `ANTHROPIC_API_KEY` | Spørg Claude → API-nøgle |
| `PLEX_RECOMMENDER_QUEUE_CONNECTION`, `PLEX_RECOMMENDER_QUEUE` | (kun .env) kø til Claude-jobbet |

Indstillingerne gemmes krypteret med `APP_KEY` i `storage/app/plex-recommender.json`.

## Tests

```bash
composer install && vendor/bin/phpunit
```
Testene kører med `Http::fake()` og Livewire-tests af Filament-siderne. De kræver
hverken Plex eller TMDB.
