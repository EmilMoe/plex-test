<?php

namespace EmilMoe\PlexRecommender\Auth;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores the Plex connection obtained via "Log ind med Plex" in an encrypted
 * JSON file (default storage/app/plex-recommender.json). Values from .env /
 * config (PLEX_URL + PLEX_TOKEN) take precedence when both are set.
 */
class CredentialStore
{
    protected ?array $data = null;

    public function __construct(
        protected Filesystem $files,
        protected Encrypter $encrypter,
        protected string $path,
        protected ?string $configUrl = null,
        protected ?string $configToken = null,
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
            $this->data = []; // e.g. APP_KEY changed – treat as not connected
        }

        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function put(array $values): void
    {
        $this->data = array_merge($this->all(), $values);
        $this->files->ensureDirectoryExists(dirname($this->path));
        $this->files->put($this->path, $this->encrypter->encryptString(json_encode($this->data)));
    }

    /** Forget the server connection and account token, but keep the client id. */
    public function disconnect(): void
    {
        $clientId = $this->get('client_id');
        $this->data = [];
        $this->files->delete($this->path);
        if ($clientId) {
            $this->put(['client_id' => $clientId]);
        }
    }

    /** Stable per-installation identifier Plex uses to recognise this app. */
    public function clientId(): string
    {
        if (! $id = $this->get('client_id')) {
            $this->put(['client_id' => $id = (string) Str::uuid()]);
        }

        return $id;
    }

    public function usesConfig(): bool
    {
        return (bool) ($this->configUrl && $this->configToken);
    }

    public function url(): ?string
    {
        return $this->usesConfig() ? $this->configUrl : $this->get('server_url');
    }

    public function token(): ?string
    {
        return $this->usesConfig() ? $this->configToken : $this->get('server_token');
    }

    public function connected(): bool
    {
        return (bool) ($this->url() && $this->token());
    }
}
