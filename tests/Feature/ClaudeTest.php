<?php

namespace EmilMoe\PlexRecommender\Tests\Feature;

use EmilMoe\PlexRecommender\Claude\ClaudeAdvisor;
use EmilMoe\PlexRecommender\Data\MediaItem;
use EmilMoe\PlexRecommender\Filament\Pages\Recommendations;
use EmilMoe\PlexRecommender\TasteProfile;
use EmilMoe\PlexRecommender\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

class ClaudeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Any existing file works as "the claude binary": Process is faked.
        config(['plex-recommender.claude.binary' => PHP_BINARY]);
    }

    protected function rec(string $title, ?int $year = null, string $reason = 'Passer til dig.'): array
    {
        return ['title' => $title, 'original_title' => null, 'year' => $year, 'director' => 'Nogen', 'country' => 'Danmark', 'reason' => $reason, 'because_you_watched' => ['Arrival']];
    }

    protected function fakeClaude(array $recommendations, string $taste = 'Du kan lide stille, kloge film.'): void
    {
        Process::fake([
            '*' => Process::result(json_encode([
                'type' => 'result',
                'is_error' => false,
                'result' => "Her er mine forslag:\n```json\n".json_encode(['taste_summary' => $taste, 'recommendations' => $recommendations])."\n```",
            ])),
        ]);
    }

    protected function advisor(): ClaudeAdvisor
    {
        return app(ClaudeAdvisor::class);
    }

    public function test_verifies_suggestions_on_tmdb_and_against_the_library(): void
    {
        $this->fakeClaude([
            $this->rec('Dune', 2021),            // in library, unwatched
            $this->rec('Arrival', 2016),         // watched
            $this->rec('Ex Machina', 2015),      // new
            $this->rec('Fuldstændig Opdigtet', 2019), // not on TMDB
        ]);

        $out = $this->advisor()->recommend('movie', 'noget med rumskibe', 'kendt');

        $this->assertSame('Claude Code', $out['driver']);
        $this->assertSame('Du kan lide stille, kloge film.', $out['taste_summary']);
        $this->assertSame(['Dune', 'Ex Machina'], array_column($out['recommendations'], 'title'));
        $this->assertTrue($out['recommendations'][0]['in_library']);
        $this->assertSame('https://image.tmdb.org/t/p/w342/exm.jpg', $out['recommendations'][1]['poster']);
        $this->assertSame(['seen' => 1, 'popular' => 0, 'not_found' => 1], $out['filtered']);

        Process::assertRan(function (PendingProcess $process) {
            $this->assertSame(PHP_BINARY, $process->command[0]);
            $this->assertContains('--system-prompt', $process->command); // replaces Claude Code's coding prompt
            $this->assertSame('opus', $process->command[array_search('--model', $process->command) + 1]);
            $this->assertStringContainsString('noget med rumskibe', $process->input);
            $this->assertStringContainsString('Blade Runner 2049', $process->input); // favourite / seen
            $this->assertStringNotContainsString('Love Actually', $process->input); // library isn't sent anymore

            return true;
        });
    }

    public function test_niche_level_drops_well_known_titles(): void
    {
        $this->fakeClaude([$this->rec('Ex Machina', 2015), $this->rec('Hævnen', 2010), $this->rec('Tvillingerne', 2005)]);

        $smal = $this->advisor()->recommend('movie', null, 'smal');
        $this->assertSame(['Hævnen', 'Tvillingerne'], array_column($smal['recommendations'], 'title'));
        $this->assertSame(1, $smal['filtered']['popular']);

        $perler = $this->advisor()->recommend('movie', null, 'perler');
        $this->assertSame(['Hævnen', 'Tvillingerne'], array_column($perler['recommendations'], 'title'));

        $this->assertStringContainsString('skjulte perler', mb_strtolower($this->advisor()->prompt('movie', null, 'perler', 10)));
    }

    public function test_answer_is_cached_and_asking_again_avoids_earlier_suggestions(): void
    {
        $this->fakeClaude([$this->rec('Hævnen', 2010)]);

        $this->assertNull($this->advisor()->cached('movie'));
        $this->advisor()->recommend('movie');
        $this->assertNotNull($this->advisor()->cached('movie'));
        $this->advisor()->recommend('movie');
        Process::assertRanTimes(fn () => true, 1);

        $this->advisor()->recommend('movie', null, null, 12, true);
        Process::assertRanTimes(fn () => true, 2);
        Process::assertRan(fn (PendingProcess $p) => str_contains($p->input, "Foreslået før (gentag IKKE)\nHævnen"));
    }

    public function test_reports_claude_code_errors_in_danish(): void
    {
        Process::fake(['*' => Process::result('', 'Invalid API key · Please run /login', 1)]);

        $out = $this->advisor()->recommend('movie');

        $this->assertStringContainsString('ikke logget ind', $out['error']);
    }

    public function test_missing_binary_gives_helpful_error(): void
    {
        config(['plex-recommender.claude.binary' => '/nope/claude']);
        Process::fake();

        $out = $this->advisor()->recommend('movie');

        $this->assertStringContainsString('/nope/claude', $out['error']);
        Process::assertNothingRan();
    }

    public function test_can_be_turned_off(): void
    {
        config(['plex-recommender.claude.driver' => 'off']);

        $this->assertFalse($this->advisor()->enabled());
        $this->assertArrayHasKey('error', $this->advisor()->recommend('movie'));
    }

    public function test_decode_tolerates_text_around_json(): void
    {
        $this->assertSame(['a' => 1], ClaudeAdvisor::decode('Svar: {"a": 1} Hilsen'));
        $this->assertSame(['a' => 1], ClaudeAdvisor::decode("```json\n{\"a\":1}\n```"));
        $this->assertNull(ClaudeAdvisor::decode('ingen json'));
    }

    public function test_suggestions_are_the_default_and_asked_automatically_on_first_visit(): void
    {
        Filament::setCurrentPanel('admin');
        $this->fakeClaude([$this->rec('Hævnen', 2010, 'Samme moralske tyngde som Arrival.')], 'Du søger alvor og stilhed.');

        Livewire::test(Recommendations::class)
            ->assertSet('source', 'claude')
            ->call('load')
            ->assertSee('Få forslag')
            ->call('autoAskClaude')
            ->assertSee('Hævnen')
            ->assertSee('Samme moralske tyngde som Arrival.')
            ->assertSee('Du søger alvor og stilhed.')
            ->assertSee('Nye forslag')
            ->call('autoAskClaude'); // cached now: no second run

        Process::assertRanTimes(fn () => true, 1);
    }

    public function test_falls_back_to_library_tab_when_claude_is_off(): void
    {
        Filament::setCurrentPanel('admin');
        config(['plex-recommender.claude.driver' => 'off']);

        Livewire::test(Recommendations::class)->assertSet('source', 'library');
    }

    public function test_suggestions_page_is_the_panel_start_page(): void
    {
        Filament::setCurrentPanel('admin');

        $this->assertSame(url('/admin'), Recommendations::getUrl());
    }

    public function test_long_shows_do_not_outweigh_movies(): void
    {
        $now = time();
        $movieSeenThrice = new MediaItem('1', 'M', MediaItem::MOVIE, viewCount: 3, lastViewedAt: $now);
        $longShowFinished = new MediaItem('2', 'S', MediaItem::SHOW, leafCount: 236, viewedLeafCount: 236, lastViewedAt: $now);
        $showDropped = new MediaItem('3', 'D', MediaItem::SHOW, leafCount: 20, viewedLeafCount: 2, lastViewedAt: $now);
        $movieOnce = new MediaItem('4', 'O', MediaItem::MOVIE, viewCount: 1, lastViewedAt: $now);

        $w = fn ($i) => TasteProfile::engagementWeight($i, $now);
        $this->assertEqualsWithDelta($w($movieSeenThrice), $w($longShowFinished), 0.01);
        $this->assertLessThan($w($movieOnce), $w($showDropped));
    }

    public function test_low_star_ratings_count_against_genre_and_director(): void
    {
        $now = time();
        $liked = new MediaItem('1', 'A', MediaItem::MOVIE, genres: ['Drama'], directors: ['Good'], viewCount: 1, lastViewedAt: $now, userRating: 9);
        $disliked = new MediaItem('2', 'B', MediaItem::MOVIE, genres: ['Horror'], directors: ['Bad'], viewCount: 1, lastViewedAt: $now, userRating: 2);

        $this->assertLessThan(0, TasteProfile::engagementWeight($disliked, $now));

        $profile = TasteProfile::build([$liked, $disliked], $now);
        $this->assertLessThan(0, $profile->genres['horror']);
        $this->assertSame(['A'], array_map(fn ($t) => $t[0]->title, $profile->topTitles)); // not a favourite

        $byBad = new MediaItem('3', 'C', MediaItem::MOVIE, genres: ['Drama'], directors: ['Bad']);
        $byGood = new MediaItem('4', 'D', MediaItem::MOVIE, genres: ['Drama'], directors: ['Good']);
        $this->assertLessThan($profile->score($byGood)[0], $profile->score($byBad)[0]);
        $this->assertNotContains('horror', array_column($profile->summary()['top_genres'], 'name'));
    }
}
