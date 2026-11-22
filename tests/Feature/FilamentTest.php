<?php

namespace EmilMoe\PlexRecommender\Tests\Feature;

use EmilMoe\PlexRecommender\Filament\Pages\PlexSettings;
use EmilMoe\PlexRecommender\Filament\Pages\Recommendations;
use EmilMoe\PlexRecommender\Filament\Pages\TasteProfile;
use EmilMoe\PlexRecommender\Settings;
use EmilMoe\PlexRecommender\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class FilamentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
    }

    public function test_plugin_registers_pages(): void
    {
        $pages = Filament::getPanel('admin')->getPages();
        $this->assertContains(Recommendations::class, $pages);
        $this->assertContains(TasteProfile::class, $pages);
        $this->assertContains(PlexSettings::class, $pages);
    }

    public function test_recommendations_page_shows_library_and_new_titles(): void
    {
        Livewire::test(Recommendations::class)
            ->call('load')
            ->assertSee('Dune')
            ->assertSee('Instruktør: Denis Villeneuve')
            ->assertSee('Sådan finder vi forslag')
            ->assertSee('Hvorfor?')
            ->assertDontSee('Blade Runner 2049')
            ->set('source', 'new')
            ->assertSee('Ex Machina')
            ->assertSee('Fordi du så')
            ->assertSee('TMDB-match')
            ->set('type', 'show')
            ->set('source', 'library')
            ->assertSee('Dark');
    }

    public function test_recommendations_genre_filter(): void
    {
        Livewire::test(Recommendations::class)
            ->call('load')
            ->set('genre', 'Romance')
            ->assertSee('Love Actually')
            ->assertDontSee('Dune');
    }

    public function test_recommendations_prompts_to_connect_when_not_connected(): void
    {
        config(['plex-recommender.plex.url' => null, 'plex-recommender.plex.token' => null]);
        $this->app->forgetInstance(Settings::class);

        Livewire::test(Recommendations::class)->call('load')->assertSee('Forbind din Plex først');
    }

    public function test_taste_profile_page(): void
    {
        Livewire::test(TasteProfile::class)
            ->assertSee('Yndlingsgenrer')
            ->assertSee('Science fiction')
            ->assertSee('Denis Villeneuve')
            ->assertSee('Severance');
    }

    public function test_settings_page_saves_values_that_override_env(): void
    {
        Livewire::test(PlexSettings::class)
            ->assertSet('data.tmdb_api_key', 'key')       // from config/.env
            ->set('data.tmdb_api_key', 'new-key')
            ->set('data.language', 'en-US')
            ->set('data.cache_minutes', 5)
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified('Indstillingerne er gemt');

        $this->app->forgetInstance(Settings::class);
        $s = app(Settings::class);
        $this->assertSame('new-key', $s->tmdbApiKey());
        $this->assertSame('en-US', $s->language());
        $this->assertSame(300, $s->cacheTtl());
    }

    public function test_settings_page_plex_login_flow(): void
    {
        config(['plex-recommender.plex.url' => null, 'plex-recommender.plex.token' => null]);
        $this->app->forgetInstance(Settings::class);

        Http::fake([
            'plex.tv/api/v2/pins/*' => Http::response(['id' => 7, 'code' => 'xyz', 'authToken' => 'acc']),
            'plex.tv/api/v2/pins' => Http::response(['id' => 7, 'code' => 'xyz']),
            'plex.tv/api/v2/user' => Http::response(['username' => 'emil']),
            'plex.tv/api/v2/resources*' => Http::response([[
                'name' => 'Stuen', 'provides' => 'server', 'clientIdentifier' => 's1', 'owned' => true, 'accessToken' => 'srv',
                'connections' => [['uri' => 'https://10-0-0-2.x.plex.direct:32400', 'local' => true, 'relay' => false]],
            ]]),
            '10-0-0-2.x.plex.direct:32400/*' => Http::response(['MediaContainer' => []]),
        ]);

        Livewire::test(PlexSettings::class)
            ->assertSee('Ikke forbundet')
            ->assertActionVisible(\Filament\Actions\Testing\TestAction::make('plexLogin')->schemaComponent('plexActions', 'form'))
            ->call('startPlexLogin')
            ->assertSet('pin.id', 7)
            ->assertSee('Venter på login')
            ->call('checkPlexLogin')
            ->assertSet('pin', null)
            ->assertNotified('Forbundet til Stuen')
            ->assertSee('Forbundet til Stuen');

        $this->assertSame('srv', app(Settings::class)->token());
        $this->assertSame('emil', app(Settings::class)->get('account_name'));
    }

    public function test_settings_access_can_be_restricted(): void
    {
        \EmilMoe\PlexRecommender\Filament\PlexRecommenderPlugin::get()->canManageSettings(fn () => false);
        $this->assertFalse(PlexSettings::canAccess());
    }

    public function test_poster_route_requires_signature_and_proxies_image(): void
    {
        Http::fake(['plex.test:32400/photo/*' => Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg'])]);

        $this->get('/plex-recommender/poster?path=/library/metadata/1/thumb/1')->assertForbidden();

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('plex-recommender.poster', now()->addHour(), ['path' => '/library/metadata/1/thumb/1']);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/photo/:/transcode') && $r->hasHeader('X-Plex-Token', 'tok'));
    }
}
