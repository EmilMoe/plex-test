<?php

namespace EmilMoe\PlexRecommender;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

/**
 * All runtime settings. Values saved from the UI (Filament settings page,
 * "Log ind med Plex", plex:login) are stored encrypted in a JSON file and take
 * precedence; config/.env values act as defaults.
 */
class Settings
{
    /** Editable settings => config key used as fallback. */
    public const KEYS = [
        'server_url' => 'plex.url',
        'server_token' => 'plex.token',
        'account_id' => 'plex.account_id',
        'verify_ssl' => 'plex.verify_ssl',
        'tmdb_api_key' => 'tmdb.api_key',
        'language' => 'tmdb.language',
        'cache_ttl' => 'cache_ttl',
        'recency_half_life_days' => 'profile.recency_half_life_days',
        'min_votes' => 'recommendations.min_votes',
    ];

    protected ?array $data = null;

    public function __construct(
        protected Filesystem $files,
        protected Encrypter $encrypter,
        protected string $path,
        protected array $config = [],
    ) {}

    public function all(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        try {
            $this->data = $this->files->exists($this->path)
                ? (json_decode($this->encrypter->decryptString($this->files->get($this->path)), true) ?: [])
                : [];
        } catch (Throwable) {
            $this->data = []; // e.g. APP_KEY changed – start over
        }

        return $this->data;
    }

    /** Stored value, falling back to config/.env. Empty strings count as "not set". */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->all()[$key] ?? null;
        if ($value === null || $value === '') {
            $value = isset(self::KEYS[$key]) ? data_get($this->config, self::KEYS[$key]) : null;
        }

        return ($value === null || $value === '') ? $default : $value;
    }

    /** Whether a value comes from the UI store (vs config/.env). */
    public function isStored(string $key): bool
    {
        return ($this->all()[$key] ?? '') !== '' && ($this->all()[$key] ?? null) !== null;
    }

    public function put(array $values): void
    {
        $this->data = array_merge($this->all(), $values);
        $this->files->ensureDirectoryExists(dirname($this->path));
        $this->files->put($this->path, $this->encrypter->encryptString(json_encode($this->data)));
    }

    /** Forget the Plex connection, keep everything else (TMDB key, client id, ...). */
    public function disconnect(): void
    {
        $this->put(array_fill_keys(
            ['server_url', 'server_token', 'server_name', 'server_id', 'account_token', 'account_name', 'connected_at'],
            null,
        ));
    }

    /** Stable per-installation identifier Plex uses to recognise this app. */
    public function clientId(): string
    {
        if (! $id = ($this->all()['client_id'] ?? null)) {
            $this->put(['client_id' => $id = (string) Str::uuid()]);
        }

        return $id;
    }

    // ---- typed accessors -------------------------------------------------

    public function url(): ?string
    {
        return $this->get('server_url');
    }

    public function token(): ?string
    {
        return $this->get('server_token');
    }

    public function connected(): bool
    {
        return (bool) ($this->url() && $this->token());
    }

    public function verifySsl(): bool
    {
        return filter_var($this->get('verify_ssl', true), FILTER_VALIDATE_BOOL);
    }

    public function accountId(): ?string
    {
        return ($id = $this->get('account_id')) ? (string) $id : null;
    }

    public function tmdbApiKey(): ?string
    {
        return $this->get('tmdb_api_key');
    }

    public function language(): string
    {
        return (string) $this->get('language', 'da-DK');
    }

    public function cacheTtl(): int
    {
        return (int) $this->get('cache_ttl', 600);
    }

    public function halfLifeDays(): int
    {
        return max(1, (int) $this->get('recency_half_life_days', 365));
    }

    public function minVotes(): int
    {
        return (int) $this->get('min_votes', 50);
    }
}
