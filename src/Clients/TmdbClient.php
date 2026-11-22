<?php

namespace EmilMoe\PlexRecommender\Clients;

use EmilMoe\PlexRecommender\Data\MediaItem;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;

class TmdbClient
{
    protected array $genreCache = [];

    public function __construct(
        protected Http $http,
        protected string $apiKey,
        protected string $language = 'da-DK',
        protected string $baseUrl = 'https://api.themoviedb.org/3',
    ) {}

    public static function kind(string $type): string
    {
        return $type === MediaItem::MOVIE ? 'movie' : 'tv';
    }

    protected function get(string $path, array $query = []): array
    {
        $request = $this->http->baseUrl($this->baseUrl)->acceptJson()->timeout(20);
        // v4 "API Read Access Token" is a JWT; v3 key is a short hex string.
        if (str_starts_with($this->apiKey, 'eyJ')) {
            $request = $request->withToken($this->apiKey);
        } else {
            $query['api_key'] = $this->apiKey;
        }

        return $request->get($path, $query)->throw()->json() ?? [];
    }

    public function recommendations(string $type, string $tmdbId, int $pages = 1): array
    {
        $out = [];
        for ($page = 1; $page <= $pages; $page++) {
            $data = $this->get('/'.self::kind($type)."/{$tmdbId}/recommendations", [
                'language' => $this->language,
                'page' => $page,
            ]);
            array_push($out, ...($data['results'] ?? []));
            if ($page >= ($data['total_pages'] ?? 1)) {
                break;
            }
        }

        return $out;
    }

    /**
     * Best match for a title (optionally with year), or null. Tries the year
     * first, then without it, since years often differ by one between sources.
     */
    public function search(string $type, string $title, ?int $year = null): ?array
    {
        $yearParam = $type === MediaItem::MOVIE ? 'year' : 'first_air_date_year';
        foreach ($year ? [$year, null] : [null] as $y) {
            $query = ['query' => $title, 'language' => $this->language, 'include_adult' => 'false'];
            if ($y) {
                $query[$yearParam] = $y;
            }
            $results = $this->get('/search/'.self::kind($type), $query)['results'] ?? [];
            if ($results) {
                return $results[0];
            }
        }

        return null;
    }

    /** Resolve a TMDB id from an IMDb or TVDB id (for items lacking a tmdb guid). */
    public function findTmdbId(string $type, ?string $imdbId, ?string $tvdbId): ?string
    {
        $key = $type === MediaItem::MOVIE ? 'movie_results' : 'tv_results';
        foreach (['imdb_id' => $imdbId, 'tvdb_id' => $tvdbId] as $source => $id) {
            if (! $id) {
                continue;
            }
            $data = $this->get("/find/{$id}", ['external_source' => $source]);
            if (! empty($data[$key])) {
                return (string) $data[$key][0]['id'];
            }
        }

        return null;
    }

    /**
     * Genre id => names in both English and the configured language. Plex genre tags
     * can be either, and TV genres like "Sci-Fi & Fantasy" are split into parts.
     *
     * @return array<int, list<string>>
     */
    public function genreNames(string $type): array
    {
        if (isset($this->genreCache[$type])) {
            return $this->genreCache[$type];
        }
        $names = [];
        foreach (array_unique(['en-US', $this->language]) as $lang) {
            foreach ($this->get('/genre/'.self::kind($type).'/list', ['language' => $lang])['genres'] ?? [] as $g) {
                $parts = [$g['name'], ...array_map('trim', explode('&', $g['name']))];
                if (str_contains($g['name'], 'Sci-Fi')) {
                    $parts[] = 'Science Fiction';
                }
                $names[$g['id']] = [...($names[$g['id']] ?? []), ...$parts];
            }
        }

        return $this->genreCache[$type] = $names;
    }
}
