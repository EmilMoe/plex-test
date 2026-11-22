<?php

namespace EmilMoe\PlexRecommender\Clients;

use EmilMoe\PlexRecommender\Data\MediaItem;
use EmilMoe\PlexRecommender\Settings;
use Generator;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

class PlexClient
{
    private const TYPE_CODES = [MediaItem::MOVIE => 1, MediaItem::SHOW => 2];

    public function __construct(
        protected Http $http,
        protected Settings $settings,
        protected int $timeout = 30,
    ) {}

    protected function request(): PendingRequest
    {
        if (! $this->settings->connected()) {
            throw new RuntimeException(
                'Ikke forbundet til Plex. Åbn Plex-indstillingerne og tryk "Log ind med Plex", eller kør: php artisan plex:login'
            );
        }

        return $this->http
            ->baseUrl(rtrim($this->settings->url(), '/'))
            ->acceptJson()
            ->timeout($this->timeout)
            ->withOptions(['verify' => $this->settings->verifySsl()])
            ->withHeaders([
                'X-Plex-Token' => $this->settings->token(),
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

    /** Plex users on the server (for limiting history to one person). */
    public function accounts(): array
    {
        $out = [];
        foreach ($this->get('/accounts')['Account'] ?? [] as $a) {
            if ((int) ($a['id'] ?? 0) > 0 && ($a['name'] ?? '') !== '') {
                $out[(string) $a['id']] = (string) $a['name'];
            }
        }

        return $out;
    }

    /** Raw image bytes + content type for a Plex image path such as /library/metadata/1/thumb/123. */
    public function image(string $path, int $width = 300, int $height = 450): array
    {
        $response = $this->request()->withHeaders(['Accept' => 'image/*'])->get('/photo/:/transcode', [
            'url' => $path, 'width' => $width, 'height' => $height, 'minSize' => 1, 'upscale' => 1,
        ])->throw();

        return [$response->body(), $response->header('Content-Type') ?: 'image/jpeg'];
    }

    /** Check the connection; returns the server's friendly name. */
    public function identity(): string
    {
        $c = $this->get('/');

        return (string) ($c['friendlyName'] ?? 'Plex');
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
