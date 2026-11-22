<?php

namespace EmilMoe\PlexRecommender\Auth;

use EmilMoe\PlexRecommender\Settings;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

/**
 * plex.tv PIN-based sign-in (the same flow Overseerr/Jellyseerr use) and
 * discovery of the servers the account has access to.
 */
class PlexTv
{
    public const PRODUCT = 'Plex Recommender';

    public function __construct(protected Http $http, protected Settings $store) {}

    protected function request(?string $token = null): PendingRequest
    {
        $headers = [
            'X-Plex-Product' => self::PRODUCT,
            'X-Plex-Client-Identifier' => $this->store->clientId(),
            'X-Plex-Device-Name' => config('app.name', 'Laravel'),
            'X-Plex-Platform' => 'Web',
        ];
        if ($token) {
            $headers['X-Plex-Token'] = $token;
        }

        return $this->http->baseUrl('https://plex.tv/api/v2')->acceptJson()->timeout(15)->withHeaders($headers);
    }

    /** @return array{id: int, code: string} */
    public function createPin(): array
    {
        $pin = $this->request()->asForm()->post('/pins', ['strong' => 'true'])->throw()->json();

        return ['id' => (int) $pin['id'], 'code' => (string) $pin['code']];
    }

    /** URL to send the user to; Plex redirects back to $forwardUrl after sign-in. */
    public function authUrl(string $code, ?string $forwardUrl = null): string
    {
        $params = array_filter([
            'clientID' => $this->store->clientId(),
            'code' => $code,
            'forwardUrl' => $forwardUrl,
            'context[device][product]' => self::PRODUCT,
        ]);

        return 'https://app.plex.tv/auth#?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** Returns the account token once the PIN has been claimed, otherwise null. */
    public function checkPin(int $id, string $code): ?string
    {
        return $this->request()->get("/pins/{$id}", ['code' => $code])->throw()->json('authToken') ?: null;
    }

    public function user(string $accountToken): array
    {
        $u = $this->request($accountToken)->get('/user')->throw()->json();

        return ['username' => $u['username'] ?? $u['title'] ?? null, 'email' => $u['email'] ?? null];
    }

    /**
     * Plex Media Servers available to the account.
     *
     * @return list<array{id: string, name: string, owned: bool, token: string, connections: list<array>}>
     */
    public function servers(string $accountToken): array
    {
        $resources = $this->request($accountToken)
            ->get('/resources', ['includeHttps' => 1, 'includeRelay' => 1])
            ->throw()->json() ?? [];

        $servers = [];
        foreach ($resources as $r) {
            if (! str_contains((string) ($r['provides'] ?? ''), 'server')) {
                continue;
            }
            $servers[] = [
                'id' => (string) $r['clientIdentifier'],
                'name' => (string) ($r['name'] ?? 'Plex'),
                'owned' => (bool) ($r['owned'] ?? false),
                'token' => (string) ($r['accessToken'] ?? $accountToken),
                'connections' => array_map(fn ($c) => [
                    'uri' => rtrim((string) $c['uri'], '/'),
                    'local' => (bool) ($c['local'] ?? false),
                    'relay' => (bool) ($c['relay'] ?? false),
                ], $r['connections'] ?? []),
            ];
        }
        usort($servers, fn ($a, $b) => $b['owned'] <=> $a['owned']);

        return $servers;
    }

    /**
     * Find the first connection that answers from this machine:
     * local first, then remote, relay last.
     */
    public function bestConnection(array $server): ?string
    {
        $connections = $server['connections'];
        usort($connections, fn ($a, $b) => [$a['relay'], ! $a['local']] <=> [$b['relay'], ! $b['local']]);

        foreach ($connections as $c) {
            try {
                $ok = $this->http->timeout(4)->acceptJson()
                    ->withHeaders(['X-Plex-Token' => $server['token']])
                    ->get($c['uri'].'/identity')
                    ->successful();
                if ($ok) {
                    return $c['uri'];
                }
            } catch (Throwable) {
                // try next
            }
        }

        return null;
    }

    /** Pick connection for $server and save it. Returns the URL or null if unreachable. */
    public function connect(array $server, string $accountToken): ?string
    {
        if (! $url = $this->bestConnection($server)) {
            return null;
        }
        $this->store->put([
            'server_url' => $url,
            'server_token' => $server['token'],
            'server_name' => $server['name'],
            'server_id' => $server['id'],
            'account_token' => $accountToken,
            'account_name' => rescue(fn () => $this->user($accountToken)['username'], null, false),
            'connected_at' => now()->toIso8601String(),
        ]);

        return $url;
    }
}
