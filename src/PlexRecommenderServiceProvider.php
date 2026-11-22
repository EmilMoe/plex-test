<?php

namespace EmilMoe\PlexRecommender;

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

        $this->app->singleton(PlexClient::class, fn ($app) => new PlexClient(
            $app->make(Http::class),
            config('plex-recommender.plex.url'),
            config('plex-recommender.plex.token'),
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
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/plex-recommender.php' => config_path('plex-recommender.php'),
            ], 'plex-recommender-config');

            $this->publishes([
                __DIR__.'/../stubs/claude/commands' => base_path('.claude/commands'),
            ], 'plex-recommender-claude');

            $this->commands([Console\PlexCommand::class, Console\InstallCommand::class]);
        }

        // Register the stdio MCP server: php artisan mcp:start plex
        if (($handle = config('plex-recommender.mcp_handle')) && class_exists(Mcp::class)) {
            Mcp::local($handle, PlexServer::class);
        }
    }
}
