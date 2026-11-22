---
description: Find film/serier jeg ikke har set, baseret på hvad jeg ser mest i Plex
argument-hint: "[film|serier] [stemning/genre/fritekst]"
---

Find anbefalinger til mig ud fra min Plex-historik. Ønske: $ARGUMENTS
(hvis tomt: både film og serier, ingen særlig stemning).

Brug `{{handle}}` MCP-værktøjerne:

1. Kald `taste-profile` for at forstå min smag (og evt. `most-watched` med `days: 90`
   for hvad jeg ser lige nu).
2. **Fra mit eget bibliotek:** kald `unwatched-in-library` for den relevante type
   (brug `genre` hvis mit ønske peger på en genre).
3. **Nyt, jeg ikke har:** kald `recommend-new`. Hvis TMDB ikke er sat op, så foreslå
   selv 10–15 titler ud fra profilen.
4. Kør ALTID `check-owned` på titler du selv finder på, og fjern dem jeg allerede har set.
   Titler jeg ejer men ikke har set, flyttes over i "Fra dit bibliotek".
5. Brug din egen viden til at vurdere og sortere: undgå åbenlyse dubletter, sorter
   efter hvor godt de passer til ønsket, og bland gerne et par mindre oplagte valg ind.

Svar på dansk i to sektioner – **Fra dit bibliotek** og **Værd at skaffe** – med
5–10 titler hver: titel (år), én linje om hvorfor den passer til mig (henvis til
konkrete titler/genrer jeg har set), og evt. TMDB-score.
