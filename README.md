# Plex-anbefaler med Claude Code

Læser hvad du har **set mest** i Plex og hvad du **har** i dit bibliotek, bygger en
smagsprofil og finder relevante film og serier du **ikke har set**, både fra dit eget
bibliotek og nye titler du ikke har endnu. Claude Code bruger det via en MCP-server.

## Sådan virker det

1. **Indsamling:** henter alle film og serier fra dine Plex-biblioteker (antal visninger,
   sete afsnit, din egen rating, genrer, instruktører, skuespillere, TMDB/IMDb-id'er) og
   din afspilningshistorik.
2. **Smagsprofil:** hver set titel får en vægt:
   - film: antal gensyn (aftagende effekt)
   - serier: antal sete afsnit × hvor meget af serien du har gennemført, så en serie du
     droppede efter 2 afsnit tæller lidt
   - din egen stjerne-rating skalerer vægten
   - nyere visninger tæller mere (halveringstid 1 år)

   Vægtene lægges sammen pr. genre, instruktør, skuespiller og årti.
3. **Anbefalinger:**
   - `unwatched_in_library`: usete titler du *ejer*, scoret mod profilen, med begrundelse
   - `recommend_new`: titler du *ikke ejer*, hentet fra TMDB-anbefalinger til dine mest
     sete titler, rangeret efter hvor mange af dine favoritter der peger på dem, genre-match
     og score. Titler du allerede har, filtreres fra.
   - `check_owned`: Claude kan selv foreslå titler ud fra sin viden og tjekke dem mod dit
     bibliotek, så du ikke får anbefalet noget du har set.

## Opsætning

```bash
pip install -r requirements.txt        # requests + mcp
cp .env.example .env                   # udfyld PLEX_URL og PLEX_TOKEN (+ evt. TMDB_API_KEY)
python3 -m plex_recommender overview   # test forbindelsen
```

**Plex-token:** åbn Plex Web, vælg en vilkårlig film → `⋯` → *Get Info* → *View XML*.
URL'en indeholder `X-Plex-Token=...`. (Se også Plex' guide "Finding an authentication token".)

**TMDB (valgfri, anbefalet):** opret en gratis konto på themoviedb.org → Settings → API.
Både "API Key" (v3) og "API Read Access Token" virker. Uden TMDB virker alt undtagen
`recommend_new`, og Claude foreslår så selv titler og tjekker dem med `check_owned`.

## Brug med Claude Code

Åbn projektet i Claude Code. `.mcp.json` registrerer MCP-serveren `plex` automatisk
(godkend den første gang, eller kør `/mcp` for at se status). Derefter:

| Kommando | Hvad den gør |
|---|---|
| `/anbefal` | Anbefalinger af både film og serier |
| `/anbefal serier mørk nordisk krimi` | Med type og stemning |
| `/anbefal film noget let til fredag` | |
| `/smagsprofil` | Analyse af din seersmag |

Du kan også bare spørge frit, f.eks. *"Hvilke usete thrillere har jeg?"*, *"Hvad har jeg
set mest de sidste 3 måneder?"* eller *"Har jeg set Severance, Dark og The Bear?"*.

**Brug i andre projekter:** tilføj serveren globalt:
```bash
claude mcp add plex --scope user -e PLEX_URL=... -e PLEX_TOKEN=... -e TMDB_API_KEY=... \
  -- python3 -m plex_recommender.server
```
(kræver `pip install -e .` i denne mappe, så pakken kan findes overalt).

### MCP-værktøjer

| Værktøj | Beskrivelse |
|---|---|
| `library_overview` | Biblioteker + antal sete/igangværende/usete |
| `taste_profile` | Top-genrer, instruktører, skuespillere, årtier, mest sete titler |
| `most_watched` | Mest sete, samlet eller de sidste N dage (`days`) |
| `recent_history` | Seneste afspilninger |
| `unwatched_in_library` | Usete titler du ejer, rangeret, med genre-filter |
| `recommend_new` | Nye titler du ikke ejer (TMDB) |
| `check_owned` | Tjek en liste titler mod biblioteket |
| `search_library` | Søg på titel, genre, skuespiller, instruktør |
| `refresh_cache` | Ryd cache (data caches i 10 min.) |

## CLI uden Claude

```bash
python3 -m plex_recommender profile
python3 -m plex_recommender most-watched show --days 90
python3 -m plex_recommender unwatched movie --genre Thriller
python3 -m plex_recommender recommend show
python3 -m plex_recommender check "Dark (2017)" "The Bear"
```

## Konfiguration (`.env`)

| Variabel | |
|---|---|
| `PLEX_URL` | f.eks. `http://192.168.1.10:32400` |
| `PLEX_TOKEN` | dit Plex-token |
| `TMDB_API_KEY` | valgfri, til `recommend_new` |
| `RECOMMENDER_LANGUAGE` | sprog for TMDB-titler/resuméer, standard `da-DK` |
| `PLEX_ACCOUNT_ID` | valgfri: kun én brugers historik |
| `PLEX_VERIFY_SSL` | `false` ved selvsigneret https |

Bemærk: sete-status og visninger kommer fra den Plex-konto, som tokenet tilhører.

## Tests

```bash
pip install pytest && python3 -m pytest
```
