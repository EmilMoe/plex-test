<?php

namespace EmilMoe\PlexRecommender;

use EmilMoe\PlexRecommender\Data\MediaItem;

/**
 * A taste profile derived from what has been watched, plus scoring of titles against it.
 */
class TasteProfile
{
    /**
     * @param  array<string, float>  $genres  lowercased genre => 0..1
     * @param  array<string, float>  $directors
     * @param  array<string, float>  $actors
     * @param  array<string, float>  $decades
     * @param  list<array{0: MediaItem, 1: float}>  $topTitles  sorted by engagement desc
     */
    public function __construct(
        public array $genres = [],
        public array $directors = [],
        public array $actors = [],
        public array $decades = [],
        public array $topTitles = [],
    ) {}

    /**
     * How strongly a watched item says something about your taste.
     *
     * - Movies: rewatches count, with diminishing returns.
     * - Shows: more episodes and higher completion count more, capped so a long
     *   show weighs about as much as a much-loved movie; a show dropped after
     *   two episodes is a weak signal.
     * - Your own star rating scales the weight; 2 stars or less makes it negative.
     * - Recent viewing counts more than viewing from years ago.
     */
    public static function engagementWeight(MediaItem $item, ?int $now = null, int $halfLifeDays = 180): float
    {
        if (! $item->watched()) {
            return 0.0;
        }
        // Movies: 1 view = 2, 2 views ≈ 3.2, 3 views = 4.
        // Shows are put on the same scale, so a show isn't worth more just because
        // it has many episodes: a finished show with 10+ episodes = 4 (like a movie
        // seen three times), a show dropped after 2 of 20 episodes ≈ 1.
        $w = $item->type === MediaItem::SHOW
            ? 2.0 * min(1.0, log(1 + $item->viewedLeafCount, 2) / log(11, 2)) * (1.0 + $item->completion())
            : log(1 + $item->viewCount, 2) * 2.0;

        // Your own star rating (Plex: 0-10, i.e. half stars). Good ratings boost
        // the weight; low ratings (2 stars or less) turn it negative, so a film you
        // disliked pulls its genres, director and actors *down* in the profile.
        if ($item->userRating !== null) {
            $w *= $item->userRating >= 5
                ? 0.25 + 1.5 * ($item->userRating / 10)
                : -(5 - $item->userRating) / 5;
        }
        if ($item->lastViewedAt) {
            $ageDays = max(0, (($now ?? time()) - $item->lastViewedAt) / 86400);
            // Small floor so an old favourite never fully disappears, but no longer
            // dominates: something watched 3 half-lives ago counts ~20% of a fresh view.
            $w *= 0.1 + 0.9 * 0.5 ** ($ageDays / $halfLifeDays);
        }

        return $w;
    }

    /** @param  iterable<MediaItem>  $items */
    public static function build(iterable $items, ?int $now = null, int $halfLifeDays = 180): self
    {
        $genres = $directors = $actors = $decades = $weighted = [];

        foreach ($items as $item) {
            $w = self::engagementWeight($item, $now, $halfLifeDays);
            if ($w == 0) {
                continue;
            }
            if ($w > 0) {
                $weighted[] = [$item, $w]; // only liked titles count as favourites
            }
            foreach ($item->genres as $g) {
                $g = mb_strtolower($g);
                $genres[$g] = ($genres[$g] ?? 0) + $w;
            }
            foreach ($item->directors as $d) {
                $directors[$d] = ($directors[$d] ?? 0) + $w;
            }
            foreach ($item->actors as $i => $a) {
                $actors[$a] = ($actors[$a] ?? 0) + $w * (1.0 - 0.15 * $i); // top-billed counts more
            }
            if ($item->year) {
                $dec = intdiv($item->year, 10) * 10 .'s';
                $decades[$dec] = ($decades[$dec] ?? 0) + $w;
            }
        }
        usort($weighted, fn ($a, $b) => $b[1] <=> $a[1]);

        return new self(
            self::normalize($genres),
            self::normalize($directors),
            self::normalize($actors),
            self::normalize($decades),
            $weighted,
        );
    }

    protected static function normalize(array $d): array
    {
        if (! $d) {
            return [];
        }
        arsort($d);
        $top = max(max($d), 1e-9);

        // Disliked entries keep a negative value (down to -1) so they count against a title.
        return array_map(fn ($v) => max(-1.0, $v / $top), $d);
    }

    /** 0..1 score of how well a set of genre names matches the profile. */
    public function genreMatch(array $genreNames): float
    {
        $names = array_unique(array_map('mb_strtolower', array_filter($genreNames)));
        if (! $names || ! $this->genres) {
            return 0.0;
        }
        $vals = array_map(fn ($n) => $this->genres[$n] ?? 0.0, $names);
        rsort($vals);

        // Best genre dominates, the next couple add a little.
        return min(1.0, $vals[0] * 0.7 + array_sum(array_slice($vals, 1, 2)) * 0.15);
    }

    /**
     * Score an item against the profile: [score, human-readable reasons, per-factor breakdown].
     *
     * @return array{0: float, 1: list<string>, 2: list<array{label: string, points: float, detail: string}>}
     */
    public function score(MediaItem $item): array
    {
        $reasons = $parts = [];
        $add = function (string $label, float $points, string $detail) use (&$parts) {
            if ($points > 0) {
                $parts[] = ['label' => $label, 'points' => round($points, 2), 'detail' => $detail];
            }
        };

        $genrePoints = $this->genreMatch($item->genres) * 3.0;
        $topGenres = array_values(array_filter($item->genres, fn ($g) => ($this->genres[mb_strtolower($g)] ?? 0) >= 0.4));
        if ($topGenres) {
            $reasons[] = 'genre: '.implode(', ', array_slice($topGenres, 0, 3));
        }
        $add('Genre', $genrePoints, implode(', ', array_slice($item->genres, 0, 3)));

        $dHits = array_values(array_filter($item->directors, fn ($d) => ($this->directors[$d] ?? 0) > 0));
        $dMiss = array_values(array_filter($item->directors, fn ($d) => ($this->directors[$d] ?? 0) < 0));
        $directorPoints = 0.0;
        if ($dHits) {
            $directorPoints = max(array_map(fn ($d) => $this->directors[$d], $dHits)) * 1.5;
            $reasons[] = 'instruktør: '.implode(', ', $dHits);
            $add('Instruktør', $directorPoints, implode(', ', $dHits));
        }
        if ($dMiss) {
            // A director behind something you rated low counts against the title.
            $penalty = min(array_map(fn ($d) => $this->directors[$d], $dMiss)) * 1.5;
            $directorPoints += $penalty;
            $parts[] = ['label' => 'Instruktør', 'points' => round($penalty, 2), 'detail' => implode(', ', $dMiss).' (lav bedømmelse fra dig)'];
        }

        $aHits = array_values(array_filter($item->actors, fn ($a) => ($this->actors[$a] ?? 0) > 0));
        $actorPoints = 0.0;
        if ($aHits) {
            $actorPoints = min(1.5, array_sum(array_map(fn ($a) => $this->actors[$a], $aHits)));
            $reasons[] = 'skuespillere: '.implode(', ', array_slice($aHits, 0, 3));
            $add('Skuespillere', $actorPoints, implode(', ', array_slice($aHits, 0, 3)));
        }

        $decadePoints = 0.0;
        if ($item->year) {
            $decade = intdiv($item->year, 10) * 10 .'s';
            $decadePoints = ($this->decades[$decade] ?? 0.0) * 0.4;
            $add('Årti', $decadePoints, $decade);
        }

        $qualityPoints = 0.0;
        if ($quality = $item->audienceRating ?? $item->rating) {
            $qualityPoints = ($quality / 10) * 0.8;
            $add('Bedømmelse', $qualityPoints, number_format($quality, 1, ',', '').'/10');
        }

        // Always give at least one reason, even when nothing crossed the thresholds above.
        if (! $reasons) {
            $best = $this->bestGenre($item->genres);
            $reasons[] = match (true) {
                $best !== null => 'minder om noget du ser: '.$best,
                $decadePoints > 0 => 'fra et årti du ser meget: '.$decade,
                $qualityPoints > 0 => 'godt bedømt ('.number_format($quality, 1, ',', '').'/10)',
                default => 'ikke set endnu, men intet tydeligt match med din smag',
            };
        }

        $s = $genrePoints + $directorPoints + $actorPoints + $decadePoints + $qualityPoints;

        return [round($s, 3), $reasons, $parts];
    }

    /** The item's genre that ranks highest in the profile, if any matches at all. */
    public function bestGenre(array $genreNames): ?string
    {
        $best = null;
        $bestVal = 0.0;
        foreach ($genreNames as $g) {
            if (($v = $this->genres[mb_strtolower($g)] ?? 0.0) > $bestVal) {
                [$best, $bestVal] = [$g, $v];
            }
        }

        return $best;
    }

    public function summary(int $topN = 10): array
    {
        $top = function (array $d) use ($topN) {
            $d = array_slice(array_filter($d, fn ($v) => $v > 0), 0, $topN, true);

            return array_map(fn ($k, $v) => ['name' => (string) $k, 'weight' => round($v, 2)], array_keys($d), $d);
        };

        return [
            'top_genres' => $top($this->genres),
            'top_directors' => $top($this->directors),
            'top_actors' => $top($this->actors),
            'favorite_decades' => $top($this->decades),
            'most_engaged_titles' => array_map(
                fn ($t) => [...$t[0]->brief(), 'engagement' => round($t[1], 2)],
                array_slice($this->topTitles, 0, $topN),
            ),
        ];
    }
}
