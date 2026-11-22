<?php

namespace EmilMoe\PlexRecommender\Tests;

use EmilMoe\PlexRecommender\PlexRecommenderServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{

    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Mcp\Server\McpServiceProvider::class,
            \Livewire\LivewireServiceProvider::class,
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
            \Filament\Support\SupportServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            PlexRecommenderServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('plex-recommender.plex.url', 'http://plex.test:32400');
        $app['config']->set('plex-recommender.plex.token', 'tok');
        $app['config']->set('plex-recommender.tmdb.api_key', 'key');
        $app['config']->set('cache.default', 'file');
        $app['config']->set('cache.stores.file.path', sys_get_temp_dir().'/plex-recommender-cache-'.getmypid());
        $app['config']->set('cache.serializable_classes', false);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('plex-recommender.credentials_path', sys_get_temp_dir().'/plex-recommender-test-'.getmypid().'.json');
        $app['config']->set('plex-recommender.routes.middleware', ['web']);
        $app['config']->set('plex-recommender.routes.enabled', true);
    }

    protected function tearDown(): void
    {
        @unlink(sys_get_temp_dir().'/plex-recommender-test-'.getmypid().'.json');
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory(sys_get_temp_dir().'/plex-recommender-cache-'.getmypid());
        Fixtures::$history = null;
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(Fixtures::responses());
    }
}
