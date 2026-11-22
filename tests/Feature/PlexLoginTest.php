<?php

namespace EmilMoe\PlexRecommender\Tests\Feature;

use EmilMoe\PlexRecommender\Auth\CredentialStore;
use EmilMoe\PlexRecommender\Clients\PlexClient;
use EmilMoe\PlexRecommender\RecommenderService;
use EmilMoe\PlexRecommender\Tests\Fixtures;
use EmilMoe\PlexRecommender\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class PlexLoginTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // No manual token – rely on "Log ind med Plex".
        $app['config']->set('plex-recommender.plex.url', null);
        $app['config']->set('plex-recommender.plex.token', null);
    }

    protected function fakePlexTv(?string $authToken = 'account-token', int $servers = 1): void
    {
        $resources = [
            ['name' => 'Stuen', 'provides' => 'server', 'clientIdentifier' => 'srv1', 'owned' => true, 'accessToken' => 'server-token',
                'connections' => [
                    ['uri' => 'https://1-2-3-4.abc.plex.direct:32400', 'local' => false, 'relay' => false],
                    ['uri' => 'https://192-168-1-10.abc.plex.direct:32400', 'local' => true, 'relay' => false],
                ]],
            ['name' => 'Vens server', 'provides' => 'server', 'clientIdentifier' => 'srv2', 'owned' => false, 'accessToken' => 'friend-token',
                'connections' => [['uri' => 'https://5-6-7-8.def.plex.direct:32400', 'local' => false, 'relay' => false]]],
            ['name' => 'iPhone', 'provides' => 'client,player', 'clientIdentifier' => 'phone', 'connections' => []],
        ];
        Http::fake([
            'plex.tv/api/v2/pins/*' => Http::response(['id' => 42, 'code' => 'abcd', 'authToken' => $authToken]),
            'plex.tv/api/v2/pins' => Http::response(['id' => 42, 'code' => 'abcd']),
            'plex.tv/api/v2/user' => Http::response(['username' => 'emil']),
            'plex.tv/api/v2/resources*' => Http::response(array_slice($resources, 0, $servers === 1 ? 1 : 3)),
            '192-168-1-10.abc.plex.direct:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'srv1']]),
            '1-2-3-4.abc.plex.direct:32400/*' => Http::response('', 500),
            '5-6-7-8.def.plex.direct:32400/identity' => Http::response(['MediaContainer' => []]),
            '192-168-1-10.abc.plex.direct:32400/library/sections/1/all*' => Http::response(['MediaContainer' => ['Metadata' => Fixtures::movies(), 'totalSize' => 6]]),
            '192-168-1-10.abc.plex.direct:32400/library/sections/2/all*' => Http::response(['MediaContainer' => ['Metadata' => [], 'totalSize' => 0]]),
            '192-168-1-10.abc.plex.direct:32400/library/sections*' => Http::response(['MediaContainer' => ['Directory' => [
                ['key' => '1', 'title' => 'Film', 'type' => 'movie'], ['key' => '2', 'title' => 'Serier', 'type' => 'show'],
            ]]]),
        ]);
    }

    public function test_not_connected_gives_helpful_error(): void
    {
        $this->expectExceptionMessage('Log ind med Plex');
        app(RecommenderService::class)->items('movie');
    }

    public function test_page_shows_login_button(): void
    {
        $this->get('/plex')->assertOk()->assertSee('Log ind med Plex');
    }

    public function test_connect_redirects_to_plex(): void
    {
        $this->fakePlexTv();
        $res = $this->post('/plex/connect');
        $res->assertRedirect();
        $this->assertStringStartsWith('https://app.plex.tv/auth#?', $res->headers->get('Location'));
        $this->assertStringContainsString('code=abcd', $res->headers->get('Location'));
        $this->assertStringContainsString('forwardUrl='.rawurlencode('http://localhost/plex/callback'), $res->headers->get('Location'));
        $res->assertSessionHas('plex.pin', ['id' => 42, 'code' => 'abcd']);
    }

    public function test_callback_with_single_server_connects_via_local_address(): void
    {
        $this->fakePlexTv();
        $this->withSession(['plex.pin' => ['id' => 42, 'code' => 'abcd']])
            ->get('/plex/callback')
            ->assertRedirect('/plex')
            ->assertSessionHas('status', 'Forbundet til Stuen.');

        $store = app(CredentialStore::class);
        $this->assertSame('https://192-168-1-10.abc.plex.direct:32400', $store->url());
        $this->assertSame('server-token', $store->token());

        // The library is now read through the stored connection.
        $this->assertCount(6, app(RecommenderService::class)->items('movie'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '192-168-1-10') && $r->hasHeader('X-Plex-Token', 'server-token'));

        $this->get('/plex')->assertSee('Stuen')->assertSee('Afbryd forbindelsen');
    }

    public function test_multiple_servers_lets_user_choose(): void
    {
        $this->fakePlexTv(servers: 2);
        $this->withSession(['plex.pin' => ['id' => 42, 'code' => 'abcd']])->get('/plex/callback')->assertRedirect('/plex');
        $this->get('/plex')->assertSee('Stuen')->assertSee('Vens server')->assertDontSee('iPhone');

        $this->post('/plex/select', ['server' => 'srv2'])->assertSessionHas('status', 'Forbundet til Vens server.');
        $this->assertSame('friend-token', app(CredentialStore::class)->token());
    }

    public function test_callback_waits_while_pin_not_claimed(): void
    {
        $this->fakePlexTv(authToken: null);
        $this->withSession(['plex.pin' => ['id' => 42, 'code' => 'abcd']])
            ->get('/plex/callback')->assertOk()->assertSee('Venter på Plex');
    }

    public function test_store_is_encrypted_and_disconnect_keeps_client_id(): void
    {
        $this->fakePlexTv();
        $this->withSession(['plex.pin' => ['id' => 42, 'code' => 'abcd']])->get('/plex/callback');
        $raw = file_get_contents(config('plex-recommender.credentials_path'));
        $this->assertStringNotContainsString('server-token', $raw);

        $clientId = app(CredentialStore::class)->clientId();
        $this->post('/plex/disconnect')->assertRedirect('/plex');
        $this->app->forgetInstance(CredentialStore::class);
        $this->app->forgetInstance(PlexClient::class);
        $store = app(CredentialStore::class);
        $this->assertFalse($store->connected());
        $this->assertSame($clientId, $store->clientId());
    }

    public function test_env_token_wins_over_login(): void
    {
        config(['plex-recommender.plex.url' => 'http://plex.test:32400', 'plex-recommender.plex.token' => 'tok']);
        $this->app->forgetInstance(CredentialStore::class);
        $this->assertTrue(app(CredentialStore::class)->usesConfig());
        $this->assertSame('tok', app(CredentialStore::class)->token());
    }

    public function test_artisan_login(): void
    {
        $this->fakePlexTv();
        $this->artisan('plex:login --timeout=10')
            ->expectsOutputToContain('app.plex.tv/auth')
            ->expectsOutputToContain('Forbundet til Stuen')
            ->assertSuccessful();
        $this->assertSame('server-token', app(CredentialStore::class)->token());
    }
}
