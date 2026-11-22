<?php

namespace EmilMoe\PlexRecommender\Data;

final class MediaItem
{
    public const MOVIE = 'movie';
    public const SHOW = 'show';

    /**
     * @param  list<string>  $genres
     * @param  list<string>  $directors
     * @param  list<string>  $actors
     */
    public function __construct(
        public string $ratingKey,
        public string $title,
        public string $type,
        public ?int $year = null,
        public string $library = '',
        public array $genres = [],
        public array $directors = [],
        public array $actors = [],
        public ?string $tmdbId = null,
        public ?string $imdbId = null,
        public ?string $tvdbId = null,
        public int $viewCount = 0,
        public ?int $lastViewedAt = null,
        public int $leafCount = 0,
        public int $viewedLeafCount = 0,
        public ?float $rating = null,
        public ?float $audienceRating = null,
        public ?float $userRating = null,
        public string $summary = '',
    ) {}

    /** Build from a Plex "Metadata" JSON object. */
    public static function fromPlex(array $meta, string $library = ''): self
    {
        $tags = fn (string $key, ?int $limit = null) => array_slice(
            array_values(array_filter(array_map(fn ($t) => $t['tag'] ?? '', $meta[$key] ?? []))),
            0,
            $limit,
        );
        $float = fn ($v) => is_numeric($v) ? (float) $v : null;

        $item = new self(
            ratingKey: (string) ($meta['ratingKey'] ?? ''),
            title: (string) ($meta['title'] ?? ''),
            type: (string) ($meta['type'] ?? self::MOVIE),
            year: isset($meta['year']) ? (int) $meta['year'] : null,
            library: $library ?: (string) ($meta['librarySectionTitle'] ?? ''),
            genres: $tags('Genre'),
            directors: $tags('Director'),
            actors: $tags('Role', 5),
            viewCount: (int) ($meta['viewCount'] ?? 0),
            lastViewedAt: isset($meta['lastViewedAt']) ? (int) $meta['lastViewedAt'] : null,
            leafCount: (int) ($meta['leafCount'] ?? 0),
            viewedLeafCount: (int) ($meta['viewedLeafCount'] ?? 0),
            rating: $float($meta['rating'] ?? null),
            audienceRating: $float($meta['audienceRating'] ?? null),
            userRating: $float($meta['userRating'] ?? null),
            summary: (string) ($meta['summary'] ?? ''),
        );

        $guids = array_map(fn ($g) => $g['id'] ?? '', $meta['Guid'] ?? []);
        $guids[] = (string) ($meta['guid'] ?? '');
        $patterns = [
            'tmdbId' => '~(?:tmdb|themoviedb)://(\d+)~',
            'imdbId' => '~imdb://(tt\d+)~',
            'tvdbId' => '~(?:tvdb|thetvdb)://(\d+)~',
        ];
        foreach ($patterns as $prop => $pattern) {
            foreach ($guids as $g) {
                if (preg_match($pattern, $g, $m)) {
                    $item->{$prop} = $m[1];
                    break;
                }
            }
        }

        return $item;
    }

    public function watched(): bool
    {
        return $this->type === self::SHOW ? $this->viewedLeafCount > 0 : $this->viewCount > 0;
    }

    public function completion(): float
    {
        if ($this->type === self::SHOW) {
            return $this->leafCount ? $this->viewedLeafCount / $this->leafCount : 0.0;
        }

        return $this->viewCount > 0 ? 1.0 : 0.0;
    }

    /** "unwatched" | "in_progress" | "watched" */
    public function status(): string
    {
        if (! $this->watched()) {
            return 'unwatched';
        }

        return $this->type === self::SHOW && $this->completion() < 1.0 ? 'in_progress' : 'watched';
    }

    public function label(): string
    {
        return $this->year ? "{$this->title} ({$this->year})" : $this->title;
    }

    public function brief(): array
    {
        $d = [
            'title' => $this->title,
            'year' => $this->year,
            'type' => $this->type,
            'status' => $this->status(),
            'genres' => $this->genres,
        ];
        if ($this->type === self::SHOW) {
            $d['episodes_watched'] = "{$this->viewedLeafCount}/{$this->leafCount}";
        } else {
            $d['view_count'] = $this->viewCount;
        }
        if ($this->userRating !== null) {
            $d['your_rating'] = $this->userRating;
        }

        return $d;
    }

    /** Lowercase, strip accents/punctuation and leading articles for matching. */
    public static function normalizeTitle(string $title): string
    {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $title) ?: strtolower($title);
        $t = str_replace('&', ' and ', $t);
        $t = preg_replace('~[^a-z0-9 ]+~', ' ', $t);
        $t = preg_replace('~^(the|a|an|den|det|de) ~', '', trim($t));

        return trim(preg_replace('~\s+~', ' ', $t));
    }
}
