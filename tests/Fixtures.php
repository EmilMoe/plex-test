<?php

namespace EmilMoe\PlexRecommender\Tests;

use Illuminate\Support\Facades\Http;

class Fixtures
{
    /** Override the Plex watch history returned by the fake server (null = default). */
    public static ?array $history = null;

    public static function movie(int $key, string $title, int $year, array $genres, int $views = 0, array $directors = [], array $actors = [], ?int $tmdb = null, ?float $rating = null): array
    {
        return array_filter([
            'ratingKey' => (string) $key, 'title' => $title, 'year' => $year, 'type' => 'movie',
            'Genre' => array_map(fn ($g) => ['tag' => $g], $genres),
            'Director' => array_map(fn ($d) => ['tag' => $d], $directors),
            'Role' => array_map(fn ($a) => ['tag' => $a], $actors),
            'viewCount' => $views, 'lastViewedAt' => $views ? time() - 86400 : null,
            'audienceRating' => $rating,
            'Guid' => $tmdb ? [['id' => "tmdb://{$tmdb}"], ['id' => 'imdb://tt0000001']] : null,
        ], fn ($v) => $v !== null);
    }

    public static function show(int $key, string $title, int $year, array $genres, int $watched, int $total, ?int $tvdb = null): array
    {
        return [
            'ratingKey' => (string) $key, 'title' => $title, 'year' => $year, 'type' => 'show',
            'Genre' => array_map(fn ($g) => ['tag' => $g], $genres),
            'leafCount' => $total, 'viewedLeafCount' => $watched,
            'lastViewedAt' => $watched ? time() - 3600 : null,
            'guid' => $tvdb ? "com.plexapp.agents.thetvdb://{$tvdb}?lang=en" : '',
        ];
    }

    public static function movies(): array
    {
        return [
            self::movie(1, 'Blade Runner 2049', 2017, ['Science Fiction', 'Drama'], 3, ['Denis Villeneuve'], ['Ryan Gosling'], 335984),
            self::movie(2, 'Arrival', 2016, ['Science Fiction', 'Drama'], 2, ['Denis Villeneuve'], [], 329865),
            self::movie(3, 'Notting Hill', 1999, ['Romance', 'Comedy'], 1),
            self::movie(4, 'Dune', 2021, ['Science Fiction', 'Adventure'], 0, ['Denis Villeneuve'], [], 438631, 8.0),
            self::movie(5, 'Love Actually', 2003, ['Romance', 'Comedy'], 0, [], [], null, 7.0),
            self::movie(6, 'Drive', 2011, ['Drama', 'Thriller'], 0, [], ['Ryan Gosling']),
        ];
    }

    public static function shows(): array
    {
        return [
            self::show(10, 'Severance', 2022, ['Science Fiction', 'Drama'], 19, 19, 371980),
            self::show(11, 'Friends', 1994, ['Comedy'], 2, 236),
            self::show(12, 'Dark', 2017, ['Science Fiction', 'Mystery'], 0, 26),
        ];
    }

    public static function responses(): array
    {
        $mc = fn (array $c) => Http::response(['MediaContainer' => $c]);
        $now = time();

        return [
            'plex.test:32400/library/sections/1/all*' => $mc(['Metadata' => self::movies(), 'totalSize' => 6]),
            'plex.test:32400/library/sections/2/all*' => $mc(['Metadata' => self::shows(), 'totalSize' => 3]),
            'plex.test:32400/library/sections*' => $mc(['Directory' => [
                ['key' => '1', 'title' => 'Film', 'type' => 'movie'],
                ['key' => '2', 'title' => 'Serier', 'type' => 'show'],
            ]]),
            'plex.test:32400/status/sessions/history/all*' => fn () => $mc(['totalSize' => count($h = self::$history ?? [
                ['type' => 'episode', 'grandparentRatingKey' => '10', 'grandparentTitle' => 'Severance',
                    'parentIndex' => 2, 'index' => 10, 'title' => 'Cold Harbor', 'viewedAt' => $now - 100],
                ['type' => 'episode', 'grandparentRatingKey' => '10', 'viewedAt' => $now - 200],
                ['type' => 'episode', 'grandparentRatingKey' => '11', 'viewedAt' => $now - 300],
                ['type' => 'movie', 'ratingKey' => '1', 'title' => 'Blade Runner 2049', 'year' => 2017, 'viewedAt' => $now - 400],
            ]), 'Metadata' => $h]),
            'api.themoviedb.org/3/genre/*' => Http::response(['genres' => [
                ['id' => 878, 'name' => 'Science Fiction'], ['id' => 18, 'name' => 'Drama'],
                ['id' => 10749, 'name' => 'Romance'], ['id' => 35, 'name' => 'Comedy'],
            ]]),
            'api.themoviedb.org/3/movie/*/recommendations*' => Http::response(['total_pages' => 1, 'results' => [
                ['id' => 438631, 'title' => 'Dune', 'release_date' => '2021-09-15', 'vote_count' => 9000, 'vote_average' => 7.8, 'genre_ids' => [878]],
                ['id' => 1, 'title' => 'Sicario', 'release_date' => '2015-09-17', 'vote_count' => 8000, 'vote_average' => 7.4, 'genre_ids' => [18]],
                ['id' => 2, 'title' => 'Ex Machina', 'release_date' => '2015-01-21', 'vote_count' => 12000, 'vote_average' => 7.6, 'genre_ids' => [878, 18]],
                ['id' => 3, 'title' => 'Obscure Thing', 'release_date' => '2020-01-01', 'vote_count' => 3, 'vote_average' => 9.0, 'genre_ids' => [878]],
                ['id' => 4, 'title' => 'Rom-com Deluxe', 'release_date' => '2019-01-01', 'vote_count' => 500, 'vote_average' => 6.0, 'genre_ids' => [10749, 35]],
            ]]),
            'api.themoviedb.org/3/find/*' => Http::response(['movie_results' => [], 'tv_results' => []]),
            'api.themoviedb.org/3/tv/*/recommendations*' => Http::response(['total_pages' => 1, 'results' => []]),
        ];
    }
}
