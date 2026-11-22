<?php

namespace EmilMoe\PlexRecommender\Tests\Feature;

use EmilMoe\PlexRecommender\Data\MediaItem;
use EmilMoe\PlexRecommender\Facades\PlexRecommender;
use EmilMoe\PlexRecommender\RecommenderService;
use EmilMoe\PlexRecommender\Tests\Fixtures;
use EmilMoe\PlexRecommender\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class RecommenderTest extends TestCase
{
    protected function svc(): RecommenderService
    {
        return app(RecommenderService::class);
    }

    public function test_parses_guids_and_titles(): void
    {
        $m = MediaItem::fromPlex(Fixtures::movies()[0]);
        $this->assertSame('335984', $m->tmdbId);
        $this->assertSame('tt0000001', $m->imdbId);
        $this->assertSame('371980', MediaItem::fromPlex(Fixtures::shows()[0])->tvdbId);
        $this->assertSame('lord of the rings the two towers', MediaItem::normalizeTitle('The Lord of the Rings: The Two Towers'));
        $this->assertSame('amelie', MediaItem::normalizeTitle('Amélie'));
    }

    public function test_sends_plex_token(): void
    {
        $this->svc()->items('movie');
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Plex-Token', 'tok'));
    }

    public function test_profile_prefers_sci_fi(): void
    {
        $p = PlexRecommender::tasteProfile();
        $this->assertSame('science fiction', $p['top_genres'][0]['name']);
        $this->assertSame('Denis Villeneuve', $p['top_directors'][0]['name']);
        $titles = array_column($p['most_engaged_titles'], 'title');
        $this->assertLessThan(array_search('Friends', $titles), array_search('Severance', $titles));
    }

    public function test_unwatched_ranking(): void
    {
        $recs = $this->svc()->unwatchedInLibrary('movie');
        $titles = array_column($recs, 'title');
        $this->assertSame('Dune', $titles[0]);
        $this->assertNotContains('Blade Runner 2049', $titles);
        $this->assertStringContainsString('Denis Villeneuve', implode(' ', $recs[0]['why']));

        $this->assertSame(['Dark'], array_column($this->svc()->unwatchedInLibrary('serier'), 'title'));
        $this->assertContains('Friends', array_column($this->svc()->unwatchedInLibrary('show', 20, null, true), 'title'));
        $this->assertSame(['Love Actually'], array_column($this->svc()->unwatchedInLibrary('movie', 20, 'romance'), 'title'));
    }

    public function test_recommend_new_filters_owned_and_low_votes(): void
    {
        $out = $this->svc()->recommendNew('movie');
        $titles = array_column($out['recommendations'], 'title');
        $this->assertNotContains('Dune', $titles);
        $this->assertNotContains('Obscure Thing', $titles);
        $this->assertSame('Ex Machina', $titles[0]);
        $this->assertLessThan(array_search('Rom-com Deluxe', $titles), array_search('Ex Machina', $titles));
        $this->assertNotEmpty($out['recommendations'][0]['because_you_watched']);
    }

    public function test_recommend_new_without_tmdb(): void
    {
        config(['plex-recommender.tmdb.api_key' => null]);
        $this->app->forgetInstance(RecommenderService::class);
        $this->assertArrayHasKey('error', $this->svc()->recommendNew('movie'));
    }

    public function test_check_owned(): void
    {
        $res = collect($this->svc()->checkOwned(['Dune (2021)', 'Severence', 'Ex Machina', 'Dune (1984)']))->keyBy('query');
        $this->assertTrue($res['Dune (2021)']['in_library']);
        $this->assertSame('unwatched', $res['Dune (2021)']['status']);
        $this->assertSame('watched', $res['Severence']['status']); // fuzzy match
        $this->assertFalse($res['Ex Machina']['in_library']);
        $this->assertFalse($res['Dune (1984)']['in_library']);
    }

    public function test_most_watched_recent_and_history(): void
    {
        $recent = $this->svc()->mostWatched('show', 20, 30);
        $this->assertSame('Severance', $recent[0]['title']);
        $this->assertSame(2, $recent[0]['plays_in_period']);
        $this->assertStringStartsWith('Severance S02E10', $this->svc()->recentHistory(2)[0]['title']);
    }

    public function test_library_is_cached(): void
    {
        $this->svc()->items('movie');
        $this->svc()->items('movie');
        Http::assertSentCount(2); // sections + section/1/all, only once
    }

    public function test_artisan_commands(): void
    {
        $this->artisan('plex unwatched movie --limit=3')->assertSuccessful()->expectsOutputToContain('Dune');
        $this->artisan('plex check "Dark (2017)" --json')->assertSuccessful()->expectsOutputToContain('"in_library": true');
    }
}
