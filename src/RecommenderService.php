<?php

namespace EmilMoe\PlexRecommender;

use EmilMoe\PlexRecommender\Clients\PlexClient;
use EmilMoe\PlexRecommender\Clients\TmdbClient;
use EmilMoe\PlexRecommender\Data\MediaItem;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use InvalidArgumentException;

/**
 * All high-level operations. Used by the MCP tools, the artisan commands and
 * directly in your own app via the PlexRecommender facade.
 */
class RecommenderService
{
    protected ?TmdbClient $tmdbClient = null;

    protected ?string $tmdbClientKey = null;

    public function __construct(
        protected PlexClient $plex,
        protected Settings $settings,
        protected Cache $cache,
        protected Http $http,
    ) {}

    public function plex(): PlexClient
    {
        return $this->plex;
    }

    /** TMDB client for the current settings, or null when no API key is configured. */
    public function tmdb(): ?TmdbClient
    {
        if (! $key = $this->settings->tmdbApiKey()) {
            return null;
        }
        $id = $key.'|'.$this->settings->language();
        if ($this->tmdbClientKey !== $id) {
            $this->tmdbClient = new TmdbClient($this->http, $key, $this->settings->language(), (string) config('plex-recommender.tmdb.base_url', 'https://api.themoviedb.org/3'));
            $this->tmdbClientKey = $id;
        }

        return $this->tmdbClient;
    }

    public static function normalizeType(string $type): string
    {
        $t = match (mb_strtolower($type)) {
            'movie', 'movies', 'film', 'films' => MediaItem::MOVIE,
            'show', 'shows', 'tv', 'serie', 'serier', 'series' => MediaItem::SHOW,
            default => null,
        };

        return $t ?? throw new InvalidArgumentException("Type skal være 'movie' eller 'show', fik '{$type}'.");
    }

    public function hasTmdb(): bool
    {
        return $this->tmdb() !== null;
    }

    // ------------------------------------------------------------------ data

    /** @return list<MediaItem> */
    public function items(string $type): array
    {
        $type = self::normalizeType($type);

        // Cached as plain arrays: Laravel 13 refuses to unserialize objects by default.
        $rows = $this->cache->remember(
            "plex-recommender:items:{$type}:v2",
            $this->settings->cacheTtl(),
            fn () => array_map(fn (MediaItem $i) => $i->toArray(), $this->plex->libraryItems($type)),
        );

        return array_map(fn (array $row) => MediaItem::fromArray($row), $rows);
    }

    /** @return list<MediaItem> */
    public function allItems(): array
    {
        return [...$this->items(MediaItem::MOVIE), ...$this->items(MediaItem::SHOW)];
    }

    public function refresh(): void
    {
        $this->cache->forget('plex-recommender:items:'.MediaItem::MOVIE.':v2');
        $this->cache->forget('plex-recommender:items:'.MediaItem::SHOW.':v2');
    }

    public function profile(?string $type = null): TasteProfile
    {
        return TasteProfile::build($type ? $this->items($type) : $this->allItems(), null, $this->settings->halfLifeDays());
    }

    // ------------------------------------------------------------ operations

    public function libraryOverview(): array
    {
        $stats = [];
        foreach ([MediaItem::MOVIE, MediaItem::SHOW] as $t) {
            $items = $this->items($t);
            $stats[$t] = ['total' => count($items), ...array_count_values(array_map(fn ($i) => $i->status(), $items))];
        }

        return ['libraries' => $this->plex->libraries(), 'stats' => $stats, 'tmdb_enabled' => $this->hasTmdb()];
    }

    public function tasteProfile(?string $type = null, int $topN = 10): array
    {
        return $this->profile($type)->summary($topN);
    }

    /** Most watched: by engagement (all time), or by plays within the last $days days. */
    public function mostWatched(string $type, int $limit = 20, ?int $days = null): array
    {
        $type = self::normalizeType($type);
        if (! $days) {
            return array_map(
                fn ($t) => [...$t[0]->brief(), 'engagement' => round($t[1], 2)],
                array_slice($this->profile($type)->topTitles, 0, $limit),
            );
        }

        $history = $this->plex->history(5000, time() - $days * 86400, $this->settings->accountId());
        $plays = $last = [];
        foreach ($history as $h) {
            $key = match (true) {
                $type === MediaItem::MOVIE && ($h['type'] ?? '') === 'movie' => (string) ($h['ratingKey'] ?? ''),
                $type === MediaItem::SHOW && ($h['type'] ?? '') === 'episode' => (string) ($h['grandparentRatingKey']
                    ?? basename((string) ($h['grandparentKey'] ?? ''))),
                default => null,
            };
            if (! $key) {
                continue;
            }
            $plays[$key] = ($plays[$key] ?? 0) + 1;
            $last[$key] = max($last[$key] ?? 0, (int) ($h['viewedAt'] ?? 0));
        }
        arsort($plays);

        $byKey = [];
        foreach ($this->items($type) as $i) {
            $byKey[$i->ratingKey] = $i;
        }

        $out = [];
        foreach (array_slice($plays, 0, $limit, true) as $key => $n) {
            $entry = isset($byKey[$key]) ? $byKey[$key]->brief() : ['rating_key' => (string) $key];
            $out[] = [...$entry, 'plays_in_period' => $n, 'last_viewed' => date('Y-m-d', $last[$key])];
        }

        return $out;
    }

    public function recentHistory(int $limit = 30): array
    {
        return array_map(function ($h) {
            if (($h['type'] ?? '') === 'episode') {
                $title = sprintf(
                    '%s S%02dE%02d – %s',
                    $h['grandparentTitle'] ?? '?',
                    (int) ($h['parentIndex'] ?? 0),
                    (int) ($h['index'] ?? 0),
                    $h['title'] ?? '',
                );
            } else {
                $title = ($h['title'] ?? '').(isset($h['year']) ? " ({$h['year']})" : '');
            }

            return [
                'title' => $title,
                'type' => $h['type'] ?? null,
                'viewed_at' => date('Y-m-d H:i', (int) ($h['viewedAt'] ?? 0)),
            ];
        }, $this->plex->history($limit, null, $this->settings->accountId()));
    }

    /** Titles you own but haven't watched, ranked by fit with your taste profile. */
    public function unwatchedInLibrary(string $type, int $limit = 20, ?string $genre = null, bool $includeInProgress = false): array
    {
        $type = self::normalizeType($type);
        $profile = $this->profile();
        $candidates = [];

        foreach ($this->items($type) as $item) {
            $status = $item->status();
            if ($status === 'watched' || ($status === 'in_progress' && ! $includeInProgress)) {
                continue;
            }
            if ($genre && ! in_array(mb_strtolower($genre), array_map('mb_strtolower', $item->genres), true)) {
                continue;
            }
            [$score, $why, $breakdown] = $profile->score($item);
            $candidates[] = [...$item->brief(), 'score' => $score, 'why' => $why, 'breakdown' => $breakdown, 'summary' => mb_substr($item->summary, 0, 300), 'thumb' => $item->thumb];
        }
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($candidates, 0, $limit);
    }

    /** Titles NOT in your library, via TMDB recommendations for your most-watched titles. */
    public function recommendNew(string $type, int $limit = 20, int $seeds = 15, ?int $minVotes = null): array
    {
        $type = self::normalizeType($type);
        $tmdb = $this->tmdb();
        $minVotes ??= $this->settings->minVotes();
        if (! $tmdb) {
            return ['error' => 'TMDB_API_KEY er ikke sat. Brug taste_profile + check_owned i stedet, '
                .'eller tilføj en gratis nøgle fra themoviedb.org.'];
        }

        $items = $this->items($type);
        $ownedTmdb = array_flip(array_filter(array_map(fn ($i) => $i->tmdbId, $items)));
        $ownedTitles = [];
        foreach ($items as $i) {
            $ownedTitles[MediaItem::normalizeTitle($i->title).'|'.$i->year] = true;
        }

        $profile = $this->profile();
        $seedItems = array_slice(array_values(array_filter($profile->topTitles, fn ($t) => $t[0]->type === $type)), 0, $seeds);
        $genreMap = $tmdb->genreNames($type);

        $scores = $because = $info = $usedSeeds = $seedWeights = [];
        foreach ($seedItems as [$item, $weight]) {
            $tmdbId = $item->tmdbId ?? $tmdb->findTmdbId($type, $item->imdbId, $item->tvdbId);
            if (! $tmdbId) {
                continue;
            }
            $usedSeeds[] = $item->label();
            $seedWeights[$item->title] = $weight;
            foreach ($tmdb->recommendations($type, $tmdbId) as $pos => $rec) {
                $id = $rec['id'];
                $scores[$id] = ($scores[$id] ?? 0) + $weight * (1.0 - $pos / 25);
                $because[$id][] = $item->title;
                $info[$id] = $rec;
            }
        }

        $results = [];
        foreach ($scores as $id => $base) {
            $rec = $info[$id];
            $title = $rec['title'] ?? $rec['name'] ?? '';
            $orig = $rec['original_title'] ?? $rec['original_name'] ?? '';
            $date = $rec['release_date'] ?? $rec['first_air_date'] ?? '';
            $year = ctype_digit(substr($date, 0, 4)) ? (int) substr($date, 0, 4) : null;

            if (isset($ownedTmdb[(string) $id]) || ($rec['vote_count'] ?? 0) < $minVotes) {
                continue;
            }
            foreach (array_filter([$title, $orig]) as $t) {
                if (isset($ownedTitles[MediaItem::normalizeTitle($t).'|'.$year])) {
                    continue 2;
                }
            }

            $genreIds = $rec['genre_ids'] ?? [];
            $allGenreNames = array_merge(...array_map(fn ($g) => $genreMap[$g] ?? [], $genreIds ?: [0]));
            $quality = ($rec['vote_average'] ?? 0) / 10;
            $genreFactor = 0.6 + 0.8 * $profile->genreMatch($allGenreNames);
            $qualityFactor = 0.7 + 0.6 * $quality;
            $score = $base * $genreFactor * $qualityFactor;
            $seedTitles = array_values(array_unique($because[$id]));

            $results[] = [
                'title' => $title,
                'original_title' => $orig && $orig !== $title ? $orig : null,
                'year' => $year,
                'genres' => array_values(array_filter(array_map(fn ($g) => $genreMap[$g][0] ?? null, $genreIds))),
                'tmdb_rating' => $rec['vote_average'] ?? null,
                'votes' => $rec['vote_count'] ?? null,
                'because_you_watched' => array_slice($seedTitles, 0, 4),
                'score' => round($score, 3),
                'breakdown' => [
                    'tmdb_match' => round($base, 2),
                    'seed_count' => count($seedTitles),
                    'seeds' => array_map(fn ($t) => ['title' => $t, 'engagement' => round($seedWeights[$t] ?? 0, 2)], array_slice($seedTitles, 0, 5)),
                    'genre_factor' => round($genreFactor, 2),
                    'quality_factor' => round($qualityFactor, 2),
                ],
                'overview' => mb_substr($rec['overview'] ?? '', 0, 300),
                'url' => 'https://www.themoviedb.org/'.TmdbClient::kind($type)."/{$id}",
                'poster' => ! empty($rec['poster_path']) ? 'https://image.tmdb.org/t/p/w342'.$rec['poster_path'] : null,
            ];
        }
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return ['seeds_used' => $usedSeeds, 'recommendations' => array_slice($results, 0, $limit)];
    }

    /**
     * Check titles (optionally "Title (Year)") against the library. Useful when Claude
     * suggests titles from its own knowledge: filter out what you already have or have seen.
     *
     * @param  list<string>  $titles
     */
    public function checkOwned(array $titles): array
    {
        $index = [];
        foreach ($this->allItems() as $i) {
            $index[MediaItem::normalizeTitle($i->title)][] = $i;
        }

        return array_map(function (string $raw) use ($index) {
            $name = trim($raw);
            $year = null;
            if (preg_match('~^(.*?)\s*\((\d{4})\)\s*$~', $name, $m)) {
                [$name, $year] = [$m[1], (int) $m[2]];
            }
            $norm = MediaItem::normalizeTitle($name);
            $matches = $index[$norm] ?? $this->fuzzyMatches($norm, $index);
            if ($year) {
                $matches = array_values(array_filter($matches, fn ($i) => ! $i->year || abs($i->year - $year) <= 1));
            }
            if (! $matches) {
                return ['query' => $raw, 'in_library' => false];
            }

            return ['query' => $raw, 'in_library' => true, 'match' => $matches[0]->label(), ...$matches[0]->brief()];
        }, $titles);
    }

    protected function fuzzyMatches(string $norm, array $index): array
    {
        $best = null;
        $bestPct = 0.0;
        foreach (array_keys($index) as $key) {
            similar_text($norm, (string) $key, $pct);
            if ($pct > $bestPct) {
                [$best, $bestPct] = [$key, $pct];
            }
        }

        return $bestPct >= 88 ? $index[$best] : [];
    }

    public function searchLibrary(string $query, int $limit = 20): array
    {
        $q = mb_strtolower($query);
        $hits = array_filter($this->allItems(), fn (MediaItem $i) => str_contains(mb_strtolower($i->title), $q)
            || in_array($q, array_map('mb_strtolower', $i->genres), true)
            || array_filter([...$i->actors, ...$i->directors], fn ($p) => str_contains(mb_strtolower($p), $q)));

        return array_map(fn ($i) => $i->brief(), array_slice(array_values($hits), 0, $limit));
    }
}
