<?php

namespace EmilMoe\PlexRecommender\Tests;

use EmilMoe\PlexRecommender\PlexRecommenderServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        $providers = [PlexRecommenderServiceProvider::class];
        if (class_exists(\Laravel\Mcp\Server\McpServiceProvider::class)) {
            array_unshift($providers, \Laravel\Mcp\Server\McpServiceProvider::class);
        }

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('plex-recommender.plex.url', 'http://plex.test:32400');
        $app['config']->set('plex-recommender.plex.token', 'tok');
        $app['config']->set('plex-recommender.tmdb.api_key', 'key');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('plex-recommender.credentials_path', sys_get_temp_dir().'/plex-recommender-test-'.getmypid().'.json');
        $app['config']->set('plex-recommender.routes.middleware', ['web']);
    }

    protected function tearDown(): void
    {
        @unlink(sys_get_temp_dir().'/plex-recommender-test-'.getmypid().'.json');
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(Fixtures::responses());
    }
}
