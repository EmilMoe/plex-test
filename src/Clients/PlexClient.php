<?php

namespace EmilMoe\PlexRecommender\Clients;

use EmilMoe\PlexRecommender\Data\MediaItem;
use Generator;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

class PlexClient
{
    private const TYPE_CODES = [MediaItem::MOVIE => 1, MediaItem::SHOW => 2];

    public function __construct(
        protected Http $http,
        protected ?string $url,
        protected ?string $token,
        protected bool $verifySsl = true,
        protected int $timeout = 30,
    ) {}

    protected function request(): PendingRequest
    {
        if (! $this->url || ! $this->token) {
            throw new RuntimeException(
                'PLEX_URL og PLEX_TOKEN skal være sat i .env. Se README for hvordan du finder dit Plex-token.'
            );
        }

        return $this->http
            ->baseUrl(rtrim($this->url, '/'))
            ->acceptJson()
            ->timeout($this->timeout)
            ->withOptions(['verify' => $this->verifySsl])
            ->withHeaders([
                'X-Plex-Token' => $this->token,
                'X-Plex-Client-Identifier' => 'laravel-plex-recommender',
                'X-Plex-Product' => 'laravel-plex-recommender',
            ]);
    }

    protected function get(string $path, array $query = [], array $headers = []): array
    {
        return $this->request()->withHeaders($headers)->get($path, $query)->throw()->json('MediaContainer') ?? [];
    }

    /** @return list<array{key: string, title: string, type: string}> */
    public function libraries(): array
    {
        return array_map(fn ($d) => [
            'key' => (string) $d['key'],
            'title' => (string) ($d['title'] ?? ''),
            'type' => (string) ($d['type'] ?? ''),
        ], $this->get('/library/sections')['Directory'] ?? []);
    }

    /** @return list<MediaItem> All movies or shows across every library section of that type. */
    public function libraryItems(string $type): array
    {
        $items = [];
        foreach ($this->libraries() as $lib) {
            if ($lib['type'] !== $type) {
                continue;
            }
            $pages = $this->paged("/library/sections/{$lib['key']}/all", [
                'type' => self::TYPE_CODES[$type],
                'includeGuids' => 1,
            ]);
            foreach ($pages as $meta) {
                $items[] = MediaItem::fromPlex($meta, $lib['title']);
            }
        }

        return $items;
    }

    /** Watch history, newest first. $since is a unix timestamp. */
    public function history(int $limit = 100, ?int $since = null, ?string $accountId = null): array
    {
        $query = ['sort' => 'viewedAt:desc'];
        if ($since) {
            $query['viewedAt>'] = $since;
        }
        if ($accountId) {
            $query['accountID'] = $accountId;
        }
        $out = [];
        foreach ($this->paged('/status/sessions/history/all', $query, 200) as $entry) {
            $out[] = $entry;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    protected function paged(string $path, array $query, int $pageSize = 500): Generator
    {
        $start = 0;
        while (true) {
            $container = $this->get($path, $query, [
                'X-Plex-Container-Start' => (string) $start,
                'X-Plex-Container-Size' => (string) $pageSize,
            ]);
            $batch = $container['Metadata'] ?? [];
            yield from $batch;
            $total = (int) ($container['totalSize'] ?? $container['size'] ?? count($batch));
            $start += count($batch);
            if (! $batch || $start >= $total) {
                return;
            }
        }
    }
}
