<?php

namespace EmilMoe\PlexRecommender\Claude;

use EmilMoe\PlexRecommender\Clients\TmdbClient;
use EmilMoe\PlexRecommender\Data\MediaItem;
use EmilMoe\PlexRecommender\RecommenderService;
use EmilMoe\PlexRecommender\Settings;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Asks Claude for personal recommendations: first a description of the user's
 * taste in words, then titles that fit it. Every suggestion is verified on TMDB
 * (drops invented titles, enforces "how niche"), and checked against the library.
 */
class ClaudeAdvisor
{
    /** How niche the suggestions should be => [prompt text, max TMDB votes movie, max TMDB votes tv]. */
    public const NICHE = [
        'kendt' => [
            'Gerne anerkendte titler, også kendte, så længe de passer præcist til smagen.',
            null, null,
        ],
        'smal' => [
            'Mindre kendte titler: arthouse, internationale film, festivalfilm, oversete titler fra alle årtier. '
            .'Undgå store Hollywood-produktioner og titler "alle" har set eller hørt om.',
            8000, 3000,
        ],
        'perler' => [
            'Virkelig skjulte perler som de færreste har hørt om: små festivalfilm, debutfilm, glemte ældre titler, '
            .'film fra mindre filmlande. Ingen titler der har været store hits, heller ikke kult-hits.',
            1500, 600,
        ],
    ];

    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'taste_summary' => [
                'type' => 'string',
                'description' => '2-3 sætninger på dansk, i du-form: hvad kendetegner brugerens smag (tone, temaer, tempo, stil, lande) – ikke bare genrer',
            ],
            'questions' => [
                'type' => 'array',
                'description' => '2-3 korte opklarende spørgsmål med 2-4 svarmuligheder, tilpasset netop denne brugers smag, der kan hjælpe med at ramme bedre næste gang. '
                    .'Spørg IKKE om stemning, længde eller land (det spørges der allerede om).',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string'],
                        'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['question', 'options'],
                    'additionalProperties' => false,
                ],
            ],
            'recommendations' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Titlen som den kendes i Danmark'],
                        'original_title' => ['type' => ['string', 'null']],
                        'year' => ['type' => ['integer', 'null']],
                        'director' => ['type' => ['string', 'null']],
                        'country' => ['type' => ['string', 'null']],
                        'reason' => ['type' => 'string', 'description' => '1-2 sætninger på dansk med konkrete fællestræk med brugerens favoritter'],
                        'because_you_watched' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['title', 'original_title', 'year', 'director', 'country', 'reason', 'because_you_watched'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['taste_summary', 'recommendations', 'questions'],
        'additionalProperties' => false,
    ];

    /**
     * Quick questions that are always offered, before Claude's own follow-ups.
     *
     * @return array<string, list<string>>
     */
    public static function baseQuestions(string $type): array
    {
        return [
            'Stemning' => ['Let og sjov', 'Spændende', 'Tankevækkende', 'Mørk og tung', 'Rørende', 'Skæv og anderledes'],
            ...($type === MediaItem::SHOW
                ? ['Omfang' => ['Miniserie', 'Et par sæsoner', 'Gerne mange sæsoner']]
                : ['Længde' => ['Under 1½ time', 'Gerne lang']]),
            'Hvorfra' => ['Dansk/nordisk', 'Europæisk', 'Asiatisk', 'Amerikansk'],
        ];
    }

    /** Answers are kept this long (so earlier searches can be browsed); "Nye forslag" always asks fresh. */
    public const CACHE_DAYS = 30;

    public function __construct(
        protected RecommenderService $svc,
        protected Settings $settings,
        protected Cache $cache,
        protected ?ClaudeDriver $driver = null,
    ) {}

    public function driver(): ?ClaudeDriver
    {
        if ($this->driver) {
            return $this->driver;
        }

        return match ($this->settings->claudeDriver()) {
            'code' => new ClaudeCodeDriver($this->settings->claudeBinary(), $this->settings->claudeModel() ?? 'sonnet'),
            'api' => ($key = $this->settings->anthropicApiKey()) ? new AnthropicApiDriver($key, $this->settings->claudeModel()) : null,
            default => null,
        };
    }

    public function enabled(): bool
    {
        return $this->driver() !== null;
    }

    public static function niche(?string $niche): string
    {
        return isset(self::NICHE[$niche]) ? $niche : 'smal';
    }

    /** Previously fetched answer for these inputs, without calling Claude. */
    public function cached(string $type, ?string $wish = null, ?string $niche = null): ?array
    {
        return $this->cache->get($this->cacheKey(RecommenderService::normalizeType($type), $this->cleanWish($wish), self::niche($niche)));
    }

    /**
     * @return array{taste_summary: string, recommendations: list<array>, driver: string, asked_at: int, niche: string, filtered: array}|array{error: string}
     */
    public function recommend(string $type, ?string $wish = null, ?string $niche = null, int $limit = 12, bool $fresh = false, ?callable $phase = null): array
    {
        $phase ??= fn (string $p) => null;
        $type = RecommenderService::normalizeType($type);
        $wish = $this->cleanWish($wish);
        $niche = self::niche($niche);

        if (! $driver = $this->driver()) {
            return ['error' => 'Claude er ikke sat op. Vælg Claude Code eller indsæt en API-nøgle under Plex-indstillinger.'];
        }
        if (! $fresh && ($hit = $this->cached($type, $wish, $niche))) {
            return $hit;
        }

        $started = microtime(true);
        try {
            $phase('prompt');
            // Ask for extra titles: some are dropped as seen, too popular or not found.
            $prompt = $this->prompt($type, $wish, $niche, $limit + 8);
            $phase('claude');
            $text = $driver->ask($this->systemPrompt(), $prompt, self::SCHEMA);
        } catch (ClaudeException $e) {
            return ['error' => $e->getMessage()];
        }

        $data = self::decode($text);
        if (! isset($data['recommendations']) || ! is_array($data['recommendations'])) {
            return ['error' => 'Kunne ikke forstå svaret fra Claude. Prøv igen.'];
        }

        $phase('verify');
        [$recs, $filtered] = $this->verify($type, $niche, $data['recommendations']);
        $this->recordDuration(microtime(true) - $started);

        $result = [
            'taste_summary' => (string) ($data['taste_summary'] ?? ''),
            'questions' => self::cleanQuestions($data['questions'] ?? [], $type),
            'recommendations' => array_slice($recs, 0, $limit),
            'driver' => $driver->label(),
            'asked_at' => time(),
            'niche' => $niche,
            'filtered' => $filtered,
        ];
        $this->cache->put($this->cacheKey($type, $wish, $niche), $result, now()->addDays(self::CACHE_DAYS));
        $this->remember($type, array_column($result['recommendations'], 'title'));

        return $result;
    }

    // ---------------------------------------------------------------- queue

    /**
     * Ask in the background (queue job), so the user can leave the page.
     * With the "sync" queue driver this runs right away, like recommend().
     *
     * @param  array{typed?: string, answers?: array}  $ui  form state, to restore the page later
     */
    public function dispatch(string $type, ?string $wish, ?string $niche, bool $fresh = false, array $ui = []): array
    {
        $type = RecommenderService::normalizeType($type);
        $wish = $this->cleanWish($wish);
        $niche = self::niche($niche);

        $current = $this->status($type, $wish, $niche);
        if ($current && in_array($current['state'], ['queued', 'running'], true)) {
            return $current; // already on its way
        }

        $entry = ['wish' => (string) $wish, 'niche' => $niche, 'typed' => (string) ($ui['typed'] ?? ''), 'answers' => (array) ($ui['answers'] ?? [])];
        $this->cache->forever("plex-recommender:claude:latest:{$type}", $entry);
        $this->addToHistory($type, $entry);
        $this->setStatus($type, $wish, $niche, ['state' => 'queued', 'phase' => null]);

        $job = new AskClaudeJob($type, $wish, $niche, $fresh);
        $pending = dispatch($job);
        if ($connection = config('plex-recommender.claude.queue_connection')) {
            $pending->onConnection($connection);
        }
        if ($queue = config('plex-recommender.claude.queue')) {
            $pending->onQueue($queue);
        }
        try {
            unset($pending); // dispatches now (the sync driver runs the job right here)
        } catch (Throwable $e) {
            $this->markFailed($type, $wish, $niche, 'Claude-jobbet fejlede: '.$e->getMessage());
        }

        return $this->status($type, $wish, $niche) ?? ['state' => 'queued'];
    }

    /** Run by AskClaudeJob. */
    public function runQueued(string $type, ?string $wish, string $niche, bool $fresh): void
    {
        $this->setStatus($type, $wish, $niche, ['state' => 'running', 'phase' => 'prompt', 'started_at' => time()]);
        $result = $this->recommend($type, $wish, $niche, 12, $fresh, function (string $phase) use ($type, $wish, $niche) {
            $this->setStatus($type, $wish, $niche, ['phase' => $phase]);
        });
        $this->setStatus($type, $wish, $niche, isset($result['error'])
            ? ['state' => 'failed', 'error' => $result['error']]
            : ['state' => 'done']);
    }

    /**
     * Background status: state queued|running|done|failed, phase, queued_at,
     * started_at, error, estimate (seconds). Stale jobs are reported as failed.
     */
    public function status(string $type, ?string $wish, ?string $niche): ?array
    {
        $status = $this->cache->get($this->statusKey(RecommenderService::normalizeType($type), $this->cleanWish($wish), self::niche($niche)));
        if (! $status) {
            return null;
        }
        $status['estimate'] = $this->estimate();
        $since = $status['started_at'] ?? $status['queued_at'] ?? time();
        if ($status['state'] === 'running' && time() - $since > 600) {
            return ['state' => 'failed', 'error' => 'Claude blev ikke færdig. Prøv igen.'] + $status;
        }

        return $status;
    }

    public function markFailed(string $type, ?string $wish, string $niche, string $error): void
    {
        $this->setStatus($type, $this->cleanWish($wish), self::niche($niche), ['state' => 'failed', 'error' => $error]);
    }

    /** Remember which search is shown, so it's restored when coming back. */
    public function setLatest(string $type, array $entry): void
    {
        $this->cache->forever('plex-recommender:claude:latest:'.RecommenderService::normalizeType($type), [
            'wish' => (string) ($entry['wish'] ?? ''), 'niche' => self::niche($entry['niche'] ?? null),
            'typed' => (string) ($entry['typed'] ?? ''), 'answers' => (array) ($entry['answers'] ?? []),
        ]);
    }

    /** What was asked last (to restore the page when coming back). */
    public function latest(string $type): ?array
    {
        return $this->cache->get('plex-recommender:claude:latest:'.RecommenderService::normalizeType($type));
    }

    /**
     * Earlier searches that still have a saved answer, newest first.
     *
     * @return list<array{wish: string, niche: string, typed: string, answers: array, asked_at: int, count: int, titles: list<string>}>
     */
    public function history(string $type, int $limit = 12): array
    {
        $type = RecommenderService::normalizeType($type);
        $out = [];
        foreach ($this->cache->get("plex-recommender:claude:history:{$type}", []) as $entry) {
            if ($result = $this->cached($type, $entry['wish'], $entry['niche'])) {
                $out[] = $entry + [
                    'asked_at' => $result['asked_at'],
                    'count' => count($result['recommendations']),
                    'titles' => array_slice(array_column($result['recommendations'], 'title'), 0, 3),
                ];
            }
        }
        usort($out, fn ($a, $b) => $b['asked_at'] <=> $a['asked_at']);

        return array_slice($out, 0, $limit);
    }

    protected function addToHistory(string $type, array $entry): void
    {
        $key = "plex-recommender:claude:history:{$type}";
        $same = fn ($e) => mb_strtolower($e['wish']) === mb_strtolower($entry['wish']) && $e['niche'] === $entry['niche'];
        $list = array_values(array_filter($this->cache->get($key, []), fn ($e) => ! $same($e)));
        $this->cache->forever($key, array_slice([$entry, ...$list], 0, 30));
    }

    /** Expected seconds for an answer: average of the last runs, 45 s until we know. */
    public function estimate(): int
    {
        $runs = $this->cache->get('plex-recommender:claude:durations:'.$this->settings->claudeDriver(), []);

        return $runs ? (int) round(array_sum($runs) / count($runs)) : 45;
    }

    protected function recordDuration(float $seconds): void
    {
        $key = 'plex-recommender:claude:durations:'.$this->settings->claudeDriver();
        $runs = array_slice([...$this->cache->get($key, []), max(1.0, $seconds)], -10);
        $this->cache->forever($key, $runs);
    }

    protected function setStatus(string $type, ?string $wish, string $niche, array $values): void
    {
        $key = $this->statusKey($type, $wish, $niche);
        $status = array_merge($this->cache->get($key, []), $values);
        if (($values['state'] ?? null) === 'queued') {
            $status = ['state' => 'queued', 'phase' => null, 'queued_at' => time()];
        }
        $this->cache->put($key, $status, now()->addMinutes(30));
    }

    protected function statusKey(string $type, ?string $wish, string $niche): string
    {
        return 'plex-recommender:claude:status:'.md5($type.'|'.mb_strtolower((string) $wish).'|'.$niche.'|'.$this->settings->claudeDriver());
    }

    /**
     * Drop what's seen, invented or too popular; enrich the rest with TMDB data.
     *
     * @return array{0: list<array>, 1: array{seen: int, popular: int, not_found: int}}
     */
    protected function verify(string $type, string $niche, array $raw): array
    {
        $tmdb = $this->svc->tmdb();
        $maxVotes = self::NICHE[$niche][$type === MediaItem::SHOW ? 2 : 1];
        $filtered = ['seen' => 0, 'popular' => 0, 'not_found' => 0];

        $byTmdb = [];
        foreach ($this->svc->items($type) as $item) {
            if ($item->tmdbId) {
                $byTmdb[$item->tmdbId] = $item;
            }
        }

        $out = $seenTitles = [];
        foreach ($raw as $r) {
            if (! is_array($r) || empty($r['title'])) {
                continue;
            }
            $title = (string) $r['title'];
            $orig = ! empty($r['original_title']) && $r['original_title'] !== $title ? (string) $r['original_title'] : null;
            $year = isset($r['year']) && is_numeric($r['year']) ? (int) $r['year'] : null;

            $match = null;
            if ($tmdb) {
                try {
                    foreach (array_filter([$orig, $title]) as $t) {
                        if ($match = $tmdb->search($type, $t, $year)) {
                            break;
                        }
                    }
                } catch (Throwable) {
                    $tmdb = null; // TMDB unavailable: skip verification for the rest
                }
                if ($tmdb && ! $match) {
                    $filtered['not_found']++;

                    continue;
                }
            }

            // Library check: by TMDB id when we have it, otherwise by title.
            $owned = $match && isset($byTmdb[(string) $match['id']]) ? $byTmdb[(string) $match['id']] : null;
            $status = $owned?->status();
            $inLibrary = (bool) $owned;
            if (! $owned) {
                $o = $this->svc->checkOwned([$year ? "{$title} ({$year})" : $title])[0];
                if (! $o['in_library'] && $orig) {
                    $o = $this->svc->checkOwned([$year ? "{$orig} ({$year})" : $orig])[0];
                }
                $inLibrary = $o['in_library'];
                $status = $o['status'] ?? null;
            }
            if ($status === 'watched') {
                $filtered['seen']++;

                continue;
            }

            if ($match && $maxVotes !== null && ($match['vote_count'] ?? 0) > $maxVotes) {
                $filtered['popular']++;

                continue;
            }

            $key = $match ? 'tmdb:'.$match['id'] : mb_strtolower($title).'|'.$year;
            if (isset($seenTitles[$key])) {
                continue;
            }
            $seenTitles[$key] = true;

            $date = $match['release_date'] ?? $match['first_air_date'] ?? '';
            $out[] = [
                'title' => $match ? ($match['title'] ?? $match['name'] ?? $title) : $title,
                'original_title' => $orig,
                'year' => ctype_digit(substr($date, 0, 4)) ? (int) substr($date, 0, 4) : $year,
                'director' => $r['director'] ?? null,
                'country' => $r['country'] ?? null,
                'reason' => (string) ($r['reason'] ?? ''),
                'because_you_watched' => array_values(array_filter((array) ($r['because_you_watched'] ?? []), 'is_string')),
                'in_library' => $inLibrary,
                'status' => $status,
                'rating_key' => $owned?->ratingKey,
                'tmdb_id' => $match['id'] ?? null,
                'tmdb_rating' => $match['vote_average'] ?? null,
                'votes' => $match['vote_count'] ?? null,
                'overview' => mb_substr((string) ($match['overview'] ?? ''), 0, 400),
                'poster' => ! empty($match['poster_path']) ? 'https://image.tmdb.org/t/p/w342'.$match['poster_path'] : null,
                'url' => $match ? 'https://www.themoviedb.org/'.TmdbClient::kind($type).'/'.$match['id'] : null,
            ];
        }

        return [$out, $filtered];
    }

    /** Quick round trip to check the setup works. Returns the driver label. */
    public function test(): string
    {
        if (! $driver = $this->driver()) {
            throw new ClaudeException('Claude er ikke sat op.');
        }
        $text = $driver->ask('Du er en testassistent.', 'Svar med {"ok": true}', [
            'type' => 'object',
            'properties' => ['ok' => ['type' => 'boolean']],
            'required' => ['ok'],
            'additionalProperties' => false,
        ]);
        if (! (self::decode($text)['ok'] ?? false)) {
            throw new ClaudeException('Claude svarede, men ikke som forventet: '.mb_substr($text, 0, 200));
        }

        return $driver->label();
    }

    public function systemPrompt(): string
    {
        return <<<'TXT'
        Du er en passioneret filmkender med bred viden om verdensfilm, festivalfilm og tv fra hele verden. Du giver personlige
        anbefalinger til én dansk bruger ud fra deres Plex-historik. Du skal finde det, algoritmerne hos streamingtjenesterne
        ikke finder.

        Sådan arbejder du:
        1. Læs favoritterne, det brugeren har set for nylig, og det de ikke brød sig om. Find det der faktisk forbinder
           favoritterne: tone, stemning, temaer, tempo, fortælleform, visuel stil, lande og perioder. Genrer alene er for groft.
        2. Beskriv smagen kort i taste_summary.
        3. Find titler der rammer den beskrivelse præcist, i den grad af "smalhed" brugeren beder om.

        Regler:
        - Det brugeren har set for nylig vejer mest. Gamle favoritter er baggrund.
        - Foreslå aldrig noget fra listerne "Allerede set" eller "Foreslået før".
        - Kun titler der findes i virkeligheden. Er du i tvivl om en titel eller dens årstal, så vælg en anden.
        - Variér: ikke flere titler af samme instruktør, og gerne forskellige lande og årtier.
        - Begrundelsen skal nævne konkrete fællestræk med titler brugeren har set (f.eks. "samme kolde, nordiske tone som Dark"),
          ikke generiske vendinger som "du kan lide drama". Dansk, højst 2 sætninger.
        - Stil til sidst 2-3 korte opklarende spørgsmål (questions), som ville hjælpe dig med at ramme endnu bedre næste gang,
          f.eks. om et tema, en periode eller en stil du ser i historikken. Svarmulighederne skal være korte.
        - Svar kun med JSON efter det givne schema.
        TXT;
    }

    public function prompt(string $type, ?string $wish, string $niche, int $count): string
    {
        $kind = $type === MediaItem::SHOW ? 'serier' : 'film';
        $items = $this->svc->items($type);
        $profile = $this->svc->profile($type);

        $describe = function (MediaItem $i): string {
            $bits = array_filter([
                $i->directors ? 'instr. '.implode(', ', array_slice($i->directors, 0, 2)) : null,
                $i->genres ? implode('/', array_slice($i->genres, 0, 3)) : null,
                $i->type === MediaItem::SHOW ? "{$i->viewedLeafCount}/{$i->leafCount} afsnit" : ($i->viewCount > 1 ? "set {$i->viewCount} gange" : null),
                $i->userRating !== null ? 'min bedømmelse '.round($i->userRating / 2, 1).'/5' : null,
                $i->lastViewedAt ? 'sidst set '.date('Y-m', $i->lastViewedAt) : null,
            ]);

            return '- '.$i->label().($bits ? ' ('.implode(', ', $bits).')' : '');
        };

        $favorites = array_map(fn ($t) => $describe($t[0]), array_slice($profile->topTitles, 0, 25));

        $recent = [];
        try {
            foreach ($this->svc->mostWatched($type, 15, 90) as $r) {
                if (isset($r['title'])) {
                    $recent[] = "- {$r['title']} (sidst set {$r['last_viewed']})";
                }
            }
        } catch (Throwable) {
            // History endpoint unavailable: favorites carry the recency info.
        }

        // Low ratings and shows dropped early say what to avoid.
        $disliked = array_filter($items, fn (MediaItem $i) => ($i->userRating !== null && $i->userRating <= 4)
            || ($i->type === MediaItem::SHOW && $i->leafCount >= 6 && $i->viewedLeafCount > 0 && $i->viewedLeafCount <= 2
                && $i->lastViewedAt && $i->lastViewedAt < time() - 30 * 86400));
        $disliked = array_map(fn (MediaItem $i) => $describe($i), array_slice(array_values($disliked), 0, 20));

        $watched = array_filter($items, fn (MediaItem $i) => $i->watched());
        usort($watched, fn ($a, $b) => ($b->lastViewedAt ?? 0) <=> ($a->lastViewedAt ?? 0));
        $seen = array_map(fn (MediaItem $i) => $i->label(), array_slice($watched, 0, 500));

        $before = $this->previous($type);

        return implode("\n\n", array_filter([
            "Find {$count} {$kind} til mig.",
            'Hvor smalt: '.self::NICHE[$niche][0],
            $wish ? "Mit ønske lige nu (vægt det højt): {$wish}" : null,
            "## Set for nylig (sidste 90 dage)\n".($recent ? implode("\n", $recent) : '–'),
            "## Mine favoritter (mest engagerede først)\n".(implode("\n", $favorites) ?: '–'),
            $disliked ? "## Det brød jeg mig ikke om (lav bedømmelse eller droppet hurtigt)\n".implode("\n", $disliked) : null,
            "## Allerede set (foreslå IKKE)\n".(implode('; ', $seen) ?: '–'),
            $before ? "## Foreslået før (gentag IKKE)\n".implode('; ', $before) : null,
        ]));
    }

    /** @return array<string, list<string>> Claude's follow-up questions, without duplicates of the base questions. */
    protected static function cleanQuestions(mixed $questions, string $type): array
    {
        $out = [];
        foreach (is_array($questions) ? $questions : [] as $q) {
            $question = trim((string) ($q['question'] ?? ''));
            $options = array_values(array_unique(array_filter(array_map(fn ($o) => trim((string) $o), (array) ($q['options'] ?? [])))));
            if ($question !== '' && count($options) >= 2 && ! isset(self::baseQuestions($type)[$question])) {
                $out[mb_substr($question, 0, 120)] = array_slice(array_map(fn ($o) => mb_substr($o, 0, 60), $options), 0, 4);
            }
        }

        return array_slice($out, 0, 3, true);
    }

    /** Parse JSON from a model answer, tolerating code fences or text around it. */
    public static function decode(string $text): ?array
    {
        $text = trim($text);
        if (is_array($json = json_decode($text, true))) {
            return $json;
        }
        if (preg_match('~```(?:json)?\s*(.+?)```~s', $text, $m) && is_array($json = json_decode(trim($m[1]), true))) {
            return $json;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end > $start && is_array($json = json_decode(substr($text, $start, $end - $start + 1), true))) {
            return $json;
        }

        return null;
    }

    /** Titles suggested earlier, so asking again gives new ones. */
    public function previous(string $type): array
    {
        return $this->cache->get("plex-recommender:claude:previous:{$type}", []);
    }

    protected function remember(string $type, array $titles): void
    {
        $all = array_values(array_unique([...$titles, ...$this->previous($type)]));
        $this->cache->forever("plex-recommender:claude:previous:{$type}", array_slice($all, 0, 150));
    }

    protected function cleanWish(?string $wish): ?string
    {
        return trim((string) $wish) ?: null;
    }

    protected function cacheKey(string $type, ?string $wish, string $niche): string
    {
        return 'plex-recommender:claude:v2:'.md5($type.'|'.mb_strtolower((string) $wish).'|'.$niche.'|'.$this->settings->claudeDriver());
    }
}
