<?php

namespace EmilMoe\PlexRecommender;

use EmilMoe\PlexRecommender\Auth\PlexTv;
use EmilMoe\PlexRecommender\Clients\PlexClient;
use EmilMoe\PlexRecommender\Mcp\PlexServer;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

class PlexRecommenderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/plex-recommender.php', 'plex-recommender');

        $this->app->singleton(Settings::class, fn ($app) => new Settings(
            $app->make('files'),
            $app->make('encrypter'),
            config('plex-recommender.credentials_path') ?: storage_path('app/plex-recommender.json'),
            config('plex-recommender', []),
        ));

        $this->app->singleton(PlexTv::class, fn ($app) => new PlexTv($app->make(Http::class), $app->make(Settings::class)));

        $this->app->singleton(PlexClient::class, fn ($app) => new PlexClient(
            $app->make(Http::class),
            $app->make(Settings::class),
            (int) config('plex-recommender.plex.timeout', 30),
        ));

        $this->app->singleton(RecommenderService::class, fn ($app) => new RecommenderService(
            $app->make(PlexClient::class),
            $app->make(Settings::class),
            $app->make('cache')->store(),
            $app->make(Http::class),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'plex-recommender');

        if (config('plex-recommender.routes.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        // Signed poster proxy used by the Filament pages.
        \Illuminate\Support\Facades\Route::get('plex-recommender/poster', \EmilMoe\PlexRecommender\Http\Controllers\PosterController::class)
            ->middleware('signed')
            ->name('plex-recommender.poster');

        if (class_exists(\Filament\Support\Facades\FilamentAsset::class)) {
            \Filament\Support\Facades\FilamentAsset::register([
                \Filament\Support\Assets\Css::make('plex-recommender', __DIR__.'/../resources/dist/plex-recommender.css'),
            ], 'emilmoe/plex-recommender');
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
