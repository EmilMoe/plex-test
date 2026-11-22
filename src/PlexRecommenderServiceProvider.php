<?php

namespace EmilMoe\PlexRecommender;

use EmilMoe\PlexRecommender\Auth\CredentialStore;
use EmilMoe\PlexRecommender\Auth\PlexTv;
use EmilMoe\PlexRecommender\Clients\PlexClient;
use EmilMoe\PlexRecommender\Clients\TmdbClient;
use EmilMoe\PlexRecommender\Mcp\PlexServer;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

class PlexRecommenderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/plex-recommender.php', 'plex-recommender');

        $this->app->singleton(CredentialStore::class, fn ($app) => new CredentialStore(
            $app->make('files'),
            $app->make('encrypter'),
            config('plex-recommender.credentials_path') ?: storage_path('app/plex-recommender.json'),
            config('plex-recommender.plex.url') ?: null,
            config('plex-recommender.plex.token') ?: null,
        ));

        $this->app->singleton(PlexTv::class, fn ($app) => new PlexTv($app->make(Http::class), $app->make(CredentialStore::class)));

        $this->app->singleton(PlexClient::class, fn ($app) => new PlexClient(
            $app->make(Http::class),
            $app->make(CredentialStore::class),
            filter_var(config('plex-recommender.plex.verify_ssl', true), FILTER_VALIDATE_BOOL),
            (int) config('plex-recommender.plex.timeout', 30),
        ));

        $this->app->singleton(TmdbClient::class, fn ($app) => config('plex-recommender.tmdb.api_key')
            ? new TmdbClient(
                $app->make(Http::class),
                config('plex-recommender.tmdb.api_key'),
                config('plex-recommender.tmdb.language', 'da-DK'),
            )
            : null);

        $this->app->singleton(RecommenderService::class, fn ($app) => new RecommenderService(
            $app->make(PlexClient::class),
            config('plex-recommender.tmdb.api_key') ? $app->make(TmdbClient::class) : null,
            $app->make('cache')->store(),
            (int) config('plex-recommender.cache_ttl', 600),
            config('plex-recommender.plex.account_id') ?: null,
            (int) config('plex-recommender.profile.recency_half_life_days', 365),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'plex-recommender');

        if (config('plex-recommender.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/plex-recommender.php' => config_path('plex-recommender.php'),
            ], 'plex-recommender-config');

            $this->publishes([
                __DIR__.'/../stubs/claude/commands' => base_path('.claude/commands'),
            ], 'plex-recommender-claude');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/plex-recommender'),
            ], 'plex-recommender-views');

            $this->commands([
                Console\PlexCommand::class,
                Console\InstallCommand::class,
                Console\LoginCommand::class,
                Console\LogoutCommand::class,
            ]);
        }

        // Register the stdio MCP server: php artisan mcp:start plex
        if (($handle = config('plex-recommender.mcp_handle')) && class_exists(Mcp::class)) {
            Mcp::local($handle, PlexServer::class);
        }
    }
}
