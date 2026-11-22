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
     * - Shows: more episodes and higher completion count more; a show dropped
     *   after two episodes is a weak signal.
     * - Your own star rating scales the weight.
     * - Recent viewing counts more than viewing from years ago.
     */
    public static function engagementWeight(MediaItem $item, ?int $now = null, int $halfLifeDays = 365): float
    {
        if (! $item->watched()) {
            return 0.0;
        }
        $w = $item->type === MediaItem::SHOW
            ? log(1 + $item->viewedLeafCount, 2) * (0.5 + $item->completion())
            : log(1 + $item->viewCount, 2) * 2.0;

        if ($item->userRating !== null) {
            $w *= 0.25 + 1.5 * ($item->userRating / 10);
        }
        if ($item->lastViewedAt) {
            $ageDays = max(0, (($now ?? time()) - $item->lastViewedAt) / 86400);
            $w *= 0.35 + 0.65 * 0.5 ** ($ageDays / $halfLifeDays);
        }

        return $w;
    }

    /** @param  iterable<MediaItem>  $items */
    public static function build(iterable $items, ?int $now = null, int $halfLifeDays = 365): self
    {
        $genres = $directors = $actors = $decades = $weighted = [];

        foreach ($items as $item) {
            $w = self::engagementWeight($item, $now, $halfLifeDays);
            if ($w <= 0) {
                continue;
            }
            $weighted[] = [$item, $w];
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
        $top = max($d);

        return array_map(fn ($v) => $v / $top, $d);
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

    /** @return array{0: float, 1: list<string>} score and human-readable reasons */
    public function score(MediaItem $item): array
    {
        $reasons = [];
        $s = $this->genreMatch($item->genres) * 3.0;

        $topGenres = array_values(array_filter($item->genres, fn ($g) => ($this->genres[mb_strtolower($g)] ?? 0) >= 0.4));
        if ($topGenres) {
            $reasons[] = 'genre: '.implode(', ', array_slice($topGenres, 0, 3));
        }

        $dHits = array_values(array_filter($item->directors, fn ($d) => isset($this->directors[$d])));
        if ($dHits) {
            $s += max(array_map(fn ($d) => $this->directors[$d], $dHits)) * 1.5;
            $reasons[] = 'instruktør: '.implode(', ', $dHits);
        }

        $aHits = array_values(array_filter($item->actors, fn ($a) => isset($this->actors[$a])));
        if ($aHits) {
            $s += min(1.5, array_sum(array_map(fn ($a) => $this->actors[$a], $aHits)));
            $reasons[] = 'skuespillere: '.implode(', ', array_slice($aHits, 0, 3));
        }

        if ($item->year) {
            $s += ($this->decades[intdiv($item->year, 10) * 10 .'s'] ?? 0.0) * 0.4;
        }

        if ($quality = $item->audienceRating ?? $item->rating) {
            $s += ($quality / 10) * 0.8;
        }

        return [round($s, 3), $reasons];
    }

    public function summary(int $topN = 10): array
    {
        $top = fn (array $d) => array_map(
            fn ($k, $v) => ['name' => (string) $k, 'weight' => round($v, 2)],
            array_keys(array_slice($d, 0, $topN, true)),
            array_slice($d, 0, $topN, true),
        );

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
